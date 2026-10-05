<?php

namespace Nugsoft\SignalBridge\Channels\Ussd;

use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

/**
 * USSD channel client.
 *
 * USSD support is planned. The method signatures and endpoint paths below
 * reflect the intended contract; the gateway's USSD routes are not active yet,
 * so calls currently come back as errors from the API rather than working.
 */
class UssdClient extends BaseChannelClient
{
    /**
     * Push a USSD notification to a subscriber.
     *
     * @param  string  $phone  Subscriber phone in E.164 format
     * @param  string  $serviceCode  USSD service code (e.g. '*123#')
     * @param  string  $message  Text to display (max 182 characters)
     * @param  array<string, mixed>  $options  Optional: reference, metadata
     * @return array<string, mixed>
     */
    public function push(string $phone, string $serviceCode, string $message, array $options = []): array
    {
        if (trim($phone) === '') {
            throw new ValidationException('Subscriber phone number is required');
        }

        if (trim($serviceCode) === '') {
            throw new ValidationException('USSD service code is required');
        }

        if (trim($message) === '') {
            throw new ValidationException('Message content is required');
        }

        if (mb_strlen($message) > 182) {
            throw new ValidationException('USSD message exceeds maximum length of 182 characters');
        }

        return $this->request('POST', 'ussd/push', [
            'json' => [
                'phone' => $phone,
                'service_code' => $serviceCode,
                'message' => $message,
                'reference' => $options['reference'] ?? null,
                'metadata' => $options['metadata'] ?? [],
            ],
        ]);
    }

    /**
     * Retrieve the details and current status of a USSD session.
     *
     * @return array<string, mixed>
     */
    public function session(string $sessionId): array
    {
        if (trim($sessionId) === '') {
            throw new ValidationException('Session ID is required');
        }

        return $this->request('GET', "ussd/sessions/{$sessionId}");
    }

    /**
     * Send a response within an active interactive USSD session.
     *
     * @return array<string, mixed>
     */
    public function respond(string $sessionId, string $message, bool $endSession = false): array
    {
        if (trim($sessionId) === '') {
            throw new ValidationException('Session ID is required');
        }

        if (trim($message) === '') {
            throw new ValidationException('Response message is required');
        }

        if (mb_strlen($message) > 182) {
            throw new ValidationException('USSD response exceeds maximum length of 182 characters');
        }

        return $this->request('POST', "ussd/sessions/{$sessionId}/respond", [
            'json' => [
                'message' => $message,
                'end_session' => $endSession,
            ],
        ]);
    }
}
