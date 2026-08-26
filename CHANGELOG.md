# Changelog

All notable changes to `remonode/laravel-sdk` will be documented in this file.

## v1.0.0 (2026-08-26)

### Features
- API key generation (public `pk_` and private `sk_` key pairs)
- Local key validation with timing-safe comparison (`hash_equals`)
- Key rotation (revoke old, generate new)
- Key revocation with lockout protection (prevents revoking the last active key)
- User-scoped key listing and active key checks
- Optional metadata sync to Remonode portal
- `ValidateRemonodeApiKey` middleware for route protection
- `VerifyRemonodeWebhook` middleware for Paystack webhook signature validation
- `RemonodeWebhookController` for handling Paystack webhook events
- `ApiKeyController` for programmatic key management
- Artisan commands: `remonode:test-connection`, `remonode:sync-keys`
- `Remonode` facade with `Remonode.keys` accessor
- Configurable enforcement toggle and exempt URIs
- Cache-backed key validation with configurable TTL
- Laravel 10/11/12/13 and PHP 8.1+ support
- Auto-discovery of service provider and facade

### Security
- Secrets hashed with SHA-256, only prefix stored for indexed lookup
- Raw secret shown exactly once at generation time
- Timing-safe comparison prevents timing attacks
- Cache keys use hashed secrets to avoid exposure in Redis
