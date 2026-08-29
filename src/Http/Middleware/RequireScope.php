<?php

namespace Remonode\SDK\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireScope
{
    /**
     * Validate that the API key has the required scope(s).
     *
     * Keys with null scopes have full access (backward compatible).
     * Keys with scopes array are restricted to those scopes only.
     *
     * Usage:
     *   Route::middleware('remonode.scope:read')->group(...);       // requires read
     *   Route::middleware('remonode.scope:write')->group(...);      // requires write
     *   Route::middleware('remonode.scope:admin')->group(...);      // requires admin
     *   Route::middleware('remonode.scope:read,write')->group(...); // requires read AND write
     */
    public function handle(Request $request, Closure $next, string ...$requiredScopes): mixed
    {
        $apiKey = $request->attributes->get('remonode_api_key');

        if (! $apiKey) {
            return $next($request);
        }

        // null scopes = full access (backward compatible)
        $keyScopes = $apiKey->scopes;

        if (empty($keyScopes)) {
            $request->attributes->set('remonode_scope_used', 'full');
            return $next($request);
        }

        // Ensure scopes is an array
        $keyScopes = is_array($keyScopes) ? $keyScopes : json_decode($keyScopes, true) ?? [];

        // Check if the key has ALL required scopes
        foreach ($requiredScopes as $scope) {
            if (! in_array($scope, $keyScopes, true)) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient scope. Required: {$scope}.",
                    'error' => 'insufficient_scope',
                    'required_scopes' => $requiredScopes,
                    'key_scopes' => $keyScopes,
                ], 403);
            }
        }

        // Track which scope was used for analytics
        $request->attributes->set('remonode_scope_used', $requiredScopes[0] ?? 'full');

        return $next($request);
    }
}
