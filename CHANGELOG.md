# Changelog

All notable changes to `signalbridge-php-sdk` will be documented in this file.

## [Unreleased]

### Added
- **WhatsApp, fully.** `sendTemplate()` now takes the template's variables as a
  plain list, with `header` for templates that start with a document, image or
  video, and `flow` data for a template's Flow button. New: `sendFlow()`;
  `listTemplates()`, `getTemplate()`, `createTemplate()`, `deleteTemplate()`;
  `listFlows()`, `getFlow()`, `createFlow()`, `updateFlow()`, `publishFlow()`,
  `regenerateFlowSecret()`, `deleteFlow()`; `received()`, `getReceived()` and
  `downloadMedia()` for what customers send you. SignalBridge holds every
  WhatsApp credential and does WhatsApp's Flow encryption, so none of this
  needs Meta access. Flow data calls forwarded to your endpoint verify with the
  existing webhook signature helper.

### Changed
- `sendTemplate()`'s third argument is now the variables
  (`['John', 'UGX 50,000']`), not Meta's `components` structure — the gateway
  builds that. Passing the old structure fails with an explanation. WhatsApp
  was unreleased on the gateway, so no working integration relied on it.
- **`sender_id` no longer has any effect.** The gateway now sends every message
  as `NUGSOFT`, the only sender ID registered with its vendors, and ignores any
  `sender_id` it is given. The option is still accepted so existing calls keep
  working. A client-chosen sender ID the vendor had withdrawn left every message
  refused with "sender id not assigned".
- Documented that `is_test` is only a label: the message is still delivered and
  charged. It was described as a test mode.

### Fixed
- A 404 for something that does not exist — `status()` on an unknown message,
  a deleted webhook — reported "API endpoint not found" and pointed at the base
  URL. The gateway's own message is now passed through; the base-URL hint is
  kept for a 404 that did not come from the gateway.

## [2.0.0] - 2026-10-02

Brought in line with `nugsoft/signalbridge-laravel-sdk`: same channel
accessors, same typed exceptions, and the same segment maths as the gateway.

### Fixed

- **Every request went to the wrong URL.** The client set Guzzle's `base_uri` to
  `https://host/api` and then requested paths such as `/sms/send`. Per RFC 3986
  a path beginning with `/` resolves against the host root, so every call lost
  the `/api` prefix and 404'd. URLs are now built in full and the SDK no longer
  uses `base_uri`.
- **Segment counting disagreed with the gateway**, so `estimateCost()` quoted
  prices that invoices did not match. Two causes, both now covered by tests:
  the GSM alphabet was single-quoted, making `\n` and `\r` literal backslash
  sequences so any multi-line message was billed as Unicode; and the escape-table
  characters (`^{}\[]~|€`) were missing, so a message containing a `{placeholder}`
  was treated as Unicode too. Emoji now count as two UTF-16 units, as the
  gateway counts them.
- **`composer.json` claimed PHP 7.4 support** while the code used `match` and
  `never`, so it could not even be parsed below 8.1. The constraint is now `^8.1`.
- The default base URL now points at the production gateway
  (`https://signal-bridge.nugsoftapps.net/api`). It previously pointed at a
  staging host that was spelled differently in each repository.
- `mobileMoney()->initiate()` sends `note`, which is the field the gateway reads.
  `description` is accepted as an alias for it; `callback_url`, which the gateway
  has no support for, is no longer sent and silently dropped.
- A failed response no longer leaks as a raw Guzzle exception: connection
  failures are reported as `SignalBridgeException` ("Could not reach
  SignalBridge"), separately from HTTP error statuses.

### Added

- Channel accessors: `sms()`, `whatsapp()`, `mobileMoney()`, `ussd()`. The flat
  methods (`sendSms()`, `sendBatch()`, …) remain and proxy to them.
- Delivery status: `getMessageStatus()` and `getMessages()`, including batch
  follow-up by ids.
- Webhook management: `listWebhooks()`, `createWebhook()`, `getWebhook()`,
  `updateWebhook()`, `deleteWebhook()`, `regenerateWebhookSecret()`.
- `Support\WebhookSignature` for verifying inbound webhooks: constant-time
  comparison against the raw body, with `verifyCurrentRequest()` for plain PHP
  endpoints.
- CSV exports: `exportMessages()` and `exportTransactions()`.
- `requestCredit()` for asking administrators for a top-up.
- WhatsApp templates via `whatsapp()->sendTemplate()`.
- Exceptions matching the Laravel SDK: `UnauthorizedException` (401),
  `RateLimitedException` (429) and `InsufficientPermissionsException` (403 when
  a token lacks an ability).
- `Support\MessageSegments`, shared by the client and the SMS channel.
- A test suite (45 tests) with the HTTP layer faked throughout.
- A `ClientInterface` may be injected to control proxies, TLS or testing.

### Changed

- **Token abilities are now enforced by the gateway.** A token scoped to, say,
  `sms:send` is refused elsewhere with a 403, raised here as
  `InsufficientPermissionsException`. Tokens created with `*` are unaffected.
  See "Token abilities" in the README.
- Reading a balance no longer creates one on the gateway: a currency with
  nothing stored reads as zero.
- Requests are never retried automatically. The gateway charges a message when
  it accepts it, so a retried `POST` risks a second charge and a second SMS.

## [1.0.0] - 2025-11-26

### Added
- Initial release of vanilla PHP SDK
- Send single SMS messages
- Send batch SMS (up to 100 messages)
- Balance management (check balance, get summary, view transactions)
- Token management (list tokens, revoke current token)
- Scheduled message support
- Segment calculation (GSM 7-bit vs Unicode detection)
- Cost estimation
- Custom typed exceptions
- Comprehensive documentation with real-world examples
