<?php

namespace Remonode\SDK\Services;

use Remonode\SDK\Models\LocalApiKey;

/**
 * Unified API for the Remonode Connector package.
 *
 * Provides local key generation, validation, rotation, revocation,
 * and optional sync to the Remonode portal.
 */
class RemonodeManager
{
    public function __construct(
        private readonly ApiKeyManager $keys,
        private readonly KeyValidator $validator,
        private readonly KeyGenerationService $generator,
        private readonly ?RemonodeClient $client = null,
    ) {}

    /**
     * Generate a new API key pair LOCALLY for a user.
     *
     * @return array{key: LocalApiKey, raw_secret: string, public_key: string}
     */
    public function generate(
        int $userId,
        ?string $name = null,
        ?string $expiresAt = null,
    ): array {
        return $this->keys->generate($userId, $name, $expiresAt);
    }

    /**
     * Rotate a key pair: revoke old, generate new.
     *
     * @return array{key: LocalApiKey, raw_secret: string, public_key: string, old_key: LocalApiKey}
     */
    public function rotate($key): array
    {
        return $this->keys->rotate($key);
    }

    /**
     * Revoke a key pair.
     */
    public function revoke($key): void
    {
        $this->keys->revoke($key);
    }

    /**
     * Validate an API key against the local database.
     */
    public function validate(string $rawKey): ?LocalApiKey
    {
        return $this->validator->validate($rawKey);
    }

    /**
     * List all keys for a user.
     */
    public function listForUser(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->keys->listForUser($userId);
    }

    /**
     * Find a key by key_id.
     */
    public function findByKeyId(string $keyId): ?LocalApiKey
    {
        return $this->keys->findByKeyId($keyId);
    }

    /**
     * Find a key by public key.
     */
    public function findByPublicKey(string $publicKey): ?LocalApiKey
    {
        return $this->keys->findByPublicKey($publicKey);
    }

    /**
     * Check if a user has active keys.
     */
    public function hasActiveKeys(int $userId): bool
    {
        return $this->keys->hasActiveKeys($userId);
    }

    /**
     * Check if a key can be revoked (not the last active one).
     */
    public function canRevoke(LocalApiKey $key): bool
    {
        return $this->keys->canRevoke($key);
    }

    /**
     * Register this application with the Remonode portal.
     *
     * Call once during initial setup.
     */
    public function register(string $appName, string $ownerEmail, ?string $ownerName = null): array
    {
        if (! $this->client) {
            throw new \RuntimeException(
                'Remonode portal client not configured. Set REMONODE_PORTAL_URL and REMONODE_PORTAL_KEY in your .env file.'
            );
        }

        return $this->client->registerApplication($appName, $ownerEmail, $ownerName);
    }

    /**
     * Manually sync a key's metadata to the Remonode portal.
     */
    public function syncToPortal(LocalApiKey $key): ?array
    {
        if (! $this->client) {
            return null;
        }

        return $this->client->syncKeyMetadata([
            'key_id' => $key->key_id,
            'public_key' => $key->public_key,
            'user_id' => $key->user_id,
            'name' => $key->name,
            'status' => $key->status,
            'environment' => $key->environment,
        ]);
    }
}
