<?php

namespace Nugsoft\SignalBridge\Channels\MobileMoney;

use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Exceptions\ServiceUnavailableException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

class MobileMoneyClient extends BaseChannelClient
{
    /**
     * Ask a payer to pay (request-to-pay).
     *
     * The payer gets a prompt on their phone, so the response is not the
     * outcome: its status is 'pending'. It carries SignalBridge's reference for
     * the transaction in data.reference — store it and pass it to verify().
     *
     * @param  string  $phone  Payer's phone number, digits only (e.g. '256700000000' or '0700000000')
     * @param  float  $amount  Amount to collect (must be > 0)
     * @param  string  $currency  Currency code (default: 'UGX')
     * @param  array<string, mixed>  $options  Optional: note — shown to the payer, at most 100
     *                                         characters ('description' is accepted as an alias)
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

        if ($amount <= 0) {
            throw new ValidationException('Amount must be greater than zero');
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

        // The gateway reads 'note' — the text shown to the payer. 'description'
        // and 'callback_url' are not part of the API.
        $note = $options['note'] ?? $options['description'] ?? null;

        if (! empty($note)) {
            if (mb_strlen((string) $note) > 100) {
                throw new ValidationException('The note must be at most 100 characters');
            }

            $payload['note'] = $note;
        }

        return $this->mobileMoneyRequest('POST', 'mobile-money/initiate', ['json' => $payload]);
    }

    /**
     * Ask what became of a transaction.
     *
     * Statuses only move forward: 'pending', then possibly 'processing', then
     * 'completed' or 'failed' ('cancelled', 'rejected' and 'rolled_back' exist
     * for payouts). A 404 means no transaction of yours has that reference.
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
     * Disburse (send) money to a mobile money wallet.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws ServiceUnavailableException always, until the endpoint ships
     */
    public function disburse(string $phone, float $amount, string $currency = 'UGX', array $options = []): array
    {
        // The gateway has no /mobile-money/disburse route. The driver behind it
        // is written, but sending money out is not exposed over the API yet.
        // Calling through would 404, which the error handler reports as a bad
        // base URL and sends you looking in the wrong place.
        throw new ServiceUnavailableException(
            'Mobile money disbursement is not available yet: the SignalBridge API does not expose '
                .'/mobile-money/disburse. Use initiate() to collect payments. Contact the SignalBridge '
                .'team if you need payouts enabled.'
        );
    }
}
