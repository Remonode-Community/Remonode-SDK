<?php

namespace Remonode\SDK\Tests\Unit;

use Remonode\SDK\Tests\TestCase;
use Remonode\SDK\Services\KeyGenerationService;

class KeyGenerationServiceTest extends TestCase
{
    private KeyGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('remonode.key_generation.public_prefix', 'pk_');
        $this->app['config']->set('remonode.key_generation.secret_prefix', 'sk_');
        $this->app['config']->set('remonode.key_generation.public_random_length', 32);
        $this->app['config']->set('remonode.key_generation.secret_random_length', 40);
        $this->app['config']->set('remonode.key_generation.hash_algo', 'sha256');
        $this->app['config']->set('remonode.key_generation.key_id_length', 16);
        $this->app['config']->set('remonode.key_generation.secret_lookup_length', 12);
        $this->service = new KeyGenerationService();
    }

    public function test_generate_public_key_has_prefix(): void
    {
        $key = $this->service->generatePublicKey();
        $this->assertStringStartsWith('pk_', $key);
        $this->assertGreaterThan(3, strlen($key));
    }

    public function test_generate_secret_key_has_prefix(): void
    {
        $key = $this->service->generateSecretKey();
        $this->assertStringStartsWith('sk_', $key);
        $this->assertGreaterThan(3, strlen($key));
    }

    public function test_generate_keys_are_unique(): void
    {
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $keys[] = $this->service->generateSecretKey();
        }
        $this->assertCount(100, array_unique($keys));
    }

    public function test_hash_secret_is_deterministic(): void
    {
        $secret = 'sk_test1234567890abcdef';
        $hash1 = $this->service->hashSecret($secret);
        $hash2 = $this->service->hashSecret($secret);
        $this->assertEquals($hash1, $hash2);
    }

    public function test_hash_secret_uses_sha256(): void
    {
        $secret = 'sk_test';
        $hash = $this->service->hashSecret($secret);
        $this->assertEquals(hash('sha256', $secret), $hash);
    }

    public function test_extract_key_id_from_secret(): void
    {
        $key = $this->service->generateSecretKey();
        $keyId = $this->service->extractKeyId($key);
        $this->assertStringStartsWith('sk_', $keyId);
        $this->assertLessThanOrEqual(strlen($key), strlen($keyId));
    }

    public function test_extract_secret_prefix(): void
    {
        $key = 'sk_abcdefghijklmnop1234567890extra';
        $prefix = $this->service->extractSecretPrefix($key);
        $this->assertEquals('abcdefghijkl', $prefix);
    }

    public function test_generate_key_pair_returns_all_fields(): void
    {
        $pair = $this->service->generateKeyPair();
        $this->assertArrayHasKey('public_key', $pair);
        $this->assertArrayHasKey('secret_key', $pair);
        $this->assertArrayHasKey('key_id', $pair);
        $this->assertArrayHasKey('secret_prefix', $pair);
        $this->assertStringStartsWith('pk_', $pair['public_key']);
        $this->assertStringStartsWith('sk_', $pair['secret_key']);
    }

    public function test_public_prefix_and_secret_prefix(): void
    {
        $this->assertEquals('pk_', $this->service->generatePublicPrefix());
        $this->assertEquals('sk_', $this->service->generateSecretPrefix());
    }
}
