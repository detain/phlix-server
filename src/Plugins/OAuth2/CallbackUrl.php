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
 * Resolves the ABSOLUTE `redirect_uri` an OAuth2/OIDC browser flow must send to
 * the provider.
 *
 * ## Why this exists (S48 review r1, Finding 1 — HIGH)
 *
 * Both browser flows used to pass a path-only callback (`/auth/github/callback`,
 * `/auth/oidc/callback`) as `redirect_uri`. GitHub matches the value's **scheme,
 * host and port** against the registered OAuth-App callback URL (and every OIDC
 * IdP matches it against a registered absolute redirect URI), so a path-only
 * value can never match: the authorize page answers `redirect_uri_mismatch` and
 * the browser never comes back. The flow could not complete against a real
 * provider.
 *
 * ## Resolution order
 *
 *  1. the operator-configured `redirect_uri` plugin setting, when it is a valid
 *     absolute http(s) URL — the explicit, documented path (the same pattern the
 *     working Trakt flow uses, {@see \Phlix\Server\Http\Controllers\TraktOAuthController});
 *  2. otherwise derive `<scheme>://<Host><callback path>` from the request, so a
 *     single-hostname deployment works with no configuration at all;
 *  3. otherwise `null` — the caller answers 503 with an actionable code rather
 *     than sending a value the provider is guaranteed to reject.
 *
 * A path-only value is NEVER produced.
 *
 * ## Is the Host-derived form safe?
 *
 * `Host` is client-supplied, but it cannot be turned into a redirect primitive:
 * a browser sets `Host` from the URL the user navigated to, and the provider
 * itself refuses any `redirect_uri` whose host is not the one registered for the
 * OAuth app. An attacker who forces a foreign `Host` therefore only breaks their
 * OWN flow (the authorize call fails), and no code is ever delivered to that
 * host. The value is additionally re-validated here (no control characters, no
 * credentials/@, host charset restricted) before it is used in a URL.
 *
 * @package Phlix\Plugins\OAuth2
 * @since 0.102.0
 */
final class CallbackUrl
{
    /**
     * Whether the given string is a usable ABSOLUTE http(s) callback URL.
     *
     * Rejects control characters (CR/LF header + query smuggling), non-http(s)
     * schemes, userinfo (`user@host` — which browsers and providers parse
     * inconsistently), and anything without a host.
     */
    public static function isAbsolute(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }
        if (str_contains($url, '@')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && self::isValidHost($host);
    }

    /**
     * Resolve the absolute callback URL for a flow, or null when neither the
     * configured setting nor the request yields one.
     *
     * @param string      $configured     The `redirect_uri` plugin setting (may be '').
     * @param string|null $host           The request's `Host` header (may be null).
     * @param string|null $forwardedProto The request's `X-Forwarded-Proto` header.
     * @param string      $callbackPath   The flow's fixed callback path, e.g.
     *                                    `/auth/github/callback`.
     */
    public static function resolve(
        string $configured,
        ?string $host,
        ?string $forwardedProto,
        string $callbackPath,
    ): ?string {
        $configured = trim($configured);
        if ($configured !== '' && self::isAbsolute($configured)) {
            return $configured;
        }

        return self::fromHost($host, $forwardedProto, $callbackPath);
    }

    /**
     * Build `<scheme>://<host><path>` from the request's `Host` header, or null
     * when there is no usable host.
     */
    private static function fromHost(?string $host, ?string $forwardedProto, string $callbackPath): ?string
    {
        $host = is_string($host) ? trim($host) : '';
        if ($host === '' || !self::isValidHost(self::stripPort($host))) {
            return null;
        }
        if (!self::isValidPortSuffix($host)) {
            return null;
        }

        $candidate = self::scheme($forwardedProto) . '://' . $host . $callbackPath;

        return self::isAbsolute($candidate) ? $candidate : null;
    }

    /**
     * The scheme to advertise: an explicit `X-Forwarded-Proto` wins; otherwise
     * https, downgraded to http only in the documented local-HTTP dev mode
     * (`PHLIX_COOKIE_INSECURE=1`, the same switch the auth cookies use).
     */
    private static function scheme(?string $forwardedProto): string
    {
        if (is_string($forwardedProto) && $forwardedProto !== '') {
            // A proxy chain may append values ("https, http") — the first hop wins.
            $first = strtolower(trim(explode(',', $forwardedProto)[0]));
            if ($first === 'https' || $first === 'http') {
                return $first;
            }
        }

        return getenv('PHLIX_COOKIE_INSECURE') === '1' ? 'http' : 'https';
    }

    /**
     * Host charset gate: DNS names, IPv4 literals, or a bracketed IPv6 literal.
     */
    private static function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (preg_match('/^\[[0-9A-Fa-f:.]+\]$/', $host) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/', $host) === 1;
    }

    /**
     * Strip a `:port` suffix from a `Host` header value.
     */
    private static function stripPort(string $host): string
    {
        if (str_starts_with($host, '[')) {
            $close = strpos($host, ']');
            return $close === false ? $host : substr($host, 0, $close + 1);
        }
        $colon = strrpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }

    /**
     * Whether the optional port suffix on a `Host` header is numeric and in range.
     */
    private static function isValidPortSuffix(string $host): bool
    {
        $bare = self::stripPort($host);
        if ($bare === $host) {
            return true;
        }
        $port = substr($host, strlen($bare) + 1);

        return preg_match('/^\d{1,5}$/', $port) === 1 && (int) $port >= 1 && (int) $port <= 65535;
    }
}
