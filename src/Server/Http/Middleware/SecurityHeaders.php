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
 *    frame-ancestors SAMEORIGIN so the SPA can embed itself but no external
 *    domain can)
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
        if (!isset($headers['Content-Security-Policy'])) {
            $response->header(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; "
                . "media-src 'self' blob:; worker-src 'self' blob:; "
                . "frame-ancestors 'self'; base-uri 'self'",
            );
        }

        return $response;
    }
}
