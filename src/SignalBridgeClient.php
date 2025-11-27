<?php

namespace Nugsoft\SignalBridge;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Nugsoft\SignalBridge\Exceptions\InsufficientBalanceException;
use Nugsoft\SignalBridge\Exceptions\NoClientException;
use Nugsoft\SignalBridge\Exceptions\ServiceUnavailableException;
use Nugsoft\SignalBridge\Exceptions\SignalBridgeException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

class SignalBridgeClient
{
    private string $baseUrl;
    private string $token;
    private Client $httpClient;
    private int $timeout;
    private bool $logging;

    public function __construct(
        string $token,
        string $baseUrl = 'https://signal-bridge.nugsoftstagging.com/api',
        int $timeout = 30,
        bool $logging = true
    ) {
        if (empty($token)) {
            throw new SignalBridgeException('API token is required');
        }

        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->logging = $logging;

        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => "Bearer {$this->token}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Send an SMS message
     *
     * @param string $recipient Phone number (e.g., '256700000000')
     * @param string $message Message content (max 1000 chars)
     * @param array $options Optional parameters
     * @return array Response data
     * @throws SignalBridgeException
     */
    public function sendSms(string $recipient, string $message, array $options = []): array
    {
        $payload = array_merge([
            'recipient' => $recipient,
            'message' => $message,
            'metadata' => [],
            'is_test' => false,
        ], $options);

        try {
            $response = $this->httpClient->post('/sms/send', [
                'json' => $payload,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Send batch SMS messages
     *
     * @param array $messages Array of message objects
     * @param array $options Optional parameters
     * @return array Response data
     * @throws SignalBridgeException
     */
    public function sendBatch(array $messages, array $options = []): array
    {
        $payload = [
            'messages' => $messages,
            'is_test' => $options['is_test'] ?? false,
        ];

        if (isset($options['sender_id'])) {
            $payload['sender_id'] = $options['sender_id'];
        }

        try {
            $response = $this->httpClient->post('/sms/send-batch', [
                'json' => $payload,
                'timeout' => 60, // Longer timeout for batch operations
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get current balance for a currency
     *
     * @param string $currency Currency code (default: UGX)
     * @return array Balance details
     * @throws SignalBridgeException
     */
    public function getBalance(string $currency = 'UGX'): array
    {
        try {
            $response = $this->httpClient->get('/balance', [
                'query' => ['currency' => $currency],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get balance summary with recent activity
     *
     * @return array Summary data
     * @throws SignalBridgeException
     */
    public function getBalanceSummary(): array
    {
        try {
            $response = $this->httpClient->get('/balance/summary');

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get transaction history
     *
     * @param array $filters Filter options (per_page, page, type, start_date, end_date)
     * @return array Paginated transactions
     * @throws SignalBridgeException
     */
    public function getTransactions(array $filters = []): array
    {
        try {
            $response = $this->httpClient->get('/balance/transactions', [
                'query' => $filters,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get all API tokens
     *
     * @return array List of tokens
     * @throws SignalBridgeException
     */
    public function getTokens(): array
    {
        try {
            $response = $this->httpClient->get('/tokens');

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Revoke the current API token
     *
     * @return array Response data
     * @throws SignalBridgeException
     */
    public function revokeCurrentToken(): array
    {
        try {
            $response = $this->httpClient->delete('/tokens/current');

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Calculate approximate segments for a message
     *
     * @param string $message Message content
     * @return int Number of segments
     */
    public function calculateSegments(string $message): int
    {
        $length = mb_strlen($message);
        $isUnicode = !$this->isGsm7Bit($message);

        if ($isUnicode) {
            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }

    /**
     * Estimate cost for a message
     *
     * @param string $message Message content
     * @param float $segmentPrice Price per segment
     * @return float Estimated cost
     */
    public function estimateCost(string $message, float $segmentPrice): float
    {
        return $this->calculateSegments($message) * $segmentPrice;
    }

    /**
     * Check if message uses GSM 7-bit encoding
     *
     * @param string $text Message text
     * @return bool
     */
    private function isGsm7Bit(string $text): bool
    {
        $gsm7BitChars = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

        for ($i = 0; $i < mb_strlen($text); $i++) {
            if (mb_strpos($gsm7BitChars, mb_substr($text, $i, 1)) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Handle API errors
     *
     * @param RequestException $exception
     * @return never
     * @throws SignalBridgeException
     */
    private function handleError(RequestException $exception): never
    {
        $response = $exception->getResponse();
        $statusCode = $response ? $response->getStatusCode() : 500;
        $body = $response ? $response->getBody()->getContents() : '{}';
        $data = json_decode($body, true) ?? [];

        if ($this->logging) {
            error_log(sprintf(
                'SignalBridge API Error [%d]: %s',
                $statusCode,
                $data['message'] ?? $exception->getMessage()
            ));
        }

        $message = $data['message'] ?? 'Unknown error occurred';

        match ($statusCode) {
            402 => throw new InsufficientBalanceException($message, $data['data'] ?? []),
            403 => throw new NoClientException($message),
            422 => throw new ValidationException($message, $data['errors'] ?? [], $data),
            503 => throw new ServiceUnavailableException($message),
            default => throw new SignalBridgeException($message, $statusCode, $data),
        };
    }
}
