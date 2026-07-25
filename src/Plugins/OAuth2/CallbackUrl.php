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
 *     hostname ({@see self::configuredHost()}), so a single-hostname deployment
 *     that ran `scripts/install.sh --domain` needs no per-plugin configuration;
 *  3. otherwise `null` — the caller answers 503 with an actionable code rather
 *     than sending a value the provider is guaranteed to reject.
 *
 * A path-only value is NEVER produced, and neither is a value derived from an
 * unvouched-for `Host`.
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
 * ## Fail CLOSED when there is no allowlist (review r3)
 *
 * Review r2's first cut treated an unset `PHLIX_DOMAIN` as "no allowlist" and
 * kept deriving from any syntactically valid `Host`. That left the attack above
 * **fully open on every deployment that never ran `install.sh --domain`** — i.e.
 * exactly the population least likely to notice. So the rule is now:
 *
 *   **no configured public hostname ⇒ NO derivation at all** ⇒ `resolve()`
 *   returns null ⇒ the caller answers `503 callback_url_not_configured` with an
 *   actionable message (set `PHLIX_DOMAIN`, or set the plugin's absolute
 *   `redirect_uri` setting — resolution step 1, which keeps first priority and
 *   works with `PHLIX_DOMAIN` unset).
 *
 * This cannot regress a working deployment, which is what makes fail-closed the
 * cheap choice here: review r1 finding 1 established that the *relative*
 * `redirect_uri` these flows sent before S48's fix round makes them fail against
 * any real provider (`redirect_uri_mismatch`, the browser never returns). GitHub
 * auth is brand new in S48 and OIDC has been broken against a real IdP since S44
 * wired it, so there is no population of working end-to-end deployments to
 * break. Fail-closed costs nobody a working login; fail-open costs everyone who
 * has not set one env var.
 *
 * ## Accepted behaviour change (documented so it is not a surprise)
 *
 * With `PHLIX_DOMAIN` set, reaching `/auth/{provider}/authorize` over anything
 * OTHER than that hostname — a LAN IP (`https://192.168.1.10:8096/…`), `localhost`, or the
 * relay/hub hostname — now answers `503 callback_url_not_configured` instead of
 * deriving a callback from it. Such a flow could never have completed anyway: the
 * provider has the registered domain, not the LAN IP, so it would have answered
 * `redirect_uri_mismatch`. An operator who genuinely serves auth on a second
 * hostname sets that provider's `redirect_uri` setting to the absolute URL they
 * registered with the provider (resolution step 1, unaffected by the allowlist).
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
     * The ONE public hostname a request-derived callback URL may use, or '' when
     * none is configured — in which case NOTHING may be derived (fail closed; see
     * the class docblock).
     *
     * Read from `PHLIX_DOMAIN` — set by `scripts/install.sh --domain` and the SAME
     * env value `config/hub.php` composes into `hub.domain`/`hub.public_url`. The
     * env is read rather than `$config['hub']['domain']` deliberately:
     * `config/hub.php:42` substitutes the literal `phlix.media` when the env is
     * empty, so the config value is NEVER empty and gating on it would silently
     * allowlist somebody else's domain on an unconfigured box (review r2, NEW-1).
     * Garbage in the env (a URL, a path, an out-of-range port) is treated as
     * "unconfigured" rather than as an allowlist that can never match — either way
     * the outcome is the actionable 503, never a derived URL.
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
     *                                    nothing may be derived at all (fail
     *                                    closed — review r3). Required, not
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
     * setting or hosted on the allowlisted hostname — mirroring the two ways
     * {@see self::resolve()} can produce one. With no allowlist configured only the
     * configured setting can match, exactly as on the authorize leg (fail closed,
     * review r3); an in-flight state row that predates this rule falls back to a
     * fresh resolve, which answers the actionable 503.
     *
     * @param string $url         The state-carried callback URL.
     * @param string $configured  The `redirect_uri` plugin setting (may be '').
     * @param string $allowedHost {@see self::configuredHost()}; '' = only the
     *                            configured setting is replayable.
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
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $allowedHost) === 0;
    }

    /**
     * A log-safe rendering of a client-supplied `Host` header, for the actionable
     * 503's log line.
     *
     * `Host` is attacker-controlled, so it is stripped of everything outside
     * printable US-ASCII (a CR/LF would otherwise let a forged Host forge whole log
     * lines) and length-capped.
     */
    public static function sanitizeHostForLog(?string $host): string
    {
        if (!is_string($host) || trim($host) === '') {
            return '(none)';
        }
        $clean = preg_replace('/[^\x21-\x7E]/', '', $host) ?? '';

        return $clean === '' ? '(unprintable)' : substr($clean, 0, 128);
    }

    /**
     * Build `<scheme>://<host><path>` from the request's `Host` header, or null
     * when no public hostname is configured, when there is no usable host, or when
     * the presented host is not the configured one (review r2 NEW-1 / r3).
     */
    private static function fromHost(
        ?string $host,
        ?string $forwardedProto,
        string $callbackPath,
        string $allowedHost,
    ): ?string {
        // FAIL CLOSED (review r3). No configured public hostname ⇒ there is nothing
        // to vouch for the client-supplied Host with, so refuse to derive at all
        // rather than reopening the NEW-1 phishing chain on every box that never ran
        // `install.sh --domain`. The caller answers 503 callback_url_not_configured,
        // which the operator fixes with PHLIX_DOMAIN or an explicit `redirect_uri`
        // setting. See the class docblock for why this breaks no working deployment.
        if ($allowedHost === '') {
            return null;
        }

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
        if (strcasecmp($bare, $allowedHost) !== 0) {
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
