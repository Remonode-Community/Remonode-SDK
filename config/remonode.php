<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remonode Portal URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your Remonode portal (e.g., https://remonode.ng).
    | Used for syncing key metadata and registration.
    |
    */
    'portal_url' => env('REMONODE_PORTAL_URL', 'http://localhost:8006'),

    /*
    |--------------------------------------------------------------------------
    | Portal API Key (Shared Secret)
    |--------------------------------------------------------------------------
    |
    | The shared secret used to authenticate YOUR app against Remonode's
    | portal endpoints. Must match the portal key on the Remonode server.
    |
    */
    'portal_key' => env('REMONODE_PORTAL_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Your Application UUID
    |--------------------------------------------------------------------------
    |
    | Assigned by Remonode when you register your app.
    | Set this after completing registration via Remonode::register().
    |
    */
    'app_uuid' => env('REMONODE_APP_UUID', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    */
    'timeout' => env('REMONODE_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Key Generation
    |--------------------------------------------------------------------------
    |
    | Configure how API keys are generated locally. Keys are generated in
    | your application — Remonode never generates them for you.
    |
    | prefix:     The string prefix for keys (pk_ / sk_)
    | random_len: Number of random characters after the prefix
    | hash_algo:  Hashing algorithm for secret key storage
    | key_id_length: Number of random chars used in the key_id lookup
    | secret_lookup_length: Prefix length for indexed DB lookup
    */
    'key_generation' => [
        'public_prefix' => env('REMONODE_PK_PREFIX', 'pk_'),
        'secret_prefix' => env('REMONODE_SK_PREFIX', 'sk_'),
        'public_random_length' => 32,
        'secret_random_length' => 40,
        'hash_algo' => 'sha256',
        'key_id_length' => 16,
        'secret_lookup_length' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The default environment for generated keys. Can be 'production' or 'sandbox'.
    |
    */
    'environment' => env('REMONODE_ENVIRONMENT', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Local Cache
    |--------------------------------------------------------------------------
    |
    | Cache validated keys locally to avoid hitting the database on every request.
    |
    */
    'cache_enabled' => env('REMONODE_CACHE_ENABLED', true),
    'cache_ttl' => env('REMONODE_CACHE_TTL', 60), // minutes

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    */
    'table' => 'remonode_api_keys',

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The fully qualified class name of your application's User model.
    |
    */
    'user_model' => env('REMONODE_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Sync to Portal
    |--------------------------------------------------------------------------
    |
    | Whether to automatically sync key metadata to Remonode after local
    | generation. Set to false if you don't use Remonode or want manual sync.
    |
    */
    'sync_to_portal' => env('REMONODE_SYNC_TO_PORTAL', true),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret for verifying Paystack webhook payloads forwarded
    | from Remonode. Set this to the same value as your Remonode's
    | PAYSTACK_WEBHOOK_SECRET or a custom shared secret.
    |
    */
    'webhook_secret' => env('REMONODE_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Enforce API Key Validation
    |--------------------------------------------------------------------------
    |
    | Master switch for the ValidateRemonodeApiKey middleware.
    | Set to false during migration/rollout to skip enforcement.
    |
    */
    'enforcement' => env('REMONODE_API_KEY_ENFORCEMENT', true),

    /*
    |--------------------------------------------------------------------------
    | Exempt URIs
    |--------------------------------------------------------------------------
    |
    | Routes that bypass API key validation even when enforcement is on.
    |
    */
    'exempt_uris' => [
        'api/health',
        'api/v1/auth/register',
        'api/v1/auth/login',
    ],

];
