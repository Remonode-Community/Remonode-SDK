<?php

namespace Remonode\SDK\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class AuthenticatePortalKey
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('remonode.portal_provision_keys', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Portal key provisioning is disabled.',
            ], 403);
        }

        $portalKey = config('remonode.portal_key', '');

        if (blank($portalKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Portal key not configured.',
            ], 500);
        }

        $providedKey = $request->header('X-Portal-Key');

        if (blank($providedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing portal key. Provide X-Portal-Key header.',
            ], 401);
        }

        if (! hash_equals($portalKey, $providedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid portal key.',
            ], 401);
        }

        return $next($request);
    }
}
