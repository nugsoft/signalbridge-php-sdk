<?php

namespace Nugsoft\SignalBridge\Channels\MobileMoney;

use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Exceptions\ServiceUnavailableException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

class MobileMoneyClient extends BaseChannelClient
{
    /**
     * The smallest amount the gateway collects.
     */
    public const MINIMUM_AMOUNT = 500;

    /**
     * The most metadata keys the gateway keeps.
     */
    public const MAX_METADATA_KEYS = 20;

    /**
     * Ask a payer to pay (request-to-pay).
     *
     * The payer gets a prompt on their phone, so the response is not the
     * outcome: its status is 'pending'. It carries SignalBridge's reference for
     * the transaction in data.reference — store it and pass it to verify(), or
     * wait for the payment.completed / payment.failed webhook. Once completed,
     * the amount less Nugsoft's collection fee (data.fee) joins your mobile
     * money balance (data.net_amount).
     *
     * @param  string  $phone  Payer's phone number, digits only (e.g. '256700000000' or '0700000000')
     * @param  float  $amount  Amount to collect, at least 500
     * @param  string  $currency  Currency code (default: 'UGX'); must be the gateway wallet's
     * @param  array<string, mixed>  $options  Optional: note — shown to the payer, at most 100
     *                                         characters ('description' is accepted as an alias);
     *                                         metadata — your own data, at most 20 keys, returned
     *                                         with the status and in webhooks
     * @return array<string, mixed>
     *
     * @throws ServiceUnavailableException while the gateway has mobile money collections switched off
     * @throws ValidationException when the request is invalid, or the provider refused it; for a
     *                             refusal, getData() holds the transaction, reference included
     */
    public function initiate(string $phone, float $amount, string $currency = 'UGX', array $options = []): array
    {
        if (trim($phone) === '') {
            throw new ValidationException('Phone number is required');
        }

        if ($amount < self::MINIMUM_AMOUNT) {
            throw new ValidationException('Amount must be at least '.self::MINIMUM_AMOUNT);
        }

        // The gateway gives every transaction its own reference and ignores one
        // sent with the request, so accepting it here would let callers poll by
        // an ID that does not exist.
        if (array_key_exists('reference', $options)) {
            throw new ValidationException(
                'SignalBridge assigns the reference: read it from data.reference in the response instead of passing one.'
            );
        }

        $payload = [
            'phone' => $phone,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ];

        // The gateway reads 'note' — the text shown to the payer — and
        // 'metadata'. 'description' is an alias; 'callback_url' is not part of
        // the API.
        $note = $options['note'] ?? $options['description'] ?? null;

        if (! empty($note)) {
            if (mb_strlen((string) $note) > 100) {
                throw new ValidationException('The note must be at most 100 characters');
            }

            $payload['note'] = $note;
        }

        if (array_key_exists('metadata', $options) && $options['metadata'] !== null) {
            if (! is_array($options['metadata'])) {
                throw new ValidationException('Metadata must be an array');
            }

            if (count($options['metadata']) > self::MAX_METADATA_KEYS) {
                throw new ValidationException('Metadata can have at most '.self::MAX_METADATA_KEYS.' keys');
            }

            $payload['metadata'] = $options['metadata'];
        }

        return $this->mobileMoneyRequest('POST', 'mobile-money/initiate', ['json' => $payload]);
    }

    /**
     * Ask what became of a transaction.
     *
     * Statuses only move forward: 'pending', then 'completed', or 'failed',
     * 'cancelled' or 'rejected' — all three final. A completed payment carries
     * data.fee and data.net_amount, and data.metadata is what you sent. A 404
     * means no transaction of yours has that reference.
     *
     * @param  string  $reference  data.reference from initiate()
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        if (trim($reference) === '') {
            throw new ValidationException('Reference is required');
        }

        return $this->mobileMoneyRequest('GET', 'mobile-money/transactions/'.rawurlencode($reference));
    }

    /**
     * Your mobile money balance: what your payers paid, less fees, not yet
     * paid out to you. One entry per currency; 'available' can be paid out,
     * 'held' is set aside for payouts still going through. Empty until a
     * payment completes. Amounts are strings with two decimals.
     *
     * @return array<string, mixed>
     */
    public function balance(): array
    {
        return $this->mobileMoneyRequest('GET', 'mobile-money/balance');
    }

    /**
     * Send a request, reading the two answers the shared handler would misread.
     *
     * The gateway answers 403 while collections are switched off; the shared
     * handler would call that a token without a client. And a payment the
     * provider refused is a 422 that still carries the transaction, whose
     * reason belongs in the message.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function mobileMoneyRequest(string $method, string $path, array $options = []): array
    {
        $response = $this->perform($method, $path, $options);
        $status = $response->getStatusCode();

        if ($status < 400) {
            return $this->decode($response);
        }

        $data = json_decode((string) $response->getBody(), true);
        $data = is_array($data) ? $data : [];
        $message = (string) ($data['message'] ?? '');

        if ($status === 403 && str_contains(strtolower($message), 'not available')) {
            throw new ServiceUnavailableException($message);
        }

        $transaction = $data['data'] ?? null;

        if ($status === 422 && is_array($transaction) && ($transaction['status'] ?? null) === 'failed') {
            $reason = $transaction['status_message'] ?? null;

            throw new ValidationException(
                rtrim($message !== '' ? $message : 'The payment request was refused.', '.').($reason ? ": {$reason}" : '.'),
                [],
                $transaction
            );
        }

        $this->handleError($response);
    }

    /**
     * Send money out to a mobile money wallet — not offered over the API.
     *
     * Collected money is paid out to you by Nugsoft, to the payout account your
     * administrators save on the Balance page of the SignalBridge dashboard,
     * where they also ask for payouts.
     *
     * @deprecated Ask for payouts on the SignalBridge dashboard instead.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws ServiceUnavailableException always
     */
    public function disburse(string $phone, float $amount, string $currency = 'UGX', array $options = []): array
    {
        // There is no /mobile-money/disburse route. Calling through would 404,
        // which the error handler reports as a bad base URL and sends you
        // looking in the wrong place.
        throw new ServiceUnavailableException(
            'Payouts are not made through the API: your administrators ask for them on the Balance page '
                .'of the SignalBridge dashboard, and Nugsoft pays your mobile money balance out to your '
                .'payout account. Use initiate() to collect payments and balance() to read what you hold.'
        );
    }
}
