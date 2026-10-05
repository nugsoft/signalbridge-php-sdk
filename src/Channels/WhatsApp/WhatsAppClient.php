<?php

namespace Nugsoft\SignalBridge\Channels\WhatsApp;

use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

class WhatsAppClient extends BaseChannelClient
{
    /**
     * Send a plain-text WhatsApp message.
     *
     * @param  string  $recipient  Phone number in E.164 format (e.g. '256700000000')
     * @param  string  $message  Message text (max 4096 characters)
     * @param  array<string, mixed>  $options  Optional: metadata, is_test
     * @return array<string, mixed>
     */
    public function send(string $recipient, string $message, array $options = []): array
    {
        if (trim($recipient) === '') {
            throw new ValidationException('Recipient phone number is required');
        }

        if (trim($message) === '') {
            throw new ValidationException('Message content is required');
        }

        if (mb_strlen($message) > 4096) {
            throw new ValidationException('WhatsApp message exceeds maximum length of 4096 characters');
        }

        return $this->request('POST', 'whatsapp/send', [
            'json' => [
                'recipient' => $recipient,
                'message' => $message,
                'metadata' => $options['metadata'] ?? [],
                'is_test' => $options['is_test'] ?? false,
            ],
        ]);
    }

    /**
     * Send a WhatsApp template message (pre-approved Meta Business template).
     *
     * Templates must be approved in Meta Business Manager before use.
     * Components map to the template's variable placeholders.
     *
     * @param  string  $recipient  Phone number in E.164 format
     * @param  string  $templateName  The approved template name
     * @param  array<int, array<string, mixed>>  $components  Variable components
     * @param  array<string, mixed>  $options  Optional: language, metadata, is_test
     * @return array<string, mixed>
     */
    public function sendTemplate(string $recipient, string $templateName, array $components = [], array $options = []): array
    {
        if (trim($recipient) === '') {
            throw new ValidationException('Recipient phone number is required');
        }

        if (trim($templateName) === '') {
            throw new ValidationException('Template name is required');
        }

        return $this->request('POST', 'whatsapp/send', [
            'json' => [
                'recipient' => $recipient,
                'template' => $templateName,
                'components' => $components,
                'language' => $options['language'] ?? 'en_US',
                'metadata' => $options['metadata'] ?? [],
                'is_test' => $options['is_test'] ?? false,
            ],
        ]);
    }
}
