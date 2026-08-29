<?php

namespace Remonode\SDK;

use Illuminate\Support\ServiceProvider;
use Remonode\SDK\Services\RemonodeClient;
use Remonode\SDK\Services\ApiKeyManager;
use Remonode\SDK\Services\KeyValidator;
use Remonode\SDK\Services\KeyGenerationService;
use Remonode\SDK\Services\RemonodeManager;

class RemonodeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/remonode.php', 'remonode');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register SDK API routes directly in the router under the 'api' middleware group.
        // loadRoutesFrom() always applies the 'web' middleware group which includes CSRF,
        // blocking POST requests from external servers (like the Remonode portal).
        $this->app['router']->group([
            'prefix' => 'api/v1/remonode',
            'middleware' => ['api'],
        ], function () {
            require __DIR__ . '/../routes/api.php';
        });
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');

        // Register middleware
        $this->app['router']->aliasMiddleware('remonode.key', \Remonode\SDK\Http\Middleware\ValidateRemonodeKeyType::class);
        $this->app['router']->aliasMiddleware('remonode.portal', \Remonode\SDK\Http\Middleware\AuthenticatePortalKey::class);
        $this->app['router']->aliasMiddleware('remonode.rate_limit', \Remonode\SDK\Http\Middleware\RateLimitKey::class);
        $this->app['router']->aliasMiddleware('remonode.track_usage', \Remonode\SDK\Http\Middleware\TrackUsage::class);
        $this->app['router']->aliasMiddleware('remonode.scope', \Remonode\SDK\Http\Middleware\RequireScope::class);
        $this->app['router']->aliasMiddleware('remonode.environment', \Remonode\SDK\Http\Middleware\RequireEnvironment::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Remonode\SDK\Commands\TestConnectionCommand::class,
                \Remonode\SDK\Commands\SyncKeysCommand::class,
                \Remonode\SDK\Commands\PushKeysToPortalCommand::class,
                \Remonode\SDK\Commands\ExpireKeysCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/../config/remonode.php' => config_path('remonode.php'),
        ], 'remonode-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'remonode-migrations');
    }

    public function register(): void
    {
        // Core services — no external dependencies for key generation
        $this->app->singleton(KeyGenerationService::class);

        $this->app->singleton(RemonodeClient::class, function ($app) {
            $url = $app['config']['remonode.portal_url'];
            $key = $app['config']['remonode.portal_key'];

            if (blank($url) || blank($key)) {
                return null;
            }

            return new RemonodeClient(
                baseUrl: $url,
                portalKey: $key,
                timeout: (int) $app['config']['remonode.timeout'],
            );
        });

        $this->app->singleton(KeyValidator::class, function ($app) {
            return new KeyValidator(
                generator: $app->make(KeyGenerationService::class),
            );
        });

        $this->app->singleton(ApiKeyManager::class, function ($app) {
            return new ApiKeyManager(
                generator: $app->make(KeyGenerationService::class),
                client: $app->make(RemonodeClient::class),
            );
        });

        // Unified manager — the facade accessor
        $this->app->singleton(RemonodeManager::class, function ($app) {
            return new RemonodeManager(
                keys: $app->make(ApiKeyManager::class),
                validator: $app->make(KeyValidator::class),
                generator: $app->make(KeyGenerationService::class),
                client: $app->make(RemonodeClient::class),
            );
        });

        $this->app->alias(RemonodeManager::class, 'remonode.keys');

        // Webhook service
        $this->app->singleton(\Remonode\SDK\Services\WebhookService::class);
    }
}
