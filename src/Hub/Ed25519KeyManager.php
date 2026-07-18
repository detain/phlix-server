<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
     * Builds the native PEM string from the raw libsodium secret key.
     *
     * Emits the application's own `-----BEGIN ED25519 PRIVATE KEY-----` label
     * wrapping the base64url of the raw libsodium secret key. The writer is
     * intentionally unchanged (SV-4.16): newly generated keys keep this native
     * format, while {@see parsePem()} now additionally tolerates reading a
     * standard PKCS#8 Ed25519 key so externally generated keys also load.
     *
     * @param string $secretKey 64-byte libsodium secret key (seed ‖ public key).
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
     * Two on-disk PEM formats are accepted so the reader tolerates both keys
     * this application generates itself and keys produced by standard tooling
     * (SV-4.16):
     *
     *  1. Native format (written by {@see buildPem()}):
     *     `-----BEGIN ED25519 PRIVATE KEY-----` wrapping the base64url of the
     *     raw 64-byte libsodium secret key (32-byte seed ‖ 32-byte public key).
     *  2. Standard PKCS#8 (RFC 8410), e.g. produced by
     *     `openssl genpkey -algorithm Ed25519`:
     *     `-----BEGIN PRIVATE KEY-----` wrapping the DER-encoded 32-byte seed.
     *     The seed is expanded back to the 64-byte libsodium secret key so the
     *     rest of the pipeline is identical regardless of source format.
     *
     * Only the native format is written back; see {@see buildPem()}.
     *
     * @param string $pem The PEM content.
     *
     * @return string|null The raw 64-byte Ed25519 secret key, or null on parse failure.
     */
    private function parsePem(string $pem): ?string
    {
        // Format 1: the application's own label wrapping a base64url-encoded
        // raw 64-byte libsodium secret key. Kept first for full backward
        // compatibility with keys written by buildPem().
        $native = $this->parseNativeEd25519Pem($pem);
        if ($native !== null) {
            return $native;
        }

        // Format 2: interop with a standard PKCS#8 Ed25519 private key.
        return $this->parsePkcs8Ed25519Pem($pem);
    }

    /**
     * Parses the native `-----BEGIN ED25519 PRIVATE KEY-----` PEM format.
     *
     * The body is the base64url encoding of the raw 64-byte libsodium secret
     * key (32-byte seed ‖ 32-byte public key), as written by {@see buildPem()}.
     *
     * @param string $pem The PEM content.
     *
     * @return string|null The raw 64-byte Ed25519 secret key, or null if the
     *                     native label is absent or the body is malformed.
     */
    private function parseNativeEd25519Pem(string $pem): ?string
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
     * Parses a standard PKCS#8 Ed25519 private key (RFC 8410).
     *
     * The 32-byte seed is extracted from the DER structure and expanded via
     * libsodium into the 64-byte secret key (seed ‖ public key). The sodium
     * seed-expansion path is used rather than ext-openssl because PHP's
     * `openssl_pkey_get_details()` does not reliably expose the raw Ed25519 key
     * material across builds, whereas seed expansion is portable and exact.
     *
     * @param string $pem The PEM content.
     *
     * @return string|null The raw 64-byte Ed25519 secret key, or null if the
     *                     PKCS#8 label is absent, the DER is malformed, or the
     *                     key is not an Ed25519 key.
     */
    private function parsePkcs8Ed25519Pem(string $pem): ?string
    {
        $pattern = '/-----BEGIN PRIVATE KEY-----(.*?)-----END PRIVATE KEY-----/s';
        if (!preg_match($pattern, $pem, $matches)) {
            return null;
        }

        $base64 = preg_replace('/\s+/', '', $matches[1]);
        if (!is_string($base64) || $base64 === '') {
            return null;
        }

        // PKCS#8 uses standard (not URL-safe) base64.
        $der = base64_decode($base64, true);
        if ($der === false || strlen($der) < 48) {
            return null;
        }

        $seed = $this->extractEd25519SeedFromPkcs8($der);
        if ($seed === null || $seed === '') {
            return null;
        }

        // Expand the 32-byte seed into the 64-byte libsodium secret key so
        // loadKeyPair()'s strlen === 64 check and every downstream
        // sodium_crypto_sign_* call behave exactly as for a native key.
        $keypair = sodium_crypto_sign_seed_keypair($seed);

        return sodium_crypto_sign_secretkey($keypair);
    }

    /**
     * Extracts the 32-byte Ed25519 seed from a PKCS#8 DER blob.
     *
     * The Ed25519 algorithm OID (1.3.101.112) is verified to be present before
     * any extraction, so a non-Ed25519 PKCS#8 key (RSA, P-256, …) is never
     * silently mis-read as an Ed25519 seed. The seed is a 32-byte OCTET STRING
     * nested inside the PrivateKey OCTET STRING, framed in DER as
     * `04 22 04 20 <32 seed bytes>`.
     *
     * @param string $der Raw DER bytes of the PKCS#8 PrivateKeyInfo.
     *
     * @return string|null The 32-byte seed, or null if the structure is not a
     *                     well-formed Ed25519 PKCS#8 key.
     */
    private function extractEd25519SeedFromPkcs8(string $der): ?string
    {
        // OID 1.3.101.112 (Ed25519) => DER: 06 03 2B 65 70.
        if (strpos($der, "\x06\x03\x2b\x65\x70") === false) {
            return null;
        }

        // Inner 32-byte OCTET STRING (04 20) nested inside the PrivateKey
        // OCTET STRING (04 22). Anchor on the full framing so a coincidental
        // 04 20 elsewhere cannot be mistaken for the seed marker.
        $marker = "\x04\x22\x04\x20";
        $pos = strpos($der, $marker);
        if ($pos === false) {
            return null;
        }

        $seed = substr($der, $pos + strlen($marker), 32);
        if (strlen($seed) !== 32) {
            return null;
        }

        return $seed;
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
