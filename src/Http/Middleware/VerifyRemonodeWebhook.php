<?php

namespace Remonode\SDK\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyRemonodeWebhook
{
    /**
     * Verify the webhook request originated from Remonode/Paystack.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $secret = config('remonode.webhook_secret');

        if (blank($secret)) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook secret not configured.',
            ], 500);
        }

        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return response()->json([
                'success' => false,
                'message' => 'Missing webhook signature.',
            ], 401);
        }

        $payload = $request->getContent();
        $expectedHash = hash_hmac('sha512', $payload, $secret);

        if (! hash_equals($expectedHash, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        return $next($request);
    }
}
