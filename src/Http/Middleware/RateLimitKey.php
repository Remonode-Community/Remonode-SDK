<?php

namespace Remonode\SDK\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Remonode\SDK\Models\UsageLog;

class RateLimitKey
{
    /**
     * Per-key rate limiting middleware.
     *
     * Uses Laravel's cache to track requests per key per minute.
     * Returns 429 with Retry-After header when exceeded.
     *
     * Usage:
     *   Route::middleware('remonode.rate_limit')->group(...);
     *   Route::middleware('remonode.rate_limit:60')->group(...);  // 60 req/min
     */
    public function handle(Request $request, Closure $next, ?int $maxAttempts = null): mixed
    {
        $apiKey = $request->attributes->get('remonode_api_key');

        if (! $apiKey) {
            return $next($request);
        }

        $limit = $maxAttempts
            ?? $apiKey->rate_limit_per_minute
            ?? (int) config('remonode.rate_limit.default_per_minute', 120);

        $key = 'remonode_rl:' . $apiKey->id . ':' . now()->format('YmdHi');
        $current = Cache::get($key, 0);

        if ($current >= $limit) {
            $retryAfter = 60 - now()->second;

            // Mark the request as rate-limited for usage logging
            $request->attributes->set('remonode_rate_limited', true);

            return response()->json([
                'success' => false,
                'message' => 'Rate limit exceeded.',
                'error' => 'rate_limit_exceeded',
                'rate_limit' => [
                    'limit' => $limit,
                    'remaining' => 0,
                    'resets_at' => now()->addSeconds($retryAfter)->toISOString(),
                ],
            ], 429)->withHeaders([
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) now()->addSeconds($retryAfter)->timestamp,
                'Retry-After' => (string) $retryAfter,
            ]);
        }

        Cache::put($key, $current + 1, now()->addMinutes(1));

        $response = $next($request);

        // Add rate limit headers to successful responses
        if ($response instanceof \Illuminate\Http\Response) {
            $response->headers->set('X-RateLimit-Limit', (string) $limit);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, $limit - $current - 1));
            $response->headers->set('X-RateLimit-Reset', (string) now()->addMinutes(1)->timestamp);
        }

        return $response;
    }
}
