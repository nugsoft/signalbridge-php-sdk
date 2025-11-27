# Changelog

All notable changes to `signalbridge-php` will be documented in this file.

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
- Custom typed exceptions:
  - `InsufficientBalanceException`
  - `ValidationException`
  - `NoClientException`
  - `ServiceUnavailableException`
  - `SignalBridgeException`
- Comprehensive documentation with 6 real-world examples
- Example files for common use cases
- Support for PHP 7.4, 8.0, 8.1, 8.2, 8.3, and 8.4

### Features
- ✅ Framework-agnostic design
- ✅ Works with any PHP project
- ✅ Guzzle HTTP client for reliable API communication
- ✅ Automatic segment calculation and cost estimation
- ✅ Metadata support for audit trails
- ✅ Test mode for development
- ✅ Custom sender ID support
- ✅ Scheduled message delivery
- ✅ Batch processing with detailed results
- ✅ Balance tracking and transaction history
- ✅ Comprehensive error handling
- ✅ Error logging support
