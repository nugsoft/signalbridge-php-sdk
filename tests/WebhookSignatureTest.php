<?php

namespace Nugsoft\SignalBridge\Tests;

use Nugsoft\SignalBridge\Support\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'webhook-signing-secret';

    public function test_a_correctly_signed_payload_is_accepted(): void
    {
        $payload = '{"event":"message.delivered","message_id":42}';

        $this->assertTrue(WebhookSignature::verify(
            $payload,
            WebhookSignature::sign($payload, self::SECRET),
            self::SECRET
        ));
    }

    public function test_a_bare_hex_digest_is_accepted(): void
    {
        $payload = '{"event":"message.sent"}';
        $digest = hash_hmac('sha256', $payload, self::SECRET);

        $this->assertTrue(WebhookSignature::verify($payload, $digest, self::SECRET));
    }

    public function test_a_tampered_payload_fails(): void
    {
        $payload = '{"event":"message.delivered","message_id":42}';
        $signature = WebhookSignature::sign($payload, self::SECRET);

        $this->assertFalse(WebhookSignature::verify(
            '{"event":"message.delivered","message_id":43}',
            $signature,
            self::SECRET
        ));
    }

    public function test_the_wrong_secret_fails(): void
    {
        $payload = '{"event":"message.sent"}';

        $this->assertFalse(WebhookSignature::verify(
            $payload,
            WebhookSignature::sign($payload, 'another-secret'),
            self::SECRET
        ));
    }

    public function test_a_missing_signature_or_secret_fails(): void
    {
        $payload = '{"event":"message.sent"}';

        $this->assertFalse(WebhookSignature::verify($payload, '', self::SECRET));
        $this->assertFalse(WebhookSignature::verify($payload, WebhookSignature::sign($payload, self::SECRET), ''));
    }

    public function test_the_signature_is_read_from_the_server_headers(): void
    {
        $_SERVER['HTTP_X_SIGNALBRIDGE_SIGNATURE'] = 'sha256=abc';

        $this->assertSame('sha256=abc', WebhookSignature::signatureFromServer());

        unset($_SERVER['HTTP_X_SIGNALBRIDGE_SIGNATURE']);

        $this->assertSame('', WebhookSignature::signatureFromServer());
    }
}
