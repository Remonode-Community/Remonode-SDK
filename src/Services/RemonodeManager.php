<?php

namespace Remonode\SDK\Services;

use Remonode\SDK\Exceptions\RemonodeConnectionException;
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
     * Returns success with local-only mode if portal is not configured.
     */
    public function register(string $appName, string $ownerEmail, ?string $ownerName = null): array
    {
        if (! $this->client) {
            return [
                'success' => true,
                'mode' => 'local_only',
                'message' => 'App registered locally. Portal sync skipped (REMONODE_PORTAL_KEY not configured).',
                'data' => [
                    'app_name' => $appName,
                    'owner_email' => $ownerEmail,
                    'owner_name' => $ownerName,
                ],
            ];
        }

        try {
            // Send app URL and portal key so the portal can provision keys back to us
            // Use APP_URL as the connected app's public URL
            $registeredUrl = config('app.url');
            $portalKey = config('remonode.portal_key');

            return $this->client->registerApplication(
                $appName,
                $ownerEmail,
                $ownerName,
                $registeredUrl,
                $portalKey,
            );
        } catch (RemonodeConnectionException $e) {
            return [
                'success' => false,
                'mode' => 'local_only',
                'message' => 'App registered locally but portal sync failed. Remonode portal unreachable.',
                'error' => $e->getMessage(),
                'data' => [
                    'app_name' => $appName,
                    'owner_email' => $ownerEmail,
                    'owner_name' => $ownerName,
                ],
            ];
        }
    }

    /**
     * Manually sync a key's metadata to the Remonode portal.
     */
    public function syncToPortal(LocalApiKey $key): ?array
    {
        if (! $this->client) {
            return null;
        }

        try {
            return $this->client->syncKeyMetadata([
                'key_id' => $key->key_id,
                'public_key' => $key->public_key,
                'user_id' => $key->user_id,
                'name' => $key->name,
                'status' => $key->status,
                'environment' => $key->environment,
            ]);
        } catch (RemonodeConnectionException $e) {
            return null;
        }
    }

    /**
     * Check the connected app's current plan, usage, and quota status.
     */
    public function checkQuota(): array
    {
        if (! $this->client) {
            return [
                'success' => false,
                'mode' => 'local_only',
                'message' => 'Quota check requires portal connection. Set REMONODE_PORTAL_URL and REMONODE_PORTAL_KEY in your .env file.',
            ];
        }

        try {
            return $this->client->checkQuota();
        } catch (RemonodeConnectionException $e) {
            return [
                'success' => false,
                'mode' => 'local_only',
                'message' => 'Quota check failed. Remonode portal unreachable.',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get available plans from the Remonode portal.
     */
    public function getPlans(): array
    {
        if (! $this->client) {
            return [
                'success' => false,
                'mode' => 'local_only',
                'message' => 'Plans require portal connection. Set REMONODE_PORTAL_URL and REMONODE_PORTAL_KEY in your .env file.',
            ];
        }

        try {
            return $this->client->getPlans();
        } catch (RemonodeConnectionException $e) {
            return [
                'success' => false,
                'mode' => 'local_only',
                'message' => 'Plans fetch failed. Remonode portal unreachable.',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upgrade the connected app's plan.
     */
    public function upgradePlan(string $planCode): array
    {
        if (! $this->client) {
            return [
                'success' => false,
                'mode' => 'local_only',
                'message' => 'Plan upgrade requires portal connection. Set REMONODE_PORTAL_URL and REMONODE_PORTAL_KEY in your .env file.',
            ];
        }

        try {
            return $this->client->upgradePlan($planCode);
        } catch (RemonodeConnectionException $e) {
            return [
                'success' => false,
                'mode' => 'local_only',
                'message' => 'Plan upgrade failed. Remonode portal unreachable.',
                'error' => $e->getMessage(),
            ];
        }
    }
}
