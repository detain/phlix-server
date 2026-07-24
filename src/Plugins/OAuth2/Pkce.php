<?php

/**
 * Phlix media server component: OAuth2.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\OAuth2;

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
     * Generate a cryptographically-random RFC 7636 `code_verifier`.
     *
     * Returns a 64-character string drawn from the unreserved-character set (hex
     * digits), well within the 43–128 char window the spec requires. Callers
     * persist this server-side keyed by the corresponding `state` and replay it
     * on the token exchange.
     */
    public static function generateCodeVerifier(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Compute the RFC 7636 S256 `code_challenge` for a verifier.
     */
    public static function computeCodeChallenge(string $codeVerifier): string
    {
        return self::base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    /**
     * URL-safe base64 encoding without padding, per RFC 7636 §4.2.
     */
    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
