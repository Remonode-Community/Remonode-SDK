<?php

namespace Remonode\SDK\Tests\Unit;

use Remonode\SDK\Tests\TestCase;
use Remonode\SDK\Services\RemonodeManager;

class RemonodeManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('remonode.sync_to_portal', false);
        $this->app['config']->set('remonode.cache_enabled', false);
        $this->app['config']->set('remonode.environment', 'sandbox');
    }

    public function test_facade_resolves_manager(): void
    {
        $manager = $this->app->make('remonode.keys');
        $this->assertInstanceOf(RemonodeManager::class, $manager);
    }

    public function test_generate_through_manager(): void
    {
        /** @var RemonodeManager $manager */
        $manager = $this->app->make('remonode.keys');
        $result = $manager->generate(1, 'Manager Test');

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('raw_secret', $result);
        $this->assertArrayHasKey('public_key', $result);
        $this->assertEquals('Manager Test', $result['key']->name);
    }

    public function test_validate_through_manager(): void
    {
        /** @var RemonodeManager $manager */
        $manager = $this->app->make('remonode.keys');
        $created = $manager->generate(1);

        $result = $manager->validate($created['raw_secret']);
        $this->assertNotNull($result);
    }

    public function test_revoke_through_manager(): void
    {
        /** @var RemonodeManager $manager */
        $manager = $this->app->make('remonode.keys');
        $created = $manager->generate(1);

        $manager->revoke($created['key']);

        $this->assertDatabaseHas('remonode_api_keys', [
            'id' => $created['key']->id,
            'status' => 'revoked',
        ]);
    }

    public function test_rotate_through_manager(): void
    {
        /** @var RemonodeManager $manager */
        $manager = $this->app->make('remonode.keys');
        $original = $manager->generate(1);

        $rotated = $manager->rotate($original['key']);

        $this->assertNotEquals($original['key']->key_id, $rotated['key']->key_id);
    }

    public function test_list_for_user_through_manager(): void
    {
        /** @var RemonodeManager $manager */
        $manager = $this->app->make('remonode.keys');
        $manager->generate(1, 'Key 1');
        $manager->generate(1, 'Key 2');
        $manager->generate(2, 'Key 3');

        $keys = $manager->listForUser(1);
        $this->assertCount(2, $keys);
    }

    public function test_has_active_keys_through_manager(): void
    {
        /** @var RemonodeManager $manager */
        $manager = $this->app->make('remonode.keys');

        $this->assertFalse($manager->hasActiveKeys(1));
        $manager->generate(1);
        $this->assertTrue($manager->hasActiveKeys(1));
    }

    public function test_register_throws_without_client(): void
    {
        $this->expectException(\RuntimeException::class);
        /** @var RemonodeManager $manager */
        $manager = $this->app->make('remonode.keys');
        $manager->register('Test App', 'test@example.com');
    }
}
