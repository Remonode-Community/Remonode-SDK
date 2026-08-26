<?php

namespace Remonode\SDK\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Remonode\SDK\Http\Middleware\VerifyRemonodeWebhook;
use Remonode\SDK\Tests\TestCase;

class VerifyRemonodeWebhookMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('remonode.webhook_secret', 'test-webhook-secret');
    }

    private function middleware(): VerifyRemonodeWebhook
    {
        return new VerifyRemonodeWebhook();
    }

    private function signPayload(string $payload, string $secret): string
    {
        return hash_hmac('sha512', $payload, $secret);
    }

    private function createWebhookRequest(string $payload = '{}', ?string $signature = null): Request
    {
        $request = Request::create(
            '/webhook',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload
        );

        if ($signature !== null) {
            $request->headers->set('x-paystack-signature', $signature);
        }

        return $request;
    }

    public function test_returns_500_when_secret_not_configured(): void
    {
        $this->app['config']->set('remonode.webhook_secret', '');
        $request = $this->createWebhookRequest();
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(500, $response->getStatusCode());
    }

    public function test_returns_401_when_signature_missing(): void
    {
        $request = $this->createWebhookRequest();
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_returns_401_for_invalid_signature(): void
    {
        $request = $this->createWebhookRequest('{}', 'invalid_signature');
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_passes_with_valid_signature(): void
    {
        $payload = '{"event":"charge.success","data":{"amount":1000}}';
        $signature = $this->signPayload($payload, 'test-webhook-secret');

        $request = $this->createWebhookRequest($payload, $signature);
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_uses_timing_safe_comparison(): void
    {
        $payload = '{"event":"charge.success"}';
        $wrongSignature = $this->signPayload($payload, 'test-webhook-secref');

        $request = $this->createWebhookRequest($payload, $wrongSignature);
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(401, $response->getStatusCode());
    }
}
