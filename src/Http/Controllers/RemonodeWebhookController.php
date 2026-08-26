<?php

namespace Remonode\SDK\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Remonode\SDK\Events\RemonodeSubscriptionCreated;
use Remonode\SDK\Events\RemonodeSubscriptionUpdated;
use Remonode\SDK\Events\RemonodeSubscriptionCancelled;
use Remonode\SDK\Events\RemonodePaymentFailed;

class RemonodeWebhookController extends Controller
{
    /**
     * Handle Paystack webhook events forwarded from Remonode.
     *
     * Protected by VerifyRemonodeWebhook middleware (HMAC-SHA512 signature).
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $data = $request->input('data');

        match ($event) {
            'charge.success' => event(new RemonodeSubscriptionUpdated('charge.success', $data)),
            'subscription.create' => event(new RemonodeSubscriptionCreated($data)),
            'subscription.not_renew' => event(new RemonodeSubscriptionCancelled('subscription.not_renew', $data)),
            'subscription.disable' => event(new RemonodeSubscriptionCancelled('subscription.disable', $data)),
            'subscription.enable' => event(new RemonodeSubscriptionUpdated('subscription.enable', $data)),
            'invoice.payment_failed' => event(new RemonodePaymentFailed($data)),
            default => null,
        };

        return response()->json(['status' => 'success']);
    }
}
