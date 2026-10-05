<?php

namespace Nugsoft\SignalBridge\Channels\MobileMoney;

use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Exceptions\ServiceUnavailableException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

class MobileMoneyClient extends BaseChannelClient
{
    /**
     * Initiate a mobile money collection (request-to-pay).
     *
     * The response returns a transaction ID and status 'pending'. The final
     * status is delivered by webhook or polled with verify().
     *
     * @param  string  $phone  Payer's phone number in E.164 format
     * @param  float  $amount  Amount to collect (must be > 0)
     * @param  string  $currency  Currency code (default: 'UGX')
     * @param  array<string, mixed>  $options  Optional: reference, note
     * @return array<string, mixed>
     */
    public function initiate(string $phone, float $amount, string $currency = 'UGX', array $options = []): array
    {
        if (trim($phone) === '') {
            throw new ValidationException('Phone number is required');
        }

        if ($amount <= 0) {
            throw new ValidationException('Amount must be greater than zero');
        }

        $payload = [
            'phone' => $phone,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ];

        if (! empty($options['reference'])) {
            $payload['reference'] = $options['reference'];
        }

        // The gateway reads 'note' — the text shown to the payer. 'description'
        // and 'callback_url' are not part of the API and were silently dropped.
        $note = $options['note'] ?? $options['description'] ?? null;

        if (! empty($note)) {
            $payload['note'] = $note;
        }

        return $this->request('POST', 'mobile-money/initiate', ['json' => $payload]);
    }

    /**
     * Verify (poll) the status of a mobile money transaction.
     *
     * @param  string  $transactionId  UUID returned from initiate()
     * @return array<string, mixed>
     */
    public function verify(string $transactionId): array
    {
        if (trim($transactionId) === '') {
            throw new ValidationException('Transaction ID is required');
        }

        return $this->request('GET', "mobile-money/transactions/{$transactionId}");
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
