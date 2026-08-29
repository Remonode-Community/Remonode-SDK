<?php

namespace Remonode\SDK\Commands;

use Illuminate\Console\Command;
use Remonode\SDK\Models\LocalApiKey;
use Remonode\SDK\Services\WebhookService;

class ExpireKeysCommand extends Command
{
    protected $signature = 'remonode:expire-keys';

    protected $description = 'Auto-revoke expired API keys and send webhook notifications';

    public function handle(WebhookService $webhook): int
    {
        $expiredKeys = LocalApiKey::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expiredKeys->isEmpty()) {
            $this->info('No expired keys found.');
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($expiredKeys as $key) {
            $key->update(['status' => 'expired']);

            // Send webhook notification
            $webhookUrl = config('remonode.webhook_url');
            if ($webhookUrl) {
                $webhook->send(
                    event: 'key.expired',
                    payload: [
                        'key_id' => $key->key_id,
                        'user_id' => $key->user_id,
                        'name' => $key->name,
                        'expired_at' => $key->expires_at->toISOString(),
                    ],
                    url: $webhookUrl,
                );
            }

            $count++;
            $this->line("  Expired: {$key->key_id} ({$key->name})");
        }

        $this->info("Done. Expired {$count} key(s).");

        return self::SUCCESS;
    }
}
