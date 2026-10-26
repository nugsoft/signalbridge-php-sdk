<?php

namespace Nugsoft\SignalBridge\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Nugsoft\SignalBridge\Exceptions\InsufficientBalanceException;
use Nugsoft\SignalBridge\Exceptions\InsufficientPermissionsException;
use Nugsoft\SignalBridge\Exceptions\NoClientException;
use Nugsoft\SignalBridge\Exceptions\RateLimitedException;
use Nugsoft\SignalBridge\Exceptions\ServiceUnavailableException;
use Nugsoft\SignalBridge\Exceptions\SignalBridgeException;
use Nugsoft\SignalBridge\Exceptions\UnauthorizedException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;
use Nugsoft\SignalBridge\SignalBridgeClient;
use Nugsoft\SignalBridge\Support\MagicNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SignalBridgeClientTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /**
     * A client whose HTTP layer is faked. No test may ever reach the real
     * gateway: that would send a real SMS and bill the account.
     *
     * @param  array<int, Response>  $responses
     */
    private function client(array $responses = [], string $baseUrl = 'https://gateway.test/api'): SignalBridgeClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler($responses ?: [new Response(200, [], '{"success":true}')]));
        $stack->push(Middleware::history($this->history));

        return new SignalBridgeClient(
            token: 'test-token',
            baseUrl: $baseUrl,
            logging: false,
            httpClient: new HttpClient(['handler' => $stack])
        );
    }

    private function lastRequest(): Request
    {
        return $this->history[count($this->history) - 1]['request'];
    }

    private function lastRequestOrNull(): ?Request
    {
        return $this->history === [] ? null : $this->lastRequest();
    }

    public function test_it_keeps_the_api_prefix_on_every_path(): void
    {
        $client = $this->client([
            new Response(200, [], '{"success":true}'),
            new Response(200, [], '{"success":true}'),
            new Response(200, [], '{"success":true}'),
        ]);

        $client->sendSms('256700000000', 'Hello');
        $this->assertSame('https://gateway.test/api/sms/send', (string) $this->lastRequest()->getUri());

        $client->getBalance();
        $this->assertSame('https://gateway.test/api/balance?currency=UGX', (string) $this->lastRequest()->getUri());

        $client->getMessageStatus(42);
        $this->assertSame('https://gateway.test/api/sms/messages/42', (string) $this->lastRequest()->getUri());
    }

    public function test_it_tolerates_a_base_url_with_a_trailing_slash(): void
    {
        $client = $this->client([new Response(200, [], '{"success":true}')], 'https://gateway.test/api/');

        $client->sendSms('256700000000', 'Hello');

        $this->assertSame('https://gateway.test/api/sms/send', (string) $this->lastRequest()->getUri());
    }

    public function test_it_sends_the_bearer_token(): void
    {
        $client = $this->client();

        $client->getBalance();

        $this->assertSame('Bearer test-token', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_it_posts_the_message_payload(): void
    {
        $client = $this->client();

        $client->sendSms('256700000000', 'Hello', [
            'sender_id' => 'NUGSOFT',
            'metadata' => ['order_id' => 7],
            'is_test' => true,
        ]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame([
            'recipient' => '256700000000',
            'message' => 'Hello',
            'metadata' => ['order_id' => 7],
            'is_test' => true,
            'sender_id' => 'NUGSOFT',
        ], $body);
    }

    public function test_it_returns_the_decoded_response(): void
    {
        $client = $this->client([
            new Response(200, [], '{"success":true,"data":{"message_id":123,"cost":75}}'),
        ]);

        $result = $client->sendSms('256700000000', 'Hello');

        $this->assertSame(123, $result['data']['message_id']);
    }

    public function test_a_batch_posts_every_message(): void
    {
        $client = $this->client();

        $client->sendBatch([
            ['recipient' => '256700000000', 'message' => 'One'],
            ['recipient' => '256700000001', 'message' => 'Two'],
        ]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertCount(2, $body['messages']);
        $this->assertSame('https://gateway.test/api/sms/send-batch', (string) $this->lastRequest()->getUri());
    }

    public function test_message_ids_are_joined_for_the_listing_filter(): void
    {
        $client = $this->client();

        $client->getMessages(['ids' => [1, 2, 3]]);

        $this->assertStringContainsString('ids=1%2C2%2C3', (string) $this->lastRequest()->getUri());
    }

    public function test_a_refresh_is_only_requested_when_asked_for(): void
    {
        $client = $this->client([new Response(200, [], '{}'), new Response(200, [], '{}')]);

        $client->getMessageStatus(5);
        $this->assertStringNotContainsString('refresh', (string) $this->lastRequest()->getUri());

        $client->getMessageStatus(5, refresh: true);
        $this->assertStringContainsString('refresh=1', (string) $this->lastRequest()->getUri());
    }

    public function test_an_empty_recipient_is_refused_before_any_request(): void
    {
        $client = $this->client();

        $this->expectException(ValidationException::class);

        try {
            $client->sendSms('  ', 'Hello');
        } finally {
            $this->assertCount(0, $this->history);
        }
    }

    public function test_an_over_long_message_is_refused(): void
    {
        $client = $this->client();

        $this->expectException(ValidationException::class);

        $client->sendSms('256700000000', str_repeat('a', 1001));
    }

    public function test_an_empty_token_is_refused(): void
    {
        $this->expectException(SignalBridgeException::class);

        new SignalBridgeClient('');
    }

    #[DataProvider('errorResponses')]
    public function test_it_maps_error_responses_to_typed_exceptions(int $status, string $body, string $expected): void
    {
        $client = $this->client([new Response($status, [], $body)]);

        $this->expectException($expected);

        $client->sendSms('256700000000', 'Hello');
    }

    /**
     * @return array<string, array{int, string, class-string}>
     */
    public static function errorResponses(): array
    {
        return [
            'unauthenticated' => [401, '{"message":"Unauthenticated."}', UnauthorizedException::class],
            'insufficient balance' => [402, '{"message":"Insufficient balance.","data":{"required_balance":75}}', InsufficientBalanceException::class],
            'no client' => [403, '{"message":"No client associated with your account."}', NoClientException::class],
            'missing ability' => [403, '{"message":"This API token does not have permission to perform this action."}', InsufficientPermissionsException::class],
            'validation' => [422, '{"message":"The given data was invalid.","errors":{"recipient":["bad"]}}', ValidationException::class],
            'rate limited' => [429, '{"message":"Too many requests."}', RateLimitedException::class],
            'server error' => [500, '{"message":"Server error"}', SignalBridgeException::class],
            'unavailable' => [503, '{"message":"SMS service is currently unavailable."}', ServiceUnavailableException::class],
            'unreadable body' => [200, 'not json', SignalBridgeException::class],
        ];
    }

    public function test_a_missing_resource_reports_what_the_gateway_said(): void
    {
        $client = $this->client([new Response(404, [], '{"success":false,"message":"Message not found."}')]);

        $this->expectException(SignalBridgeException::class);
        $this->expectExceptionMessage('Message not found.');

        $client->getMessageStatus(999);
    }

    public function test_a_404_that_is_not_from_the_gateway_points_at_the_base_url(): void
    {
        $client = $this->client([new Response(404, [], '<html>Not Found</html>')]);

        $this->expectException(SignalBridgeException::class);
        $this->expectExceptionMessageMatches('/Verify the base URL/');

        $client->getMessageStatus(999);
    }

    public function test_insufficient_balance_carries_the_figures(): void
    {
        $client = $this->client([
            new Response(402, [], '{"message":"Insufficient balance.","data":{"required_balance":75,"current_balance":10,"segments":1}}'),
        ]);

        try {
            $client->sendSms('256700000000', 'Hello');
            $this->fail('Expected InsufficientBalanceException');
        } catch (InsufficientBalanceException $e) {
            $this->assertSame(75.0, $e->getRequiredBalance());
            $this->assertSame(10.0, $e->getCurrentBalance());
            $this->assertSame(1, $e->getSegments());
        }
    }

    public function test_validation_errors_are_readable(): void
    {
        $client = $this->client([
            new Response(422, [], '{"message":"Invalid","errors":{"recipient":["The recipient must be a valid phone number."]}}'),
        ]);

        try {
            $client->sendSms('256700000000', 'Hello');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('The recipient must be a valid phone number.', $e->getFirstError());
        }
    }

    public function test_an_unreachable_gateway_is_reported_as_such(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new \GuzzleHttp\Exception\ConnectException('Connection refused', new Request('POST', 'https://gateway.test/api/sms/send')),
        ]));

        $client = new SignalBridgeClient(
            token: 'test-token',
            baseUrl: 'https://gateway.test/api',
            logging: false,
            httpClient: new HttpClient(['handler' => $stack])
        );

        $this->expectException(SignalBridgeException::class);
        $this->expectExceptionMessageMatches('/Could not reach SignalBridge/');

        $client->sendSms('256700000000', 'Hello');
    }

    public function test_exports_return_raw_csv(): void
    {
        $client = $this->client([new Response(200, [], "ID,Recipient\n1,256700000000\n")]);

        $this->assertStringContainsString('256700000000', $client->exportMessages());
        $this->assertSame('https://gateway.test/api/export/messages', (string) $this->lastRequest()->getUri());
    }

    public function test_webhook_management_hits_the_right_routes(): void
    {
        $client = $this->client(array_fill(0, 5, new Response(200, [], '{"success":true}')));

        $client->createWebhook('https://example.com/hook', ['message.sent']);
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('https://gateway.test/api/webhooks', (string) $this->lastRequest()->getUri());

        $client->updateWebhook(3, ['is_active' => false]);
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('https://gateway.test/api/webhooks/3', (string) $this->lastRequest()->getUri());

        $client->deleteWebhook(3);
        $this->assertSame('DELETE', $this->lastRequest()->getMethod());

        $client->regenerateWebhookSecret(3);
        $this->assertSame('https://gateway.test/api/webhooks/3/regenerate-secret', (string) $this->lastRequest()->getUri());
    }

    public function test_the_whatsapp_channel_sends_text_and_templates(): void
    {
        $client = $this->client([new Response(200, [], '{}'), new Response(200, [], '{}')]);

        $client->whatsapp()->send('256700000000', 'Hello');
        $this->assertSame('https://gateway.test/api/whatsapp/send', (string) $this->lastRequest()->getUri());

        $client->whatsapp()->sendTemplate('256700000000', 'fee_reminder', ['John', 'UGX 50,000'], [
            'header' => ['type' => 'document', 'url' => 'https://files.example.com/r.pdf', 'filename' => 'r.pdf'],
        ]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame('fee_reminder', $body['template']);
        $this->assertSame(['John', 'UGX 50,000'], $body['variables']);
        $this->assertSame('document', $body['header']['type']);
        $this->assertArrayNotHasKey('components', $body);
    }

    public function test_the_old_components_structure_is_refused_with_an_explanation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/plain list/');

        $this->client()->whatsapp()->sendTemplate('256700000000', 'order_confirmation', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'John']]],
        ]);
    }

    public function test_a_flow_is_sent_as_an_interactive_message(): void
    {
        $client = $this->client();

        $client->whatsapp()->sendFlow('256700000000', 'spa_booking', 'Book your next session', 'Book now', ['screen' => 'BOOKING']);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame(['name' => 'spa_booking', 'body' => 'Book your next session', 'button' => 'Book now', 'screen' => 'BOOKING'], $body['flow']);
    }

    public function test_a_file_is_sent_on_its_own(): void
    {
        $this->client()->whatsapp()->sendMedia('256700000000', 'document', 'https://files.example.com/report.pdf', [
            'caption' => 'Term 2 report',
            'filename' => 'report.pdf',
        ]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame('https://gateway.test/api/whatsapp/send', (string) $this->lastRequest()->getUri());
        $this->assertSame(['type' => 'document', 'url' => 'https://files.example.com/report.pdf', 'caption' => 'Term 2 report', 'filename' => 'report.pdf'], $body['media']);
        $this->assertArrayNotHasKey('message', $body);
    }

    public function test_a_file_of_an_unknown_type_or_on_plain_http_is_refused_before_sending(): void
    {
        foreach ([['gif', 'https://example.com/a.gif'], ['image', 'http://example.com/a.jpg']] as [$type, $url]) {
            try {
                $this->client()->whatsapp()->sendMedia('256700000000', $type, $url);
                $this->fail("{$type} {$url} was not refused");
            } catch (ValidationException) {
                $this->assertNull($this->lastRequestOrNull());
            }
        }
    }

    public function test_reply_buttons_are_sent_with_their_ids_and_a_text_header(): void
    {
        $this->client()->whatsapp()->sendButtons('256700000000', 'Can you make it?', [
            'confirm_4821' => 'Confirm',
            'reschedule_4821' => 'Reschedule',
        ], ['header' => 'Tomorrow, 10:00', 'footer' => 'Acme Clinic']);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame([
            'type' => 'buttons',
            'body' => 'Can you make it?',
            'buttons' => [['id' => 'confirm_4821', 'title' => 'Confirm'], ['id' => 'reschedule_4821', 'title' => 'Reschedule']],
            'header' => ['type' => 'text', 'text' => 'Tomorrow, 10:00'],
            'footer' => 'Acme Clinic',
        ], $body['interactive']);
    }

    public function test_more_than_three_buttons_are_refused_before_sending(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/sendList/');

        $this->client()->whatsapp()->sendButtons('256700000000', 'Pick', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);
    }

    public function test_a_list_and_a_link_button_are_sent_as_interactive_messages(): void
    {
        $client = $this->client([new Response(200, [], '{}'), new Response(200, [], '{}')]);

        $client->whatsapp()->sendList('256700000000', 'Which branch?', 'Choose a branch', [
            ['title' => 'Kampala', 'rows' => [['id' => 'kla_central', 'title' => 'Central', 'description' => 'Open until 8pm']]],
        ]);

        $list = json_decode((string) $this->lastRequest()->getBody(), true)['interactive'];
        $this->assertSame('list', $list['type']);
        $this->assertSame('Choose a branch', $list['button']);
        $this->assertSame('kla_central', $list['sections'][0]['rows'][0]['id']);

        $client->whatsapp()->sendLinkButton('256700000000', 'Your invoice is ready.', 'Pay now', 'https://pay.example.com/inv/42');

        $this->assertSame(
            ['type' => 'cta_url', 'body' => 'Your invoice is ready.', 'button' => 'Pay now', 'url' => 'https://pay.example.com/inv/42'],
            json_decode((string) $this->lastRequest()->getBody(), true)['interactive']
        );
    }

    public function test_a_list_of_more_than_ten_options_is_refused_before_sending(): void
    {
        $this->expectException(ValidationException::class);

        $this->client()->whatsapp()->sendList('256700000000', 'Pick', 'Open', [
            ['title' => 'All', 'rows' => array_map(fn ($i) => ['id' => "r{$i}", 'title' => "R{$i}"], range(1, 11))],
        ]);
    }

    public function test_whatsapp_templates_flows_and_received_messages_hit_the_right_routes(): void
    {
        $ok = fn () => new Response(200, [], '{"success":true}');
        $client = $this->client([$ok(), $ok(), $ok(), $ok(), $ok(), $ok(), $ok(), $ok(), new Response(200, [], '%PDF-1.4')]);
        $whatsapp = $client->whatsapp();

        $calls = [
            fn () => $whatsapp->createTemplate(['name' => 'fee_reminder']),
            fn () => $whatsapp->listTemplates('approved'),
            fn () => $whatsapp->getTemplate(4, true),
            fn () => $whatsapp->deleteTemplate(4),
            fn () => $whatsapp->createFlow(['name' => 'spa_booking']),
            fn () => $whatsapp->updateFlow(3, ['endpoint_url' => 'https://spa.example.com/flow']),
            fn () => $whatsapp->publishFlow(3),
            fn () => $whatsapp->received(['since' => '2026-10-07']),
        ];

        $expected = [
            ['POST', 'https://gateway.test/api/whatsapp/templates'],
            ['GET', 'https://gateway.test/api/whatsapp/templates?status=approved'],
            ['GET', 'https://gateway.test/api/whatsapp/templates/4?refresh=1'],
            ['DELETE', 'https://gateway.test/api/whatsapp/templates/4'],
            ['POST', 'https://gateway.test/api/whatsapp/flows'],
            ['PUT', 'https://gateway.test/api/whatsapp/flows/3'],
            ['POST', 'https://gateway.test/api/whatsapp/flows/3/publish'],
            ['GET', 'https://gateway.test/api/whatsapp/received?since=2026-10-07'],
        ];

        foreach ($calls as $index => $call) {
            $call();
            $this->assertSame($expected[$index], [$this->lastRequest()->getMethod(), (string) $this->lastRequest()->getUri()]);
        }

        $this->assertSame('%PDF-1.4', $whatsapp->downloadMedia(7));
        $this->assertSame('https://gateway.test/api/whatsapp/received/7/media', (string) $this->lastRequest()->getUri());
    }

    /**
     * The gateway's transaction, as initiate() and verify() return it.
     *
     * @return array<string, mixed>
     */
    private function mobileMoneyTransaction(array $overrides = []): array
    {
        return [
            'reference' => '0b6f2c1e-7d3a-4a5b-9c8d-1e2f3a4b5c6d',
            'direction' => 'collection',
            'status' => 'pending',
            'status_message' => 'Request is being processed',
            'phone' => '256700000000',
            'amount' => '5000.00',
            'currency' => 'UGX',
            ...$overrides,
        ];
    }

    public function test_mobile_money_sends_only_what_the_gateway_reads_and_returns_its_reference(): void
    {
        $client = $this->client([new Response(202, [], json_encode(['success' => true, 'data' => $this->mobileMoneyTransaction()]))]);

        $tx = $client->mobileMoney()->initiate('256700000000', 5000, 'ugx', [
            'description' => 'Invoice payment',
            'metadata' => ['tenant_id' => 'school-42'],
            'callback_url' => 'https://example.test/momo',
        ]);

        $this->assertSame('0b6f2c1e-7d3a-4a5b-9c8d-1e2f3a4b5c6d', $tx['data']['reference']);
        $this->assertSame(
            ['phone' => '256700000000', 'amount' => 5000, 'currency' => 'UGX', 'note' => 'Invoice payment', 'metadata' => ['tenant_id' => 'school-42']],
            json_decode((string) $this->lastRequest()->getBody(), true)
        );
    }

    public function test_mobile_money_refuses_a_reference_of_your_own(): void
    {
        $client = $this->client();

        try {
            $client->mobileMoney()->initiate('256700000000', 5000, 'UGX', ['reference' => 'INV-1']);
            $this->fail('A reference of the caller\'s own was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('data.reference', $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function test_mobile_money_refuses_a_note_longer_than_the_gateway_takes(): void
    {
        $client = $this->client();

        $this->expectException(ValidationException::class);

        $client->mobileMoney()->initiate('256700000000', 5000, 'UGX', ['note' => str_repeat('x', 101)]);
    }

    public function test_mobile_money_switched_off_is_reported_as_unavailable(): void
    {
        $client = $this->client([new Response(403, [], json_encode(['success' => false, 'message' => 'Mobile money collections are not available yet.']))]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Mobile money collections are not available yet.');

        $client->mobileMoney()->initiate('256700000000', 5000);
    }

    public function test_mobile_money_without_the_ability_is_still_a_permissions_error(): void
    {
        $client = $this->client([new Response(403, [], json_encode([
            'success' => false,
            'message' => 'This API token does not have permission to perform this action. Required ability: mobile-money:send.',
        ]))]);

        $this->expectException(InsufficientPermissionsException::class);

        $client->mobileMoney()->initiate('256700000000', 5000);
    }

    public function test_a_refused_payment_says_why_and_keeps_the_transaction(): void
    {
        $client = $this->client([new Response(422, [], json_encode([
            'success' => false,
            'message' => 'The payment request was refused.',
            'data' => $this->mobileMoneyTransaction(['status' => 'failed', 'status_message' => 'Amount must be at least 500']),
        ]))]);

        try {
            $client->mobileMoney()->initiate('256700000000', 5000);
            $this->fail('A refused payment did not throw.');
        } catch (ValidationException $e) {
            $this->assertSame('The payment request was refused: Amount must be at least 500', $e->getMessage());
            $this->assertSame('0b6f2c1e-7d3a-4a5b-9c8d-1e2f3a4b5c6d', $e->getData()['reference']);
        }
    }

    public function test_mobile_money_is_verified_by_its_reference(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['success' => true, 'data' => $this->mobileMoneyTransaction(['status' => 'completed'])]))]);

        $result = $client->mobileMoney()->verify('0b6f2c1e-7d3a-4a5b-9c8d-1e2f3a4b5c6d');

        $this->assertSame('completed', $result['data']['status']);
        $this->assertSame(
            'https://gateway.test/api/mobile-money/transactions/0b6f2c1e-7d3a-4a5b-9c8d-1e2f3a4b5c6d',
            (string) $this->lastRequest()->getUri()
        );
    }

    public function test_mobile_money_refuses_an_amount_below_the_gateway_minimum(): void
    {
        $client = $this->client();

        try {
            $client->mobileMoney()->initiate('256700000000', 499);
            $this->fail('An amount below 500 was sent.');
        } catch (ValidationException $e) {
            $this->assertSame('Amount must be at least 500', $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function test_mobile_money_refuses_more_metadata_than_the_gateway_keeps(): void
    {
        $client = $this->client();

        $this->expectException(ValidationException::class);

        $client->mobileMoney()->initiate('256700000000', 5000, 'UGX', ['metadata' => array_fill_keys(range(1, 21), 'x')]);
    }

    public function test_mobile_money_balance_is_read(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['success' => true, 'data' => [
            ['currency' => 'UGX', 'available' => '148500.00', 'held' => '52200.00', 'updated_at' => '2026-10-26T11:02:13+03:00'],
        ]]))]);

        $balances = $client->mobileMoney()->balance()['data'];

        $this->assertSame('148500.00', $balances[0]['available']);
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('https://gateway.test/api/mobile-money/balance', (string) $this->lastRequest()->getUri());
    }

    public function test_disbursement_points_to_payouts_on_the_dashboard(): void
    {
        $client = $this->client();

        try {
            $client->mobileMoney()->disburse('256700000000', 5000);
            $this->fail('disburse() did not throw.');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('Balance page of the SignalBridge dashboard', $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function test_credit_requests_post_to_the_gateway(): void
    {
        $client = $this->client([new Response(202, [], '{"success":true}')]);

        $client->requestCredit(10000, 'ugx', 'Monthly top-up');

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame('https://gateway.test/api/balance/add-credit', (string) $this->lastRequest()->getUri());
        // json_encode writes 10000.0 as 10000, so compare the decoded value loosely.
        $this->assertEquals(['amount' => 10000, 'currency' => 'UGX', 'description' => 'Monthly top-up'], $body);
    }

    public function test_the_whatsapp_balance_is_read_and_topped_up_on_its_own(): void
    {
        $client = $this->client([
            new Response(200, [], '{"success":true}'),
            new Response(200, [], '{"success":true}'),
            new Response(202, [], '{"success":true}'),
        ]);

        $client->getBalance('UGX', 'whatsapp');
        $this->assertSame('https://gateway.test/api/balance?currency=UGX&channel=whatsapp', (string) $this->lastRequest()->getUri());

        $client->getTransactions(['channel' => 'WhatsApp']);
        $this->assertSame('https://gateway.test/api/balance/transactions?channel=whatsapp', (string) $this->lastRequest()->getUri());

        $client->requestCredit(5000, 'UGX', null, 'whatsapp');
        $this->assertEquals(['amount' => 5000, 'currency' => 'UGX', 'channel' => 'whatsapp'], json_decode((string) $this->lastRequest()->getBody(), true));
    }

    public function test_a_channel_with_no_balance_is_refused_before_sending(): void
    {
        $client = $this->client();

        try {
            $client->getBalance('UGX', 'ussd');
            $this->fail('A balance channel that does not exist was sent.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sms, whatsapp', $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function test_a_sandbox_client_talks_to_the_sandbox(): void
    {
        $client = SignalBridgeClient::sandbox('sandbox-token', logging: false, httpClient: new HttpClient(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{}')]))]));

        $this->assertTrue($client->isSandbox());
        $this->assertFalse($this->client()->isSandbox());
    }

    public function test_the_sandbox_tools_hit_their_routes(): void
    {
        $client = $this->client([new Response(200, [], '{}'), new Response(201, [], '{}'), new Response(200, [], '{}'), new Response(200, [], '{}')], SignalBridgeClient::SANDBOX_URL);
        $tools = $client->sandboxTools();

        $tools->magicNumbers();
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame(SignalBridgeClient::SANDBOX_URL.'/sandbox', (string) $this->lastRequest()->getUri());

        $tools->customerMessage(MagicNumbers::DELIVERED, ['type' => 'interactive', 'reply_to_message_id' => 42, 'reply_id' => 'confirm_42']);
        $this->assertSame(SignalBridgeClient::SANDBOX_URL.'/sandbox/whatsapp/customer-message', (string) $this->lastRequest()->getUri());
        $this->assertSame(['from' => '256700000001', 'type' => 'interactive', 'reply_to_message_id' => 42, 'reply_id' => 'confirm_42'], json_decode((string) $this->lastRequest()->getBody(), true));

        $tools->setMessageStatus(42, 'read');
        $this->assertSame(SignalBridgeClient::SANDBOX_URL.'/sandbox/messages/42/status', (string) $this->lastRequest()->getUri());
        $this->assertSame(['status' => 'read'], json_decode((string) $this->lastRequest()->getBody(), true));

        $tools->addCredit();
        $this->assertSame(SignalBridgeClient::SANDBOX_URL.'/sandbox/credit', (string) $this->lastRequest()->getUri());
    }

    public function test_the_sandbox_tools_on_live_say_where_they_live(): void
    {
        $client = $this->client([new Response(404, [], '{"message":"Not Found"}')]);

        $this->expectException(SignalBridgeException::class);
        $this->expectExceptionMessageMatches('/only in the sandbox/');

        $client->sandboxTools()->addCredit();
    }

    public function test_a_message_that_is_not_yours_is_still_reported_as_such(): void
    {
        $client = $this->client([new Response(404, [], '{"success":false,"message":"Message not found."}')], SignalBridgeClient::SANDBOX_URL);

        $this->expectExceptionMessage('Message not found.');

        $client->sandboxTools()->setMessageStatus(99, 'delivered');
    }

    public function test_an_unknown_sandbox_status_is_refused_before_sending(): void
    {
        $this->expectException(ValidationException::class);

        $this->client()->sandboxTools()->setMessageStatus(1, 'lost');
    }
}
