<?php

namespace Remonode\SDK\Commands;

use Illuminate\Console\Command;
use Remonode\SDK\Services\RemonodeClient;

class TestConnectionCommand extends Command
{
    protected $signature = 'remonode:test-connection';

    protected $description = 'Test connectivity to the Remonode portal';

    public function handle(?RemonodeClient $client): int
    {
        $this->info('Testing connection to Remonode portal...');
        $this->info('  URL: ' . config('remonode.portal_url', 'NOT SET'));
        $this->info('  Portal Key: ' . (config('remonode.portal_key') ? '***configured***' : 'NOT SET'));

        if (! $client) {
            $this->error('Portal client not configured. Set REMONODE_PORTAL_URL and REMONODE_PORTAL_KEY.');
            return self::FAILURE;
        }

        try {
            $response = $client->healthCheck();
            $this->info('Connection successful!');
            $this->info('  Response: ' . json_encode($response));
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Connection failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
