<?php

namespace Remonode\SDK\Services;

use Illuminate\Support\Str;

class KeyGenerationService
{
    public function generatePublicPrefix(): string
    {
        return config('remonode.key_generation.public_prefix', 'pk_');
    }

    public function generateSecretPrefix(): string
    {
        return config('remonode.key_generation.secret_prefix', 'sk_');
    }

    /**
     * Generate a full public key: pk_<random>
     */
    public function generatePublicKey(): string
    {
        $prefix = $this->generatePublicPrefix();
        $length = (int) config('remonode.key_generation.public_random_length', 32);

        return $prefix . Str::random($length);
    }

    /**
     * Generate a full secret key: sk_<random>
     *
     * Returns the raw key. The hash and prefix are derived from it.
     */
    public function generateSecretKey(): string
    {
        $prefix = $this->generateSecretPrefix();
        $length = (int) config('remonode.key_generation.secret_random_length', 40);

        return $prefix . Str::random($length);
    }

    /**
     * Hash a secret key for storage (SHA-256 by default).
     */
    public function hashSecret(string $secretKey): string
    {
        $algo = config('remonode.key_generation.hash_algo', 'sha256');

        return hash($algo, $secretKey);
    }

    /**
     * Extract the key_id from a raw key.
     *
     * Format: <prefix><random16chars>...
     * Example: sk_live_a1b2c3d4e5f6g7h8...rest => sk_live_a1b2c3d4e5f6g7h8
     */
    public function extractKeyId(string $rawKey): string
    {
        $idLength = (int) config('remonode.key_generation.key_id_length', 16);

        // Find the second underscore (end of environment prefix like sk_live_)
        $prefixEnd = strpos($rawKey, '_', strpos($rawKey, '_') + 1) + 1;

        return substr($rawKey, 0, $prefixEnd + $idLength);
    }

    /**
     * Extract the secret_lookup_length prefix for indexed DB lookups.
     *
     * This is the first N random characters after the prefix, used as a
     * fast index to narrow down candidates before hash comparison.
     */
    public function extractSecretPrefix(string $secretKey): string
    {
        $lookupLength = (int) config('remonode.key_generation.secret_lookup_length', 12);
        $prefix = $this->generateSecretPrefix();

        return substr($secretKey, strlen($prefix), $lookupLength);
    }

    /**
     * Generate a complete key pair.
     *
     * Returns raw values. Caller is responsible for hashing the secret
     * before storage.
     *
     * @return array{public_key: string, secret_key: string, key_id: string, secret_prefix: string}
     */
    public function generateKeyPair(): array
    {
        $publicKey = $this->generatePublicKey();
        $secretKey = $this->generateSecretKey();

        return [
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
            'key_id' => $this->extractKeyId($secretKey),
            'secret_prefix' => $this->extractSecretPrefix($secretKey),
        ];
    }

    /**
     * Detect environment from a raw key.
     */
    public function detectEnvironment(string $rawKey): string
    {
        return str_contains($rawKey, '_live_') ? 'production' : 'sandbox';
    }
}
