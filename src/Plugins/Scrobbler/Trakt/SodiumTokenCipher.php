<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * libsodium-backed {@see TokenCipher} for encrypting OAuth tokens at rest
 * (step S1).
 *
 * Uses authenticated symmetric encryption ({@see \sodium_crypto_secretbox()},
 * XSalsa20-Poly1305) with a fresh random 24-byte nonce per call. The stored
 * value is `"v1:" . base64( nonce || ciphertext )` so:
 *  - the version tag lets {@see SodiumTokenCipher::decrypt()} distinguish its
 *    own ciphertext from a legacy plaintext token (written before encryption
 *    was enabled) and pass the latter through unchanged, and
 *  - the random nonce is recovered from the head of the payload on decrypt.
 *
 * The 32-byte secret key is supplied by the host (resolved from plugin config).
 * Construct via {@see SodiumTokenCipher::fromConfig()} which accepts the key as
 * base64, hex, or raw 32 bytes.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
final class SodiumTokenCipher implements TokenCipher
{
    /**
     * Marker prefix identifying a value encrypted by this cipher. A stored
     * token lacking this prefix is treated as legacy plaintext on decrypt.
     */
    private const PREFIX = 'v1:';

    /**
     * @param string $key Raw 32-byte (SODIUM_CRYPTO_SECRETBOX_KEYBYTES) secret key
     *
     * @throws \InvalidArgumentException When the key is not exactly 32 bytes.
     */
    public function __construct(private readonly string $key)
    {
        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Trakt token encryption key must be exactly %d bytes, got %d.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($this->key),
            ));
        }
    }

    /**
     * Build a cipher from a host-config key string, or return null when no
     * usable key is configured (so the caller can degrade to store-as-is).
     *
     * Accepts the key as:
     *  - raw 32 bytes,
     *  - base64 (standard) of 32 bytes, or
     *  - 64-char hex of 32 bytes.
     *
     * @param mixed $rawKey The `token_encryption_key` config value (any type)
     *
     * @return self|null A cipher when a valid 32-byte key was decoded, else null
     *
     * @since 0.14.0
     */
    public static function fromConfig(mixed $rawKey): ?self
    {
        if (!is_string($rawKey) || $rawKey === '') {
            return null;
        }

        $key = self::decodeKey($rawKey);
        if ($key === null) {
            return null;
        }

        return new self($key);
    }

    /**
     * Decode a config key string into raw 32 bytes.
     *
     * @param string $rawKey The configured key (raw/base64/hex)
     *
     * @return string|null Raw 32-byte key, or null when it cannot be decoded
     */
    private static function decodeKey(string $rawKey): ?string
    {
        // Already raw 32 bytes.
        if (strlen($rawKey) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $rawKey;
        }

        // 64-char hex.
        if (strlen($rawKey) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2
            && ctype_xdigit($rawKey)
        ) {
            $bin = @hex2bin($rawKey);
            if (is_string($bin) && strlen($bin) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $bin;
            }
        }

        // Standard base64.
        $decoded = base64_decode($rawKey, true);
        if (is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * @inheritDoc
     */
    public function decrypt(string $cipher): string
    {
        // Legacy plaintext (or anything not produced by this cipher): pass through
        // so an upgrade does not lock the user out of existing stored tokens.
        if (!str_starts_with($cipher, self::PREFIX)) {
            return $cipher;
        }

        $payload = base64_decode(substr($cipher, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            // Corrupt/truncated: return the original value rather than crashing.
            return $cipher;
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $body = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($body, $nonce, $this->key);
        if ($plain === false) {
            // Wrong key or tampered ciphertext: return the original (do not throw
            // inside a resident worker).
            return $cipher;
        }

        return $plain;
    }
}
