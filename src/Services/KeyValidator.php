<?php

namespace Remonode\SDK\Services;

use Illuminate\Support\Facades\Cache;
use Remonode\SDK\Models\LocalApiKey;

class KeyValidator
{
    public function __construct(
        private readonly KeyGenerationService $generator,
    ) {}

    /**
     * Validate an API key against the local database.
     *
     * Flow:
     * 1. Check cache (fast path)
     * 2. Lookup by key_id (for sk_* private keys)
     * 3. Fallback: lookup by public_key (for pk_* public keys)
     * 4. For private keys, hash and compare with hash_equals()
     * 5. Check status is 'active' and not expired
     * 6. Update last_used_at (throttled)
     * 7. Cache the result
     *
     * @return LocalApiKey|null  The validated key model, or null if invalid
     */
    public function validate(string $rawKey): ?LocalApiKey
    {
        $cacheEnabled = config('remonode.cache_enabled', true);
        $cacheTtl = (int) config('remonode.cache_ttl', 60);

        // Fast path: check cache (use hashed key to avoid exposing raw secret in Redis)
        if ($cacheEnabled) {
            $cacheKey = 'remonode_key:' . hash('sha256', $rawKey);
            $cached = Cache::get($cacheKey);
            if ($cached instanceof LocalApiKey && $cached->isActive()) {
                $this->touchLastUsed($cached);
                return $cached;
            }
        }

        $apiKey = $this->lookupAndVerify($rawKey);

        if ($apiKey) {
            $this->touchLastUsed($apiKey);

            if ($cacheEnabled) {
                $cacheKey = 'remonode_key:' . hash('sha256', $rawKey);
                Cache::put($cacheKey, $apiKey, now()->addMinutes($cacheTtl));
            }
        }

        return $apiKey;
    }

    /**
     * Look up and verify a key in the local database.
     */
    private function lookupAndVerify(string $rawKey): ?LocalApiKey
    {
        // For private keys (sk_*), use prefix-based lookup for indexed performance
        if (str_starts_with($rawKey, 'sk_')) {
            return $this->verifySecretKey($rawKey);
        }

        // For public keys (pk_*), direct lookup
        if (str_starts_with($rawKey, 'pk_')) {
            return $this->verifyPublicKey($rawKey);
        }

        return null;
    }

    /**
     * Verify a secret key using prefix narrowing + hash comparison.
     */
    private function verifySecretKey(string $rawKey): ?LocalApiKey
    {
        $secretPrefix = $this->generator->extractSecretPrefix($rawKey);
        $hash = $this->generator->hashSecret($rawKey);

        // Fast indexed lookup by prefix, then constant-time hash comparison
        $candidates = LocalApiKey::where('secret_prefix', $secretPrefix)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        foreach ($candidates as $candidate) {
            if (hash_equals($candidate->secret_hash, $hash)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Verify a public key by direct lookup.
     */
    private function verifyPublicKey(string $rawKey): ?LocalApiKey
    {
        $key = LocalApiKey::where('public_key', $rawKey)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        return $key;
    }

    /**
     * Throttle last_used_at updates to at most once per minute.
     */
    private function touchLastUsed(LocalApiKey $key): void
    {
        if ($key->last_used_at === null || $key->last_used_at->diffInMinutes(now()) >= 1) {
            $key->update(['last_used_at' => now()]);
        }
    }
}
