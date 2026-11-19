<?php

/**
 * Phlix media server component: Common tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Token;

/**
 * E-13 — the named, size-pinned token generators. Each test pins the
 * published SHAPE (length + alphabet) of a policy so a future edit that
 * silently changes a security state's entropy budget reddens here, at the
 * table, not in an OAuth handshake at 3am.
 */
final class TokenTest extends TestCase
{
    public function test_state_is_16_bytes_of_lowercase_hex(): void
    {
        $state = Token::state();

        $this->assertSame(32, \strlen($state), 'OAuth/OIDC state budget is 16 bytes = 32 hex chars.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $state);
    }

    public function test_pkce_verifier_is_32_bytes_fitting_the_rfc_7636_window(): void
    {
        $verifier = Token::pkceVerifier();

        $this->assertSame(64, \strlen($verifier), 'PKCE code_verifier budget is 32 bytes = 64 hex chars.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $verifier);
        // RFC 7636 §4.1: the verifier must sit inside the 43..128 unreserved set.
        $this->assertGreaterThanOrEqual(43, \strlen($verifier));
        $this->assertLessThanOrEqual(128, \strlen($verifier));
    }

    public function test_reset_token_is_32_bytes_the_raised_post_accident_budget(): void
    {
        $token = Token::resetToken();

        $this->assertSame(
            64,
            \strlen($token),
            'Reset secrets are 32 bytes: the 12-byte CLI accident was deliberately risen (E-13); '
                . 'anything smaller reopens the offline-guess budget the audit closed.'
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_invite_token_is_32_bytes_matching_the_admin_controller_era_budget(): void
    {
        $token = Token::inviteToken();

        $this->assertSame(64, \strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_correlation_secret_is_32_bytes(): void
    {
        $secret = Token::correlation();

        $this->assertSame(64, \strlen($secret), 'StateCorrelation browser-binding secret is 32 bytes.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function test_jti_is_16_bytes_matching_the_jwt_handler_refresh_wire_shape(): void
    {
        $jti = Token::jti();

        $this->assertSame(
            32,
            \strlen($jti),
            'Token::jti() is the JWT id mint itself — JwtHandler delegates its refresh '
                . 'jti here (SRV-TINIES ruling (ii)) — and a width change would silently '
                . 'move JWT wire payloads and overflow the CHAR(32) auth_refresh_grants.jti PK.'
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $jti);
    }

    public function test_tmp_suffix_is_8_bytes(): void
    {
        $suffix = Token::tmpSuffix();

        $this->assertSame(16, \strlen($suffix));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $suffix);
    }

    public function test_prefixed_carries_the_wire_prefix_verbatim(): void
    {
        $boundary = Token::prefixed('phlix-', 16);
        $groupId = Token::prefixed('sp_', 8);

        $this->assertMatchesRegularExpression('/^phlix-[0-9a-f]{32}$/', $boundary);
        $this->assertMatchesRegularExpression('/^sp_[0-9a-f]{16}$/', $groupId);
    }

    public function test_prefixed_refuses_an_empty_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Token::prefixed('', 16);
    }

    public function test_prefixed_refuses_a_non_positive_byte_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Token::prefixed('sp_', 0);
    }

    public function test_prefixed_refuses_an_absurd_byte_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Token::prefixed('sp_', 65);
    }

    public function test_consecutive_mints_never_repeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[] = Token::state();
        }

        $this->assertCount(200, \array_unique($seen), '200 state mints must all be distinct.');
    }

    public function test_the_entropy_stream_is_not_mt_srand_steerable(): void
    {
        // Same discipline as UuidOrderSeedFuzzTest: pin the global Mersenne
        // Twister, mint, re-pin to the same constant, mint again. If any
        // generator ever falls back to mt_rand(), the two batches collide
        // deterministically; with random_bytes() they cannot.
        \mt_srand(20261119);
        $first = [];
        for ($i = 0; $i < 50; $i++) {
            $first[] = Token::state() . Token::tmpSuffix() . Token::prefixed('sp_', 8);
        }

        \mt_srand(20261119);
        $second = [];
        for ($i = 0; $i < 50; $i++) {
            $second[] = Token::state() . Token::tmpSuffix() . Token::prefixed('sp_', 8);
        }

        $this->assertNotSame($first, $second, 'Token mints replayed under a re-pinned mt_srand must not repeat.');
    }

    public function test_generators_are_pairwise_independent(): void
    {
        $all = [
            Token::state(),
            Token::pkceVerifier(),
            Token::resetToken(),
            Token::inviteToken(),
            Token::correlation(),
            Token::jti(),
            Token::tmpSuffix(),
        ];

        $this->assertCount(\count($all), \array_unique($all));
    }
}
