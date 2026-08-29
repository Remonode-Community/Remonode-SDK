<?php

namespace Remonode\SDK\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Remonode\SDK\Models\UsageLog;

class LogApiUsage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        private readonly array $data,
    ) {}

    public function handle(): void
    {
        UsageLog::create($this->data);
    }

    public function failed(\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::warning('Failed to log API usage', [
            'api_key_id' => $this->data['api_key_id'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
