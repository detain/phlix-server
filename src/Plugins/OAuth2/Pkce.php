<?php

/**
 * Phlix media server component: OAuth2.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Plugins\OAuth2;

use Phlix\Common\Token;
use Phlix\Shared\Security\Base64Url;
use Phlix\Shared\Security\Pkce as SharedPkce;

/**
 * RFC 7636 PKCE (Proof Key for Code Exchange) helpers, shared by every OAuth2
 * authorization-code provider (OIDC + GitHub).
 *
 * These were originally private static helpers on
 * {@see \Phlix\Plugins\Oidc\OidcProvider}; S48 extracts them here so the new
 * {@see AbstractOAuth2Provider} base and the GitHub flow reuse the exact same,
 * already-tested (OidcPkceTest) implementation rather than duplicating it.
 * {@see \Phlix\Plugins\Oidc\OidcProvider}'s public static helpers now delegate to
 * this class, so their contract (and OidcPkceTest) is unchanged.
 *
 * @package Phlix\Plugins\OAuth2
 * @since 0.102.0
 */
final class Pkce
{
    /**
     * Delegation law (phlix-shared v0.56.0 adoption, Wave-S1): the S256 challenge
     * formula and the base64url primitive below are thin delegates onto
     * {@see \Phlix\Shared\Security\Pkce} / {@see \Phlix\Shared\Security\Base64Url} —
     * byte-for-byte equivalence over a 1500+-vector harness at the parent
     * implementation. This class remains the in-repo import surface for the
     * OAuth2/OIDC/GitHub callers; do not re-implement the math here.
     */

    /**
     * Generate a cryptographically-random RFC 7636 `code_verifier`.
     *
     * Returns a 64-character string drawn from the unreserved-character set (hex
     * digits), well within the 43–128 char window the spec requires. Callers
     * persist this server-side keyed by the corresponding `state` and replay it
     * on the token exchange.
     */
    public static function generateCodeVerifier(): string
    {
        return Token::pkceVerifier();
    }

    /**
     * Compute the RFC 7636 S256 `code_challenge` for a verifier.
     */
    public static function computeCodeChallenge(string $codeVerifier): string
    {
        return SharedPkce::challengeFor($codeVerifier);
    }

    /**
     * URL-safe base64 encoding without padding, per RFC 7636 §4.2.
     */
    public static function base64UrlEncode(string $bytes): string
    {
        return Base64Url::encode($bytes);
    }
}
