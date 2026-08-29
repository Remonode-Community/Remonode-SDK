<?php

namespace Remonode\SDK;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Remonode\SDK\Models\LocalApiKey generate(int $userId, ?string $name = null, ?string $expiresAt = null)
 * @method static array rotate(\Remonode\SDK\Models\LocalApiKey|string $key)
 * @method static void revoke(\Remonode\SDK\Models\LocalApiKey|string $key)
 * @method static \Illuminate\Database\Eloquent\Collection listForUser(int $userId)
 * @method static \Remonode\SDK\Models\LocalApiKey|null findByKeyId(string $keyId)
 * @method static \Remonode\SDK\Models\LocalApiKey|null findByPublicKey(string $publicKey)
 * @method static \Remonode\SDK\Models\LocalApiKey|null validate(string $rawKey)
 * @method static bool hasActiveKeys(int $userId)
 * @method static bool canRevoke(\Remonode\SDK\Models\LocalApiKey $key)
 * @method static array register(string $appName, string $ownerEmail, ?string $ownerName = null)
 * @method static array checkQuota()
 * @method static array getPlans()
 * @method static array upgradePlan(string $planCode)
 *
 * @see \Remonode\SDK\Services\ApiKeyManager
 * @see \Remonode\SDK\Services\KeyValidator
 * @see \Remonode\SDK\Services\RemonodeManager
 * @see \Remonode\SDK\Services\WebhookService
 */
class RemonodeFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'remonode.keys';
    }

    /**
     * Access the webhook service directly.
     */
    public static function webhooks(): \Remonode\SDK\Services\WebhookService
    {
        return app(\Remonode\SDK\Services\WebhookService::class);
    }
}
