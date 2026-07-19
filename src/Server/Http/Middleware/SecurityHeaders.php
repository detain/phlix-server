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
 *  - X-Frame-Options: SAMEORIGIN
 *  - Strict-Transport-Security (HSTS) with a 1-year max-age
 *  - Content-Security-Policy scoped to the SPA (script/style self-only;
 *    `media-src`/`worker-src 'self' blob:` so hls.js can attach its MSE
 *    `blob:` object URL and spawn its transmux Web Worker; frame-ancestors
 *    SAMEORIGIN so the SPA can embed itself but no external domain can)
 *
 * Called from {@see \Phlix\Server\Workerman\HttpHandler} after CORS decoration
 * on every response that passes through the Workerman entrypoint. The CGI entry
 * point ({@see public/index.php}) applies the same headers via the same calls.
 *
 * @package Phlix\Server\Http\Middleware
 */
final class SecurityHeaders
{
    /** One-year HSTS max-age (in seconds). */
    private const HSTS_MAX_AGE = 31536000;

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

        // Guard: don't overwrite an existing value (caller wins).
        if (!isset($headers['X-Content-Type-Options'])) {
            $response->header('X-Content-Type-Options', 'nosniff');
        }

        if (!isset($headers['X-Frame-Options'])) {
            $response->header('X-Frame-Options', 'SAMEORIGIN');
        }

        // HSTS: only on secure connections. An http response should not declare
        // HSTS because a MITM could inject it on the plain-text response and
        // lock the browser into https for subsequent visits.
        if (!isset($headers['Strict-Transport-Security'])) {
            $response->header('Strict-Transport-Security', 'max-age=' . self::HSTS_MAX_AGE . '; includeSubDomains');
        }

        // CSP: restrictive by default — no inline scripts/styles, only same-origin.
        // frame-ancestors SAMEORIGIN lets the SPA embed its own pages but blocks
        // clickjacking from external iframes. Base-uri NONE further restricts
        // any injected base-tag attacks.
        //
        // Guard: a caller that already set its own CSP wins. The SPA shell
        // ({@see \Phlix\Server\WebPortal\Controllers\SharedUiController}) does
        // exactly this — it serves an inline bootstrap `<script>` and sets a CSP
        // carrying a per-request `'nonce-…'` (built via {@see contentSecurityPolicy()})
        // so the inline block executes without weakening `script-src` to
        // `'unsafe-inline'`.
        if (!isset($headers['Content-Security-Policy'])) {
            $response->header('Content-Security-Policy', self::contentSecurityPolicy());
        }

        return $response;
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

        return "default-src 'self'; script-src " . $scriptSrc . "; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; "
            . "media-src 'self' blob:; worker-src 'self' blob:; "
            . "frame-ancestors 'self'; base-uri 'self'";
    }
}
