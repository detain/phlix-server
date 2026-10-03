<?php

/**
 * Phlix media server component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Server\Http\Response;

/**
 * Applies repo-wide security headers to every HTTP response.
 *
 * Adds:
 *  - X-Content-Type-Options: nosniff
 *  - X-Frame-Options: from {@see SecurityHeadersPolicy::frameOptions()}
 *    (settings key `security.frame_options`, default SAMEORIGIN — the value
 *    this class hardcoded before the key existed; NONE omits the header)
 *  - Strict-Transport-Security with max-age from
 *    {@see SecurityHeadersPolicy::hstsMaxAgeSeconds()} (settings key
 *    `security.hsts_max_age_seconds`, default 1 year + includeSubDomains)
 *  - Content-Security-Policy scoped to the SPA (`script-src 'self'` — never
 *    `'unsafe-inline'`, with an opt-in per-request nonce for the SPA shell's
 *    inline bootstrap block; `style-src 'self' 'unsafe-inline'` — inline STYLE
 *    is deliberate, the SPA applies theme tokens as per-element inline styles
 *    via `el.style.setProperty()`, and element style attributes are exactly
 *    what `'unsafe-inline'` in `style-src` governs; `media-src`/`worker-src
 *    'self' blob:` so hls.js can attach its MSE `blob:` object URL and spawn
 *    its transmux Web Worker; frame-ancestors SAMEORIGIN so the SPA can embed
 *    itself but no external domain can)
 *
 * Called from {@see \Phlix\Server\Workerman\HttpHandler} after CORS decoration
 * on every response that passes through the Workerman entrypoint. (The
 * historical second caller — the pre-S171 CGI front controller `public/index.php`
 * — no longer exists; this handler is the sole production entry.)
 *
 * @package Phlix\Server\Http\Middleware
 */
final class SecurityHeaders
{
    /** @var SecurityHeadersPolicy Per-request effective values (live reads) */
    private SecurityHeadersPolicy $policy;

    /**
     * @param SecurityHeadersPolicy|null $policy Settings-backed values; NULL
     *        (the pre-W4 construction shape) emits the historical hardcoded
     *        pair — SAMEORIGIN + max-age=31536000 — because a policy built
     *        without a store answers exactly those shipped constants.
     */
    public function __construct(?SecurityHeadersPolicy $policy = null)
    {
        $this->policy = $policy ?? new SecurityHeadersPolicy();
    }

