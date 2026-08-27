<?php

namespace Remonode\SDK\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Remonode\SDK\Exceptions\RemonodeConnectionException;
use Remonode\SDK\Exceptions\RemonodeApiException;

class RemonodeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $portalKey,
        private readonly int $timeout = 15,
    ) {}

    /**
     * Register this application with the Remonode portal.
     *
     * Called once during initial setup to establish the app connection.
     */
    public function registerApplication(string $appName, string $ownerEmail, ?string $ownerName = null): array
    {
        return $this->post('/api/v1/portal/applications/register', [
            'name' => $appName,
            'owner_email' => $ownerEmail,
            'owner_name' => $ownerName,
        ]);
    }

    /**
     * Sync key metadata to the Remonode portal.
     *
     * After local key generation, send metadata so Remonode can track
     * the application's keys for management and billing.
     */
    public function syncKeyMetadata(array $metadata): array
    {
        return $this->post('/api/v1/portal/applications/sync-key', $metadata);
    }

    /**
     * Sync key status changes (rotation, revocation) to the portal.
     */
    public function syncKeyStatus(string $remoteId, string $status): array
    {
        return $this->post("/api/v1/portal/applications/keys/{$remoteId}/status", [
            'status' => $status,
        ]);
    }

    /**
     * List keys from the portal (for sync/audit purposes).
     */
    public function listKeys(array $params = []): array
    {
        return $this->get('/api/v1/portal/applications/keys', $params);
    }

    /**
     * Health check endpoint.
     */
    public function healthCheck(): array
    {
        return $this->get('/api/v1/portal/health');
    }

    /**
     * Check the connected app's current plan, usage, and quota status.
     */
    public function checkQuota(): array
    {
        return $this->get('/api/v1/developer/connected-app/status');
    }

    /**
     * Get available plans from the Remonode portal.
     */
    public function getPlans(): array
    {
        return $this->get('/api/v1/developer/connected-app/plans');
    }

    /**
     * Upgrade the connected app's plan.
     */
    public function upgradePlan(string $planCode): array
    {
        return $this->post('/api/v1/developer/connected-app/upgrade', [
            'plan_code' => $planCode,
        ]);
    }

    /**
     * Make a GET request to the Remonode portal.
     */
    public function get(string $endpoint, array $query = []): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->get(rtrim($this->baseUrl, '/') . $endpoint, $query);

            return $this->handleResponse($response);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RemonodeConnectionException(
                "Remonode portal unreachable: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Make a POST request to the Remonode portal.
     */
    public function post(string $endpoint, array $data = []): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->post(rtrim($this->baseUrl, '/') . $endpoint, $data);

            return $this->handleResponse($response);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RemonodeConnectionException(
                "Remonode portal unreachable: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    private function headers(): array
    {
        return [
            'X-Portal-Key' => $this->portalKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    private function handleResponse($response): array
    {
        $body = $response->json();

        if ($response->failed()) {
            Log::error('Remonode portal API error', [
                'status' => $response->status(),
                'body' => $body,
            ]);

            throw new RemonodeApiException(
                $body['message'] ?? "Remonode returned HTTP {$response->status()}",
                $response->status()
            );
        }

        return $body;
    }
}
