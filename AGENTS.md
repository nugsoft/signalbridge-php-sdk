# SignalBridge PHP SDK — notes for AI coding agents

Sends SMS and WhatsApp, and collects mobile money, through the SignalBridge
gateway. Messages cost real money and reach real handsets — the rules below exist
because each one has gone wrong in practice.

Inside a Laravel application use `nugsoft/signalbridge-laravel-sdk` instead; it
ships the same guidance in a form Laravel Boost picks up automatically.

## Setup

```php
use Nugsoft\SignalBridge\SignalBridgeClient;

$client = new SignalBridgeClient(token: getenv('SIGNALBRIDGE_TOKEN'));
```

The base URL defaults to the production gateway
(`SignalBridgeClient::DEFAULT_BASE_URL`). Pass `baseUrl:` for another
environment, and always include the `/api` suffix — without it every call 404s
and the SDK reports "API endpoint not found".

Recipients are international format without a `+` (`256700000000`). Do not set a sender ID:
the gateway sends everything as `NUGSOFT` and ignores `sender_id`. SMS bodies are
capped at 1000 characters, WhatsApp at 4096. `scheduled_at` must be in the future.

## Never retry a send

The gateway charges a message the moment it accepts one, and there is no
idempotency key. Wrapping a send in a retry loop, a Guzzle retry middleware or a
queue worker with automatic retries bills and delivers it twice. A timeout is the
dangerous case: it says nothing about whether the gateway processed the request.

This SDK deliberately performs no automatic retries. If a send times out, find
out what happened before sending again:

```php
$client->getMessages(['recipient' => $to, 'start_date' => date('Y-m-d')]);
```

## Never send real messages from tests

Fake the HTTP layer in every test. A test that reaches the real gateway sends a
real SMS and bills the account — including from CI.

```php
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$stack = HandlerStack::create(new MockHandler([
    new Response(200, [], '{"success":true,"data":{"message_id":1}}'),
]));

$client = new SignalBridgeClient(
    token: 'test-token',
    httpClient: new HttpClient(['handler' => $stack])
);
```

There is no test mode. `'is_test' => true` only labels a message — it is still
delivered and charged. For a manual check against the real gateway, use a number
you control.

## Never calculate cost yourself

Segment counting is not "length / 160". Unicode, emoji and the GSM escape table
all change it, and the gateway's rules are mirrored exactly by the SDK:

```php
$segments = $client->calculateSegments($message);
$cost     = $client->estimateCost($message, (float) $segmentPrice);
```

Hand-rolling this produces quotes that do not match the invoice.

## Catch the typed exceptions

A generic catch hides a billing problem as a delivery problem.

```php
use Nugsoft\SignalBridge\Exceptions\InsufficientBalanceException;      // 402
use Nugsoft\SignalBridge\Exceptions\InsufficientPermissionsException;  // 403, token ability
use Nugsoft\SignalBridge\Exceptions\NoClientException;                 // 403, no client on the account
use Nugsoft\SignalBridge\Exceptions\RateLimitedException;              // 429
use Nugsoft\SignalBridge\Exceptions\ServiceUnavailableException;       // 503
use Nugsoft\SignalBridge\Exceptions\UnauthorizedException;             // 401
use Nugsoft\SignalBridge\Exceptions\ValidationException;               // 422
```

`InsufficientBalanceException` carries `getRequiredBalance()`,
`getCurrentBalance()` and `getSegments()`. `ValidationException` carries
`getErrors()` and `getFirstError()`. `RateLimitedException` is per-client and
per-minute: back off, do not retry in a tight loop.

## Token abilities

The gateway enforces what a token may do. A token created without a selection
gets `*` and reaches everything; a narrower one raises
`InsufficientPermissionsException` elsewhere.

| Ability | Needed by |
|---------|-----------|
| `sms:send` | `sendSms()`, `sendBatch()` |
| `sms:read` | `getMessageStatus()`, `getMessages()` |
| `balance:read` | `getBalance()`, `getBalanceSummary()`, `getTransactions()` |
| `balance:request-credit` | `requestCredit()` |
| `webhooks:read` / `webhooks:write` | reading / changing webhooks |
| `export:read` | `exportMessages()`, `exportTransactions()` |

WhatsApp and mobile money abilities cannot be granted yet, so those two channels
need a full-access (`*`) token.

## Delivery is asynchronous

A send returns `queued`, not `delivered`. To learn the outcome, either register a
webhook, or poll:

```php
$client->getMessageStatus($messageId);          // stored status
$client->getMessages(['ids' => $ids]);          // whole batch at once
```

`permanently_failed` means every retry was exhausted **and the charge was
refunded** — treat it as not-sent, not as a silent cost.

Do not call `getMessageStatus($id, refresh: true)` in a loop. It asks the vendor
live and is rate limited; the plain call is normally current within minutes.

## Verify every webhook

Webhooks are signed over the raw body. Use the helper — a hand-rolled check
usually hashes a re-encoded payload or compares with `===`, and both fail
silently:

```php
use Nugsoft\SignalBridge\Support\WebhookSignature;

$payload = file_get_contents('php://input');

if (! WebhookSignature::verify($payload, WebhookSignature::signatureFromServer(), $secret)) {
    http_response_code(403);
    exit;
}
```

Valid events are `message.sent`, `message.delivered`, `message.failed`,
`message.permanently_failed` and `*`. The signing secret is returned once, when
the webhook is created.

## Bulk sending

`sendBatch()` takes up to 100 messages per call and returns a `message_id` per
recipient — keep them to reconcile later with `getMessages(['ids' => ...])`. Do
not loop `sendSms()` for bulk.

A batch stops at the first insufficient balance, so a partial batch is normal:
read `successful`, `failed` and the per-message results rather than assuming all
or nothing.

## Balances

`getBalance()` returns the resource under `data`, and reading one never creates
one — a currency with nothing stored reads as zero. Currency must be a
three-letter code.

```php
$balance = $client->getBalance('UGX')['data'];
$balance['available_balance'];   // balance + credit limit
$balance['segment_price'];       // pass to estimateCost()
```

Clients cannot credit themselves: `requestCredit()` only notifies the
administrators.

## WhatsApp and mobile money

```php
$client->whatsapp()->send('256700000000', 'Your order has shipped');
$client->mobileMoney()->initiate('256700000000', 15000, 'UGX', [
    'reference' => 'INV-1',
    'note' => 'Invoice payment',   // shown to the payer
]);
```

Poll `mobileMoney()->verify($transactionId)` for the result.

Avoid `whatsapp()->sendTemplate()` for now: the gateway does not yet deliver
template sends as templates, so the message goes out empty and ends up
permanently failed. Use `whatsapp()->send()` within the 24-hour window.

## Not available

`mobileMoney()->disburse()` throws `ServiceUnavailableException`; the gateway
exposes no payout endpoint. `ussd()` is likewise unreleased. Collecting payments
with `mobileMoney()->initiate()` works normally.

## Do not log message bodies or recipient numbers

The SDK deliberately logs only the status, message and error code. Keep it that
way in application code.
