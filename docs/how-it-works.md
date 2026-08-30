# Remonode SDK — How It Works

A complete guide to understanding the Remonode Laravel SDK, what it does, and how it connects to the Remonode portal.

---

## Table of Contents

- [Part 1: Non-Technical Explanation](#part-1-non-technical-explanation)
  - [What Is the Remonode SDK?](#what-is-the-remonode-sdk)
  - [The Problem It Solves](#the-problem-it-solves)
  - [How API Keys Work](#how-api-keys-work)
  - [What the Portal Does](#what-the-portal-does)
  - [Daily Workflow](#daily-workflow)
- [Part 2: Technical Explanation](#part-2-technical-explanation)
  - [Architecture Overview](#architecture-overview)
  - [Key Generation & Storage](#key-generation--storage)
  - [Key Validation Flow](#key-validation-flow)
  - [Middleware System](#middleware-system)
  - [Usage Tracking](#usage-tracking)
  - [Portal Synchronization](#portal-synchronization)
  - [Webhook Delivery](#webhook-delivery)
  - [Rate Limiting & Quotas](#rate-limiting--quotas)
  - [Artisan Commands](#artisan-commands)
  - [File Structure](#file-structure)
  - [Configuration Reference](#configuration-reference)

---

## Part 1: Non-Technical Explanation

### What Is the Remonode SDK?

The Remonode SDK is a **Laravel package** you install into your PHP application. It gives your app the ability to generate, validate, and manage **API keys** — the digital credentials that allow other software to access your API.

Think of it like a doorman for your application. When someone tries to access your API, the SDK checks their credentials, makes sure they're allowed in, and writes down who came and what they did.

### The Problem It Solves

If you're building an API and want to let other developers or apps use it, you need a way to:

1. **Give them a key** — a secure credential they can use to authenticate
2. **Validate the key** — check if it's real, active, and not expired on every request
3. **Track usage** — know who called what, when, and how often
4. **Control access** — limit some users to read-only, others to full access
5. **Revoke access** — cut off someone immediately when they shouldn't be allowed in

Building all of this from scratch takes weeks. The SDK does it in minutes. You install it, run one command, and you have a full API key system.

### How API Keys Work

Every registered application gets two keys:

| Key Type | Prefix | Purpose | Header |
|----------|--------|---------|--------|
| **Public Key** | `pk_` | Read-only access (safe to share) | `X-Public-Key` |
| **Secret Key** | `sk_` | Full access (keep private) | `X-Api-Key` |

**Example:**
- Public: `pk_2GxGtLpvw4zVPN4yFUU9KjvEzSP398CR`
- Secret: `sk_KMN6IkrEdWEmnY`

The public key is like a library card — it lets you read. The secret key is like a master key — it lets you read, write, and delete.

**Security design**: The SDK generates keys locally inside your app. It hashes the secret key immediately after generation. The plaintext is shown to you exactly once, then it's gone. The Remonode portal never receives the raw key — only a record that a key exists.

### What the Portal Does

The Remonode portal is a **web dashboard** where you can see all your apps and their API activity in one place. It connects to your app through the SDK.

With the portal you can:

- **See all connected apps** on a single dashboard
- **View usage analytics** — how many calls per day, which endpoints are popular
- **Manage keys** — see when each key was last used, revoke keys instantly
- **Set quotas** — limit how many API calls each app can make per month
- **Get webhooks** — receive real-time notifications when something happens

The portal is optional. The SDK works perfectly on its own. The portal just adds a centralized view across multiple apps.

### Daily Workflow

**Morning**: You open the portal. Yesterday, your app served 2,847 API calls. Everything looks normal.

**New integration**: A third-party wants to use your API. You tell them to install the SDK, run `php artisan remonode:register-app`, and their keys are automatically synced to your portal. You can now see them under "Connected Apps."

**Monitoring**: Usage data flows from your app to the portal in real-time. You see charts of calls per minute, which endpoints are most used, and which keys are active.

**Revoking access**: A partner's contract ends. You click "Revoke" in the portal. Their API key stops working immediately.

---

## Part 2: Technical Explanation

### Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│                  YOUR APPLICATION                    │
│                                                     │
│  ┌──────────────────────────────────────────────┐   │
│  │  Remonode SDK (composer package)             │   │
│  │                                              │   │
│  │  ┌────────────┐  ┌──────────────────────┐    │   │
│  │  │ Key        │  │ Key                  │    │   │
│  │  │ Generation │  │ Validation           │    │   │
│  │  └────────────┘  └──────────────────────┘    │   │
│  │  ┌────────────┐  ┌──────────────────────┐    │   │
│  │  │ Middleware  │  │ Usage Tracking       │    │   │
│  │  └────────────┘  └──────────────────────┘    │   │
│  │  ┌────────────┐  ┌──────────────────────┐    │   │
│  │  │ Portal     │  │ Webhook              │    │   │
│  │  │ Sync       │  │ Delivery             │    │   │
│  │  └────────────┘  └──────────────────────┘    │   │
│  └──────────────────────────────────────────────┘   │
│                      │                              │
│                      ▼                              │
│  ┌──────────────────────────────┐                  │
│  │  Local SQLite/MySQL DB       │                  │
│  │  remonode_api_keys           │                  │
│  │  remonode_api_usage_logs     │                  │
│  │  remonode_webhook_deliveries │                  │
│  └──────────────────────────────┘                  │
└─────────────────────────────────────────────────────┘
                       │
            sync + pull │
                       ▼
┌─────────────────────────────────────────────────────┐
│                  REMONODE PORTAL                     │
│                  (Optional)                          │
│                                                     │
│  ┌──────────────┐  ┌──────────────┐  ┌───────────┐ │
│  │ Dashboard    │  │ Usage        │  │ Webhooks  │ │
│  │              │  │ Analytics    │  │           │ │
│  └──────────────┘  └──────────────┘  └───────────┘ │
│  ┌──────────────┐  ┌──────────────┐                │
│  │ Connected    │  │ Billing &    │                │
│  │ Apps         │  │ Plans        │                │
│  └──────────────┘  └──────────────┘                │
└─────────────────────────────────────────────────────┘
```

**Key principle**: Your app generates keys locally. The portal only receives metadata (timestamps, key IDs, usage counts) — never the actual key material. Even if the portal is compromised, your keys remain safe.

### Key Generation & Storage

When you run `php artisan remonode:generate-key`, the SDK:

1. **Generates** two random keys:
   - Public key: `pk_` + 32 random alphanumeric characters
   - Secret key: `sk_` + 40 random alphanumeric characters

2. **Stores** them in the `remonode_api_keys` table:
   ```
   id:              1
   key_id:          sk_KMN6IkrEdWEmnY           (public identifier)
   public_key:      pk_2GxGtLpvw4zVPN4yFUU9KjvEzSP398CR
   secret_prefix:   KMN6IkrEdW                   (first 12 chars, for fast DB lookup)
   secret_hash:     sha256(sk_...)               (one-way hash — plaintext is gone)
   status:          active
   user_id:         1
   scopes:          ["phone-numbers", "payments"]
   rate_limit:      1000
   environment:     production
   ```

3. **Displays** the secret key exactly **once**. After that, it's hashed and the plaintext is gone forever.

4. **Optionally syncs** to the Remonode portal via `remonode:push-keys`.

### Key Validation Flow

When an API request arrives with an API key, here is what happens inside the SDK:

```
Request arrives:
  X-Public-Key: pk_2GxGtLpvw4zVPN4yFUU9KjvEzSP398CR
         │
         ▼
┌──────────────────────────────────┐
│  ValidateRemonodeKeyType          │  ← middleware
│  (src/Http/Middleware/)           │
└────────────┬─────────────────────┘
             │
             ▼
┌──────────────────────────────────┐
│  KeyValidator::validate()         │  ← src/Services/KeyValidator.php
│                                   │
│  Step 1: Check cache              │
│    cache_key = sha256(raw_key)    │
│    if found → return cached model │
│                                   │
│  Step 2: Database lookup          │
│    PK → WHERE public_key = ?      │
│    SK → WHERE secret_prefix = ?   │
│         + hash_equals() compare   │
│                                   │
│  Step 3: Verify                   │
│    status = 'active'?             │
│    expires_at is null or future?  │
│                                   │
│  Step 4: Cache for 60 seconds     │
│    Cache::put(key, model, 60s)    │
└────────────┬─────────────────────┘
             │
             ▼
┌──────────────────────────────────┐
│  Track Usage (built-in)           │
│                                   │
│  INSERT INTO remonode_api_usage_  │
│  logs                             │
│  (api_key_id, user_id, method,    │
│   path, status_code,              │
│   response_time_ms, ip_address,   │
│   user_agent, environment, ...)   │
└──────────────────────────────────┘
```

### Middleware System

The SDK registers 6 middleware aliases that you apply to your routes:

| Alias | Class | Purpose |
|-------|-------|---------|
| `remonode.key` | `ValidateRemonodeKeyType` | Validate API key + track usage |
| `remonode.key:pk` | (same, with `pk` param) | Public keys only |
| `remonode.key:sk` | (same, with `sk` param) | Secret keys only |
| `remonode.portal` | `AuthenticatePortalKey` | Authenticate portal sync requests |
| `remonode.rate_limit` | `RateLimitKey` | Rate limit by key |
| `remonode.scope` | `RequireScope` | Require specific scopes |
| `remonode.environment` | `RequireEnvironment` | Restrict to prod/staging/dev |

**Route examples:**

```php
// Only public keys (pk_*) — for read endpoints
Route::middleware('remonode.key:pk')->group(function () {
    Route::get('/data', [Controller::class, 'index']);
});

// Only secret keys (sk_*) — for write endpoints
Route::middleware('remonode.key:sk')->group(function () {
    Route::post('/data', [Controller::class, 'store']);
    Route::delete('/data/{id}', [Controller::class, 'destroy']);
});

// Both key types allowed (default)
Route::middleware('remonode.key')->group(function () {
    Route::get('/data/{id}', [Controller::class, 'show']);
});

// With rate limiting and scope enforcement
Route::middleware(['remonode.key:sk', 'remonode.rate_limit', 'remonode.scope:payments'])->group(function () {
    Route::post('/payments', [PaymentController::class, 'charge']);
});
```

### Usage Tracking

Usage tracking is **built into** the `remonode.key` middleware. Every validated API call is logged automatically — no extra middleware needed.

**What gets logged per request:**

| Field | Description |
|-------|-------------|
| `api_key_id` | Which key was used |
| `user_id` | Which user owns the key |
| `method` | HTTP method (GET, POST, DELETE) |
| `path` | Request path (e.g., `api/v1/phone-numbers/info/countries`) |
| `status_code` | Response status (200, 404, 500) |
| `response_time_ms` | How long the request took in milliseconds |
| `ip_address` | Client's IP address |
| `user_agent` | Client software (browser, Postman, curl, etc.) |
| `environment` | production, staging, or development |
| `scope_used` | Which scope was exercised (if scoped) |
| `rate_limited` | Whether the request was rate-limited |

**Sync vs Async:**

| Mode | Default | Requires | Reliability |
|------|---------|----------|-------------|
| **Sync** | Yes | Nothing extra | High — written immediately |
| **Async** | No | Queue worker running | Medium — may be delayed or lost |

Configure in `config/remonode.php`:
```php
'usage_tracking' => [
    'enabled' => true,
    'async' => false,   // false = sync (recommended)
    'queue' => 'default',
],
```

### Portal Synchronization

The SDK connects to the Remonode portal through two mechanisms:

#### 1. Push Keys (One-Time Setup)

After registering your app, push key metadata to the portal:

```bash
php artisan remonode:push-keys
```

This sends key IDs, public keys, statuses, and user IDs to the portal. The portal stores this as a "Connected App."

**What gets pushed:** key_id, public_key, status, user_id, scopes, environment
**What never leaves your server:** the raw secret key

#### 2. Pull Usage (Ongoing)

The portal's `PullUsageService` calls your app's `/portal/usage/logs` endpoint to fetch usage data.

```
Portal Dashboard
    │
    ▼
PullUsageService (portal server)
    │
    │  HTTP GET /portal/usage/logs
    │  Header: X-Portal-Secret: <shared_secret>
    │
    ▼
Your App's /portal/usage/logs endpoint (SDK route)
    │
    │  Validates shared_secret
    │  Queries remonode_api_usage_logs
    │  Returns raw logs with key_id mapping
    │
    ▼
Portal stores in its own usage_logs table
    │
    ▼
Dashboard displays analytics
```

**Portal API endpoints served by the SDK:**

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/portal/provision` | POST | Portal registers your app |
| `/portal/usage` | GET | Usage summary for a specific user |
| `/portal/usage/logs` | GET | Raw usage logs for portal sync |

**Authentication**: All portal requests must include a `shared_secret` header. The SDK validates this against the `REMONODE_PORTAL_SHARED_SECRET` value in your `.env`.

### Webhook Delivery

The portal can send webhooks to your app when events occur (key revoked, quota exceeded, etc.):

```
Portal event occurs
    │
    ▼
WebhookDelivery job dispatched
    │
    │  HTTP POST to your webhook URL
    │  Payload: { event: "key.revoked", data: {...} }
    │
    ├── 2xx response → status = 'delivered'
    ├── non-2xx → status = 'failed'
    └── timeout → status = 'timeout'
    │
    ▼
Retried up to 3 times with exponential backoff
    │
    ▼
Final status recorded in remonode_webhook_deliveries table
```

### Rate Limiting & Quotas

The SDK supports two levels of rate limiting:

**Per-key rate limiting** (local):

```php
// Apply rate_limit middleware to routes
Route::middleware(['remonode.key:sk', 'remonode.rate_limit'])->group(function () {
    Route::post('/payments', [PaymentController::class, 'charge']);
});
```

Configure in `config/remonode.php`:
```php
'rate_limiting' => [
    'enabled' => true,
    'default_limit' => 1000,      // requests per window
    'window_minutes' => 60,       // time window
],
```

**Global quota enforcement** (via portal):

When `quota_enforcement` is enabled, the middleware checks the portal to verify the app hasn't exceeded its monthly quota.

### Artisan Commands

| Command | Purpose |
|---------|---------|
| `php artisan remonode:generate-key` | Generate a new API key pair |
| `php artisan remonode:push-keys` | Sync keys to the Remonode portal |
| `php artisan remonode:register-app` | Register your app with the portal |

### File Structure

```
remonode-sdk/
├── config/
│   └── remonode.php                      # All configuration
├── database/
│   └── migrations/
│       ├── create_remonode_api_keys_table.php
│       ├── add_scopes_and_rate_limits_to_remonode_api_keys_table.php
│       ├── create_remonode_api_usage_logs_table.php
│       ├── create_remonode_webhook_deliveries_table.php
│       └── add_unique_index_to_usage_logs_table.php
├── src/
│   ├── Console/Commands/
│   │   ├── GenerateKeyCommand.php         # remonode:generate-key
│   │   ├── PushKeysCommand.php            # remonode:push-keys
│   │   └── RegisterAppCommand.php         # remonode:register-app
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── PortalProvisionController.php  # /portal/* endpoints
│   │   └── Middleware/
│   │       ├── ValidateRemonodeKeyType.php    # Main validation + tracking
│   │       ├── AuthenticatePortalKey.php      # Portal auth
│   │       ├── RateLimitKey.php               # Per-key rate limiting
│   │       ├── RequireScope.php               # Scope enforcement
│   │       ├── RequireEnvironment.php         # Environment restrictions
│   │       └── TrackUsage.php                 # Standalone tracking (legacy)
│   ├── Jobs/
│   │   └── LogApiUsage.php                    # Async usage logging
│   ├── Models/
│   │   ├── LocalApiKey.php                    # Key model
│   │   ├── UsageLog.php                       # Usage log model
│   │   └── WebhookDelivery.php                # Webhook model
│   ├── Services/
│   │   ├── KeyGenerationService.php           # Key generation logic
│   │   ├── KeyValidator.php                   # Key validation logic
│   │   └── PortalSyncService.php              # Portal sync logic
│   ├── RemonodeFacade.php                     # Facade (Remonode::)
│   ├── RemonodeManager.php                    # Main manager class
│   └── RemonodeServiceProvider.php            # Registers everything
├── docs/
│   └── how-it-works.md                        # This file
├── tests/                                     # PHPUnit tests
└── composer.json
```

### Configuration Reference

All configuration lives in `config/remonode.php`:

```php
return [
    // Portal connection (optional — SDK works without it)
    'portal_url' => env('REMONODE_PORTAL_URL'),
    'portal_key' => env('REMONODE_PORTAL_KEY'),
    'portal_shared_secret' => env('REMONODE_PORTAL_SHARED_SECRET'),

    // User model (links keys to your app's users)
    'user_model' => env('REMONODE_USER_MODEL', App\Models\User::class),

    // Key enforcement
    'enforcement' => true,          // false = disable all key checks
    'cache_enabled' => true,        // cache validated keys (recommended)
    'cache_ttl' => 60,              // cache duration in seconds

    // Usage tracking
    'usage_tracking' => [
        'enabled' => true,
        'async' => false,           // false = sync (no queue needed)
        'queue' => 'default',
    ],

    // Rate limiting
    'rate_limiting' => [
        'enabled' => false,
        'default_limit' => 1000,
        'window_minutes' => 60,
    ],

    // Scopes (fine-grained permissions per key)
    'scopes' => [
        'phone-numbers',
        'payments',
        'notifications',
    ],
];
```

**Environment variables (`.env`):**

```env
# Portal connection (optional)
REMONODE_PORTAL_URL=https://remonode.ng
REMONODE_PORTAL_KEY=your-portal-api-key
REMONODE_PORTAL_SHARED_SECRET=your-shared-secret

# User model (defaults to App\Models\User)
REMONODE_USER_MODEL=App\Models\User
```

---

## Summary

| Concept | What It Means |
|---------|---------------|
| **SDK** | Laravel package you install in your app |
| **Portal** | Optional web dashboard for centralized management |
| **Public Key (`pk_`)** | Read-only access, safe to share |
| **Secret Key (`sk_`)** | Full access, keep private |
| **Middleware** | Applied to routes to validate + track |
| **Usage Tracking** | Logged automatically on every validated request |
| **Sync** | Key metadata sent to portal, raw keys never leave your server |
| **Webhooks** | Portal notifies your app of events in real-time |

The SDK is **self-contained**. It works independently of the portal. If the portal is offline, your app still validates keys and tracks everything locally. The portal is an optional layer for viewing analytics across multiple apps.
