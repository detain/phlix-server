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
 *  2. otherwise derive `<scheme>://<Host><callback path>` from the request — but
 *     ONLY when the request's `Host` matches the operator-configured public
 *     hostname ({@see self::configuredHost()}) — so a single-hostname deployment
 *     works with no configuration at all;
 *  3. otherwise `null` — the caller answers 503 with an actionable code rather
 *     than sending a value the provider is guaranteed to reject.
 *
 * A path-only value is NEVER produced.
 *
 * ## The Host allowlist (review r2, NEW-1 — MED)
 *
 * `Host` is client-supplied. Review r1 accepted the derived form on the argument
 * that "a forged Host only breaks the attacker's own flow, because the provider
 * refuses any `redirect_uri` whose host is not registered". That is true of
 * GitHub (an OAuth App has exactly ONE registered callback) but it is NOT true of
 * a generic OIDC IdP: Keycloak and friends accept WILDCARD redirect
 * registrations (`https://host/*`, sometimes `*`) and multi-host lists. Against
 * such an IdP a forged `Host` is a real, non-self-inflicted attack:
 *
 *   1. the attacker calls `/auth/oidc/authorize` from a non-browser client with
 *      `Host: evil.example`;
 *   2. the answer is an authorize URL whose `redirect_uri` points at the
 *      ATTACKER's host;
 *   3. the attacker phishes that URL to a victim, who authenticates at the
 *      genuine IdP (genuine domain, genuine TLS);
 *   4. the IdP's wildcard registration accepts the redirect and delivers the
 *      VICTIM's `code` to the attacker's host;
 *   5. the attacker replays `code`+`state` and gets a session for the victim.
 *
 * The browser-binding correlation cookie does NOT defend this — the attacker ran
 * the authorize leg, so the attacker holds the cookie. The fix is this allowlist:
 * derivation happens only when the request `Host` (port stripped) matches the
 * operator-configured public hostname, i.e. `PHLIX_DOMAIN` — the same env value
 * `config/hub.php` turns into `hub.domain` / `hub.public_url`. On a mismatch
 * `resolve()` returns null and the caller answers
 * `503 callback_url_not_configured`, which an operator fixes by setting the
 * provider's `redirect_uri` setting (resolution step 1, unaffected).
 *
 * When `PHLIX_DOMAIN` is unset (a dev box / an install that never ran
 * `--domain`) there is no allowlist to enforce and the pre-r2 behaviour is kept:
 * gating on the `config/hub.php` fallback literal (`phlix.media`) would 503 every
 * login on such a box, which is strictly worse than the risk it removes.
 *
 * The derived value is re-validated here in every case (no control characters, no
 * credentials/@, host charset restricted, numeric in-range port) before it is
 * used in a URL.
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
     * The operator-configured public hostname derivation is allowed for, or '' when
     * none is configured (no allowlist → derive from any syntactically valid Host).
     *
     * Read from `PHLIX_DOMAIN` — set by `scripts/install.sh --domain` and the SAME
     * env value `config/hub.php` composes into `hub.domain`/`hub.public_url`. The
     * env is read rather than `$config['hub']['domain']` deliberately:
     * `config/hub.php:42` substitutes the literal `phlix.media` when the env is
     * empty, so the config value is NEVER empty and gating on it would 503 every
     * login on an unconfigured box (review r2, NEW-1). Garbage in the env is
     * ignored (treated as "unconfigured") rather than producing an allowlist that
     * can never match.
     */
    public static function configuredHost(): string
    {
        $domain = getenv('PHLIX_DOMAIN');
        $domain = is_string($domain) ? strtolower(trim($domain)) : '';
        if ($domain === '') {
            return '';
        }
        $bare = self::stripPort($domain);
        if (!self::isValidHost($bare) || !self::isValidPortSuffix($domain)) {
            return '';
        }

        return $bare;
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
     * @param string      $allowedHost    The ONLY hostname a `Host`-derived value may
     *                                    use ({@see self::configuredHost()}); '' =
     *                                    no allowlist configured. Required, not
     *                                    defaulted, so a new call site cannot
     *                                    silently opt out of the r2 NEW-1 gate.
     */
    public static function resolve(
        string $configured,
        ?string $host,
        ?string $forwardedProto,
        string $callbackPath,
        string $allowedHost,
    ): ?string {
        $configured = trim($configured);
        if ($configured !== '' && self::isAbsolute($configured)) {
            return $configured;
        }

        return self::fromHost($host, $forwardedProto, $callbackPath, $allowedHost);
    }

    /**
     * Whether a callback URL replayed from the server-side state may still be used
     * as the token-exchange `redirect_uri` (review r2, NEW-8 — defence in depth).
     *
     * Only server code writes `context['callback_url']` today, so this is not
     * reachable input; re-running the authorize-time rules on the way out is cheap
     * now that they exist, and it also hardens the legacy-row fallback. A value is
     * replayable when it is absolute AND either byte-identical to the configured
     * setting or (with an allowlist in force) hosted on the allowlisted hostname.
     *
     * @param string $url         The state-carried callback URL.
     * @param string $configured  The `redirect_uri` plugin setting (may be '').
     * @param string $allowedHost {@see self::configuredHost()}; '' = no allowlist.
     */
    public static function isReplayable(string $url, string $configured, string $allowedHost): bool
    {
        if (!self::isAbsolute($url)) {
            return false;
        }
        if (trim($configured) !== '' && trim($configured) === $url) {
            return true;
        }
        if ($allowedHost === '') {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $allowedHost) === 0;
    }

    /**
     * Build `<scheme>://<host><path>` from the request's `Host` header, or null
     * when there is no usable host — or when an allowlist is configured and the
     * presented host is not it (review r2, NEW-1).
     */
    private static function fromHost(
        ?string $host,
        ?string $forwardedProto,
        string $callbackPath,
        string $allowedHost,
    ): ?string {
        $host = is_string($host) ? trim($host) : '';
        $bare = self::stripPort($host);
        if ($host === '' || !self::isValidHost($bare)) {
            return null;
        }
        if (!self::isValidPortSuffix($host)) {
            return null;
        }
        // NEW-1 — a client-supplied Host may only be turned into a redirect_uri
        // when it IS the operator's public hostname. Otherwise fall through to
        // null so the caller answers 503 callback_url_not_configured.
        if ($allowedHost !== '' && strcasecmp($bare, $allowedHost) !== 0) {
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
