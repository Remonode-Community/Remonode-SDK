# Remonode Laravel SDK — How It Works

A complete guide to understanding the Remonode SDK, what it does, and how it connects to the Remonode portal.

---

## Table of Contents

- [Part 1: Non-Technical Explanation](#part-1-non-technical-explanation)
  - [What Is Remonode?](#what-is-remonode)
  - [The Problem It Solves](#the-problem-it-solves)
  - [How Keys Work](#how-keys-work)
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
  - [File Structure](#file-structure)
  - [Configuration Reference](#configuration-reference)

---

## Part 1: Non-Technical Explanation

### What Is Remonode?

Remonode is a platform for **managing who has access to your API**. Think of it as a security guard that sits at the entrance of your application and checks credentials before letting anyone in.

Every app you build needs a way to identify its users. Most apps use email and password. But when one app needs to talk to another app (machine-to-machine), there are no emails or passwords involved. Instead, they use **API keys** — long random strings that act like passwords for software.

Remonode helps you **create, manage, and track** these API keys.

### The Problem It Solves

Without Remonode, if you wanted to give someone access to your API, you would need to:

1. Manually generate a random key
2. Store it in your database
3. Write code to check if the key is valid on every request
4. Write separate code to track who used what and when
5. Build an admin panel to revoke keys, view usage, and manage billing

Remonode handles all of this for you through two components:

| Component | What It Is | Where It Runs |
|-----------|-----------|---------------|
| **SDK** | A package you install in your Laravel app | Your app (e.g., FemojV1 on Herd) |
| **Portal** | A web dashboard for managing keys and viewing analytics | Remonode server (Docker) |

### How Keys Work

Remonode uses two types of keys for every registered application:

#### Public Key (`pk_...`)
- Used for **read-only** endpoints (e.g., fetching data)
- Sent in the `X-Public-Key` header
- Safe to expose in client-side code or URLs
- Example: `pk_2GxGtLpvw4zVPN4yFUU9KjvEzSP398CR`

#### Secret Key (`sk_...`)
- Used for **write** endpoints (e.g., creating or deleting data)
- Sent in the `X-Api-Key` header or as a Bearer token
- **Never** share this — it's like a root password
- Example: `sk_KMN6IkrEdWEmnY`

Both keys are generated **locally inside your app**. Remonode never sees the raw key — it only receives a sync record so it knows the key exists. This is a deliberate security design: even if the Remonode portal is compromised, your actual keys are safe.

### What the Portal Does

The Remonode portal (running at `remonode.ng` or in Docker) provides:

- **Dashboard** — See all your connected apps and their API usage at a glance
- **Key Management** — View which keys are active, when they were last used, and revoke them
- **Usage Analytics** — See how many API calls were made, by which keys, to which endpoints
- **Connected Apps** — Register and manage multiple applications from one place
- **Billing & Plans** — Set usage quotas and monitor consumption per app
- **Webhooks** — Get notified in real-time when certain events happen (key created, quota exceeded, etc.)

### Daily Workflow

Here is what a typical day looks like for a developer using Remonode:

**Morning**: You log into the Remonode portal. The dashboard shows all your apps. FemojV1 had 2,847 API calls yesterday. Everything looks normal.

**Adding a new integration**: A third-party wants to access your phone number API. You give them your portal URL. They install the SDK in their app, run a register command, and their keys are automatically synced to your portal. You can see them under "Connected Apps."

**Monitoring**: Throughout the day, usage data flows from each connected app to the portal. You can see real-time charts showing calls per minute, which endpoints are most popular, and which keys are making the most requests.

**Revoking access**: A partner's contract expires. You click "Revoke" next to their key in the portal. Their API access is immediately blocked.

---

## Part 2: Technical Explanation

### Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│                    REMONODE ECOSYSTEM                │
│                                                     │
│  ┌──────────────┐         ┌──────────────────────┐  │
│  │  Your App    │         │  Remonode Portal      │  │
│  │  (FemojV1)   │         │  (Docker)             │  │
│  │              │         │                       │  │
│  │  ┌────────┐  │  sync   │  ┌─────────────────┐  │  │
│  │  │ SDK    │──┼────────►│  │ Connected Apps  │  │  │
│  │  │ v1.2.6 │  │         │  │ Usage Logs      │  │  │
│  │  └────────┘  │         │  │ Webhooks        │  │  │
│  │      │       │         │  └─────────────────┘  │  │
│  │      ▼       │         └──────────────────────┘  │
│  │  ┌────────┐  │                                    │
│  │  │ Local  │  │         ┌──────────────────────┐  │
│  │  │ SQLite │  │         │  Third-Party App      │  │
│  │  └────────┘  │         │  (Another Laravel app)│  │
│  └──────────────┘         └──────────────────────┘  │
└─────────────────────────────────────────────────────┘
```

**Key principle**: Your app generates keys locally. The Remonode portal only receives metadata (timestamps, key IDs, usage counts) — never the actual key material.

### Key Generation & Storage

When you run `php artisan remonode:generate-key`, the SDK:

1. **Generates** two random keys:
   - Public key: `pk_` + 32 random alphanumeric characters
   - Secret key: `sk_` + 40 random alphanumeric characters

2. **Stores** them in the `remonode_api_keys` table:
   ```
   id: 1
   key_id: sk_KMN6IkrEdWEmnY          (public identifier)
   public_key: pk_2GxGtLpvw4zVPN4yFUU9KjvEzSP398CR
   secret_prefix: KMN6IkrEdW            (first 12 chars, for fast lookup)
   secret_hash: sha256(sk_...)          (hashed — original is gone)
   status: active
   user_id: 1
   scopes: ["phone-numbers", "payments"]
   rate_limit: 1000
   environment: production
   ```

3. **Displays** the secret key exactly **once**. After that, it's hashed and the plaintext is gone forever.

4. **Optionally syncs** to the Remonode portal (via `remonode:push-keys` or auto-sync).

### Key Validation Flow

When an API request arrives with an API key:

```
Request with X-Public-Key: pk_2GxGtLpvw4zVPN4yFUU9KjvEzSP398CR
         │
         ▼
┌─────────────────────────┐
│  ValidateRemonodeKeyType │  (middleware)
│  middleware               │
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  KeyValidator::validate()│
│                          │
│  1. Check cache (SHA-256 │
│     hash of raw key)     │
│     └── HIT → return     │
│                          │
│  2. Lookup by prefix or  │
│     direct match         │
│     └── PK: WHERE        │
│         public_key = ?   │
│     └── SK: WHERE        │
│         secret_prefix = ?│
│         + hash_equals()  │
│                          │
│  3. Check status=active  │
│     and not expired      │
│                          │
│  4. Cache for 60s        │
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  Track Usage (sync)      │
│                          │
│  INSERT INTO             │
│  remonode_api_usage_logs │
│  (api_key_id, method,    │
│   path, status_code,     │
│   response_time_ms, ...) │
└─────────────────────────┘
```

### Middleware System

The SDK registers 6 middleware aliases:

| Alias | Class | Purpose |
|-------|-------|---------|
| `remonode.key` | `ValidateRemonodeKeyType` | Validate API key + track usage (default) |
| `remonode.portal` | `AuthenticatePortalKey` | Authenticate portal sync requests |
| `remonode.rate_limit` | `RateLimitKey` | Rate limit by key |
| `remonode.track_usage` | `TrackUsage` | Standalone usage tracking (no longer needed) |
| `remonode.scope` | `RequireScope` | Require specific scopes on a key |
| `remonode.environment` | `RequireEnvironment` | Restrict to prod/staging/dev |

**Route usage:**

```php
// Only public keys (pk_*) allowed
Route::middleware('remonode.key:pk')->group(function () {
    Route::get('/data', [Controller::class, 'index']);
});

// Only secret keys (sk_*) allowed
Route::middleware('remonode.key:sk')->group(function () {
    Route::post('/data', [Controller::class, 'store']);
});

// Both key types allowed (default)
Route::middleware('remonode.key')->group(function () {
    Route::get('/data', [Controller::class, 'show']);
});
```

### Usage Tracking

Usage tracking is built into the `ValidateRemonodeKeyType` middleware. Every validated API call is logged automatically.

**What gets logged:**
- `api_key_id` — Which key was used
- `user_id` — Which user owns the key
- `method` — HTTP method (GET, POST, etc.)
- `path` — Request path (`api/v1/phone-numbers/info/countries`)
- `status_code` — Response status (200, 404, 500, etc.)
- `response_time_ms` — How long the request took
- `ip_address` — Client IP
- `user_agent` — Client software
- `environment` — production/staging/development
- `scope_used` — Which scope was exercised (if scoped)
- `rate_limited` — Whether the request was rate-limited

**Sync vs Async:**
- **Sync** (default): Usage is written to the database immediately on every request. No queue worker needed. Reliable.
- **Async**: Usage is dispatched to a queue job. Requires a running queue worker (`php artisan queue:work`). Faster response times but usage may be delayed or lost if the queue is down.

Configure in `config/remonode.php`:
```php
'usage_tracking' => [
    'enabled' => true,
    'async' => false,  // true requires queue worker
    'queue' => 'default',
],
```

### Portal Synchronization

The portal needs usage data from your app to display analytics. There are two sync mechanisms:

#### 1. Push Keys (One-Time)

Register your app with the portal, then push your keys:

```bash
php artisan remonode:push-keys
```

This sends key metadata (key_id, public_key, status, user_id) to the portal. The portal stores this as a "Connected App."

#### 2. Pull Usage (Ongoing)

The portal's `PullUsageService` calls your app's `/portal/usage/logs` endpoint to fetch usage data.

**Flow:**
```
Portal Dashboard → PullUsageService → HTTP GET /portal/usage/logs
                                            │
                                            ▼
                                    FemojV1 /portal/usage/logs
                                    (SDK endpoint, authenticated
                                     by portal shared secret)
                                            │
                                            ▼
                                    Returns raw usage_logs rows
                                    with key_id mapping
                                            │
                                            ▼
                                    Portal stores in its own
                                    usage_logs table, mapped to
                                    its own api_key_id
```

**Portal endpoints (on your app):**

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/portal/provision` | POST | Portal registers your app |
| `/portal/usage` | GET | Get usage summary for a user |
| `/portal/usage/logs` | GET | Get raw usage logs (for portal sync) |

**Authentication:** Portal requests include a `shared_secret` header. The SDK validates this against `REMONODE_PORTAL_SHARED_SECRET` in your `.env`.

### Webhook Delivery

The portal can send webhooks to your app when events occur:

```
Portal event (key revoked, quota exceeded)
    │
    ▼
WebhookDelivery::dispatch(url, payload, headers)
    │
    ▼
HTTP POST to your app's webhook endpoint
    │
    ├── Success (2xx) → status = 'delivered'
    ├── Failure (non-2xx) → status = 'failed'
    └── Timeout → status = 'timeout'
    │
    ▼
Up to 3 retries with exponential backoff
```

### Rate Limiting & Quotas

The SDK supports two levels of rate limiting:

**Per-key rate limiting** (via middleware):
```php
Route::middleware(['remonode.key:sk', 'remonode.rate_limit'])->group(function () {
    // Rate limited to key's configured limit
});
```

**Global quota enforcement** (via portal):
```php
// In config/remonode.php
'quota_enforcement' => true,
```

When enabled, the middleware checks the portal's `/api/v1/connected-app/status` endpoint to verify the app hasn't exceeded its monthly quota.

### File Structure

```
remonode-sdk/
├── config/
│   └── remonode.php              # All configuration
├── database/
│   └── migrations/               # 5 migration files
├── src/
│   ├── Console/
│   │   └── Commands/
│   │       ├── GenerateKeyCommand.php      # remonode:generate-key
│   │       ├── PushKeysCommand.php         # remonode:push-keys
│   │       └── RegisterAppCommand.php      # remonode:register-app
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── PortalProvisionController.php  # Portal API endpoints
│   │   └── Middleware/
│   │       ├── ValidateRemonodeKeyType.php  # Main middleware
│   │       ├── AuthenticatePortalKey.php    # Portal auth
│   │       ├── RateLimitKey.php             # Rate limiting
│   │       ├── RequireScope.php             # Scope enforcement
│   │       ├── RequireEnvironment.php       # Env restrictions
│   │       └── TrackUsage.php               # Standalone tracking
│   ├── Jobs/
│   │   └── LogApiUsage.php                  # Async usage logging
│   ├── Models/
│   │   ├── LocalApiKey.php                  # Key model
│   │   ├── UsageLog.php                     # Usage log model
│   │   └── WebhookDelivery.php              # Webhook model
│   ├── Services/
│   │   ├── KeyGenerationService.php         # Key generation
│   │   ├── KeyValidator.php                 # Key validation
│   │   └── PortalSyncService.php            # Portal sync
│   ├── RemonodeFacade.php                   # Facade
│   ├── RemonodeManager.php                  # Main manager
│   └── RemonodeServiceProvider.php          # Service provider
├── docs/
│   └── how-it-works.md                      # This file
└── composer.json
```

### Configuration Reference

All configuration lives in `config/remonode.php`:

```php
return [
    // Portal connection
    'portal_url' => env('REMONODE_PORTAL_URL'),
    'portal_key' => env('REMONODE_PORTAL_KEY'),
    'portal_shared_secret' => env('REMONODE_PORTAL_SHARED_SECRET'),

    // User model (for linking keys to users)
    'user_model' => env('REMONODE_USER_MODEL', App\Models\User::class),

    // Key enforcement
    'enforcement' => true,          // Set false to disable key checks
    'cache_enabled' => true,        // Cache validated keys (recommended)
    'cache_ttl' => 60,              // Cache duration in seconds

    // Usage tracking
    'usage_tracking' => [
        'enabled' => true,
        'async' => false,           // false = sync (no queue needed)
        'queue' => 'default',
    ],

    // Rate limiting
    'rate_limiting' => [
        'enabled' => false,         // Enable per-key rate limiting
        'default_limit' => 1000,
        'window_minutes' => 60,
    ],

    // Scopes (fine-grained permissions)
    'scopes' => [
        'phone-numbers',
        'payments',
        'notifications',
    ],
];
```

**Environment variables (`.env`):**

```env
REMONODE_PORTAL_URL=https://remonode.ng
REMONODE_PORTAL_KEY=your-portal-api-key
REMONODE_PORTAL_SHARED_SECRET=your-shared-secret
REMONODE_USER_MODEL=App\Models\User
```

---

## Summary

| Concept | What It Means |
|---------|---------------|
| **SDK** | Package installed in your Laravel app |
| **Portal** | Web dashboard for managing all connected apps |
| **Keys** | Generated locally, never leave your app |
| **Sync** | Key metadata sent to portal, not the raw keys |
| **Usage** | Every API call logged automatically |
| **Middleware** | Applied to routes to validate + track |
| **Webhooks** | Portal notifies your app of events |

The SDK is **decoupled** — it works independently of the portal. If the portal is down, your app still validates keys and tracks usage locally. The portal is an optional overlay for centralized management.
