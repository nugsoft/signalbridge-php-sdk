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

        $client->whatsapp()->sendTemplate('256700000000', 'order_confirmation', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'John']]],
        ]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame('order_confirmation', $body['template']);
        $this->assertSame('en_US', $body['language']);
    }

    public function test_mobile_money_sends_the_note_the_gateway_reads(): void
    {
        $client = $this->client();

        $client->mobileMoney()->initiate('256700000000', 5000, 'ugx', [
            'reference' => 'INV-1',
            'description' => 'Invoice payment',
        ]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame('Invoice payment', $body['note']);
        $this->assertSame('UGX', $body['currency']);
        $this->assertSame('INV-1', $body['reference']);
        $this->assertArrayNotHasKey('description', $body);
        $this->assertArrayNotHasKey('callback_url', $body);
    }

    public function test_disbursement_reports_that_it_is_unavailable(): void
    {
        $client = $this->client();

        $this->expectException(ServiceUnavailableException::class);

        $client->mobileMoney()->disburse('256700000000', 5000);
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
}
