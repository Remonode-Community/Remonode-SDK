# Remonode Laravel SDK

Laravel SDK for API key management and Remonode portal integration. Generate, validate, rotate, and revoke API keys **locally** — Remonode manages and tracks them centrally.

---

## Architecture

```
┌──────────────────────────┐          ┌──────────────────────────┐
│   Your Laravel App        │          │   Remonode Portal         │
│                           │          │                           │
│   This package lives here │          │   - Central management    │
│                           │          │   - Billing/Subscriptions │
│   YOU generate keys:      │  ──────► │   - Key metadata sync     │
│   pk_... + sk_...         │  sync    │   - Usage tracking        │
│   Stored in YOUR DB       │          │   - Audit trail           │
│   Validated locally       │          │                           │
└──────────────────────────┘          └──────────────────────────┘
         │                                      │
         ▼                                      ▼
   Your API Routes                     Paystack Payments
   (protected by middleware)           (handled by Remonode)
```

**Key principle:** Your application generates its own keys. Remonode never generates keys for you.

---

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- A [Remonode](https://www.remonode.com) account (required for portal sync features)

---

## Installation

### Prerequisites

Create an account at [www.remonode.com](https://www.remonode.com) before using the portal sync features. The email used in your app must match your Remonode account.

### 1. Require the package

```bash
composer require remonode/laravel-sdk
```

### 2. Publish configuration and migrations

```bash
php artisan vendor:publish --tag=remonode-config
php artisan vendor:publish --tag=remonode-migrations
```

### 3. Run migrations

```bash
php artisan migrate
```

This creates the `remonode_api_keys` table in your database.

### 4. Configure your `.env`

```env
# Required: Remonode portal connection
REMONODE_PORTAL_URL=https://remonode.ng
REMONODE_PORTAL_KEY=your-portal-secret-key

# Optional: Your app UUID (assigned after registration)
REMONODE_APP_UUID=

# Optional: Key generation customization
REMONODE_PK_PREFIX=pk_
REMONODE_SK_PREFIX=sk_
REMONODE_ENVIRONMENT=production

# Optional: Features
REMONODE_SYNC_TO_PORTAL=true
REMONODE_API_KEY_ENFORCEMENT=true
REMONODE_QUOTA_ENFORCEMENT=false
REMONODE_CACHE_ENABLED=true
REMONODE_CACHE_TTL=60
```

### 5. (Optional) Register your app with Remonode

If you want Remonode to track your app's keys and billing, you must first have an account on [Remonode](https://www.remonode.com). Your email on Remonode must match the one used in your Laravel app.

```php
use Remonode\SDK\RemonodeFacade as Remonode;

$result = Remonode::register(
    appName: 'My Laravel App',
    ownerEmail: 'admin@myapp.com',
    ownerName: 'John Doe'
);
```

---

## Quick Start

### Protect a route with API key validation

```php
use Remonode\SDK\Http\Middleware\ValidateRemonodeApiKey;

Route::middleware(ValidateRemonodeApiKey::class)->group(function () {
    Route::get('/api/v1/data', function () {
        $apiKey = request()->get('remonode_api_key');
        return response()->json([
            'message' => 'Authenticated!',
            'key_name' => $apiKey->name,
        ]);
    });
});
```

### Generate keys for a user

```php
use Remonode\SDK\RemonodeFacade as Remonode;

$result = Remonode::generate(
    userId: $user->id,
    name: 'Production API Key'
);

// $result['raw_secret'] = 'sk_live_...'  ← SHOW THIS TO USER ONCE
// $result['public_key'] = 'pk_live_...'
// $result['key']        = LocalApiKey model (stored in DB)
```

---

## User Stories

### Story 1: "Install and connect my app to Remonode"

**As a developer, I want to install the package and connect my app.**

1. Create an account at [www.remonode.com](https://www.remonode.com)
2. Install the package:

```bash
composer require remonode/laravel-sdk
php artisan vendor:publish --tag=remonode-config
php artisan vendor:publish --tag=remonode-migrations
php artisan migrate
```

Edit `.env`:
```env
REMONODE_PORTAL_URL=https://remonode.ng
REMONODE_PORTAL_KEY=your-shared-secret
REMONODE_APP_UUID=your-app-uuid
```

Test the connection:
```bash
php artisan remonode:test-connection
```

Register your app (email must match your Remonode account):
```php
use Remonode\SDK\RemonodeFacade as Remonode;

$result = Remonode::register(
    appName: 'My Laravel App',
    ownerEmail: 'your-email@example.com', // Must exist on www.remonode.com
    ownerName: 'Your Name'
);
```

```
Testing connection to Remonode portal...
  URL: https://remonode.ng
  Portal Key: ***configured***
Connection successful!
```

---

### Story 2: "Generate API keys for my users"

**As a developer, I want to generate API keys locally when users sign up or subscribe.**

Keys are generated **entirely in your application**. No HTTP call to Remonode is required.

```php
use Remonode\SDK\RemonodeFacade as Remonode;

// During user registration or subscription activation
$result = Remonode::generate(
    userId: $user->id,
    name: 'Mobile App Key',
    expiresAt: now()->addYear()->toDateTimeString() // optional
);

// Return the secret key to the user ONCE
return response()->json([
    'public_key'  => $result['public_key'],    // pk_live_abc123...
    'secret_key'  => $result['raw_secret'],    // sk_live_xyz789... (SHOW ONCE)
    'key_id'      => $result['key']->key_id,   // sk_live_abc123def456
]);
```

**What happens behind the scenes:**

1. Package generates `pk_` + 32 random chars (public key)
2. Package generates `sk_` + 40 random chars (secret key)
3. Extracts 12-char lookup prefix from the secret key
4. Hashes the full secret key with SHA-256
5. Stores everything in your `remonode_api_keys` table
6. **The plaintext secret key is returned exactly once — it is never stored**
7. If `sync_to_portal=true`, key metadata is sent to Remonode

---

### Story 3: "Protect my API routes"

**As a developer, I want only authenticated API consumers to access my endpoints.**

The middleware checks the `X-Api-Key`, `X-Public-Key`, or `Authorization: Bearer` header against your local database.

```php
// In your routes/api.php
use Remonode\SDK\Http\Middleware\ValidateRemonodeApiKey;

Route::middleware(ValidateRemonodeApiKey::class)->group(function () {
    Route::get('/api/v1/wallet/balance', [WalletController::class, 'balance']);
    Route::post('/api/v1/transfer', [TransferController::class, 'store']);
});
```

**API consumer sends a request:**

```bash
curl -X GET https://yourapp.com/api/v1/wallet/balance \
  -H "X-Api-Key: sk_live_abcdefgh12345678rest..." \
  -H "Authorization: Bearer your-sanctum-token" \
  -H "Accept: application/json"
```

**Validation flow:**

1. Read `X-Api-Key` header value
2. Extract 12-char prefix from the secret key
3. Query `remonode_api_keys` WHERE `secret_prefix` = prefix (indexed lookup)
4. For each candidate, hash the input and compare with `hash_equals()` (constant-time)
5. Check key is active and not expired
6. Attach key model to request
7. Access it in your controller: `$request->get('remonode_api_key')`

**If key is invalid:**
```json
{ "success": false, "message": "Invalid API key." }
```

---

### Story 4: "Let users manage their API keys"

**As a user, I want to view, rotate, and revoke my API keys.**

The package provides ready-made routes:

```php
// routes/api.php — included automatically by the package
// GET    /api/v1/remonode/api-keys              → List keys
// POST   /api/v1/remonode/api-keys              → Generate new key pair
// POST   /api/v1/remonode/api-keys/{keyId}/rotate → Rotate key
// POST   /api/v1/remonode/api-keys/{keyId}/revoke → Revoke key
```

All routes require `auth:sanctum` middleware.

**Generate a new key via API:**

```bash
curl -X POST https://yourapp.com/api/v1/remonode/api-keys \
  -H "Authorization: Bearer your-sanctum-token" \
  -H "Content-Type: application/json" \
  -d '{"name": "My New Key"}'
```

```json
{
    "success": true,
    "message": "API key pair generated. Store the secret key securely.",
    "data": {
        "id": 5,
        "key_id": "sk_live_a1b2c3d4e5f6g7h8",
        "public_key": "pk_live_mypub...",
        "secret_key": "sk_live_mysecretkey...",
        "masked_secret": "sk_live_...g7h8",
        "name": "My New Key",
        "status": "active",
        "environment": "production",
        "created_at": "2026-08-26T10:00:00.000000Z"
    }
}
```

**Rotate a compromised key:**

```php
use Remonode\SDK\RemonodeFacade as Remonode;

$result = Remonode::rotate($keyId);
// Old key is revoked, new key pair is generated
// $result['raw_secret'] contains the new secret — show it once
```

**Revoke a key:**

```php
Remonode::revoke($keyId);
```

**Lockout protection:** The package prevents revoking a user's last active key pair.

---

### Story 5: "Receive billing and subscription webhooks"

**As a developer, I want to receive webhook events when subscriptions change.**

Webhooks are automatically routed with HMAC-SHA512 signature verification.

```php
// In your EventServiceProvider, listen for package events:
use Remonode\SDK\Events\RemonodeSubscriptionCreated;
use Remonode\SDK\Events\RemonodeSubscriptionUpdated;
use Remonode\SDK\Events\RemonodeSubscriptionCancelled;
use Remonode\SDK\Events\RemonodePaymentFailed;

protected $listen = [
    RemonodeSubscriptionCreated::class => [
        \App\Listeners\HandleNewSubscription::class,
    ],
    RemonodeSubscriptionUpdated::class => [
        \App\Listeners\HandleSubscriptionUpdate::class,
    ],
    RemonodeSubscriptionCancelled::class => [
        \App\Listeners\HandleSubscriptionCancel::class,
    ],
    RemonodePaymentFailed::class => [
        \App\Listeners\HandlePaymentFailure::class,
    ],
];
```

```php
// app/Listeners/HandleNewSubscription.php
use Remonode\SDK\Events\RemonodeSubscriptionCreated;

class HandleNewSubscription
{
    public function handle(RemonodeSubscriptionCreated $event): void
    {
        $email = $event->data['customer']['email'] ?? null;
        // Enable API access for this user
        // Sync subscription status to your local DB
    }
}
```

| Event | When | Your Action |
|-------|------|-------------|
| `RemonodeSubscriptionCreated` | User subscribes | Enable API access |
| `RemonodeSubscriptionUpdated` | Payment succeeds | Confirm access active |
| `RemonodeSubscriptionCancelled` | User cancels | Disable API access |
| `RemonodePaymentFailed` | Payment fails | Warn user, set grace period |

---

### Story 6: "Use the package without Remonode"

**As a developer, I want to use the key management features without connecting to Remonode.**

The package works fully offline. Set `REMONODE_SYNC_TO_PORTAL=false` and leave portal credentials blank:

```env
REMONODE_SYNC_TO_PORTAL=false
REMONODE_PORTAL_URL=
REMONODE_PORTAL_KEY=
```

All local features work:
- Key generation
- Key validation
- Key rotation/revocation
- Middleware protection
- Key management API

Only Remonode-specific features are disabled:
- Portal registration
- Key metadata sync
- Webhook events from Remonode

---

## Configuration Reference

### `config/remonode.php`

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `portal_url` | `REMONODE_PORTAL_URL` | `http://localhost:8006` | Remonode portal base URL |
| `portal_key` | `REMONODE_PORTAL_KEY` | `''` | Shared secret for portal auth |
| `app_uuid` | `REMONODE_APP_UUID` | `''` | Your app's UUID on Remonode |
| `timeout` | `REMONODE_TIMEOUT` | `15` | HTTP timeout (seconds) |
| `key_generation.public_prefix` | `REMONODE_PK_PREFIX` | `pk_` | Public key prefix |
| `key_generation.secret_prefix` | `REMONODE_SK_PREFIX` | `sk_` | Secret key prefix |
| `key_generation.public_random_length` | — | `32` | Random chars in public key |
| `key_generation.secret_random_length` | — | `40` | Random chars in secret key |
| `key_generation.hash_algo` | — | `sha256` | Hash algorithm for secret storage |
| `key_generation.secret_lookup_length` | — | `12` | Prefix chars for indexed lookup |
| `environment` | `REMONODE_ENVIRONMENT` | `production` | Key environment (production/sandbox) |
| `cache_enabled` | `REMONODE_CACHE_ENABLED` | `true` | Cache validated keys |
| `cache_ttl` | `REMONODE_CACHE_TTL` | `60` | Cache TTL in minutes |
| `sync_to_portal` | `REMONODE_SYNC_TO_PORTAL` | `true` | Auto-sync metadata to Remonode |
| `enforcement` | `REMONODE_API_KEY_ENFORCEMENT` | `true` | Master middleware switch |
| `quota_enforcement` | `REMONODE_QUOTA_ENFORCEMENT` | `false` | Check monthly quota via portal |
| `webhook_secret` | `REMONODE_WEBHOOK_SECRET` | `''` | HMAC secret for webhook verification |
| `user_model` | `REMONODE_USER_MODEL` | `App\Models\User` | Your User model class |

---

## Facade API

```php
use Remonode\SDK\RemonodeFacade as Remonode;

// Generate keys locally
$result = Remonode::generate($userId, $name, $expiresAt);
// Returns: ['key' => LocalApiKey, 'raw_secret' => 'sk_...', 'public_key' => 'pk_...']

// Validate a raw key against local DB
$key = Remonode::validate($rawKey);
// Returns: LocalApiKey or null

// Rotate a key
$result = Remonode::rotate($keyOrKeyId);

// Revoke a key
Remonode::revoke($keyOrKeyId);

// List all keys for a user
$keys = Remonode::listForUser($userId);

// Find by key_id or public_key
$key = Remonode::findByKeyId('sk_live_abc123...');
$key = Remonode::findByPublicKey('pk_live_xyz789...');

// Check user capabilities
$hasKeys = Remonode::hasActiveKeys($userId);
$canRevoke = Remonode::canRevoke($key);

// Register with Remonode (optional)
$apps = Remonode::register('My App', 'admin@myapp.com', 'John');

// Manual sync to portal
Remonode::syncToPortal($key);
```

---

## Database Schema

### `remonode_api_keys` table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-increment |
| `user_id` | FK → users | Owner of the key (nullable) |
| `app_uuid` | string(36) | Remonode app UUID (nullable) |
| `key_id` | string(100) | Unique lookup ID: `sk_live_abc123def456` |
| `secret_prefix` | string(16) | First 12 random chars for indexed lookup |
| `public_key` | text | Full public key (plaintext, not secret) |
| `secret_hash` | string | SHA-256 hash of secret key |
| `secret_last_four` | string(4) | Last 4 chars for masked display |
| `name` | string | Human-readable label |
| `status` | string(20) | `active`, `revoked`, `rotated`, `expired` |
| `environment` | string(20) | `production` or `sandbox` |
| `remote_id` | string | Remonode's key UUID (if synced) |
| `expires_at` | timestamp | Optional expiration |
| `last_used_at` | timestamp | Last validation time |
| `created_at/updated_at` | timestamps | — |
| `deleted_at` | timestamp | Soft delete |

---

## Billing & Quotas

Connected apps are auto-assigned the **Free** plan on registration. Plans control monthly API call limits:

| Plan | Monthly Requests | Price |
|------|-----------------|-------|
| Free | 10,000 | ₦0 |
| Starter | 100,000 | ₦5,000/mo |
| Pro | 1,000,000 | ₦25,000/mo |
| Enterprise | Unlimited | Custom |

To enforce quotas, enable it in your `.env`:

```env
REMONODE_QUOTA_ENFORCEMENT=true
```

When enabled, the middleware returns `429` with quota details when the limit is exceeded. Developers can upgrade via the Remonode portal.

---

## Security

1. **Secret keys are never stored in plaintext** — only SHA-256 hashes
2. **Timing-safe comparison** via `hash_equals()` prevents timing attacks
3. **Prefix-based indexed lookup** — fast DB queries, only candidates are hash-compared
4. **Keys shown once** — plaintext secret returned at generation, then discarded
5. **Lockout protection** — cannot revoke last active key pair
6. **Webhook verification** — HMAC-SHA512 signature check on all webhook payloads
7. **`secret_hash` hidden** — never serialized in JSON responses

---

## Artisan Commands

```bash
# Test portal connectivity
php artisan remonode:test-connection

# Sync key metadata from portal
php artisan remonode:sync-keys
php artisan remonode:sync-keys --email=user@example.com
```

---

## License

MIT
