<?php

namespace Remonode\SDK\Tests\Integration;

use Illuminate\Support\Facades\Http;
use Remonode\SDK\Services\RemonodeClient;
use Remonode\SDK\Exceptions\RemonodeApiException;
use Remonode\SDK\Exceptions\RemonodeConnectionException;
use Remonode\SDK\Tests\TestCase;

class RemonodeClientTest extends TestCase
{
    private RemonodeClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new RemonodeClient(
            baseUrl: 'https://remonode.test',
            portalKey: 'test-portal-key',
            timeout: 10,
        );
    }

    public function test_health_check_success(): void
    {
        Http::fake([
            'https://remonode.test/api/v1/portal/health' => Http::response([
                'success' => true,
                'status' => 'healthy',
            ], 200),
        ]);

        $result = $this->client->healthCheck();
        $this->assertTrue($result['success']);
        $this->assertEquals('healthy', $result['status']);
    }

    public function test_register_application_success(): void
    {
        Http::fake([
            'https://remonode.test/api/v1/portal/applications/register' => Http::response([
                'success' => true,
                'data' => ['app_uuid' => 'test-uuid-123'],
            ], 200),
        ]);

        $result = $this->client->registerApplication('Test App', 'admin@test.com');
        $this->assertTrue($result['success']);
        $this->assertEquals('test-uuid-123', $result['data']['app_uuid']);
    }

    public function test_sync_key_metadata_success(): void
    {
        Http::fake([
            'https://remonode.test/api/v1/portal/applications/sync-key' => Http::response([
                'success' => true,
                'data' => ['id' => 42],
            ], 200),
        ]);

        $result = $this->client->syncKeyMetadata([
            'key_id' => 'sk_test123',
            'public_key' => 'pk_test123',
            'user_id' => 1,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(42, $result['data']['id']);
    }

    public function test_sync_key_status_success(): void
    {
        Http::fake([
            'https://remonode.test/api/v1/portal/applications/keys/42/status' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = $this->client->syncKeyStatus('42', 'revoked');
        $this->assertTrue($result['success']);
    }

    public function test_list_keys_success(): void
    {
        Http::fake([
            'https://remonode.test/api/v1/portal/applications/keys' => Http::response([
                'success' => true,
                'data' => [['key_id' => 'sk_123']],
            ], 200),
        ]);

        $result = $this->client->listKeys();
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);
    }

    public function test_throws_api_exception_on_failure(): void
    {
        Http::fake([
            'https://remonode.test/api/v1/portal/health' => Http::response([
                'message' => 'Unauthorized',
            ], 401),
        ]);

        try {
            $this->client->healthCheck();
            $this->fail('Expected RemonodeApiException was not thrown');
        } catch (RemonodeApiException $e) {
            $this->assertEquals(401, $e->getStatusCode());
            $this->assertEquals('Unauthorized', $e->getMessage());
        }
    }

    public function test_throws_connection_exception_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $this->expectException(RemonodeConnectionException::class);
        $this->client->healthCheck();
    }

    public function test_sends_portal_key_header(): void
    {
        Http::fake([
            'https://remonode.test/api/v1/portal/health' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $this->client->healthCheck();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Portal-Key')
                && $request->header('X-Portal-Key')[0] === 'test-portal-key';
        });
    }

    public function test_sends_accept_json_header(): void
    {
        Http::fake([
            'https://remonode.test/api/v1/portal/health' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $this->client->healthCheck();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Accept')
                && $request->header('Accept')[0] === 'application/json';
        });
    }
}
