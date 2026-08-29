<?php

namespace Remonode\SDK\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Remonode\SDK\Services\ApiKeyManager;
use Remonode\SDK\Services\RemonodeClient;
use Remonode\SDK\Models\LocalApiKey;
use Illuminate\Support\Facades\Log;

class PortalProvisionController extends Controller
{
    public function __construct(
        private readonly ApiKeyManager $keyManager,
        private readonly ?RemonodeClient $client,
    ) {}

    /**
     * Generate API keys for a connected app user.
     *
     * Called by the Remonode portal when a user clicks "Generate Key"
     * on the connected app dashboard. Keys are generated locally in
     * the consuming app and returned to the portal.
     *
     * POST /api/v1/remonode/portal/provision
     * Header: X-Portal-Key: {shared_secret}
     */
    public function provision(Request $request): JsonResponse
    {
        // Accept either user_id or email — at least one is required
        $request->validate([
            'user_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'string', 'in:production,sandbox'],
            'key_type' => ['nullable', 'string', 'in:both,public,private'],
            'expires_at' => ['nullable', 'date'],
        ]);

        // Manual check: at least user_id or email must be provided
        $userId = $request->input('user_id');
        $email = $request->input('email');

        if (empty($userId) && empty($email)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => [
                    'user_id' => ['The user id field is required when email is not present.'],
                    'email' => ['The email field is required when user id is not present.'],
                ],
            ], 422);
        }

        // Resolve user_id from email if not provided
        if (empty($userId) && ! empty($email)) {
            $userClass = config('remonode.user_model', 'App\\Models\\User');
            $user = $userClass::where('email', $email)->first();
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found for email: '.$email,
                ], 404);
            }
            $userId = $user->id;
        }

        $name = $request->input('name') ?? 'Portal Generated Key';
        $environment = $request->input('environment') ?? config('remonode.environment', 'production');
        $keyType = $request->input('key_type') ?? 'both';
        $expiresAt = $request->input('expires_at') ?? null;

        try {
            $result = [
                'public_key' => null,
                'secret_key' => null,
                'key_id' => null,
            ];

            // Generate public key
            if (in_array($keyType, ['both', 'public'], true)) {
                $publicResult = $this->keyManager->generate(
                    userId: $userId,
                    name: $name . ' (Public)',
                    expiresAt: $expiresAt,
                );
                $result['public_key'] = $publicResult['public_key'];
                $result['key_id'] = $publicResult['key']->key_id;
            }

            // Generate secret key
            if (in_array($keyType, ['both', 'private'], true)) {
                $secretResult = $this->keyManager->generate(
                    userId: $userId,
                    name: $name . ' (Secret)',
                    expiresAt: $expiresAt,
                );
                $result['secret_key'] = $secretResult['raw_secret'];
                $result['key_id'] = $result['key_id'] ?? $secretResult['key']->key_id;
            }

            return response()->json([
                'success' => true,
                'message' => 'Key(s) generated successfully.',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Portal key provisioning failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Key generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all keys for a user (portal can query this).
     *
     * GET /api/v1/remonode/portal/keys
     * Header: X-Portal-Key: {shared_secret}
     * Query: user_id (required)
     */
    public function listKeys(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $keys = LocalApiKey::where('user_id', $validated['user_id'])
            ->get()
            ->map(fn ($key) => [
                'id' => $key->id,
                'key_id' => $key->key_id,
                'public_key' => $key->public_key,
                'name' => $key->name,
                'status' => $key->status,
                'environment' => $key->environment,
                'created_at' => $key->created_at->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $keys,
        ]);
    }

    /**
     * Revoke a key (portal can trigger this).
     *
     * POST /api/v1/remonode/portal/keys/{keyId}/revoke
     * Header: X-Portal-Key: {shared_secret}
     */
    public function revokeKey(Request $request, string $keyId): JsonResponse
    {
        $key = LocalApiKey::where('key_id', $keyId)->first();

        if (! $key) {
            return response()->json([
                'success' => false,
                'message' => 'Key not found.',
            ], 404);
        }

        $this->keyManager->revoke($key);

        return response()->json([
            'success' => true,
            'message' => 'Key revoked.',
        ]);
    }

    /**
     * Get usage analytics for a connected app (portal can query this).
     *
     * GET /api/v1/remonode/portal/usage
     * Header: X-Portal-Key: {shared_secret}
     * Query: user_id, days (optional, default 30)
     */
    public function usage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = $validated['days'] ?? 30;
        $from = now()->subDays($days);

        $keys = LocalApiKey::where('user_id', $validated['user_id'])->get();
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

        $topEndpoints = $usageLog::whereIn('api_key_id', $keyIds)
            ->where('created_at', '>=', $from)
            ->selectRaw('path, method, COUNT(*) as calls')
            ->groupBy('path', 'method')
            ->orderByDesc('calls')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $validated['user_id'],
                'period' => "{$days}d",
                'total_calls' => $totalCalls,
                'active_keys' => $keys->where('status', 'active')->count(),
                'daily_usage' => $dailyUsage,
                'top_endpoints' => $topEndpoints,
            ],
        ]);
    }
}
