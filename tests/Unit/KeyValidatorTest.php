<?php

namespace Remonode\SDK\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Remonode\SDK\Models\LocalApiKey;
use Remonode\SDK\Services\KeyGenerationService;
use Remonode\SDK\Services\KeyValidator;
use Remonode\SDK\Tests\TestCase;

class KeyValidatorTest extends TestCase
{
    private KeyValidator $validator;
    private KeyGenerationService $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('remonode.cache_enabled', false);
        $this->generator = new KeyGenerationService();
        $this->validator = new KeyValidator($this->generator);
    }

    private function createActiveKey(int $userId = 1): array
    {
        $pair = $this->generator->generateKeyPair();
        $key = LocalApiKey::create([
            'user_id' => $userId,
            'key_id' => $pair['key_id'],
            'secret_prefix' => $pair['secret_prefix'],
            'public_key' => $pair['public_key'],
            'secret_hash' => $this->generator->hashSecret($pair['secret_key']),
            'secret_last_four' => substr($pair['secret_key'], -4),
            'name' => 'Test Key',
            'status' => 'active',
            'environment' => 'sandbox',
        ]);

        return ['key' => $key, 'raw_secret' => $pair['secret_key'], 'public_key' => $pair['public_key']];
    }

    public function test_validate_with_valid_secret_key(): void
    {
        $created = $this->createActiveKey();
        $result = $this->validator->validate($created['raw_secret']);
        $this->assertNotNull($result);
        $this->assertEquals($created['key']->id, $result->id);
    }

    public function test_validate_with_valid_public_key(): void
    {
        $created = $this->createActiveKey();
        $result = $this->validator->validate($created['public_key']);
        $this->assertNotNull($result);
        $this->assertEquals($created['key']->id, $result->id);
    }

    public function test_validate_returns_null_for_invalid_key(): void
    {
        $result = $this->validator->validate('sk_invalid_key_1234567890');
        $this->assertNull($result);
    }

    public function test_validate_returns_null_for_revoked_key(): void
    {
        $created = $this->createActiveKey();
        $created['key']->update(['status' => 'revoked']);
        $result = $this->validator->validate($created['raw_secret']);
        $this->assertNull($result);
    }

    public function test_validate_returns_null_for_expired_key(): void
    {
        $created = $this->createActiveKey();
        $created['key']->update(['expires_at' => now()->subDay()]);
        $result = $this->validator->validate($created['raw_secret']);
        $this->assertNull($result);
    }

    public function test_validate_returns_key_for_non_expired_key(): void
    {
        $created = $this->createActiveKey();
        $created['key']->update(['expires_at' => now()->addDay()]);
        $result = $this->validator->validate($created['raw_secret']);
        $this->assertNotNull($result);
    }

    public function test_validate_uses_cache_when_enabled(): void
    {
        $this->app['config']->set('remonode.cache_enabled', true);
        $this->app['config']->set('remonode.cache_ttl', 60);

        $validator = new KeyValidator($this->generator);
        $created = $this->createActiveKey();

        // First call hits DB
        $result1 = $validator->validate($created['raw_secret']);
        $this->assertNotNull($result1);

        // Cache key should exist
        $cacheKey = 'remonode_key:' . hash('sha256', $created['raw_secret']);
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_validate_cache_key_does_not_expose_raw_secret(): void
    {
        $this->app['config']->set('remonode.cache_enabled', true);
        $this->app['config']->set('remonode.cache_ttl', 60);

        $validator = new KeyValidator($this->generator);
        $created = $this->createActiveKey();

        $validator->validate($created['raw_secret']);

        // The cache key uses a SHA-256 hash, not the raw secret
        $expectedCacheKey = 'remonode_key:' . hash('sha256', $created['raw_secret']);
        $this->assertNotEquals($created['raw_secret'], hash('sha256', $created['raw_secret']));
    }

    public function test_validate_multiple_keys_for_same_user(): void
    {
        $created1 = $this->createActiveKey(1);
        $created2 = $this->createActiveKey(1);

        $result1 = $this->validator->validate($created1['raw_secret']);
        $result2 = $this->validator->validate($created2['raw_secret']);

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
        $this->assertNotEquals($result1->id, $result2->id);
    }

    public function test_validate_different_users_keys(): void
    {
        $created1 = $this->createActiveKey(1);
        $created2 = $this->createActiveKey(2);

        $result1 = $this->validator->validate($created1['raw_secret']);
        $result2 = $this->validator->validate($created2['raw_secret']);

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
        $this->assertEquals(1, $result1->user_id);
        $this->assertEquals(2, $result2->user_id);
    }
}
