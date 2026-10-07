# SignalBridge PHP SDK

[![Latest Version](https://img.shields.io/packagist/v/nugsoft/signalbridge-php-sdk.svg?style=flat-square)](https://packagist.org/packages/nugsoft/signalbridge-php-sdk)
[![Total Downloads](https://img.shields.io/packagist/dt/nugsoft/signalbridge-php-sdk.svg?style=flat-square)](https://packagist.org/packages/nugsoft/signalbridge-php-sdk)

Vanilla PHP SDK for SignalBridge SMS Gateway - Send SMS messages through multiple vendors (SpeedaMobile, Africa's Talking) with a unified API. Works with any PHP project (no framework required).

## Features

- **Framework Agnostic** - Works with any PHP project
- **Simple API** - Clean, intuitive interface
- **Batch SMS** - Send up to 100 messages in a single request
- **Balance Management** - Check balance, view transactions, get usage reports
- **Scheduled Messages** - Schedule SMS for future delivery
- **Segment Calculation** - Automatic cost estimation (GSM 7-bit vs Unicode)
- **Custom Exceptions** - Typed exceptions for better error handling
- **Every Channel** - SMS, WhatsApp and Mobile Money, with USSD stubbed for when it ships
- **Delivery Status** - Read a message's status, or follow up a whole batch
- **Webhooks** - Manage endpoints and verify inbound signatures
- **Composer Ready** - Easy installation via Composer

Inside a Laravel application use
[nugsoft/signalbridge-laravel-sdk](https://github.com/nugsoft/signalbridge-laravel-sdk)
instead: same channels, same exceptions, same segment maths, plus config and a
facade. This package is for everything else.

## Using this SDK with an AI coding agent

The package ships agent guidance at [`AGENTS.md`](AGENTS.md), covering the things
that are easy to get expensively wrong — retrying a send that was already
charged, sending real messages from a test suite, hand-rolling segment costs,
skipping webhook signature verification.

Nothing discovers it on its own, because after installation it lives under
`vendor/`. Wire it up once:

**Claude Code** — add one line to your project's `CLAUDE.md`. The path stays
inside the working directory, so it needs no approval:

```md
@vendor/nugsoft/signalbridge-php-sdk/AGENTS.md
```

Claude Code also reads your project's own `AGENTS.md` when there is no
`CLAUDE.md`, so the same line works there.

**Other agents** (Cursor, Copilot, Codex, Windsurf) — copy the contents into
whatever instructions file the tool reads:

```bash
cat vendor/nugsoft/signalbridge-php-sdk/AGENTS.md >> AGENTS.md
```

Re-copy it when you upgrade the package, or prefer the import above so it stays
current by itself.

## Requirements

- PHP 8.1 or higher
- Guzzle HTTP 7.0+
- Composer

## Installation

Install via Composer:

```bash
composer require nugsoft/signalbridge-php-sdk
```

## Quick Start

```php
<?php

require_once 'vendor/autoload.php';

use Nugsoft\SignalBridge\SignalBridgeClient;

// Initialize the client
$client = new SignalBridgeClient(
    token: 'your_api_token_here'
);

// Send SMS
$result = $client->sendSms(
    recipient: '256700000000',
    message: 'Hello from PHP!',
    options: [
        'metadata' => ['user_id' => 123]
    ]
);

echo "Message sent! ID: {$result['data']['message_id']}\n";
```

## Getting Your API Token

Contact your system administrator or generate a token via cURL:

```bash
curl -X POST https://signal-bridge.nugsoftapps.net/api/tokens \
  -H "Content-Type: application/json" \
  -d '{
    "email": "your-product@nugsoft.com",
    "password": "your-password",
    "expires_in_days": 365
  }'
```

## Usage Examples

### 1. Send Simple SMS

```php
<?php

use Nugsoft\SignalBridge\SignalBridgeClient;
use Nugsoft\SignalBridge\Exceptions\InsufficientBalanceException;

$client = new SignalBridgeClient('your_token_here');

try {
    $result = $client->sendSms(
        recipient: '256700000000',
        message: 'Your verification code is 123456',
        options: [
            'metadata' => [
                'action' => 'otp_verification',
                'user_id' => 789
            ]
        ]
    );

    echo "Message sent successfully!\n";
    echo "Cost: {$result['data']['cost']} UGX\n";

} catch (InsufficientBalanceException $e) {
    echo "Insufficient balance\n";
    echo "Required: {$e->getRequiredBalance()}\n";
    echo "Available: {$e->getCurrentBalance()}\n";
}
```

### 2. Send Batch SMS

```php
<?php

$students = [
    ['phone' => '256700000000', 'name' => 'John', 'score' => 85],
    ['phone' => '256700000001', 'name' => 'Jane', 'score' => 92],
    ['phone' => '256700000002', 'name' => 'Bob', 'score' => 78],
];

// Build batch messages
$messages = [];
foreach ($students as $student) {
    $messages[] = [
        'recipient' => $student['phone'],
        'message' => "Hi {$student['name']}, your score: {$student['score']}/100",
        'metadata' => [
            'student_name' => $student['name'],
            'type' => 'exam_results'
        ]
    ];
}

$result = $client->sendBatch($messages);

echo "Sent: {$result['data']['successful']}/{$result['data']['total']}\n";
```

### 3. Schedule Future SMS

```php
<?php

// Schedule for tomorrow at 9 AM
$tomorrow9am = (new DateTime('tomorrow 9:00:00'))->format('c');

$result = $client->sendSms(
    recipient: '256700000000',
    message: 'Reminder: Your appointment is tomorrow at 10 AM',
    options: [
        'scheduled_at' => $tomorrow9am,
        'metadata' => ['type' => 'appointment_reminder']
    ]
);

echo "Message scheduled for: {$tomorrow9am}\n";
```

### 4. Check Balance Before Sending

```php
<?php

// Get current balance — the resource comes back under 'data'
$balance = $client->getBalance('UGX')['data'];

echo "Balance: {$balance['balance']} UGX\n";
echo "Available: {$balance['available_balance']} UGX\n";
echo "Segment price: {$balance['segment_price']} UGX\n";

// Calculate cost before sending. These segment figures match the gateway's own,
// so the estimate is what the invoice will say.
$message = 'Your message here';
$segments = $client->calculateSegments($message);
$estimatedCost = $client->estimateCost($message, (float) $balance['segment_price']);

if ($balance['available_balance'] < $estimatedCost) {
    echo "Insufficient balance for this message\n";
} else {
    $result = $client->sendSms('256700000000', $message);
    echo "Message sent!\n";
}
```

### 5. Get Transaction History

```php
<?php

$transactions = $client->getTransactions([
    'type' => 'debit',
    'start_date' => '2025-11-01',
    'end_date' => '2025-11-30',
    'per_page' => 50
]);

$totalCost = 0;
foreach ($transactions['data'] as $tx) {
    $totalCost += $tx['amount'];
    echo "{$tx['created_at']}: -{$tx['amount']} {$tx['currency']}\n";
}

echo "\nTotal spent: {$totalCost} UGX\n";
```

### 6. Send OTP with Session Storage

```php
<?php

session_start();

$phone = $_POST['phone'] ?? '';
$otp = rand(100000, 999999);

// Store OTP in session
$_SESSION['otp'][$phone] = [
    'code' => $otp,
    'expires' => time() + 300 // 5 minutes
];

try {
    $result = $client->sendSms(
        recipient: $phone,
        message: "Your verification code is {$otp}. Valid for 5 minutes.",
        options: [
            'metadata' => [
                'action' => 'otp_verification',
                'ip_address' => $_SERVER['REMOTE_ADDR']
            ]
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'OTP sent successfully',
        'expires_in' => 300
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
```

## API Reference

### Base URL

The client defaults to the production gateway,
`https://signal-bridge.nugsoftapps.net/api`, exposed as
`SignalBridgeClient::DEFAULT_BASE_URL`. Pass `baseUrl:` to point somewhere else.

Always include the `/api` suffix. The URL is used as given, so a missing suffix
produces 404s that the SDK reports as "API endpoint not found".

### Token abilities

A token carries abilities, and the gateway enforces them on every route. A token
created without a selection gets `*` and can do everything. A narrower token
gets a `403` with `required_ability` naming what was missing, which this SDK
raises as `InsufficientPermissionsException`:

| Ability | Allows |
|---------|--------|
| `sms:send` | `sendSms()`, `sendBatch()` |
| `sms:read` | `getMessageStatus()`, `getMessages()` |
| `balance:read` | `getBalance()`, `getBalanceSummary()`, `getTransactions()` |
| `balance:request-credit` | `requestCredit()` |
| `webhooks:read` | `listWebhooks()`, `getWebhook()` |
| `webhooks:write` | `createWebhook()`, `updateWebhook()`, `deleteWebhook()`, `regenerateWebhookSecret()` |
| `export:read` | `exportMessages()`, `exportTransactions()` |
| `whatsapp:send` | `whatsapp()->sendTemplate()`, `send()`, `sendFlow()` |
| `whatsapp:templates` | `whatsapp()->listTemplates()`, `getTemplate()`, `createTemplate()`, `deleteTemplate()` |
| `whatsapp:flows` | `whatsapp()->listFlows()`, `createFlow()`, `updateFlow()`, `publishFlow()`, … |
| `whatsapp:read` | `whatsapp()->received()`, `getReceived()`, `downloadMedia()` |

> `mobile-money:send` and `mobile-money:read` are not issuable at the moment: the mobile money channel is unreleased, so reaching it needs a full-access (`*`) token.

### Channels

```php
$client->sms();          // SmsClient — send, sendBatch, status, messages
$client->whatsapp();     // WhatsAppClient — templates, free text, Flows, received messages
$client->mobileMoney();  // MobileMoneyClient — initiate, verify
$client->ussd();         // UssdClient — planned, not live on the gateway yet
```

The flat methods (`sendSms()`, `sendBatch()`, …) are kept and proxy to the
channels, so existing code keeps working.

### WhatsApp

WhatsApp only lets a business **start** a conversation with a **template** it has
approved: submit one, wait for approval, then send it as often as you like. Free text
and Flows are delivered only within **24 hours of the person's last message to you**.
SignalBridge holds every WhatsApp credential and does all of WhatsApp's encryption.

```php
$whatsapp = $client->whatsapp();

// Once: submit a template. A template.approved webhook arrives when WhatsApp approves it.
$whatsapp->createTemplate([
    'name' => 'fee_reminder',
    'category' => 'utility',   // utility | marketing | authentication
    'body' => 'Hello {{1}}, your fee balance is {{2}}. Please pay by Friday.',
    'examples' => ['John', 'UGX 50,000'],
]);

// Then send it — the variables as a plain list
$whatsapp->sendTemplate('256700000000', 'fee_reminder', ['John', 'UGX 50,000']);

// A template that starts with a document or image takes the file as a link
$whatsapp->sendTemplate('256700000000', 'weekly_report', ['Kampala branch'], [
    'header' => ['type' => 'document', 'url' => 'https://files.example.com/report.pdf', 'filename' => 'report.pdf'],
]);

// Within 24 hours of their last message: free text, or a Flow
$whatsapp->send('256700000000', 'Thanks — we have received your payment.');
$whatsapp->sendFlow('256700000000', 'spa_booking', 'Book your next session', 'Book now');

// What customers sent you, and their files
$whatsapp->received(['since' => '2026-10-07T00:00:00+03:00']);
$bytes = $whatsapp->downloadMedia($receivedMessageId);
```

**Flows** are forms customers fill in inside WhatsApp. Create one from the JSON
WhatsApp's Flow Builder exports with `createFlow(['name' => …, 'categories' => […],
'flow_json' => …, 'endpoint_url' => …])`, then `publishFlow($id)`. Answers arrive as
a `flow.completed` webhook. If the Flow fetches live data, SignalBridge decrypts
WhatsApp's calls and posts them to your `endpoint_url` as plain JSON, signed with the
`endpoint_secret` returned when you created it. Verify with
`WebhookSignature::verifyCurrentRequest($secret)` and reply with the next screen as
JSON, e.g. `{"screen": "SLOTS", "data": {"slots": ["10:00", "11:00"]}}`, within a few
seconds.

WhatsApp webhook events: `message.sent`, `message.delivered`, `message.read`,
`message.failed`, `message.received`, `flow.completed`, `template.approved`,
`template.rejected`, `template.paused`, `template.disabled`. Template and Flow events
are not in the default subscription.

### Delivery status

```php
// One message. Pass refresh: true to ask the vendor live — rate limited, and
// rarely needed, because the gateway polls vendors in the background.
$status = $client->getMessageStatus($messageId);
echo $status['data']['status'];      // queued, sent, delivered, failed …

// A whole batch, by the ids sendBatch() returned. 'summary' counts the entire
// filtered set rather than the current page.
$messages = $client->getMessages(['ids' => [11, 12, 13]]);
print_r($messages['summary']['by_status']);
```

### Webhooks

```php
$created = $client->createWebhook('https://your-app.test/webhooks/sms', ['message.delivered']);
$secret  = $created['secret'];   // shown once, at creation

// In your webhook endpoint — verify against the RAW body, never a re-encoded copy.
use Nugsoft\SignalBridge\Support\WebhookSignature;

$payload   = file_get_contents('php://input');
$signature = WebhookSignature::signatureFromServer();

if (! WebhookSignature::verify($payload, $signature, $secret)) {
    http_response_code(403);
    exit;
}
```

### Exports

```php
file_put_contents('messages.csv', $client->exportMessages(['start_date' => '2026-01-01']));
file_put_contents('transactions.csv', $client->exportTransactions(['type' => 'debit']));
```

### Constructor

```php
$client = new SignalBridgeClient(
    token: 'your_token'
);
```

### Send SMS

```php
$result = $client->sendSms(
    recipient: '256700000000',           // Required: Phone number
    message: 'Your message here',        // Required: Message content (max 1000 chars)
    options: [
        'metadata' => [],                 // Optional: Custom data
        'is_test' => false,               // Optional: a label only — still sent and charged
        'scheduled_at' => '2025-12-01...' // Optional: ISO 8601 datetime
    ]
);
```

**Returns:**
```php
[
    'success' => true,
    'message' => 'SMS queued successfully',
    'data' => [
        'message_id' => 1234,
        'status' => 'queued',
        'vendor' => 'SpeedaMobile',
        'segments' => 1,
        'cost' => 75.00,
        'balance_after' => 9925.00
    ]
]
```

### Send Batch SMS

```php
$result = $client->sendBatch(
    messages: [
        [
            'recipient' => '256700000000',
            'message' => 'Message 1',
            'metadata' => ['order_id' => 123]
        ],
        [
            'recipient' => '256700000001',
            'message' => 'Message 2'
        ]
    ],
    options: [
        'is_test' => false
    ]
);
```

### Request Credit

```php
// Asks the administrators for a top-up. This never moves money by itself.
$client->requestCredit(10000, 'UGX', 'Monthly top-up');
```

### Get Balance

```php
$balance = $client->getBalance('UGX');
// Returns: ['success' => true, 'data' => ['currency' => 'UGX', 'balance' => 100.00,
//           'available_balance' => 100.00, 'segment_price' => 75.00, ...]]
// Reading a balance never creates one: a currency with nothing stored reads as zero.
```

### Get Balance Summary

```php
$summary = $client->getBalanceSummary();
// Returns detailed summary with recent activity and 30-day usage
```

### Get Transactions

```php
$transactions = $client->getTransactions([
    'per_page' => 15,
    'page' => 1,
    'type' => 'debit',              // credit, debit
    'start_date' => '2025-11-01',
    'end_date' => '2025-11-30'
]);
```

### Calculate Segments

```php
$segments = $client->calculateSegments('Your message here');
// Returns: 1 (for messages up to 160 GSM chars or 70 Unicode chars)
```

### Estimate Cost

```php
$cost = $client->estimateCost('Your message', 75.00);
// Returns: 75.00 (segments * price)
```

### Token Management

```php
// Get all tokens
$tokens = $client->getTokens();

// Revoke current token
$result = $client->revokeCurrentToken();
```

## Exception Handling

The SDK provides typed exceptions for better error handling:

```php
use Nugsoft\SignalBridge\Exceptions\InsufficientBalanceException;
use Nugsoft\SignalBridge\Exceptions\ValidationException;
use Nugsoft\SignalBridge\Exceptions\NoClientException;
use Nugsoft\SignalBridge\Exceptions\ServiceUnavailableException;
use Nugsoft\SignalBridge\Exceptions\SignalBridgeException;

try {
    $client->sendSms('256700000000', 'Test message');

} catch (InsufficientBalanceException $e) {
    $required = $e->getRequiredBalance();
    $current = $e->getCurrentBalance();
    $segments = $e->getSegments();
    echo "Need {$required} UGX, have {$current} UGX\n";

} catch (ValidationException $e) {
    $errors = $e->getErrors();
    $firstError = $e->getFirstError();
    print_r($errors);

} catch (NoClientException $e) {
    echo "No client associated with account\n";

} catch (ServiceUnavailableException $e) {
    echo "SMS service unavailable\n";

} catch (SignalBridgeException $e) {
    $data = $e->getData();
    echo "Error: {$e->getMessage()}\n";
}
```

## SMS Segments & Pricing

Messages are charged based on segments:

**GSM 7-bit Encoding** (standard characters):
- Single segment: Up to 160 characters
- Multi-part: 153 characters per segment

**Unicode Encoding** (emojis, Arabic, Chinese, etc.):
- Single segment: Up to 70 characters
- Multi-part: 67 characters per segment

The SDK automatically detects encoding and calculates segments.

**Examples:**
- `"Hello World"` (11 chars) = 1 segment (GSM)
- `"Hello 😊"` (7 chars) = 1 segment (Unicode)
- 161-character text = 2 segments (GSM)
- 71-character text with emoji = 2 segments (Unicode)

## Examples

Check the `/examples` directory for complete working examples:

- `basic_usage.php` - Simple SMS sending and balance checking
- `batch_sending.php` - Batch SMS to multiple recipients
- `scheduled_messages.php` - Schedule messages for future delivery
- `transaction_history.php` - View and analyze transaction history

## Testing

```bash
composer install
composer test
```

Every test fakes the HTTP layer with Guzzle's `MockHandler`. A test that reached
the real gateway would send a real SMS and bill the account, so none may.

`tests/MessageSegmentsTest.php` pins the segment maths against the gateway's own
cases, including the GSM alphabet itself. If the gateway's
`BalanceService::calculateSegments()` changes, that test should fail here before
a client is quoted a price that does not match their invoice.

## License

The MIT License (MIT). This package is proprietary to Nugsoft.

---

**Made with ❤️ by Nugsoft**
