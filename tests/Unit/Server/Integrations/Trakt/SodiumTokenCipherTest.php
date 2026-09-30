<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Integrations\Trakt;

use Phlix\Server\Integrations\Trakt\SodiumTokenCipher;
use PHPUnit\Framework\TestCase;

/**
 * M-4b (security audit 2026-09-30): the server now WIRES `token_encryption_key`
 * into {@see \Phlix\Server\Http\Controllers\TraktOAuthController}, so tokens
 * begin encrypting at rest for every install that has a key configured —
 * including installs whose `plugins.settings_json` already carries LEGACY
 * PLAINTEXT tokens from the pre-wiring era.
 *
 * The safety of that upgrade path rests entirely on the cipher's documented
 * passthrough contract (TokenCipher::decrypt() docblock: a value that is NOT
 * recognised as the cipher's own ciphertext is returned UNCHANGED, so an
 * upgrade never locks the operator out of stored credentials). Nothing in the
 * repo pinned that contract before this file — the class had zero tests.
 *
 * These tests pin:
 *  - encrypt() output is version-tagged (`v1:`) and round-trips;
 *  - decrypt() of a legacy plaintext token (anything without the prefix)
 *    returns the input byte-identical;
 *  - decrypt() never throws on corrupt / truncated / wrong-key material —
 *    it returns the stored value unchanged (resident-worker safety);
 *  - fromConfig() accepts raw 32-byte, 64-char hex, and base64 keys and
 *    returns null for anything unusable (graceful plaintext degrade).
 *
 * @package Phlix\Tests\Unit\Server\Integrations\Trakt
 */
final class SodiumTokenCipherTest extends TestCase
{
    private const TOKEN = 'trakt-access-token-0f3d9a';

    private function cipher(): SodiumTokenCipher
    {
        return new SodiumTokenCipher(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function test_encrypt_tags_output_with_version_prefix(): void
    {
        $encrypted = $this->cipher()->encrypt(self::TOKEN);

        $this->assertStringStartsWith('v1:', $encrypted);
        $this->assertNotSame(self::TOKEN, $encrypted, 'plaintext must not be stored verbatim');
    }

    public function test_round_trip_recovers_plaintext(): void
    {
        $cipher = $this->cipher();

        $this->assertSame(self::TOKEN, $cipher->decrypt($cipher->encrypt(self::TOKEN)));
    }

    public function test_each_encryption_uses_a_fresh_nonce(): void
    {
        $cipher = $this->cipher();

        $this->assertNotSame($cipher->encrypt(self::TOKEN), $cipher->encrypt(self::TOKEN));
    }

    /**
     * THE load-bearing M-4b pin: a token stored BEFORE encryption was wired
     * has no `v1:` prefix; decrypt() must hand it back untouched so existing
     * installs keep working the moment a key is configured.
     */
    public function test_decrypt_passes_legacy_plaintthrough_unchanged(): void
    {
        $legacyPlaintext = 'ghp_LikeStoredTokenBeforeEncryptionWasWired_1234567890';

        $this->assertSame(
            $legacyPlaintext,
            $this->cipher()->decrypt($legacyPlaintext),
            'legacy plaintext must round-trip through decrypt() byte-identical'
        );
    }

    public function test_decrypt_returns_input_unchanged_on_corrupt_base64_payload(): void
    {
        $corrupt = 'v1:!!!not-valid-base64!!!';

        $this->assertSame($corrupt, $this->cipher()->decrypt($corrupt));
    }

    public function test_decrypt_returns_input_unchanged_on_truncated_payload(): void
    {
        // Valid base64 but shorter than the 24-byte nonce -> cannot decrypt.
        $truncated = 'v1:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES - 1));

        $this->assertSame($truncated, $this->cipher()->decrypt($truncated));
    }

    public function test_decrypt_returns_input_unchanged_under_wrong_key(): void
    {
        $writer = $this->cipher();
        $reader = $this->cipher();

        $encrypted = $writer->encrypt(self::TOKEN);

        $this->assertSame(
            $encrypted,
            $reader->decrypt($encrypted),
            'a failed secretbox_open must not throw inside a resident worker'
        );
    }

    public function test_from_config_accepts_raw_hex_and_base64_encodings(): void
    {
        $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        foreach (['raw' => $raw, 'hex' => bin2hex($raw), 'base64' => base64_encode($raw)] as $label => $candidate) {
            $cipher = SodiumTokenCipher::fromConfig($candidate);

            $this->assertInstanceOf(SodiumTokenCipher::class, $cipher, "{$label}-encoded key must build a cipher");
            $this->assertSame(self::TOKEN, $cipher->decrypt($cipher->encrypt(self::TOKEN)));
        }
    }

    public function test_from_config_returns_null_for_unusable_values(): void
    {
        $this->assertNull(SodiumTokenCipher::fromConfig(null));
        $this->assertNull(SodiumTokenCipher::fromConfig(''));
        $this->assertNull(SodiumTokenCipher::fromConfig(42));
        $this->assertNull(SodiumTokenCipher::fromConfig('too-short'), 'undecodable key must degrade to plaintext storage, not crash');
    }

    public function test_constructor_rejects_non_thirty_two_byte_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SodiumTokenCipher(str_repeat('k', 16));
    }
}
