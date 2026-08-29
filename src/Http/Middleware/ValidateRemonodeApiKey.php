<?php

namespace Remonode\SDK\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Remonode\SDK\Services\KeyValidator;

class ValidateRemonodeApiKey
{
    public function __construct(
        private readonly KeyValidator $validator,
    ) {}

    /**
     * Validate API key from request headers.
     *
     * Supported headers (checked in order):
     * 1. X-Api-Key: sk_... (private/secret key)
     * 2. X-Public-Key: pk_... (public key)
     * 3. Authorization: Bearer sk_... or pk_...
     *
     * On success, attaches 'remonode_api_key' to the request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('remonode.enforcement', true)) {
            return $next($request);
        }

        // Check exempt URIs
        $exemptUris = config('remonode.exempt_uris', []);
        $path = $request->path();
        foreach ($exemptUris as $exempt) {
            if ($path === $exempt || str_starts_with($path, $exempt)) {
                return $next($request);
            }
        }

        $rawKey = $this->resolveKey($request);

        if (! $rawKey) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or missing API key.',
            ], 401);
        }

        $apiKey = $this->validator->validate($rawKey);

        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key.',
            ], 401);
        }

        if (! $apiKey->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'API key has expired or is not active.',
            ], 403);
        }

        // Check quota if enforcement is enabled
        if (config('remonode.quota_enforcement', false)) {
            $quotaCheck = $this->checkQuota($apiKey);
            if ($quotaCheck) {
                return $quotaCheck;
            }
        }

        $request->attributes->set('remonode_api_key', $apiKey);

        // Set the authenticated user from the API key's user_id
        // so $request->user() works in controllers without Sanctum
        if ($apiKey->user_id) {
            $userModel = config('remonode.user_model', App\Models\User::class);
            $user = $userModel::find($apiKey->user_id);
            if ($user) {
                auth()->setUser($user);
            }
        }

        return $next($request);
    }

    private function checkQuota($apiKey): ?mixed
    {
        // Get the application's active subscription via portal if configured
        $portalUrl = config('remonode.portal_url');
        $portalKey = config('remonode.portal_key');

        if (! $portalUrl || ! $portalKey) {
            return null;
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 10]);
            $response = $client->get($portalUrl . '/api/v1/connected-app/status', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $portalKey,
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $usage = $data['data']['usage'] ?? null;
            $plan = $data['data']['plan'] ?? null;

            if ($usage && $plan && $plan['monthly_quota'] !== null) {
                if ($usage['current_month'] >= $plan['monthly_quota']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Monthly API quota exceeded.',
                        'error' => 'quota_exceeded',
                        'quota' => [
                            'limit' => $plan['monthly_quota'],
                            'used' => $usage['current_month'],
                            'resets_at' => $usage['resets_at'],
                        ],
                    ], 429);
                }
            }
        } catch (\Exception $e) {
            // If portal is unreachable, allow the request (fail open)
        }

        return null;
    }

    private function resolveKey(Request $request): ?string
    {
        // 1. X-Api-Key header (private/secret key)
        $key = $request->header('X-Api-Key');
        if ($key) return $key;

        // 2. X-Public-Key header (public key)
        $key = $request->header('X-Public-Key');
        if ($key) return $key;

        // 3. Bearer token (may contain a key)
        $bearer = $request->bearerToken();
        if ($bearer) return $bearer;

        return null;
    }
}
