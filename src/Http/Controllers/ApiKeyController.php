<?php

namespace Remonode\SDK\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Remonode\SDK\Services\ApiKeyManager;

class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyManager $manager,
    ) {}

    /**
     * List all API keys for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $keys = $this->manager->listForUser($userId);

        return response()->json([
            'success' => true,
            'data' => $keys->map(fn ($key) => [
                'id' => $key->id,
                'key_id' => $key->key_id,
                'public_key' => $key->public_key,
                'masked_secret' => $key->maskedKey(),
                'name' => $key->name,
                'status' => $key->status,
                'environment' => $key->environment,
                'expires_at' => $key->expires_at?->toISOString(),
                'last_used_at' => $key->last_used_at?->toISOString(),
                'created_at' => $key->created_at->toISOString(),
            ]),
        ]);
    }

    /**
     * Generate a new API key pair for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $userId = $request->user()->id;
        $result = $this->manager->generate(
            userId: $userId,
            name: $request->input('name'),
            expiresAt: $request->input('expires_at'),
        );

        return response()->json([
            'success' => true,
            'message' => 'API key pair generated. Store the secret key securely — it will not be shown again.',
            'data' => [
                'id' => $result['key']->id,
                'key_id' => $result['key']->key_id,
                'public_key' => $result['public_key'],
                'secret_key' => $result['raw_secret'],
                'masked_secret' => $result['key']->maskedKey(),
                'name' => $result['key']->name,
                'status' => $result['key']->status,
                'environment' => $result['key']->environment,
                'expires_at' => $result['key']->expires_at?->toISOString(),
                'created_at' => $result['key']->created_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Rotate a key pair: revoke old, generate new.
     */
    public function rotate(Request $request, string $keyId): JsonResponse
    {
        $key = \Remonode\SDK\Models\LocalApiKey::where('key_id', $keyId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $result = $this->manager->rotate($key);

        return response()->json([
            'success' => true,
            'message' => 'Key rotated. Store the new secret key securely.',
            'data' => [
                'id' => $result['key']->id,
                'key_id' => $result['key']->key_id,
                'public_key' => $result['public_key'],
                'secret_key' => $result['raw_secret'],
                'masked_secret' => $result['key']->maskedKey(),
                'old_key_id' => $result['old_key']->key_id,
                'created_at' => $result['key']->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * Revoke a key pair.
     */
    public function revoke(Request $request, string $keyId): JsonResponse
    {
        $key = \Remonode\SDK\Models\LocalApiKey::where('key_id', $keyId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $this->manager->canRevoke($key)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revoke the last active key pair.',
            ], 422);
        }

        $this->manager->revoke($key);

        return response()->json([
            'success' => true,
            'message' => 'API key revoked.',
        ]);
    }
}
