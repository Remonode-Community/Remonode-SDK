<?php

namespace Remonode\SDK\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Remonode\SDK\RemonodeServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RemonodeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('remonode.sync_to_portal', false);
        $app['config']->set('remonode.cache_enabled', false);
        $app['config']->set('remonode.enforcement', true);
        $app['config']->set('remonode.user_model', 'Illuminate\Foundation\Auth\User');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
