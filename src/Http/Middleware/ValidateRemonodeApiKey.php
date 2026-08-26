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

        $request->attributes->set('remonode_api_key', $apiKey);

        return $next($request);
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
