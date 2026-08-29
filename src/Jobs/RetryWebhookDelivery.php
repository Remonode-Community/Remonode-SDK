<?php

namespace Remonode\SDK\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Remonode\SDK\Models\WebhookDelivery;

class RetryWebhookDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $backoff = 10;

    public function __construct(
        private readonly int $deliveryId,
    ) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if (! $delivery || $delivery->success) {
            return;
        }

        if ($delivery->attempt >= $delivery->max_attempts) {
            Log::warning('Webhook delivery max attempts reached', [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'attempt' => $delivery->attempt,
            ]);
            return;
        }

        $delivery->update([
            'attempt' => $delivery->attempt + 1,
            'next_retry_at' => null,
        ]);

        try {
            $startTime = microtime(true);

            $response = Http::withHeaders($delivery->headers ?? [])
                ->timeout(30)
                ->post($delivery->url, $delivery->payload);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $delivery->update([
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'duration_ms' => $durationMs,
                'success' => $response->successful(),
                'delivered_at' => $response->successful() ? now() : null,
                'next_retry_at' => $response->successful()
                    ? null
                    : $this->calculateNextRetry($delivery->attempt),
            ]);

            if (! $response->successful()) {
                Log::warning('Webhook delivery failed', [
                    'id' => $delivery->id,
                    'status' => $response->status(),
                    'attempt' => $delivery->attempt,
                ]);
            }

        } catch (\Exception $e) {
            $delivery->update([
                'error_message' => $e->getMessage(),
                'next_retry_at' => $this->calculateNextRetry($delivery->attempt),
            ]);

            Log::warning('Webhook delivery error', [
                'id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Exponential backoff: 1min, 5min, 30min, 2hr, 12hr
     */
    private function calculateNextRetry(int $attempt): \DateTimeInterface
    {
        $delays = [60, 300, 1800, 7200, 43200];
        $delay = $delays[min($attempt, count($delays) - 1)] ?? 43200;

        return now()->addSeconds($delay);
    }
}
