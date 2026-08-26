<?php

namespace Remonode\SDK\Commands;

use Illuminate\Console\Command;
use Remonode\SDK\Services\RemonodeClient;
use Remonode\SDK\Models\LocalApiKey;

class SyncKeysCommand extends Command
{
    protected $signature = 'remonode:sync-keys
                            {--email= : Sync keys for a specific user email}
                            {--all : Sync all keys from the portal}';

    protected $description = 'Sync API key metadata from the Remonode portal to the local database';

    public function handle(?RemonodeClient $client): int
    {
        if (! $client) {
            $this->error('Portal client not configured. Set REMONODE_PORTAL_URL and REMONODE_PORTAL_KEY.');
            return self::FAILURE;
        }

        $this->info('Syncing API key metadata from Remonode portal...');

        try {
            $params = [];
            if ($email = $this->option('email')) {
                $params['email'] = $email;
            }

            $response = $client->listKeys($params);
            $keys = $response['data'] ?? [];

            $synced = 0;
            foreach ($keys as $keyData) {
                LocalApiKey::updateOrCreate(
                    ['key_id' => $keyData['key_id']],
                    [
                        'public_key' => $keyData['public_key'] ?? null,
                        'secret_hash' => $keyData['secret_hash'] ?? null,
                        'secret_prefix' => $keyData['secret_prefix'] ?? null,
                        'secret_last_four' => $keyData['secret_last_four'] ?? null,
                        'name' => $keyData['name'] ?? null,
                        'status' => $keyData['status'] ?? 'active',
                        'environment' => $keyData['environment'] ?? 'production',
                        'remote_id' => $keyData['id'] ?? null,
                        'expires_at' => $keyData['expires_at'] ?? null,
                    ]
                );

                $this->info("  Synced: {$keyData['key_id']}");
                $synced++;
            }

            $this->info("Synced {$synced} keys.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
