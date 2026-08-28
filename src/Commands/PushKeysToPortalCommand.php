<?php

namespace Remonode\SDK\Commands;

use Illuminate\Console\Command;
use Remonode\SDK\Services\RemonodeClient;
use Remonode\SDK\Models\LocalApiKey;

class PushKeysToPortalCommand extends Command
{
    protected $signature = 'remonode:push-keys
                            {--id= : Push a specific key by key_id}
                            {--user= : Push keys for a specific user ID}
                            {--force : Re-push already synced keys}';

    protected $description = 'Push local API key metadata to the Remonode portal';

    public function handle(?RemonodeClient $client): int
    {
        if (! $client) {
            $this->error('Portal client not configured. Set REMONODE_PORTAL_URL and REMONODE_PORTAL_KEY in your .env.');
            return self::FAILURE;
        }

        $this->info('Pushing local API keys to Remonode portal...');
        $this->newLine();

        // Build query
        $query = LocalApiKey::query();

        if ($keyId = $this->option('id')) {
            $query->where('key_id', $keyId);
        } elseif ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        } elseif (! $this->option('force')) {
            // Only unsynced keys by default
            $query->whereNull('remote_id');
        }

        $keys = $query->get();

        if ($keys->isEmpty()) {
            $this->info('No keys to push.');
            return self::SUCCESS;
        }

        $pushed = 0;
        $failed = 0;

        foreach ($keys as $key) {
            try {
                $result = $client->syncKeyMetadata([
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

                $this->info("  ✓ Pushed: {$key->key_id} ({$key->name})");
                $pushed++;
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$key->key_id} — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done: {$pushed} pushed, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
