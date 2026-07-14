<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Encryptor seam for OAuth tokens at rest (step S1).
 *
 * The plugin uses this to encrypt the Trakt access/refresh tokens before they
 * are written to the {@see TraktSettingsRepository} (i.e. the
 * `plugins.settings_json` column) and to decrypt them again on load. Keeping
 * this as a tiny injectable interface lets the host provide a libsodium-backed
 * default (see {@see SodiumTokenCipher}) while unit tests pass a deterministic
 * fake, and lets the plugin degrade gracefully (store as-is) when no cipher is
 * available.
 *
 * Implementations MUST round-trip: `decrypt(encrypt($plain)) === $plain` for
 * any UTF-8 token string.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
interface TokenCipher
{
    /**
     * Encrypt a plaintext token for storage.
     *
     * @param string $plain Plaintext token value
     *
     * @return string Opaque ciphertext suitable for storage in settings_json
     *
     * @since 0.14.0
     */
    public function encrypt(string $plain): string;

    /**
     * Decrypt a previously-encrypted token.
     *
     * Implementations should tolerate a value that is NOT recognised as their
     * own ciphertext (e.g. a legacy plaintext token written before encryption
     * was enabled) by returning it unchanged, so an upgrade does not lock the
     * user out of their stored credentials.
     *
     * @param string $cipher Ciphertext produced by {@see TokenCipher::encrypt()}
     *
     * @return string The decrypted plaintext token
     *
     * @since 0.14.0
     */
    public function decrypt(string $cipher): string;
}
