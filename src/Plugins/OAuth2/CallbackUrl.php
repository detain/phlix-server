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
 * Fail-closed is the cheap choice here because there is almost nothing to
 * regress: review r1 finding 1 established that the *relative* `redirect_uri`
 * these flows sent before S48's fix round is rejected by any spec-strict provider
 * (`redirect_uri_mismatch`, the browser never returns), and GitHub auth is brand
 * new in S48. **One honest exception** (review r3, finding 1 — do not repeat the
 * earlier "nothing could have been working" overclaim): an IdP that resolves a
 * RELATIVE `redirect_uri` against the client's registered root URL — Keycloak
 * does — could have completed the pre-S48 OIDC flow, and now needs `PHLIX_DOMAIN`
 * to match the browsed hostname (or an absolute `redirect_uri` setting) or it
 * answers the 503. `scripts/install.sh` has written `PHLIX_DOMAIN` since
 * 2026-05-26, so for an installed box that is a one-line configuration step, not
 * a dead end. See `CHANGELOG.md` `[Unreleased]`.
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
 * ## The PORT is part of the origin (review r3, finding 3 — LOW)
 *
 * The allowlist compares the whole `host[:port]` AUTHORITY, not the bare host, so
 * the gate pins the origin it claims to pin:
 *
 *  - `PHLIX_DOMAIN=media.example.com` accepts `Host: media.example.com` and
 *    REFUSES `Host: media.example.com:1337` — which previously produced
 *    `redirect_uri=https://media.example.com:1337/auth/github/callback`;
 *  - `PHLIX_DOMAIN=media.example.com:8443` accepts only that exact `host:port`.
 *
 * A port that is the DEFAULT for the resolved scheme (`:443` under https, `:80`
 * under http) is normalised away on BOTH sides, so it neither blocks a match nor
 * leaks into the derived URL — the provider has the default-port form registered.
 * That normalisation is load-bearing in production, not cosmetic: see
 * {@see self::stripDefaultPort()} for the verified reason (a real front-end does
 * present `Host: <domain>:443`) and do not remove it.
 *
 * The derived value is re-validated here in every case (no control characters, no
 * credentials/@, host charset restricted, numeric in-range port) before it is
 * used in a URL.
 *
 * ## Reaching the app directly on its listen port now fails closed
 *
 * phlix-server also binds `0.0.0.0:8096`, so a client that bypasses the reverse
 * proxy presents `Host: <domain>:8096` — a DIFFERENT origin from the proxied
 * `<domain>`/`<domain>:443` pair, so it answers `503 callback_url_not_configured`
 * instead of deriving `https://<domain>:8096/auth/{provider}/callback`. That is the
 * intended outcome: the callback registered with the OAuth App / IdP is the
 * proxied form, so the provider would have rejected the ported value with
 * `redirect_uri_mismatch`. The failure simply moves earlier and says what to fix.
 * An operator who genuinely serves auth on that port sets the provider's absolute
 * `redirect_uri` setting (resolution step 1), which is the escape hatch for every
 * non-canonical origin.
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
     * The ONE public authority (`host` or `host:port`) a request-derived callback
     * URL may use, or '' when none is configured — in which case NOTHING may be
     * derived (fail closed; see the class docblock).
     *
     * Read from `PHLIX_DOMAIN` — set by `scripts/install.sh --domain` and the SAME
     * env value `config/hub.php` composes into `hub.domain`/`hub.public_url`. The
     * env is read rather than `$config['hub']['domain']` deliberately:
     * `config/hub.php:42` substitutes the literal `phlix.media` when the env is
     * empty, so the config value is NEVER empty and gating on it would silently
     * allowlist somebody else's domain on an unconfigured box (review r2, NEW-1).
     * Garbage in the env (a URL, a path, an out-of-range port) is treated as
     * "unconfigured" rather than as an allowlist that can never match — either way
     * the outcome is the actionable 503, never a derived URL. Seven malformed
     * shapes are pinned by
     * `GithubCallbackControllerTest::test_a_garbage_phlix_domain_fails_closed_like_an_unset_one`
     * (OIDC twin covers five of them), each asserted to produce the 503 with no
     * `Location`, no correlation cookie and no state row:
     * `https://phlix.test/`, `phlix.test/app`, `phlix.test:`, `phlix.test.`,
     * `phlix.test:99999`, `phlix.test:https`, `'   '`.
     *
     * Any `:port` the operator configured is KEPT (review r3 finding 3): the port
     * belongs to the origin this gate exists to pin, so it is part of the compared
     * value rather than stripped.
     *
     * Note for test authors: the PHPUnit suite is hermetic with respect to an
     * ambient `PHLIX_DOMAIN` — every test that depends on the value sets it in
     * `setUp()` and restores the ambient value in `tearDown()`, and the
     * no-allowlist cases pass `''` explicitly rather than relying on the env being
     * unset. Verified at individual-test granularity: running the whole suite with
     * and without `PHLIX_DOMAIN` exported yields identical per-test assertion
     * counts. Keep it that way — do not read this env in a test without restoring it.
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

        return $domain;
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
     * @param string      $allowedHost    The ONLY authority (`host[:port]`) a
     *                                    `Host`-derived value may use
     *                                    ({@see self::configuredHost()}); '' =
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
     * The comparison is authority-wise and bracket/default-port normalised
     * ({@see self::authorityKey()}), so it survives an IPv6 literal and an
     * explicit `:443`/`:80` on either side (review r3 findings 3 and 4).
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
        if (!is_string($host) || $host === '') {
            return false;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT);
        // Re-assemble the authority the way a `Host` header carries it. PHP's
        // parse_url() keeps the brackets on an IPv6 literal, but wrap it defensively
        // so authorityKey() never has to guess where the port starts.
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $authority = $host . (is_int($port) ? ':' . $port : '');

        return strcasecmp(
            self::authorityKey($authority, is_string($scheme) ? strtolower($scheme) : ''),
            self::authorityKey($allowedHost, is_string($scheme) ? strtolower($scheme) : ''),
        ) === 0;
    }

    /**
     * A log-safe rendering of a client-supplied `Host` header, for the actionable
     * 503's log line. Thin alias of {@see self::sanitizeForLog()}.
     */
    public static function sanitizeHostForLog(?string $host): string
    {
        return self::sanitizeForLog($host);
    }

    /**
     * Bounded, log-safe rendering of an ATTACKER-SUPPLIED string (a `Host` header,
     * a `sid` read out of the client's `state` envelope, …).
     *
     * Everything outside printable US-ASCII is dropped and the result is capped at
     * 128 bytes, so a log field stays one short readable token whatever the client
     * sent. Byte-wise on purpose (no `/u`): every byte >= 0x80 goes too, so no
     * partial multi-byte sequence can survive and the cap can never split a
     * character.
     *
     * ## This is log HYGIENE, not a log-forging fix (review r3, finding 2)
     *
     * An earlier version of this docblock claimed a CR/LF "would otherwise let a
     * forged Host forge whole log lines through the Monolog line formatter". That
     * is **empirically false for this pipeline** and the claim is not repeated
     * here: `Phlix\Common\Logger\StructuredLogger` never calls `setFormatter()`, so
     * Monolog's default `LineFormatter` applies with `allowInlineLineBreaks = false`
     * and `%context%` rendered through `toJson()` — a `"\r\n"` in a context value
     * comes out as the two-character escape `\r\n` INSIDE the JSON blob, one record
     * per line. So this helper is defence in depth (it must not depend on formatter
     * configuration, which a future change could flip) plus a bounded field size;
     * it is NOT what stands between the app and log injection today.
     */
    public static function sanitizeForLog(?string $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '(none)';
        }
        $clean = preg_replace('/[^\x21-\x7E]/', '', $value) ?? '';

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

        $scheme = self::scheme($forwardedProto);
        // A default port for this scheme is dropped from BOTH the presented Host and
        // the derived URL: `https://host:443/cb` is the same origin as
        // `https://host/cb`, and it is the latter an operator registers with the
        // provider.
        $host = self::stripDefaultPort($host, $scheme);
        // NEW-1 — a client-supplied Host may only be turned into a redirect_uri
        // when it IS the operator's public authority. Otherwise fall through to null
        // so the caller answers 503 callback_url_not_configured. r3 finding 3: the
        // PORT is compared too, so `Host: media.example.com:1337` no longer rides in
        // on `PHLIX_DOMAIN=media.example.com`.
        if (strcasecmp(self::authorityKey($host, $scheme), self::authorityKey($allowedHost, $scheme)) !== 0) {
            return null;
        }

        $candidate = $scheme . '://' . $host . $callbackPath;

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
     * Drop a `:port` suffix that is the DEFAULT for the given scheme, so
     * `example.com:443` under https (and `example.com:80` under http) compares —
     * and renders — as `example.com`.
     *
     * ## DO NOT "simplify" this away — it is what keeps production logging in
     *
     * This helper looks like cosmetic tidying next to {@see self::authorityKey()}.
     * It is not: without it, whole-authority comparison (review r3 finding 3) would
     * refuse every request whose `Host` carries an explicit `:443`, and real
     * front-ends send exactly that. Verified read-only against the live deployment
     * (S48 TestEngineer, 2026-07-25) — HAProxy 3.2.9 in front of phlix-server:
     *
     *  - it performs NO `Host` rewriting anywhere (no `set-header Host`,
     *    `replace-header Host`, `reqirep`, `add-header Host`, `set-uri`) — the
     *    `server phlix 127.0.0.1:8096` line is a TCP target, not a `Host` override —
     *    so the app sees the `Host` the browser sent;
     *  - its routing ACL is an EXACT `hdr(host) -i <domain>` equality match, and
     *    HAProxy normalises the scheme's default port BEFORE that ACL, so
     *    `Host: <domain>:443` is routed to the application just like the bare
     *    `Host: <domain>` (probed: both reach the app; `Host: <domain>:8096` is
     *    answered by HAProxy's 404 default backend and never reaches the app).
     *
     * So `<domain>` and `<domain>:443` are BOTH authorities the application really
     * receives. With this normalisation both derive the registered, portless
     * callback URL. Remove it and every `Host: <domain>:443` request answers
     * `503 callback_url_not_configured` — i.e. an intermittent, front-end-dependent
     * total login outage. Pinned by
     * `CallbackUrlTest::test_the_production_haproxy_authority_still_derives_the_registered_callback`
     * (neutering this method to `return $host` turns 13 tests RED).
     *
     * The one deliberate consequence: a client that BYPASSES the proxy and talks to
     * the app's own listen port sends `Host: <domain>:8096`, which is a different
     * origin and now fails closed instead of deriving a `:8096` callback. Nothing
     * completable is lost — the provider has the `:443` form registered, so it
     * would have answered `redirect_uri_mismatch` anyway; the failure just moves
     * earlier and names the remedy. See the class docblock's escape hatch.
     */
    private static function stripDefaultPort(string $host, string $scheme): string
    {
        $bare = self::stripPort($host);
        if ($bare === $host) {
            return $host;
        }
        $port = substr($host, strlen($bare) + 1);

        return ($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80')
            ? $bare
            : $host;
    }

    /**
     * The comparison key for a `host[:port]` authority: default port for the scheme
     * removed, IPv6 brackets removed.
     *
     * The bracket normalisation is review r3 finding 4. That finding's premise —
     * that `parse_url($url, PHP_URL_HOST)` returns an UNBRACKETED `::1` — does not
     * reproduce on this PHP (it returns `[::1]`), so the replay check was not dead;
     * but `Host: [::1]:8096` vs `PHLIX_DOMAIN=[::1]:8096` DOES need the port and the
     * brackets handled together, and normalising both sides means the check cannot
     * silently die if any producer ever hands over the bare form.
     *
     * @param string $scheme Lower-cased URL scheme, '' when unknown (then no port is
     *                       treated as default).
     */
    private static function authorityKey(string $host, string $scheme): string
    {
        $host = self::stripDefaultPort($host, $scheme);
        $bare = self::stripPort($host);
        $port = $bare === $host ? '' : substr($host, strlen($bare));

        return trim($bare, '[]') . $port;
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
