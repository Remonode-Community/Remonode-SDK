<?php

namespace Remonode\SDK\Services;

use Illuminate\Support\Facades\Log;
use Remonode\SDK\Models\LocalApiKey;
use Remonode\SDK\Exceptions\RemonodeConnectionException;

class ApiKeyManager
{
    public function __construct(
        private readonly KeyGenerationService $generator,
        private readonly ?RemonodeClient $client = null,
    ) {}

    /**
     * Generate a new API key pair LOCALLY for a user.
     *
     * Keys are generated in YOUR application. Remonode is NOT called.
     * Optionally syncs key metadata to Remonode after local storage.
     *
     * @return array{key: LocalApiKey, raw_secret: string, public_key: string}
     */
    public function generate(
        int $userId,
        ?string $name = null,
        ?string $expiresAt = null,
    ): array {
        $pair = $this->generator->generateKeyPair();
        $environment = config('remonode.environment', 'production');
        $appUuid = config('remonode.app_uuid', '');

        $key = LocalApiKey::create([
            'user_id' => $userId,
            'app_uuid' => $appUuid,
            'key_id' => $pair['key_id'],
            'secret_prefix' => $pair['secret_prefix'],
            'public_key' => $pair['public_key'],
            'secret_hash' => $this->generator->hashSecret($pair['secret_key']),
            'secret_last_four' => substr($pair['secret_key'], -4),
            'name' => $name,
            'status' => 'active',
            'environment' => $environment,
            'expires_at' => $expiresAt,
        ]);

        // Optionally sync metadata to Remonode (fire-and-forget)
        if (config('remonode.sync_to_portal', true) && $this->client) {
            $this->syncToPortal($key);
        }

        return [
            'key' => $key,
            'raw_secret' => $pair['secret_key'],
            'public_key' => $pair['public_key'],
        ];
    }

    /**
     * Rotate a key pair: revoke old, generate new.
     *
     * @param  LocalApiKey|string  $key  Model or key_id
     * @return array{key: LocalApiKey, raw_secret: string, public_key: string, old_key: LocalApiKey}
     */
    public function rotate($key): array
    {
        if (is_string($key)) {
            $key = LocalApiKey::where('key_id', $key)->firstOrFail();
        }

        $oldKey = $key->replicate();
        $key->update(['status' => 'rotated']);

        $newKeys = $this->generate(
            userId: $key->user_id,
            name: $key->name,
        );

        // Sync revocation of old key to portal
        if (config('remonode.sync_to_portal', true) && $this->client && $oldKey->remote_id) {
            try {
                $this->client->syncKeyStatus($oldKey->remote_id, 'rotated');
            } catch (RemonodeConnectionException $e) {
                Log::warning('Failed to sync old key rotation to Remonode', ['error' => $e->getMessage()]);
            }
        }

        return [
            'key' => $newKeys['key'],
            'raw_secret' => $newKeys['raw_secret'],
            'public_key' => $newKeys['public_key'],
            'old_key' => $oldKey,
        ];
    }

    /**
     * Revoke a key pair.
     *
     * @param  LocalApiKey|string  $key  Model or key_id
     */
    public function revoke($key): void
    {
        if (is_string($key)) {
            $key = LocalApiKey::where('key_id', $key)->firstOrFail();
        }

        $key->update(['status' => 'revoked']);

        // Sync revocation to portal
        if (config('remonode.sync_to_portal', true) && $this->client && $key->remote_id) {
            try {
                $this->client->syncKeyStatus($key->remote_id, 'revoked');
            } catch (RemonodeConnectionException $e) {
                Log::warning('Failed to sync key revocation to Remonode', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * List all keys for a user.
     */
    public function listForUser(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return LocalApiKey::forUser($userId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Find a key by its key_id.
     */
    public function findByKeyId(string $keyId): ?LocalApiKey
    {
        return LocalApiKey::where('key_id', $keyId)->first();
    }

    /**
     * Find a key by its full public key.
     */
    public function findByPublicKey(string $publicKey): ?LocalApiKey
    {
        return LocalApiKey::where('public_key', $publicKey)->first();
    }

    /**
     * Check if a user has any active keys.
     */
    public function hasActiveKeys(int $userId): bool
    {
        return LocalApiKey::forUser($userId)->active()->exists();
    }

    /**
     * Prevent revoking the last active key (lockout protection).
     */
    public function canRevoke(LocalApiKey $key): bool
    {
        if (! $key->isActive()) {
            return false;
        }

        return LocalApiKey::forUser($key->user_id)
            ->active()
            ->where('id', '!=', $key->id)
            ->exists();
    }

    /**
     * Sync key metadata to the Remonode portal.
     */
    private function syncToPortal(LocalApiKey $key): void
    {
        try {
            $result = $this->client->syncKeyMetadata([
                'key_id' => $key->key_id,
                'public_key' => $key->public_key,
                'user_id' => $key->user_id,
                'name' => $key->name,
                'status' => $key->status,
                'environment' => $key->environment,
            ]);

            if (isset($result['data']['id'])) {
                $key->update(['remote_id' => $result['data']['id']]);
            }
        } catch (RemonodeConnectionException $e) {
            Log::warning('Failed to sync key metadata to Remonode', [
                'key_id' => $key->key_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
