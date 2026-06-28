<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Credentialed CORS for cross-origin native clients (the producer side of the
 * phlix-ui in-memory-access-token / httpOnly-refresh-cookie seam).
 *
 * The web UI is served same-origin and needs no CORS, so this layer is OFF by
 * default: with an empty allowlist it emits NO CORS headers and the existing
 * same-origin behavior is preserved byte-for-byte. It only activates for an
 * `Origin` that EXACTLY matches one in the env allowlist
 * ({@see self::ENV_ALLOWED_ORIGINS}, a comma-separated list of exact origins
 * e.g. `https://app.example.com,https://tv.example.com`).
 *
 * For an allowlisted origin the response carries:
 *  - `Access-Control-Allow-Origin: <reflected origin>` — NEVER `*` (a wildcard
 *    is forbidden alongside credentials and the spec requires it here), and
 *  - `Access-Control-Allow-Credentials: true` so the browser sends/stores the
 *    httpOnly refresh cookie on cross-origin XHR (`credentials: 'include'`), and
 *  - `Vary: Origin` so caches don't serve one origin's ACAO to another.
 *
 * A preflight `OPTIONS` from an allowlisted origin short-circuits with `204` +
 * the allow-methods / allow-headers / max-age. Both HTTP entry points
 * (the resident {@see \Phlix\Server\Workerman\HttpHandler} and the CGI
 * `public/index.php`) call this ONE class — {@see self::preflightResponse()}
 * before dispatch and {@see self::decorate()} on the final response — so the
 * dual-entry-point behavior cannot drift.
 *
 * @package Phlix\Server\Http\Middleware
 */
final class CorsManager
{
    /** Env var holding the comma-separated exact-origin allowlist. */
    public const ENV_ALLOWED_ORIGINS = 'PHLIX_CORS_ALLOWED_ORIGINS';

    /** Methods advertised to preflight requests (the API surface). */
    private const ALLOW_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    /** Request headers the browser may send on the actual request. */
    private const ALLOW_HEADERS = 'Authorization, Content-Type, X-Device-Id';

    /** Preflight cache lifetime (seconds). 600 = 10 minutes, a safe default. */
    private const MAX_AGE = '600';

    /**
     * @var list<string> Exact origins allowed to make credentialed requests.
     *                   Empty = CORS disabled (no headers ever emitted).
     */
    private array $allowedOrigins;

    /**
     * @param list<string> $allowedOrigins Exact origins (scheme://host[:port]).
     */
    public function __construct(array $allowedOrigins = [])
    {
        $this->allowedOrigins = $allowedOrigins;
    }

    /**
     * Build the manager from the environment allowlist.
     *
     * Reads {@see self::ENV_ALLOWED_ORIGINS} (comma-separated), trims each
     * entry, drops blanks. Unset / empty → CORS disabled.
     */
    public static function fromEnv(): self
    {
        $raw = getenv(self::ENV_ALLOWED_ORIGINS);

        return new self(self::parseOrigins(is_string($raw) ? $raw : ''));
    }

    /**
     * Parse a comma-separated origin list into a clean list of exact origins.
     *
     * @return list<string>
     */
    public static function parseOrigins(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /**
     * Answer a CORS preflight request, or null when this isn't one to handle.
     *
     * Returns a `204` with the allow-methods/headers/max-age + the credentialed
     * ACAO headers when the request is an `OPTIONS` carrying an allowlisted
     * `Origin`. Returns null otherwise so the caller continues normal dispatch
     * (a non-allowed origin's OPTIONS still routes normally and gets no CORS
     * headers — preserving today's behavior).
     */
    public function preflightResponse(Request $request): ?Response
    {
        if (strtoupper($request->method) !== 'OPTIONS') {
            return null;
        }

        $origin = $this->matchedOrigin($request);
        if ($origin === null) {
            return null;
        }

        return $this->withCorsHeaders(
            (new Response())->noContent(204),
            $origin,
        )
            ->header('Access-Control-Allow-Methods', self::ALLOW_METHODS)
            ->header('Access-Control-Allow-Headers', self::ALLOW_HEADERS)
            ->header('Access-Control-Max-Age', self::MAX_AGE);
    }

    /**
     * Decorate the final response with credentialed CORS headers when the
     * request's `Origin` is allowlisted; otherwise return it untouched.
     *
     * Safe to call on every response from both entry points: with an empty
     * allowlist or a non-matching origin it is a no-op.
     */
    public function decorate(Request $request, Response $response): Response
    {
        $origin = $this->matchedOrigin($request);
        if ($origin === null) {
            return $response;
        }

        return $this->withCorsHeaders($response, $origin);
    }

    /**
     * The request's Origin if it exactly matches the allowlist, else null.
     */
    private function matchedOrigin(Request $request): ?string
    {
        if ($this->allowedOrigins === []) {
            return null;
        }

        $origin = $request->getHeader('Origin');
        if ($origin === null || $origin === '') {
            return null;
        }

        return in_array($origin, $this->allowedOrigins, true) ? $origin : null;
    }

    /**
     * Apply the reflected-origin credentialed CORS headers to a response.
     *
     * `Access-Control-Allow-Origin` is the reflected exact origin — never `*` —
     * which is mandatory when pairing with `Allow-Credentials: true`. `Vary:
     * Origin` is appended so shared caches key on the request origin.
     */
    private function withCorsHeaders(Response $response, string $origin): Response
    {
        return $response
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Access-Control-Allow-Credentials', 'true')
            ->header('Vary', self::appendVaryOrigin($response->headers['Vary'] ?? null));
    }

    /**
     * Merge `Origin` into any existing `Vary` header value without duplicating.
     */
    private static function appendVaryOrigin(?string $existing): string
    {
        if ($existing === null || trim($existing) === '') {
            return 'Origin';
        }

        foreach (explode(',', $existing) as $token) {
            if (strcasecmp(trim($token), 'Origin') === 0) {
                return $existing;
            }
        }

        return $existing . ', Origin';
    }
}
