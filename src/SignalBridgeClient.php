<?php

namespace Nugsoft\SignalBridge;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Channels\MobileMoney\MobileMoneyClient;
use Nugsoft\SignalBridge\Channels\Sms\SmsClient;
use Nugsoft\SignalBridge\Channels\Ussd\UssdClient;
use Nugsoft\SignalBridge\Channels\WhatsApp\WhatsAppClient;
use Nugsoft\SignalBridge\Exceptions\SignalBridgeException;
use Nugsoft\SignalBridge\Support\MessageSegments;
use Nugsoft\SignalBridge\Support\WebhookSignature;

/**
 * Framework-free client for the SignalBridge gateway.
 *
 * Mirrors the Laravel SDK (nugsoft/signalbridge-laravel-sdk): same channel
 * accessors, same typed exceptions, same segment maths. Use that package
 * instead inside a Laravel application.
 */
class SignalBridgeClient extends BaseChannelClient
{
    public const DEFAULT_BASE_URL = 'https://signal-bridge.nugsoftapps.net/api';

    private ?SmsClient $smsChannel = null;

    private ?WhatsAppClient $whatsAppChannel = null;

    private ?MobileMoneyClient $mobileMoneyChannel = null;

    private ?UssdClient $ussdChannel = null;

    /**
     * @param  string  $token  An API token from the SignalBridge dashboard
     * @param  string  $baseUrl  The gateway API root, including /api
     * @param  ClientInterface|null  $httpClient  Supply your own Guzzle client to
     *                                            control proxies, TLS or testing
     */
    public function __construct(
        string $token,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 30,
        bool $logging = true,
        ?ClientInterface $httpClient = null
    ) {
        if ($token === '') {
            throw new SignalBridgeException('API token is required');
        }

        parent::__construct(
            baseUrl: rtrim($baseUrl, '/'),
            token: $token,
            timeout: $timeout,
            // Deliberately no base_uri and no retry middleware: Guzzle resolves
            // a path beginning with "/" against the host root and would drop
            // the /api prefix, and retrying a POST can send an SMS twice.
            httpClient: $httpClient ?? new HttpClient,
            logging: $logging
        );
    }

    // -------------------------------------------------------------------------
    // Channel accessors
    // -------------------------------------------------------------------------

    public function sms(): SmsClient
    {
        return $this->smsChannel ??= new SmsClient($this->baseUrl, $this->token, $this->timeout, $this->httpClient, $this->logging);
    }

    public function whatsapp(): WhatsAppClient
    {
        return $this->whatsAppChannel ??= new WhatsAppClient($this->baseUrl, $this->token, $this->timeout, $this->httpClient, $this->logging);
    }

    public function mobileMoney(): MobileMoneyClient
    {
        return $this->mobileMoneyChannel ??= new MobileMoneyClient($this->baseUrl, $this->token, $this->timeout, $this->httpClient, $this->logging);
    }

    /**
     * @note USSD support is planned — available once the gateway's USSD engine ships.
     */
    public function ussd(): UssdClient
    {
        return $this->ussdChannel ??= new UssdClient($this->baseUrl, $this->token, $this->timeout, $this->httpClient, $this->logging);
    }

    // -------------------------------------------------------------------------
    // SMS — kept for backward compatibility (proxies to SmsClient)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $options  metadata, is_test (a label only — still sent and charged), sender_id (ignored: the gateway always sends as NUGSOFT), scheduled_at
     * @return array<string, mixed>
     */
    public function sendSms(string $recipient, string $message, array $options = []): array
    {
        return $this->sms()->send($recipient, $message, $options);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options  is_test (a label only — still sent and charged), sender_id (ignored: the gateway always sends as NUGSOFT)
     * @return array<string, mixed>
     */
    public function sendBatch(array $messages, array $options = []): array
    {
        return $this->sms()->sendBatch($messages, $options);
    }

    /**
     * Look up one message's delivery status.
     *
     * @return array<string, mixed>
     */
    public function getMessageStatus(int $messageId, bool $refresh = false): array
    {
        return $this->sms()->status($messageId, $refresh);
    }

    /**
     * List messages with their delivery status.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getMessages(array $filters = []): array
    {
        return $this->sms()->messages($filters);
    }

    // -------------------------------------------------------------------------
    // Account
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getBalance(string $currency = 'UGX'): array
    {
        return $this->request('GET', 'balance', ['query' => ['currency' => strtoupper($currency)]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getBalanceSummary(): array
    {
        return $this->request('GET', 'balance/summary');
    }

    /**
     * @param  array<string, mixed>  $filters  per_page, page, type, start_date, end_date
     * @return array<string, mixed>
     */
    public function getTransactions(array $filters = []): array
    {
        return $this->request('GET', 'balance/transactions', ['query' => $filters]);
    }

    /**
     * Ask the administrators for a top-up. This does not move money.
     *
     * @return array<string, mixed>
     */
    public function requestCredit(float $amount, string $currency = 'UGX', ?string $description = null): array
    {
        $payload = ['amount' => $amount, 'currency' => strtoupper($currency)];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        return $this->request('POST', 'balance/add-credit', ['json' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTokens(): array
    {
        return $this->request('GET', 'tokens');
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeCurrentToken(): array
    {
        return $this->request('DELETE', 'tokens/current');
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function listWebhooks(): array
    {
        return $this->request('GET', 'webhooks');
    }

    /**
     * The signing secret is returned once, in this response only.
     *
     * @param  array<int, string>  $events
     * @return array<string, mixed>
     */
    public function createWebhook(string $url, array $events = ['*'], bool $isActive = true): array
    {
        return $this->request('POST', 'webhooks', [
            'json' => [
                'url' => $url,
                'events' => $events,
                'is_active' => $isActive,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhook(int $webhookId): array
    {
        return $this->request('GET', "webhooks/{$webhookId}");
    }

    /**
     * @param  array<string, mixed>  $data  url, events, is_active
     * @return array<string, mixed>
     */
    public function updateWebhook(int $webhookId, array $data): array
    {
        return $this->request('PUT', "webhooks/{$webhookId}", ['json' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteWebhook(int $webhookId): array
    {
        return $this->request('DELETE', "webhooks/{$webhookId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function regenerateWebhookSecret(int $webhookId): array
    {
        return $this->request('POST', "webhooks/{$webhookId}/regenerate-secret");
    }

    /**
     * Verify that an inbound webhook really came from SignalBridge.
     *
     * @param  string  $payload  The RAW request body, exactly as received
     * @param  string  $signature  The X-SignalBridge-Signature header
     * @param  string  $secret  The signing secret shown when the webhook was created
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        return WebhookSignature::verify($payload, $signature, $secret);
    }

    // -------------------------------------------------------------------------
    // Exports
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters  start_date, end_date, status
     */
    public function exportMessages(array $filters = []): string
    {
        return $this->requestBody('GET', 'export/messages', ['query' => $filters], timeout: 120);
    }

    /**
     * @param  array<string, mixed>  $filters  start_date, end_date, type
     */
    public function exportTransactions(array $filters = []): string
    {
        return $this->requestBody('GET', 'export/transactions', ['query' => $filters], timeout: 120);
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Segments a message will be split into.
     *
     * Delegates to MessageSegments so this and the gateway cannot disagree.
     */
    public function calculateSegments(string $message): int
    {
        return MessageSegments::count($message);
    }

    /**
     * @param  float  $segmentPrice  Price per segment, from getBalance()
     */
    public function estimateCost(string $message, float $segmentPrice): float
    {
        return MessageSegments::estimateCost($message, $segmentPrice);
    }
}
