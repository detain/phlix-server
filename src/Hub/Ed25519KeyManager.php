<?php

declare(strict_types=1);

namespace Phlix\Hub;

use InvalidArgumentException;
use RuntimeException;

/**
 * Manages Ed25519 keypairs for server-to-hub authentication.
 *
 * Generates, stores, loads, and rotates Ed25519 keypairs using the
 * libsodium-compatible sodium_crypto_sign_* API. Keys are stored in
 * PEM format at the path supplied to the constructor.
 *
 * Key ID format: ISO 8601 timestamp of the key's creation date.
 * This makes key IDs deterministic and sortable.
 *
 * @package Phlix\Hub
 * @since 0.11.0
 */
final class Ed25519KeyManager
{
    /** @var string Path to the PEM-encoded private key file */
    private string $keyPath;

    /** @var string|null Cached kid from loaded key metadata */
    private ?string $kid = null;

    /**
     * Creates a new Ed25519KeyManager.
     *
     * @param string $keyPath Absolute path where the PEM-encoded Ed25519
     *                       private key is stored (or will be written).
     */
    public function __construct(string $keyPath)
    {
        $this->keyPath = $keyPath;
    }

    /**
     * Returns the current keypair, creating one if the key file does
     * not yet exist.
     *
     * @return KeyPair The current 32-byte secret / 32-byte public keypair.
     *
     * @throws RuntimeException If the key file exists but cannot be read.
     */
    public function getOrCreateKeyPair(): KeyPair
    {
        if (file_exists($this->keyPath)) {
            return $this->loadKeyPair();
        }

        return $this->generateAndStoreKeyPair();
    }

    /**
     * Generates a fresh keypair, persists it to disk, and returns it.
     *
     * @return KeyPair The newly generated keypair.
     */
    public function rotate(): KeyPair
    {
        return $this->generateAndStoreKeyPair();
    }

    /**
     * Returns the current key ID (kid) for the JWK.
     *
     * @return string ISO 8601 timestamp used as the key ID.
     */
    public function getKid(): string
    {
        if ($this->kid !== null) {
            return $this->kid;
        }

        if (file_exists($this->keyPath)) {
            $keyPair = $this->loadKeyPair();
            $this->kid = $this->extractKidFromPublicKey($keyPair->publicKey);
        } else {
            $this->kid = $this->generateKidForNow();
        }

        return $this->kid;
    }

    /**
     * Returns the current private key as raw 32-byte secret.
     *
     * @return string The 32-byte Ed25519 secret key.
     */
    public function getCurrentPrivateKey(): string
    {
        return $this->getOrCreateKeyPair()->secretKey;
    }

    /**
     * Returns the public key as a JWK map for inclusion in JWKS.
     *
     * @param string|null $kid Optional kid override. When null the
     *                         current key's kid is used.
     *
     * @return array{kty: string, crv: string, x: string, kid: string, use: string, alg: string}
     */
    public function getPublicKeyJwk(?string $kid = null): array
    {
        $keyPair = $this->getOrCreateKeyPair();
        $actualKid = $kid ?? $this->getKid();

        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => $this->base64UrlEncode($keyPair->publicKey),
            'kid' => $actualKid,
            'use' => 'sig',
            'alg' => 'EdDSA',
        ];
    }

    /**
     * Generates a new Ed25519 keypair and stores it in PEM format.
     *
     * @return KeyPair The newly generated keypair.
     *
     * @throws RuntimeException If the key file cannot be written.
     */
    private function generateAndStoreKeyPair(): KeyPair
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = substr($keypair, 0, 64);
        $publicKey = substr($keypair, 64);

        $keyPair = new KeyPair($secretKey, $publicKey);

        $this->kid = $this->extractKidFromPublicKey($publicKey);

        $pem = $this->buildPem($secretKey);
        $dir = dirname($this->keyPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (@file_put_contents($this->keyPath, $pem, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write Ed25519 private key: ' . $this->keyPath);
        }

        @chmod($this->keyPath, 0600);

        return $keyPair;
    }

    /**
     * Loads an existing keypair from the PEM file.
     *
     * @return KeyPair The loaded keypair.
     *
     * @throws RuntimeException If the PEM file is malformed or unreadable.
     */
    private function loadKeyPair(): KeyPair
    {
        $content = @file_get_contents($this->keyPath);
        if ($content === false) {
            throw new RuntimeException('Cannot read Ed25519 private key: ' . $this->keyPath);
        }

        $secretKey = $this->parsePem($content);
        if ($secretKey === null || strlen($secretKey) !== 64) {
            throw new InvalidArgumentException('Invalid Ed25519 PEM file: ' . $this->keyPath);
        }

        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        return new KeyPair($secretKey, $publicKey);
    }

    /**
     * Builds a PKCS8-compatible PEM string from raw 32-byte secret key.
     *
     * Uses the Ed25519 private key encoding (not generic PKCS8) as per
     * RFC 8410 / libsodium convention.
     *
     * @param string $secretKey 32-byte raw secret key.
     *
     * @return string PEM-encoded private key with line breaks.
     */
    private function buildPem(string $secretKey): string
    {
        $base64 = rtrim(strtr(base64_encode($secretKey), '+/', '-_'), '=');

        return "-----BEGIN ED25519 PRIVATE KEY-----\n"
            . implode("\n", str_split($base64, 64)) . "\n"
            . "-----END ED25519 PRIVATE KEY-----\n";
    }

    /**
     * Parses a PEM string and extracts the raw 64-byte Ed25519 secret key.
     *
     * @param string $pem The PEM content.
     *
     * @return string|null The raw 64-byte Ed25519 secret key, or null on parse failure.
     */
    private function parsePem(string $pem): ?string
    {
        $pattern = '/-----BEGIN ED25519 PRIVATE KEY-----(.*?)-----END ED25519 PRIVATE KEY-----/s';
        if (!preg_match($pattern, $pem, $matches)) {
            return null;
        }

        $base64Content = $matches[1];
        $base64 = preg_replace('/\s+/', '', $base64Content);
        if (!is_string($base64)) {
            return null;
        }
        $decoded = base64_decode(strtr($base64, '-_', '+/'), true);

        if ($decoded === false || strlen($decoded) < 64) {
            return null;
        }

        return substr($decoded, 0, 64);
    }

    /**
     * Extracts the key ID from a public key using its SHA-256 digest.
     *
     * The kid is the base64url-encoded first 8 bytes of the SHA-256
     * digest of the public key. This is deterministic and unique
     * (collision-resistant enough for key identification).
     *
     * @param string $publicKey 32-byte Ed25519 public key.
     *
     * @return string The key ID string.
     */
    private function extractKidFromPublicKey(string $publicKey): string
    {
        return $this->base64UrlEncode(substr(hash('sha256', $publicKey, true), 0, 8));
    }

    /**
     * Generates a kid for a new key based on the current timestamp.
     *
     * @return string ISO 8601 timestamp string.
     */
    private function generateKidForNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Encodes binary data as base64url (no padding).
     *
     * @param string $data Raw binary data.
     *
     * @return string Base64url-encoded string.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
