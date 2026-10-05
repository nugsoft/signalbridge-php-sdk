<?php

namespace Nugsoft\SignalBridge\Channels\Sms;

use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Exceptions\ValidationException;
use Nugsoft\SignalBridge\Support\MessageSegments;

class SmsClient extends BaseChannelClient
{
    /**
     * Send a single SMS message.
     *
     * @param  string  $recipient  Phone number in E.164 format (e.g. '256700000000')
     * @param  string  $message  Message text (max 1000 characters)
     * @param  array<string, mixed>  $options  Optional: metadata, is_test, sender_id, scheduled_at
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

        if (mb_strlen($message) > 1000) {
            throw new ValidationException('Message exceeds maximum length of 1000 characters');
        }

        $payload = [
            'recipient' => $recipient,
            'message' => $message,
            'metadata' => $options['metadata'] ?? [],
            'is_test' => $options['is_test'] ?? false,
        ];

        if (! empty($options['sender_id'])) {
            $payload['sender_id'] = $options['sender_id'];
        }

        if (isset($options['scheduled_at'])) {
            $payload['scheduled_at'] = $options['scheduled_at'];
        }

        return $this->request('POST', 'sms/send', ['json' => $payload]);
    }

    /**
     * Send multiple SMS messages in a single batch (up to 100 messages).
     *
     * @param  array<int, array<string, mixed>>  $messages  Each with 'recipient' and 'message'
     * @param  array<string, mixed>  $options  Optional: is_test, sender_id
     * @return array<string, mixed>
     */
    public function sendBatch(array $messages, array $options = []): array
    {
        if ($messages === []) {
            throw new ValidationException('Messages array cannot be empty');
        }

        foreach ($messages as $index => $message) {
            if (! is_array($message)) {
                throw new ValidationException("Message at index {$index} must be an array");
            }

            if (empty($message['recipient'] ?? '')) {
                throw new ValidationException("Message at index {$index} is missing 'recipient'");
            }

            if (empty($message['message'] ?? '')) {
                throw new ValidationException("Message at index {$index} is missing 'message'");
            }
        }

        $payload = [
            'messages' => $messages,
            'is_test' => $options['is_test'] ?? false,
        ];

        if (! empty($options['sender_id'])) {
            $payload['sender_id'] = $options['sender_id'];
        }

        // A batch of 100 takes longer than a single send, and this request must
        // never be retried automatically: the gateway charges on acceptance.
        return $this->request('POST', 'sms/send-batch', ['json' => $payload], timeout: 60);
    }

    /**
     * Look up one message's delivery status.
     *
     * @param  int  $messageId  The id returned by send()
     * @param  bool  $refresh  Ask the vendor live instead of returning the
     *                         stored status. Rate limited, and rarely needed —
     *                         the gateway polls vendors in the background.
     * @return array<string, mixed>
     */
    public function status(int $messageId, bool $refresh = false): array
    {
        return $this->request(
            'GET',
            "sms/messages/{$messageId}",
            $refresh ? ['query' => ['refresh' => 1]] : []
        );
    }

    /**
     * List messages with their delivery status.
     *
     * Pass 'ids' to follow up a batch — the ids come back from sendBatch(). The
     * 'summary' key in the response counts the whole filtered set, not just the
     * current page, so one call answers "how did that batch go".
     *
     * @param  array<string, mixed>  $filters  ids, status, recipient, channel,
     *                                         start_date, end_date, per_page, page
     * @return array<string, mixed>
     */
    public function messages(array $filters = []): array
    {
        if (isset($filters['ids']) && is_array($filters['ids'])) {
            $filters['ids'] = implode(',', $filters['ids']);
        }

        return $this->request('GET', 'sms/messages', ['query' => $filters]);
    }

    /**
     * Calculate the number of message segments for billing purposes.
     *
     * Delegates to MessageSegments so this and the gateway cannot disagree.
     */
    public function calculateSegments(string $message): int
    {
        return MessageSegments::count($message);
    }

    /**
     * Estimate the cost of sending a message.
     *
     * @param  float  $segmentPrice  Price per segment, from getBalance()
     */
    public function estimateCost(string $message, float $segmentPrice): float
    {
        return MessageSegments::estimateCost($message, $segmentPrice);
    }
}
