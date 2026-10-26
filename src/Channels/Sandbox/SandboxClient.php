<?php

namespace Nugsoft\SignalBridge\Channels\Sandbox;

use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Exceptions\SignalBridgeException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

/**
 * The sandbox's tools: what, on live, a customer or a provider would make
 * happen. They exist only in the sandbox — point the client at
 * SignalBridgeClient::SANDBOX_URL (or use SignalBridgeClient::sandbox()).
 */
class SandboxClient extends BaseChannelClient
{
    public const STATUSES = ['delivered', 'failed', 'read'];

    /**
     * The magic numbers and what each does, and the template review rule.
     *
     * @return array<string, mixed>
     */
    public function magicNumbers(): array
    {
        return $this->sandboxRequest('GET', 'sandbox');
    }

    /**
     * Simulate a customer's WhatsApp message to you. It arrives as a real one
     * does — a message.received or flow.completed webhook — linked to one of
     * your messages to that number (the one given, or your latest).
     *
     * @param  array<string, mixed>  $options  type (text, button, interactive, flow, image, document, audio, video,
     *                                         sticker, location), text, reply_to_message_id, reply_id (interactive),
     *                                         answers (flow), latitude, longitude, name
     * @return array<string, mixed>
     */
    public function customerMessage(string $from, array $options = []): array
    {
        if (trim($from) === '') {
            throw new ValidationException('The customer\'s number is required');
        }

        return $this->sandboxRequest('POST', 'sandbox/whatsapp/customer-message', ['json' => ['from' => $from] + $options]);
    }

    /**
     * Move one of your messages to an outcome now: delivered, failed, or — on
     * WhatsApp — read. Its webhooks fire as they would on live.
     *
     * @return array<string, mixed>
     */
    public function setMessageStatus(int $messageId, string $status): array
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new ValidationException('The status must be one of: '.implode(', ', self::STATUSES));
        }

        return $this->sandboxRequest('POST', "sandbox/messages/{$messageId}/status", ['json' => ['status' => $status]]);
    }

    /**
     * Free test credit, up to the sandbox's ceiling.
     *
     * @return array<string, mixed>
     */
    public function addCredit(): array
    {
        return $this->sandboxRequest('POST', 'sandbox/credit');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function sandboxRequest(string $method, string $path, array $options = []): array
    {
        try {
            return $this->request($method, $path, $options);
        } catch (SignalBridgeException $e) {
            // A sandbox answers "Message not found." for a message that is not
            // yours; anything else at a sandbox path means this is not one.
            if ($e->getCode() === 404 && $e->getMessage() !== 'Message not found.') {
                throw new SignalBridgeException('The sandbox tools exist only in the sandbox. Point the client at SignalBridgeClient::SANDBOX_URL.', 404, $e->getData());
            }

            throw $e;
        }
    }
}
