<?php

namespace Remonode\SDK\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Remonode\SDK\Services\KeyValidator;

class ValidateRemonodeKeyType
{
    public function __construct(
        private readonly KeyValidator $validator,
    ) {}

    /**
     * Validate API key of a specific type.
     *
     * Usage in routes:
     *   Route::middleware('remonode.key:pk')->group(...);  // Public keys only
     *   Route::middleware('remonode.key:sk')->group(...);  // Secret keys only
     *   Route::middleware('remonode.key:any')->group(...); // Both (default)
     */
    public function handle(Request $request, Closure $next, string $keyType = 'any'): mixed
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

        $rawKey = $this->resolveKey($request, $keyType);

        if (! $rawKey) {
            return response()->json([
                'success' => false,
                'message' => $this->getMissingKeyMessage($keyType),
            ], 401);
        }

        $apiKey = $this->validator->validate($rawKey);

        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key.',
            ], 401);
        }

        // Verify key type matches expected
        if ($keyType === 'pk' && ! str_starts_with($apiKey->public_key, 'pk_')) {
            return response()->json([
                'success' => false,
                'message' => 'Public key required for this endpoint.',
            ], 403);
        }

        if ($keyType === 'sk' && ! str_starts_with($apiKey->key_id, 'sk_')) {
            return response()->json([
                'success' => false,
                'message' => 'Secret key required for this endpoint.',
            ], 403);
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

        return $next($request);
    }

    private function resolveKey(Request $request, string $keyType): ?string
    {
        switch ($keyType) {
            case 'pk':
                // Only accept public keys
                return $request->header('X-Public-Key')
                    ?? $this->extractBearerIfPrefix($request->bearerToken(), 'pk_');

            case 'sk':
                // Only accept secret keys
                return $request->header('X-Api-Key')
                    ?? $this->extractBearerIfPrefix($request->bearerToken(), 'sk_');

            case 'any':
            default:
                // Accept both (original behavior)
                return $request->header('X-Api-Key')
                    ?? $request->header('X-Public-Key')
                    ?? $request->bearerToken();
        }
    }

    private function extractBearerIfPrefix(?string $bearer, string $prefix): ?string
    {
        if ($bearer && str_starts_with($bearer, $prefix)) {
            return $bearer;
        }
        return null;
    }

    private function getMissingKeyMessage(string $keyType): string
    {
        return match ($keyType) {
            'pk' => 'Missing public key. Provide X-Public-Key header with pk_* key.',
            'sk' => 'Missing secret key. Provide X-Api-Key header with sk_* key.',
            default => 'Invalid or missing API key.',
        };
    }

    private function checkQuota($apiKey): mixed
    {
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
            // Fail open
        }

        return null;
    }
}