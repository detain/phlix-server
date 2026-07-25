<?php

/**
 * Phlix media server component: OAuth2.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\OAuth2;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Binds an issued OAuth2/OIDC `state` to the BROWSER that started the flow.
 *
 * ## Why (S48 review r1 Finding 2, extended to OIDC in review r2)
 *
 * A one-shot server-side state store stops CSRF/replay but says nothing about
 * WHICH browser completes the flow. Without a browser binding an attacker can run
 * the whole authorize + provider login themselves with their OWN account, then
 * have a victim's browser issue the resulting `…/callback?code=…&state=…` request
 * (an image tag is enough — `SameSite=Lax` does not restrict `Set-Cookie` on a
 * top-level navigation, and the callback answers 302 + session cookies). The
 * victim ends up silently logged in as the ATTACKER — classic session fixation,
 * and the entry point for "look at your library" phishing.
 *
 * Neither PKCE nor the OIDC `nonce` closes this: both bind the exchange to the
 * authorize request, and the ATTACKER owns that request, so both match for them.
 *
 * ## The mechanism
 *
 * At authorize time a 32-byte CSPRNG secret is issued: the raw value goes to the
 * browser as a short-lived HttpOnly+Secure+SameSite=Lax cookie, and only its
 * SHA-256 is persisted in the server-side state context. The callback recomputes
 * the hash from the presented cookie and requires a {@see hash_equals()} match
 * BEFORE any token exchange, account creation, link, or session-cookie mint.
 * Neither a leaked `state` blob nor a database read lets a third party satisfy
 * the check, and the cookie's `Max-Age` matches the state store's TTL so no
 * slow-user expiry window is introduced.
 *
 * Each flow passes its OWN cookie name (`phlix_oauth_github`, `phlix_oauth_oidc`)
 * so two providers cannot clobber each other's in-flight binding.
 *
 * @package Phlix\Plugins\OAuth2
 * @since 0.102.0
 */
final class StateCorrelation
{
    /** The state-context key holding the SHA-256 of the correlation secret. */
    public const string CONTEXT_KEY = 'correlation';

    /**
     * Cookie lifetime in seconds. Deliberately EQUAL to the 600 s state-row TTL
     * both stores use ({@see DbOAuth2StateStore}, {@see \Phlix\Plugins\Oidc\DbOidcStateStore}):
     * the cookie must outlive the round trip to the provider but no longer, and a
     * shorter value would 403 a slow user whose state row is still valid.
     */
    public const int TTL_SECONDS = 600;

    /**
     * Mint a fresh correlation secret (raw value for the cookie).
     */
    public static function issue(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The value stored in the state context — never the secret itself.
     */
    public static function fingerprint(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Attach the short-lived, HttpOnly browser-binding cookie to the authorize
     * redirect. Same Secure/SameSite policy as the session cookies (Secure is
     * dropped ONLY in the documented local-HTTP dev mode).
     */
    public static function attach(Response $response, string $cookieName, string $secret): void
    {
        $response->cookie(
            $cookieName,
            $secret,
            maxAge: self::TTL_SECONDS,
            secure: getenv('PHLIX_COOKIE_INSECURE') !== '1',
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    /**
     * Whether the consumed state was issued to the browser making this callback.
     *
     * Fails CLOSED: a state row without a correlation fingerprint, or a request
     * without the cookie, does not match.
     *
     * @param array<string, mixed> $context The trusted server-side state context.
     */
    public static function matches(Request $request, string $cookieName, array $context): bool
    {
        $expected = is_string($context[self::CONTEXT_KEY] ?? null) ? $context[self::CONTEXT_KEY] : '';
        if ($expected === '') {
            return false;
        }

        $presented = $request->getCookie($cookieName);
        if (!is_string($presented) || $presented === '') {
            return false;
        }

        return hash_equals($expected, self::fingerprint($presented));
    }

    /**
     * Expire the correlation cookie once its state has been consumed (review r2,
     * NEW-10). The state is one-shot so a leftover cookie is harmless, but
     * clearing it makes a stale cookie self-healing instead of lingering for the
     * full TTL.
     */
    public static function clear(Response $response, string $cookieName): Response
    {
        return $response->clearCookie($cookieName);
    }
}
