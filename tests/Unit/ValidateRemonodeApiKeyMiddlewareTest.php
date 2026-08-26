<?php

namespace Remonode\SDK\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Remonode\SDK\Http\Middleware\ValidateRemonodeApiKey;
use Remonode\SDK\Models\LocalApiKey;
use Remonode\SDK\Services\KeyGenerationService;
use Remonode\SDK\Services\KeyValidator;
use Remonode\SDK\Tests\TestCase;

class ValidateRemonodeApiKeyMiddlewareTest extends TestCase
{
    private KeyGenerationService $generator;
    private KeyValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('remonode.enforcement', true);
        $this->app['config']->set('remonode.exempt_uris', ['api/health']);
        $this->generator = new KeyGenerationService();
        $this->validator = new KeyValidator($this->generator);
    }

    private function createActiveKey(int $userId = 1): array
    {
        $pair = $this->generator->generateKeyPair();
        $key = LocalApiKey::create([
            'user_id' => $userId,
            'key_id' => $pair['key_id'],
            'secret_prefix' => $pair['secret_prefix'],
            'public_key' => $pair['public_key'],
            'secret_hash' => $this->generator->hashSecret($pair['secret_key']),
            'secret_last_four' => substr($pair['secret_key'], -4),
            'name' => 'Test Key',
            'status' => 'active',
            'environment' => 'sandbox',
        ]);

        return ['key' => $key, 'raw_secret' => $pair['secret_key'], 'public_key' => $pair['public_key']];
    }

    private function middleware(): ValidateRemonodeApiKey
    {
        return new ValidateRemonodeApiKey($this->validator);
    }

    public function test_passes_through_when_enforcement_disabled(): void
    {
        $this->app['config']->set('remonode.enforcement', false);
        $request = Request::create('/api/test', 'GET');
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_returns_401_when_no_key_provided(): void
    {
        $request = Request::create('/api/test', 'GET');
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_returns_401_for_invalid_key(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Api-Key', 'sk_invalid');
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_passes_with_valid_secret_key(): void
    {
        $created = $this->createActiveKey();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Api-Key', $created['raw_secret']);
        $response = $this->middleware()->handle($request, fn ($req) => new JsonResponse([
            'key_id' => $req->attributes->get('remonode_api_key')?->key_id,
        ]));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_passes_with_valid_public_key(): void
    {
        $created = $this->createActiveKey();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Public-Key', $created['public_key']);
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_passes_with_bearer_token(): void
    {
        $created = $this->createActiveKey();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $created['raw_secret']);
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_returns_401_for_expired_key(): void
    {
        $created = $this->createActiveKey();
        $created['key']->update(['expires_at' => now()->subDay()]);
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Api-Key', $created['raw_secret']);
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        // Expired keys fail validation (not found as active), so 401
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_exempts_configured_uris(): void
    {
        $request = Request::create('/api/health', 'GET');
        $response = $this->middleware()->handle($request, fn () => new JsonResponse(['ok' => true]));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_sets_api_key_on_request(): void
    {
        $created = $this->createActiveKey();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Api-Key', $created['raw_secret']);

        $capturedKey = null;
        $this->middleware()->handle($request, function ($req) use (&$capturedKey) {
            $capturedKey = $req->attributes->get('remonode_api_key');
            return new JsonResponse(['ok' => true]);
        });

        $this->assertNotNull($capturedKey);
        $this->assertEquals($created['key']->id, $capturedKey->id);
    }
}
