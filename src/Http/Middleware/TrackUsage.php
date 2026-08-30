<?php

namespace Remonode\SDK\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Remonode\SDK\Models\UsageLog;

class TrackUsage
{
    /**
     * Track every API call per key for analytics and billing.
     *
     * Logs method, path, status code, response time, IP, user agent.
     * Uses fire-and-forget via queued job when possible, falls back to sync.
     *
     * Usage:
     *   Route::middleware('remonode.track_usage')->group(...);
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // If ValidateRemonodeKeyType already tracked usage, skip
        if ($request->attributes->has('remonode_usage_tracked')) {
            return $next($request);
        }

        $startTime = microtime(true);

        $response = $next($request);

        // Only track if a valid API key was used
        $apiKey = $request->attributes->get('remonode_api_key');
        if (! $apiKey) {
            return $response;
        }

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        $logData = [
            'api_key_id' => $apiKey->id,
            'user_id' => $apiKey->user_id,
            'method' => $request->method(),
            'path' => $request->path(),
            'route_name' => $request->route()?->getName(),
            'status_code' => $response->getStatusCode(),
            'response_time_ms' => $responseTimeMs,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'environment' => $apiKey->environment,
            'scope_used' => $request->attributes->get('remonode_scope_used'),
            'rate_limited' => $request->attributes->get('remonode_rate_limited', false),
            'created_at' => now(),
        ];

        // Fire-and-forget: dispatch to queue if available, otherwise sync
        if (config('remonode.usage_tracking.async', true) && class_exists(\Illuminate\Foundation\Dispatchable::class)) {
            try {
                \Remonode\SDK\Jobs\LogApiUsage::dispatch($logData)
                    ->onQueue(config('remonode.usage_tracking.queue', 'default'));
            } catch (\Exception $e) {
                // Queue not available, log synchronously
                UsageLog::create($logData);
            }
        } else {
            UsageLog::create($logData);
        }

        return $response;
    }
}
