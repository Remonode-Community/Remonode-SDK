<?php

namespace Remonode\SDK\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Remonode\SDK\Jobs\RetryWebhookDelivery;
use Remonode\SDK\Models\WebhookDelivery;

class WebhookService
{
    /**
     * Send a webhook event with automatic retry and delivery logging.
     *
     * @param  string  $event  Event name (e.g., 'key.generated')
     * @param  array  $payload  Event data
     * @param  string  $url  Target webhook URL
     * @param  array  $headers  Additional headers
     * @return WebhookDelivery
     */
    public function send(
        string $event,
        array $payload,
        string $url,
        array $headers = [],
    ): WebhookDelivery {
        $secret = config('remonode.webhook_secret', '');
        $algo = config('remonode.webhook_signature_algo', 'sha512');
        $maxAttempts = config('remonode.webhook_max_attempts', 5);

        // Generate signature
        $body = json_encode($payload);
        $timestamp = now()->toImmutable()->getTimestamp();
        $signature = hash_hmac($algo, "{$timestamp}.{$body}", $secret);

        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'X-Remonode-Event' => $event,
            'X-Remonode-Signature' => "t={$timestamp},v1={$signature}",
            'X-Remonode-Delivery' => bin2hex(random_bytes(16)),
            'User-Agent' => 'Remonode-SDK/' . ($this->getVersion()),
        ];

        $allHeaders = array_merge($defaultHeaders, $headers);

        // Log the delivery attempt
        $delivery = WebhookDelivery::create([
            'event' => $event,
            'url' => $url,
            'payload' => $payload,
            'headers' => $allHeaders,
            'max_attempts' => $maxAttempts,
            'signature_algo' => $algo,
            'attempt' => 1,
            'created_at' => now(),
        ]);

        try {
            $startTime = microtime(true);

            $response = Http::withHeaders($allHeaders)
                ->timeout(30)
                ->post($url, $payload);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $delivery->update([
                'status_code' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 10000),
                'duration_ms' => $durationMs,
                'success' => $response->successful(),
                'delivered_at' => $response->successful() ? now() : null,
                'next_retry_at' => $response->successful()
                    ? null
                    : $this->calculateNextRetry(1),
            ]);

            // Schedule retry if failed and retries remain
            if (! $response->successful() && $maxAttempts > 1) {
                RetryWebhookDelivery::dispatch($delivery->id)
                    ->delay(now()->addSeconds(60));
            }

            return $delivery;

        } catch (\Exception $e) {
            $delivery->update([
                'error_message' => $e->getMessage(),
                'next_retry_at' => $maxAttempts > 1
                    ? $this->calculateNextRetry(1)
                    : null,
            ]);

            if ($maxAttempts > 1) {
                RetryWebhookDelivery::dispatch($delivery->id)
                    ->delay(now()->addSeconds(60));
            }

            Log::warning('Webhook delivery failed', [
                'event' => $event,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return $delivery;
        }
    }

    /**
     * Verify a webhook signature from a incoming request.
     */
    public function verify(
        string $body,
        string $signatureHeader,
        string $secret,
        int $tolerance = 300,
    ): bool {
        if (empty($signatureHeader) || empty($secret)) {
            return false;
        }

        // Parse signature header: t=timestamp,v1=signature
        $parts = [];
        foreach (explode(',', $signatureHeader) as $pair) {
            [$key, $value] = explode('=', $pair, 2);
            $parts[$key] = $value;
        }

        if (! isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        $signature = $parts['v1'];

        // Check timestamp tolerance
        if (abs(now()->getTimestamp() - $timestamp) > $tolerance) {
            return false;
        }

        // Try sha512 first, then sha256 for backward compatibility
        $expectedSha512 = hash_hmac('sha512', "{$timestamp}.{$body}", $secret);
        $expectedSha256 = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return hash_equals($expectedSha512, $signature) || hash_equals($expectedSha256, $signature);
    }

    /**
     * Rotate the webhook signature secret.
     * Returns the new secret to be stored in config.
     */
    public function rotateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get delivery history for a specific event or all events.
     */
    public function getDeliveries(
        ?string $event = null,
        int $limit = 50,
        bool $failuresOnly = false,
    ): \Illuminate\Database\Eloquent\Collection {
        $query = WebhookDelivery::query();

        if ($event) {
            $query->where('event', $event);
        }

        if ($failuresOnly) {
            $query->where('success', false)
                ->where('attempt', '>=', 1);
        }

        return $query->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Manually retry a failed delivery.
     */
    public function retry(int $deliveryId): ?WebhookDelivery
    {
        $delivery = WebhookDelivery::find($deliveryId);

        if (! $delivery || $delivery->success) {
            return null;
        }

        RetryWebhookDelivery::dispatch($delivery->id);

        return $delivery;
    }

    private function calculateNextRetry(int $attempt): \DateTimeInterface
    {
        $delays = [60, 300, 1800, 7200, 43200];
        $delay = $delays[min($attempt, count($delays) - 1)] ?? 43200;

        return now()->addSeconds($delay);
    }

    private function getVersion(): string
    {
        $path = dirname(__DIR__, 2) . '/composer.json';
        if (file_exists($path)) {
            $composer = json_decode(file_get_contents($path), true);
            return $composer['version'] ?? '1.0.0';
        }
        return '1.0.0';
    }
}
