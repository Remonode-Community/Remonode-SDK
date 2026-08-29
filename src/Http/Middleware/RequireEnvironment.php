<?php

namespace Remonode\SDK\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireEnvironment
{
    /**
     * Environment isolation middleware.
     *
     * Ensures sandbox keys can only access sandbox routes and
     * production keys can only access production routes.
     *
     * Usage:
     *   Route::middleware('remonode.environment:production')->group(...); // prod only
     *   Route::middleware('remonode.environment:sandbox')->group(...);    // sandbox only
     *   Route::middleware('remonode.environment:any')->group(...);        // both (default)
     */
    public function handle(Request $request, Closure $next, string $requiredEnvironment = 'any'): mixed
    {
        $apiKey = $request->attributes->get('remonode_api_key');

        if (! $apiKey) {
            return $next($request);
        }

        if ($requiredEnvironment === 'any') {
            return $next($request);
        }

        $keyEnvironment = $apiKey->environment;

        if ($keyEnvironment !== $requiredEnvironment) {
            return response()->json([
                'success' => false,
                'message' => "This endpoint requires a {$requiredEnvironment} key. Your key is {$keyEnvironment}.",
                'error' => 'environment_mismatch',
                'required' => $requiredEnvironment,
                'provided' => $keyEnvironment,
            ], 403);
        }

        return $next($request);
    }
}
