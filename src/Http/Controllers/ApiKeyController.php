<?php

namespace Remonode\SDK\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Remonode\SDK\Services\ApiKeyManager;

class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyManager $manager,
    ) {}

    /**
     * List all API keys for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $keys = $this->manager->listForUser($userId);

        return response()->json([
            'success' => true,
            'data' => $keys->map(fn ($key) => [
                'id' => $key->id,
                'key_id' => $key->key_id,
                'public_key' => $key->public_key,
                'masked_secret' => $key->maskedKey(),
                'name' => $key->name,
                'status' => $key->status,
                'environment' => $key->environment,
                'expires_at' => $key->expires_at?->toISOString(),
                'last_used_at' => $key->last_used_at?->toISOString(),
                'created_at' => $key->created_at->toISOString(),
            ]),
        ]);
    }

    /**
     * Generate a new API key pair for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $userId = $request->user()->id;
        $result = $this->manager->generate(
            userId: $userId,
            name: $request->input('name'),
            expiresAt: $request->input('expires_at'),
        );

        return response()->json([
            'success' => true,
            'message' => 'API key pair generated. Store the secret key securely — it will not be shown again.',
            'data' => [
                'id' => $result['key']->id,
                'key_id' => $result['key']->key_id,
                'public_key' => $result['public_key'],
                'secret_key' => $result['raw_secret'],
                'masked_secret' => $result['key']->maskedKey(),
                'name' => $result['key']->name,
                'status' => $result['key']->status,
                'environment' => $result['key']->environment,
                'expires_at' => $result['key']->expires_at?->toISOString(),
                'created_at' => $result['key']->created_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Rotate a key pair: revoke old, generate new.
     */
    public function rotate(Request $request, string $keyId): JsonResponse
    {
        $key = \Remonode\SDK\Models\LocalApiKey::where('key_id', $keyId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $result = $this->manager->rotate($key);

        return response()->json([
            'success' => true,
            'message' => 'Key rotated. Store the new secret key securely.',
            'data' => [
                'id' => $result['key']->id,
                'key_id' => $result['key']->key_id,
                'public_key' => $result['public_key'],
                'secret_key' => $result['raw_secret'],
                'masked_secret' => $result['key']->maskedKey(),
                'old_key_id' => $result['old_key']->key_id,
                'created_at' => $result['key']->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * Revoke a key pair.
     */
    public function revoke(Request $request, string $keyId): JsonResponse
    {
        $key = \Remonode\SDK\Models\LocalApiKey::where('key_id', $keyId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $this->manager->canRevoke($key)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revoke the last active key pair.',
            ], 422);
        }

        $this->manager->revoke($key);

        return response()->json([
            'success' => true,
            'message' => 'API key revoked.',
        ]);
    }

    /**
     * Update key settings: scopes, rate limit, monthly quota.
     */
    public function update(Request $request, string $keyId): JsonResponse
    {
        $key = \Remonode\SDK\Models\LocalApiKey::where('key_id', $keyId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'scopes' => 'nullable|array',
            'scopes.*' => 'string|in:read,write,admin',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:10000',
            'monthly_quota' => 'nullable|integer|min:1',
        ]);

        $key->update(array_filter([
            'scopes' => $validated['scopes'] ?? null,
            'rate_limit_per_minute' => $validated['rate_limit_per_minute'] ?? null,
            'monthly_quota' => $validated['monthly_quota'] ?? null,
        ]));

        return response()->json([
            'success' => true,
            'data' => [
                'key_id' => $key->key_id,
                'scopes' => $key->scopes,
                'rate_limit_per_minute' => $key->rate_limit_per_minute,
                'monthly_quota' => $key->monthly_quota,
            ],
        ]);
    }

    /**
     * Get usage analytics for a specific key.
     */
    public function usage(Request $request, string $keyId): JsonResponse
    {
        $key = \Remonode\SDK\Models\LocalApiKey::where('key_id', $keyId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $days = min((int) $request->input('days', 30), 365);
        $from = now()->subDays($days);

        $usageLog = \Remonode\SDK\Models\UsageLog::class;

        // Total calls
        $totalCalls = $usageLog::where('api_key_id', $key->id)
            ->where('created_at', '>=', $from)
            ->count();

        // Calls per day
        $dailyUsage = $usageLog::where('api_key_id', $key->id)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as calls')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Calls per endpoint
        $topEndpoints = $usageLog::where('api_key_id', $key->id)
            ->where('created_at', '>=', $from)
            ->selectRaw('path, method, COUNT(*) as calls, AVG(response_time_ms) as avg_response_ms')
            ->groupBy('path', 'method')
            ->orderByDesc('calls')
            ->limit(10)
            ->get();

        // Error rate
        $errors = $usageLog::where('api_key_id', $key->id)
            ->where('created_at', '>=', $from)
            ->where('status_code', '>=', 400)
            ->count();

        // Rate limited count
        $rateLimited = $usageLog::where('api_key_id', $key->id)
            ->where('created_at', '>=', $from)
            ->where('rate_limited', true)
            ->count();

        // Monthly usage for quota check
        $monthlyUsage = $usageLog::where('api_key_id', $key->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => "{$days}d",
                'total_calls' => $totalCalls,
                'error_rate' => $totalCalls > 0 ? round(($errors / $totalCalls) * 100, 2) : 0,
                'rate_limited_count' => $rateLimited,
                'monthly_usage' => $monthlyUsage,
                'monthly_quota' => $key->monthly_quota,
                'daily_usage' => $dailyUsage,
                'top_endpoints' => $topEndpoints,
            ],
        ]);
    }

    /**
     * Get usage analytics summary for all keys.
     */
    public function usageSummary(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $days = min((int) $request->input('days', 30), 365);
        $from = now()->subDays($days);

        $keys = \Remonode\SDK\Models\LocalApiKey::forUser($userId)->active()->get();
        $keyIds = $keys->pluck('id');

        $usageLog = \Remonode\SDK\Models\UsageLog::class;

        $totalCalls = $usageLog::whereIn('api_key_id', $keyIds)
            ->where('created_at', '>=', $from)
            ->count();

        $dailyUsage = $usageLog::whereIn('api_key_id', $keyIds)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as calls')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $perKeyUsage = $usageLog::whereIn('api_key_id', $keyIds)
            ->where('created_at', '>=', $from)
            ->selectRaw('api_key_id, COUNT(*) as calls')
            ->groupBy('api_key_id')
            ->get()
            ->map(function ($item) use ($keys) {
                $key = $keys->firstWhere('id', $item->api_key_id);
                return [
                    'key_id' => $key?->key_id,
                    'name' => $key?->name,
                    'calls' => $item->calls,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'period' => "{$days}d",
                'total_calls' => $totalCalls,
                'active_keys' => $keys->count(),
                'daily_usage' => $dailyUsage,
                'per_key_usage' => $perKeyUsage,
            ],
        ]);
    }
}
