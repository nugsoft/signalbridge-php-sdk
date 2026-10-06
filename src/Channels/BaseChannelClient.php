<?php

namespace Nugsoft\SignalBridge\Channels;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Nugsoft\SignalBridge\Exceptions\InsufficientBalanceException;
use Nugsoft\SignalBridge\Exceptions\InsufficientPermissionsException;
use Nugsoft\SignalBridge\Exceptions\NoClientException;
use Nugsoft\SignalBridge\Exceptions\RateLimitedException;
use Nugsoft\SignalBridge\Exceptions\ServiceUnavailableException;
use Nugsoft\SignalBridge\Exceptions\SignalBridgeException;
use Nugsoft\SignalBridge\Exceptions\UnauthorizedException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;

abstract class BaseChannelClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $token,
        protected int $timeout,
        protected ClientInterface $httpClient,
        protected bool $logging = true
    ) {}

    /**
     * Send a request and decode the response.
     *
     * Every URL is built in full rather than leaning on Guzzle's base_uri.
     * A base_uri of ".../api" combined with a path of "/sms/send" resolves to
     * the HOST ROOT per RFC 3986 — "https://host/sms/send" — which is how every
     * call this SDK made used to miss the /api prefix and 404.
     *
     * @param  array<string, mixed>  $options  Guzzle options: json, query
     * @return array<string, mixed>
     *
     * @throws SignalBridgeException
     */
    protected function request(string $method, string $path, array $options = [], ?int $timeout = null): array
    {
        $response = $this->perform($method, $path, $options, $timeout);

        if ($response->getStatusCode() >= 400) {
            $this->handleError($response);
        }

        return $this->decode($response);
    }

    /**
     * Send a request and return the raw body, for CSV exports.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws SignalBridgeException
     */
    protected function requestBody(string $method, string $path, array $options = [], ?int $timeout = null): string
    {
        $response = $this->perform($method, $path, $options, $timeout);

        if ($response->getStatusCode() >= 400) {
            $this->handleError($response);
        }

        return (string) $response->getBody();
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws SignalBridgeException
     */
    protected function perform(string $method, string $path, array $options = [], ?int $timeout = null): ResponseInterface
    {
        $options = array_merge([
            'headers' => [
                'Authorization' => "Bearer {$this->token}",
                'Accept' => 'application/json',
            ],
            'timeout' => $timeout ?? $this->timeout,
            // Status codes are inspected here rather than thrown by Guzzle, so
            // an error response and a dead connection stay distinguishable.
            'http_errors' => false,
        ], $options);

        try {
            return $this->httpClient->request($method, $this->url($path), $options);
        } catch (GuzzleException $e) {
            $this->log('SignalBridge request failed: '.$e->getMessage());

            throw new SignalBridgeException('Could not reach SignalBridge: '.$e->getMessage(), 0, [], $e);
        }
    }

    protected function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SignalBridgeException
     */
    protected function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            throw new SignalBridgeException('Unexpected API response format');
        }

        return $data;
    }

    /**
     * Map a failed response onto a typed exception, as the Laravel SDK does.
     *
     * @throws SignalBridgeException
     */
    protected function handleError(ResponseInterface $response): never
    {
        $status = $response->getStatusCode();

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            $data = [];
        }

        $message = $data['message'] ?? 'Unknown error occurred';

        $this->log(sprintf('SignalBridge API Error [%d]: %s', $status, $message));

        match ($status) {
            401 => throw new UnauthorizedException($message),
            402 => throw new InsufficientBalanceException($message, $data['data'] ?? []),
            403 => str_contains(strtolower($message), 'permission') || str_contains(strtolower($message), 'role')
                ? throw new InsufficientPermissionsException($message)
                : throw new NoClientException($message),
            // A JSON 404 is SignalBridge answering — usually a message or
            // webhook that does not exist. Only a 404 that did not come from
            // the gateway (no JSON message) points at a wrong base URL.
            404 => throw new SignalBridgeException(
                isset($data['message']) ? $message : 'API endpoint not found. Verify the base URL passed to the client.',
                $status,
                $data
            ),
            422 => throw new ValidationException($message, $data['errors'] ?? [], $data),
            429 => throw new RateLimitedException($message),
            500 => throw new SignalBridgeException('SignalBridge server error. Please try again later.', $status, $data),
            503 => throw new ServiceUnavailableException($message),
            default => throw new SignalBridgeException($message, $status, $data),
        };
    }

    protected function log(string $message): void
    {
        if ($this->logging) {
            error_log($message);
        }
    }
}
