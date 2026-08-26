<?php

namespace Remonode\SDK\Tests\Unit;

use Remonode\SDK\Models\LocalApiKey;
use Remonode\SDK\Services\ApiKeyManager;
use Remonode\SDK\Services\KeyGenerationService;
use Remonode\SDK\Tests\TestCase;

class ApiKeyManagerTest extends TestCase
{
    private ApiKeyManager $manager;
    private KeyGenerationService $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('remonode.sync_to_portal', false);
        $this->app['config']->set('remonode.environment', 'sandbox');
        $this->app['config']->set('remonode.app_uuid', 'test-uuid');
        $this->generator = new KeyGenerationService();
        $this->manager = new ApiKeyManager($this->generator, null);
    }

    public function test_generate_creates_key_in_database(): void
    {
        $result = $this->manager->generate(1, 'Test Key');

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('raw_secret', $result);
        $this->assertArrayHasKey('public_key', $result);
        $this->assertInstanceOf(LocalApiKey::class, $result['key']);
        $this->assertDatabaseHas('remonode_api_keys', [
            'user_id' => 1,
            'name' => 'Test Key',
            'status' => 'active',
        ]);
    }

    public function test_generate_returns_valid_secret_key(): void
    {
        $result = $this->manager->generate(1);
        $this->assertStringStartsWith('sk_', $result['raw_secret']);
        $this->assertStringStartsWith('pk_', $result['public_key']);
    }

    public function test_generate_stores_hashed_secret(): void
    {
        $result = $this->manager->generate(1);
        $storedHash = $result['key']->secret_hash;
        $expectedHash = hash('sha256', $result['raw_secret']);
        $this->assertEquals($expectedHash, $storedHash);
    }

    public function test_generate_sets_correct_environment(): void
    {
        $result = $this->manager->generate(1);
        $this->assertEquals('sandbox', $result['key']->environment);
    }

    public function test_generate_sets_expires_at(): void
    {
        $expires = now()->addYear()->toDateTimeString();
        $result = $this->manager->generate(1, 'Expiring Key', $expires);
        $this->assertNotNull($result['key']->expires_at);
    }

    public function test_rotate_revokes_old_and_creates_new(): void
    {
        $original = $this->manager->generate(1, 'Original Key');
        $oldKeyId = $original['key']->key_id;

        $rotated = $this->manager->rotate($original['key']);

        $this->assertArrayHasKey('key', $rotated);
        $this->assertArrayHasKey('old_key', $rotated);
        $this->assertNotEquals($oldKeyId, $rotated['key']->key_id);
        // The old key is replicated before status change, so old_key retains original status
        $this->assertDatabaseHas('remonode_api_keys', [
            'id' => $original['key']->id,
            'status' => 'rotated',
        ]);
    }

    public function test_rotate_by_key_id_string(): void
    {
        $original = $this->manager->generate(1);
        $rotated = $this->manager->rotate($original['key']->key_id);
        $this->assertNotEquals($original['key']->key_id, $rotated['key']->key_id);
    }

    public function test_revoke_sets_status(): void
    {
        $created = $this->manager->generate(1);
        $this->manager->revoke($created['key']);

        $this->assertDatabaseHas('remonode_api_keys', [
            'id' => $created['key']->id,
            'status' => 'revoked',
        ]);
    }

    public function test_revoke_by_key_id_string(): void
    {
        $created = $this->manager->generate(1);
        $this->manager->revoke($created['key']->key_id);

        $this->assertDatabaseHas('remonode_api_keys', [
            'id' => $created['key']->id,
            'status' => 'revoked',
        ]);
    }

    public function test_list_for_user_returns_user_keys(): void
    {
        $this->manager->generate(1, 'Key 1');
        $this->manager->generate(1, 'Key 2');
        $this->manager->generate(2, 'Key 3');

        $keys = $this->manager->listForUser(1);
        $this->assertCount(2, $keys);
    }

    public function test_find_by_key_id(): void
    {
        $created = $this->manager->generate(1);
        $found = $this->manager->findByKeyId($created['key']->key_id);
        $this->assertNotNull($found);
        $this->assertEquals($created['key']->id, $found->id);
    }

    public function test_find_by_key_id_returns_null_for_missing(): void
    {
        $found = $this->manager->findByKeyId('sk_nonexistent');
        $this->assertNull($found);
    }

    public function test_find_by_public_key(): void
    {
        $created = $this->manager->generate(1);
        $found = $this->manager->findByPublicKey($created['public_key']);
        $this->assertNotNull($found);
        $this->assertEquals($created['key']->id, $found->id);
    }

    public function test_has_active_keys(): void
    {
        $this->assertFalse($this->manager->hasActiveKeys(1));
        $this->manager->generate(1);
        $this->assertTrue($this->manager->hasActiveKeys(1));
    }

    public function test_can_revoke_with_multiple_active_keys(): void
    {
        $key1 = $this->manager->generate(1);
        $key2 = $this->manager->generate(1);
        $this->assertTrue($this->manager->canRevoke($key1['key']));
    }

    public function test_cannot_revoke_last_active_key(): void
    {
        $key = $this->manager->generate(1);
        $this->assertFalse($this->manager->canRevoke($key['key']));
    }

    public function test_cannot_revoke_already_inactive_key(): void
    {
        $key = $this->manager->generate(1);
        $key['key']->update(['status' => 'revoked']);
        $this->assertFalse($this->manager->canRevoke($key['key']));
    }
}