    /**
     * Apply security headers to a response.
     *
     * @param Response $response The response to decorate (mutated in place).
     *
     * @return Response The same response (for chaining).
     */
    public function decorate(Response $response): Response
    {
        $headers = $response->headers;

        // Guard: don't overwrite an existing value (caller wins). HTTP header
        // names are case-insensitive (RFC 9110 SS4.2) and Response::header() keys
        // by the exact case it was handed, so the lookup must be case-insensitive
        // too — a caller that set 'content-security-policy' (lowercase) still
        // "wins", and decorating on top of it would ship two CSPs (browsers
        // enforce the UNION of all policies, so the weaker-looking duplicate key
        // silently changes semantics). Mirrors Response::asHeadReply()'s
        // strcasecmp scan.
        if (!self::hasHeader($headers, 'X-Content-Type-Options')) {
            $response->header('X-Content-Type-Options', 'nosniff');
        }

        // X-Frame-Options via the policy's CLOSED value set — frameOptions()
        // can only return DENY, SAMEORIGIN or the NONE sentinel; NONE omits
        // the legacy header (the always-on CSP frame-ancestors 'self' below
        // is what modern browsers obey either way — see the policy's
        // DO-NOT-EXPOSE note for why the CSP itself is not settings-backed).
        $frameOptions = $this->policy->frameOptions();
        if ($frameOptions !== SecurityHeadersPolicy::NONE && !self::hasHeader($headers, 'X-Frame-Options')) {
            $response->header('X-Frame-Options', $frameOptions);
        }

        // HSTS: emitted with the policy's clamped max-age + the fixed
        // includeSubDomains suffix (not configurable by design — a shorter
        // subdomain pin needs its own structured key, not a raw header box).
        // NOTE (pre-existing, disclosed not introduced): the historical
        // comment here said "only on secure connections", but this class has
        // never had request/TLS context and always emitted the header; the
        // plain-text MITM-injection concern remains a known open item.
        // max-age=0 is the standard clear-the-pin signal.
        if (!self::hasHeader($headers, 'Strict-Transport-Security')) {
            $response->header(
                'Strict-Transport-Security',
                'max-age=' . $this->policy->hstsMaxAgeSeconds() . '; includeSubDomains'
            );
        }

        // CSP: restrictive by default. script-src is 'self' only — inline SCRIPT
        // is never whitelisted wholesale; the SPA shell opts into a per-request
        // nonce instead (see below). style-src CARRIES 'unsafe-inline' on
        // purpose: the SPA themes by setting CSS custom properties through
        // el.style.setProperty(), which lands in element style attributes — the
        // exact construct 'unsafe-inline' in style-src permits (and what
        // AGENTS.md's theming note calls "no script-src relaxation").
        // frame-ancestors 'self' lets the SPA embed its own pages but blocks
        // clickjacking from external iframes. base-uri 'self' (not NONE — the
        // SPA shell resolves relative asset URLs against its own origin)
        // restricts injected <base> tags to same-origin.
        //
        // Guard: a caller that already set its own CSP wins. The SPA shell
        // ({@see \Phlix\Server\WebPortal\Controllers\SharedUiController}) does
        // exactly this — it serves an inline bootstrap `<script>` and sets a CSP
        // carrying a per-request `'nonce-…'` (built via {@see contentSecurityPolicy()})
        // so the inline block executes without weakening `script-src` to
        // `'unsafe-inline'`.
        if (!self::hasHeader($headers, 'Content-Security-Policy')) {
            $response->header('Content-Security-Policy', self::contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * Case-insensitive presence check over a response's header map.
     *
     * Response::header() stores the key in the exact case it was called with,
     * while HTTP treats field names case-insensitively — so a plain isset()
     * guard misses a caller that wrote 'content-security-policy' or
     * 'x-frame-options' and decorates a second copy beside it.
     *
     * @param array<string, string> $headers The response header map.
     * @param string                $name    The field name to look up.
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the repo-wide Content-Security-Policy header value.
     *
     * This is the single source of truth for the CSP so the default (applied by
     * {@see decorate()}) and any per-request variant (e.g. the SPA shell, which
     * needs a script nonce for its inline bootstrap block) stay in lock-step.
     *
     * `media-src`/`worker-src` both allow `'self' blob:` so browser HLS playback
     * works: hls.js drives an MSE `blob:` object URL on the `<video>` element and
     * spins up a `blob:`-sourced transmux Web Worker. Without these a strict
     * browser rejects the load with `MEDIA_ELEMENT_ERROR: Media load rejected by
     * URL safety check`, blocking ALL HLS/transcoded playback.
     *
     * `style-src` deliberately carries `'unsafe-inline'`: the SPA applies theme
     * tokens by writing CSS custom properties through `el.style.setProperty()`,
     * which produces element-level inline styles — the construct this keyword
     * governs. `script-src` correspondingly has NO `'unsafe-inline'`; inline
     * script only ever runs under a per-request nonce (the `$scriptNonce`
     * argument), so the CSP retains its XSS value where it matters.
     *
     * `img-src` explicitly allowlists the two TMDB image CDN hosts
     * (`https://image.tmdb.org` and `https://tmdb.org`) — with no wildcard — so
     * poster/backdrop/cast artwork served directly from TMDB (i.e. not yet locally
     * cached) renders instead of being blocked by the default `'self'` policy. This
     * is a documented stopgap; once the generic image caching/loader work
     * (updates.md #47 / S71-S73) proxies all remote artwork through our own origin,
     * the explicit TMDB hosts should be removed. See the inline `TODO` at the
     * `img-src` directive below.
     *
     * DO-NOT-EXPOSE LAW (W4): nothing in this string is ever plumbed to a
     * settings key. `media-src`/`worker-src 'self' blob:` may not be dropped
     * (hls.js MSE playback + its transmux Web Worker die without them — every
     * transcoded stream estate-wide), and `style-src 'unsafe-inline'` may not
     * be dropped either (the token-theme surface writes CSS custom properties
     * via el.style.setProperty(), which lands in element style attributes).
     * A free-form CSP text box would let one admin PUT break playback for
     * everyone with a value no schema could validate; if any directive ever
     * becomes operator-tunable it must be a STRUCTURED key with a closed
     * value set per the frame_options precedent — and the force-inject
     * fallback (blob: trio) must stay non-negotiable in code.
     *
     * @param string|null $scriptNonce Optional cryptographically-random nonce.
     *                                  When non-empty, `'nonce-<value>'` is added
     *                                  to `script-src` so a single matching inline
     *                                  `<script nonce="<value>">` may execute. The
     *                                  caller MUST emit the identical nonce on the
     *                                  inline tag in the same response.
     *
     * @return string The full CSP header value.
     */
    public static function contentSecurityPolicy(?string $scriptNonce = null): string
    {
        $scriptSrc = "'self'";
        if ($scriptNonce !== null && $scriptNonce !== '') {
            $scriptSrc .= " 'nonce-" . $scriptNonce . "'";
        }

        // img-src explicitly allowlists the TMDB image CDN hosts so poster/backdrop/
        // cast artwork served directly from TMDB (not yet locally cached) renders
        // instead of being blocked by the default `'self'` policy. No wildcard — only
        // the two known TMDB hosts are named.
        // TODO(updates.md #47 / S71-S73): stopgap allowlist. Remove the explicit TMDB
        //   hosts once the generic image caching/loader work proxies all remote
        //   artwork through our own origin.
        return "default-src 'self'; script-src " . $scriptSrc . "; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob: https://image.tmdb.org https://tmdb.org; "
            . "font-src 'self'; connect-src 'self'; "
            . "media-src 'self' blob:; worker-src 'self' blob:; "
            . "frame-ancestors 'self'; base-uri 'self'";
    }
}
