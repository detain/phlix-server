<?php

/**
 * Phlix media server component: Workerman.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Server\Workerman;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\PluginLoader;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Middleware\CorsManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\Controllers\AudiobookPageController;
use Phlix\Server\WebPortal\Controllers\BookPageController;
use Phlix\Server\WebPortal\Controllers\MusicPageController;
use Phlix\Server\WebPortal\Controllers\PhotoPageController;
use Phlix\Server\WebPortal\Controllers\PluginAdminPageController;
use Phlix\Server\WebPortal\Controllers\SharedUiController;
use Phlix\Server\WebPortal\PageRenderer;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Theming\ThemeMiddleware;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Per-request handler invoked by the Workerman HTTP worker.
 *
 * Modelled on webman's `Webman\App::onMessage()` pattern: each incoming
 * Workerman request first tries the static-file fast path against the
 * public/ document root; falling through, it converts the Workerman
 * request into the project's own {@see Request} object, validates the
 * Bearer token if one is present, and dispatches via the same router
 * tree {@see public/index.php} uses for CGI-style requests.
 *
 * `public/index.php` is left untouched as the CGI entry point — direct
 * invocation by php-fpm / `php -S` / similar continues to work. This
 * class is the *parallel* dispatcher used only when phlix-server runs
 * as a Workerman daemon via {@see start.php}.
 *
 * @package Phlix\Server\Workerman
 */
final class HttpHandler
{
    /**
     * Minimum response-body size (bytes) at or above which gzip is applied.
     *
     * Compressing a body smaller than a single ~1.5 KB TCP segment spends CPU and
     * adds the ~20-byte gzip envelope for no wire benefit (it can even grow a tiny
     * body), so bodies below this floor are sent as-is. 1 KiB is the conventional
     * threshold used by CDNs / web servers for exactly this trade-off.
     */
    private const GZIP_MIN_BYTES = 1024;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RequestAuthenticator $authenticator,
        private readonly string $publicRoot,
        private readonly Application $application,
        private readonly ?MetricsCollector $metrics = null,
    ) {
    }

    /**
     * Workerman onMessage callback.
     */
    public function __invoke(TcpConnection $connection, WorkermanRequest $wr): void
    {
        $startBytesRead = $connection->bytesRead;
        $startBytesWritten = $connection->bytesWritten;
        // Monotonic clock for request duration (L2): hrtime(true) is immune to
        // wall-clock adjustments (NTP/DST), unlike microtime(true).
        $startTime = hrtime(true);
        $responseStatus = 200;

        try {
            $request = Request::fromWorkerman($wr, $connection);

            // CORS: answer a credentialed preflight for an allowlisted origin
            // before any dispatch (shared seam with public/index.php). With an
            // empty allowlist this is always null and behavior is unchanged.
            $cors = CorsManager::fromEnv();
            $preflight = $cors->preflightResponse($request);
            if ($preflight !== null) {
                $responseStatus = $preflight->statusCode;
                $connection->send($preflight->toWorkermanResponse());
                return;
            }

            $static = $this->serveStatic($wr);
            if ($static !== null) {
                $responseStatus = $static->getStatusCode();
                $connection->send($static);
                return;
            }

            // C6/B4: Authenticate via the shared collaborator — handles Bearer
            // token OR the phlix_session cookie fallback. Populates $request->userId.
            $this->authenticator->authenticate($request);

            // S6: CSRF protection for cookie-authenticated state-changing requests.
            // Bearer tokens are safe because browsers never auto-attach the
            // Authorization header cross-origin. Cookie auth is vulnerable since
            // browsers auto-send cookies on cross-origin requests.
            if ($this->authenticator->isCookieAuthenticated($request)) {
                if (!$this->authenticator->validateCsrfOrigin($request)) {
                    $responseStatus = 403;
                    $body = json_encode([
                        'error' => 'CSRF validation failed',
                        'code' => 'csrf.invalid_origin',
                    ]) ?: '{"error":"CSRF validation failed","code":"csrf.invalid_origin"}';
                    $connection->send(new WorkermanResponse(
                        403,
                        ['Content-Type' => 'application/json; charset=utf-8'],
                        $body,
                    ));
                    return;
                }
            }

            // Media direct-play byte stream (the web player's <video> source).
            // Handled with Workerman's native withFile() before the router so
            // large files stream via the event loop instead of being read into
            // worker memory. It bypasses the router (and its middleware), so it
            // authorises inline — a resolved session OR a signed-URL token,
            // mirroring SignedUrlMiddleware.
            $mediaStream = $this->serveMediaStream($wr, $request->userId);
            if ($mediaStream !== null) {
                $responseStatus = $mediaStream->getStatusCode();
                $connection->send($mediaStream);
                return;
            }

            // Try user avatar serving (signed URL or authed session)
            $avatarResp = $this->serveUserAvatar($wr, $request->userId);
            if ($avatarResp !== null) {
                $responseStatus = $avatarResp->getStatusCode();
                $connection->send($avatarResp);
                return;
            }

            // 1) Try the fully-populated Application router first. It
            //    owns every /api/*, /health, /system/info, /.well-known,
            //    /hls/, /dash/, /stream/, /opds/, and the browser-form
            //    auth aliases (/auth/login, /auth/register, /auth/refresh).
            //    Its constructor wires ThemeMiddleware into the middleware
            //    chain, so HTML responses produced by routes here already
            //    have `{$theme_css|raw}` / `{$theme_js|raw}` substituted.
            $appResponse = $this->application->dispatch($request);
            if ($appResponse->statusCode !== 404) {
                $responseStatus = $appResponse->statusCode;
                $decorated = $cors->decorate($request, $appResponse);
                $this->compressResponse($wr, $decorated);
                $connection->send($decorated->toWorkermanResponse());
                return;
            }

            // 1b) Web-portal JSON API routes that the Application router
            //     doesn't own — /api/v1/libraries, /api/v1/media/{id},
            //     /api/v1/users/me/* (continue-watching, recently-watched,
            //     history, settings). These live on {@see WebPortalRouter}
            //     and public/index.php dispatches them for the CGI path; the
            //     Workerman daemon must mirror that or the entire web-portal
            //     API 404s (e.g. the /settings page hangs on its
            //     GET /api/v1/users/me/settings fetch). Any /api/ request the
            //     Application router 404s on is served here and never falls
            //     through to the HTML page renderer.
            if (str_starts_with($request->path, '/api/')) {
                /** @var WebPortalRouter $webPortalRouter */
                $webPortalRouter = $this->container->get(WebPortalRouter::class);
                $apiResponse = $webPortalRouter->dispatch($request);
                $responseStatus = $apiResponse->statusCode;
                $decorated = $cors->decorate($request, $apiResponse);
                $this->compressResponse($wr, $decorated);
                $connection->send($decorated->toWorkermanResponse());
                return;
            }

            // 2) Fall through to the page-rendering routes (home, login,
            //    library, search, settings, admin SSR pages, /music,
            //    /books, /audiobooks, /photo). These aren't in
            //    Application's router so we have to dispatch and apply
            //    ThemeMiddleware ourselves.
            /** @var ThemeMiddleware $theme */
            $theme = $this->container->get(ThemeMiddleware::class);
            $response = $theme->onHttpRequest($request, fn (Request $req): Response => $this->dispatch($req));
            $responseStatus = $response->statusCode;
            $decorated = $cors->decorate($request, $response);
            $this->compressResponse($wr, $decorated);
            $connection->send($decorated->toWorkermanResponse());
        } catch (Throwable $e) {
            $responseStatus = 500;
            LoggerFactory::get(LogChannels::HTTP)->error(
                'Unhandled exception in HTTP worker',
                [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            $connection->send(new WorkermanResponse(
                500,
                ['Content-Type' => 'text/html; charset=utf-8'],
                '<h1>500 Internal Server Error</h1>',
            ));
        } finally {
            // Record on EVERY path — success, early return, or exception. Uses the
            // always-defined Workerman request ($wr) for method/route so a throw in
            // Request::fromWorkerman() above cannot leave $request undefined here.
            $this->recordRequestMetrics(
                $connection,
                $wr,
                $responseStatus,
                $startTime,
                $startBytesRead,
                $startBytesWritten,
            );
        }
    }

    /**
     * Record the just-completed request into the metrics subsystem.
     *
     * No-op when metrics is absent/disabled. Computes the per-request byte deltas
     * from the connection counters and the monotonic duration, and records against
     * the low-cardinality route template (see {@see routeTemplate()}) plus the real
     * captured HTTP status. Method/path come from the Workerman request, which is
     * always in scope in the caller's `finally` even if request parsing threw.
     *
     * `$startTime` is a {@see hrtime()} nanosecond reading (monotonic — see the
     * capture in {@see self::__invoke()}); duration is derived in nanosecond
     * integer space and only then converted to milliseconds, so a long-running
     * worker's large hrtime values keep full sub-millisecond precision.
     */
    private function recordRequestMetrics(
        TcpConnection $connection,
        WorkermanRequest $wr,
        int $status,
        int $startTime,
        int $startBytesRead,
        int $startBytesWritten,
    ): void {
        if ($this->metrics === null || !$this->metrics->isEnabled()) {
            return;
        }
        $bytesIn = (int) max(0, $connection->bytesRead - $startBytesRead);
        $bytesOut = (int) max(0, $connection->bytesWritten - $startBytesWritten);
        $elapsedMs = (hrtime(true) - $startTime) / 1_000_000.0;
        $this->metrics->recordRequest(
            $wr->method(),
            self::routeTemplate($wr->path()),
            $status,
            $elapsedMs,
            $bytesIn,
            $bytesOut,
        );
    }

    /**
     * Collapse a concrete request path into a low-cardinality route template.
     *
     * The per-route rollup (`metrics_route_rollup`) groups by (method, route);
     * recording the raw path lets every distinct uuid / numeric id / asset hash
     * mint its own row and exhaust the route-cardinality cap, folding every real
     * endpoint into "__other__". Replacing variable-looking segments with "{id}"
     * keeps `/api/v1/media/<uuid>/stream` as `/api/v1/media/{id}/stream` so the
     * slow-call table and per-route timings stay meaningful. Path only — Workerman's
     * `$wr->path()` never carries the query string.
     */
    private static function routeTemplate(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment !== '' && self::isVariableSegment($segment)) {
                $segments[$i] = '{id}';
            }
        }
        return implode('/', $segments);
    }

    /**
     * Whether a single path segment looks like a variable (id / hash / token)
     * rather than a stable route word — used by {@see routeTemplate()}.
     *
     * Matches a purely numeric id, a canonical UUID, or any 8+ character token
     * carrying BOTH a letter and a digit (hex object ids, Vite asset fingerprints,
     * urlencoded names). Short stable words ("api", "v1", "media", "s01e02") lack
     * that letter-and-digit-over-8 combination and are preserved verbatim.
     */
    private static function isVariableSegment(string $segment): bool
    {
        if (ctype_digit($segment)) {
            return true;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1) {
            return true;
        }
        return strlen($segment) >= 8
            && preg_match('/[A-Za-z]/', $segment) === 1
            && preg_match('/\d/', $segment) === 1;
    }

    /**
     * Serve a file directly from public/ if the request path maps to
     * one. Returns null when no static file matches and the request
     * should fall through to the dynamic dispatcher.
     */
    private function serveStatic(WorkermanRequest $wr): ?WorkermanResponse
    {
        $path = $wr->path();
        if ($path === '' || $path === '/' || str_starts_with($path, '/api/')) {
            return null;
        }
        // No `..` traversal — realpath() canonicalises and must stay under public/.
        $candidate = $this->publicRoot . $path;
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }
        if (!str_starts_with($real, $this->publicRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!is_file($real)) {
            return null;
        }
        // Never serve PHP source files raw.
        if (strtolower((string) pathinfo($real, PATHINFO_EXTENSION)) === 'php') {
            return null;
        }
        $resp = new WorkermanResponse(200, ['Content-Type' => self::mimeFor($real)]);
        // The Vite-built web-ui bundle under /assets/app/** has content-hashed
        // filenames (e.g. index-DaB12cd3.js): the bytes for a given URL never
        // change, so it is safe to cache forever. `immutable` also tells browsers
        // not to revalidate on reload.
        //
        // Gated on the RESOLVED/jailed `$real` path (not the raw `$path` request
        // string) so a traversal-style URL that merely *starts with* `/assets/app/`
        // but resolves outside that directory (e.g. `/assets/app/../foo.js`) cannot
        // get tagged immutable — mirrors the jail check two lines above this method
        // already performs on `$real`.
        $assetsAppRoot = $this->publicRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR;
        if (str_starts_with($real, $assetsAppRoot)) {
            $resp->header('Cache-Control', 'public, max-age=31536000, immutable');
        }
        $resp->withFile($real);
        return $resp;
    }

    /**
     * Apply `Content-Encoding: gzip` to a text/JSON/HTML response when the client
     * advertises gzip support and the body is worth compressing. Mutates the
     * {@see Response} in place; a no-op for anything it must not touch.
     *
     * This is deliberately conservative and NEVER compresses media/streaming
     * responses. Two independent guards keep the streaming surface untouched:
     *
     *  1. **File-backed responses are skipped outright** (`filePath !== null`).
     *     Every HLS/DASH playlist AND segment is streamed via
     *     {@see Response::withFile()} (see {@see TranscodeFileServer}), and
     *     direct-play byte ranges / avatars are served as raw
     *     {@see WorkermanResponse}s that never reach this method at all — so no
     *     media body is ever present here to compress.
     *  2. **Content-Type must be on a strict text allowlist**
     *     ({@see self::isCompressibleType()}). Image/audio/video/`octet-stream`
     *     and the HLS `application/vnd.apple.mpegurl` / DASH `application/dash+xml`
     *     playlist types are all absent, so even a hypothetical buffered media
     *     body would not match.
     *
     * Additionally skips bodies already carrying a `Content-Encoding`, empty
     * bodies, bodies below {@see self::GZIP_MIN_BYTES}, and clients that don't
     * send `Accept-Encoding: gzip`. On success it sets `Content-Encoding: gzip`,
     * appends `Accept-Encoding` to `Vary` (so shared caches key correctly), and
     * refreshes `Content-Length` to the compressed size.
     */
    private function compressResponse(WorkermanRequest $wr, Response $response): void
    {
        // Guard 1: file-backed responses stream via withFile() — never buffer or
        // compress them. This single check excludes the whole HLS/DASH/media surface.
        if ($response->filePath !== null || $response->body === '') {
            return;
        }
        // Don't double-encode an already-encoded body.
        if (self::headerLookup($response->headers, 'Content-Encoding') !== null) {
            return;
        }
        // Not worth compressing below the threshold.
        if (strlen($response->body) < self::GZIP_MIN_BYTES) {
            return;
        }
        // Guard 2: only known text-based representations.
        if (!self::isCompressibleType(self::headerLookup($response->headers, 'Content-Type'))) {
            return;
        }
        // Honour the client's declared support.
        $accept = $wr->header('accept-encoding');
        if (!is_string($accept) || stripos($accept, 'gzip') === false) {
            return;
        }
        $gzipped = gzencode($response->body, 6);
        if ($gzipped === false || strlen($gzipped) >= strlen($response->body)) {
            // Compression failed or didn't help — leave the plain body in place.
            return;
        }

        // Drop any stale Content-Length (case-insensitively) before setting the new
        // one, so framing matches the compressed body regardless of header casing.
        foreach (array_keys($response->headers) as $name) {
            if (strcasecmp($name, 'Content-Length') === 0) {
                unset($response->headers[$name]);
            }
        }
        $response->body = $gzipped;
        $response->headers['Content-Encoding'] = 'gzip';
        $response->headers['Vary'] = self::mergeVaryAcceptEncoding(
            self::headerLookup($response->headers, 'Vary'),
        );
        $response->headers['Content-Length'] = (string) strlen($gzipped);
    }

    /**
     * Whether a Content-Type header value names a text-based, compression-friendly
     * representation. Strict allowlist: any `text/*` plus a fixed set of textual
     * `application/*` (and SVG) types. Every media/binary type — images, audio,
     * video, `application/octet-stream`, and crucially the HLS
     * `application/vnd.apple.mpegurl` and DASH `application/dash+xml` playlist
     * types — is intentionally absent, so streaming responses can never match.
     */
    private static function isCompressibleType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }
        // Strip any parameters (e.g. "; charset=utf-8") and normalise.
        $base = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($base === '') {
            return false;
        }
        if (str_starts_with($base, 'text/')) {
            return true;
        }
        return in_array($base, [
            'application/json',
            'application/javascript',
            'application/xml',
            'application/manifest+json',
            'application/ld+json',
            'application/rss+xml',
            'application/atom+xml',
            'image/svg+xml',
        ], true);
    }

    /**
     * Case-insensitive lookup into a {@see Response} header map. Different producers
     * store headers as `Content-Type` or `content-type`; this normalises the read.
     *
     * @param array<string, string> $headers
     */
    private static function headerLookup(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Merge `Accept-Encoding` into an existing `Vary` header value without
     * duplicating it. Returns the canonical `Accept-Encoding` when there is no
     * prior value.
     */
    private static function mergeVaryAcceptEncoding(?string $existing): string
    {
        if ($existing === null || trim($existing) === '') {
            return 'Accept-Encoding';
        }
        foreach (explode(',', $existing) as $token) {
            if (strcasecmp(trim($token), 'Accept-Encoding') === 0) {
                return $existing;
            }
        }
        return $existing . ', Accept-Encoding';
    }

    /**
     * Byte-serve a user avatar image.
     *
     * GET /api/v1/users/{id}/avatar
     *
     * Authorised by: resolved session (Bearer/cookie) OR valid signed-URL token
     * (so <img src="..."> works without a Bearer header).
     *
     * Uses Workerman's native {@see WorkermanResponse::withFile()} so the image
     * streams through the event loop without being read into worker memory.
     */
    private function serveUserAvatar(WorkermanRequest $wr, ?string $userId = null): ?WorkermanResponse
    {
        if ($wr->method() !== 'GET') {
            return null;
        }

        // Match /api/v1/users/{id}/avatar — captures the userId
        if (preg_match('#^/api/v1/users/([^/]+)/avatar$#', $wr->path(), $m) !== 1) {
            return null;
        }

        $targetUserId = $m[1];

        // Authorise: resolved session OR valid signed-URL token.
        // A missing/invalid/expired token → 401 so the browser shows a broken image
        // rather than a raw JSON error body on an <img> src.
        if ($userId === null || $userId === '') {
            $signer = \Phlix\Auth\SignedUrl::fromEnv();
            $exp = $wr->get('exp');
            $sig = $wr->get('sig');
            if (!$signer->verify($wr->path(), is_string($exp) ? $exp : null, is_string($sig) ? $sig : null)) {
                return new WorkermanResponse(
                    401,
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                    'Unauthorized',
                );
            }
        }

        // Get avatar path from AvatarStorage
        /** @var \Phlix\Media\Storage\AvatarStorage $avatarStorage */
        $avatarStorage = $this->container->get(\Phlix\Media\Storage\AvatarStorage::class);
        $avatarPath = $avatarStorage->path($targetUserId);

        if ($avatarPath === null || !is_file($avatarPath) || !is_readable($avatarPath)) {
            return new WorkermanResponse(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Avatar not found');
        }

        // Set Content-Type from file extension (.jpg → image/jpeg)
        $mime = $this->mimeFor(pathinfo($avatarPath, PATHINFO_EXTENSION));
        $resp = new WorkermanResponse(200, ['Content-Type' => $mime]);
        $resp->withFile($avatarPath);
        return $resp;
    }

    /**
     * Byte-serve a media item's source file for browser direct play.
     *
     * Backs `GET /media/{id}/stream` — the URL the web player's `<video>`
     * source points at (and what {@see \Phlix\Media\Streaming\StreamManager::buildDirectStreamUrl()}
     * builds). Returns null when the path is not a media-stream request so the
     * caller falls through to the normal router.
     *
     * Uses Workerman's native {@see WorkermanResponse::withFile()} so the file
     * streams through the event loop (chunked for anything over 2 MB) rather
     * than being read into worker memory — essential for multi-GB videos. HTTP
     * `Range` requests are honoured (206 + `Content-Range`) so the browser can
     * seek; an unsatisfiable range yields 416.
     */
    private function serveMediaStream(WorkermanRequest $wr, ?string $userId = null): ?WorkermanResponse
    {
        if ($wr->method() !== 'GET') {
            return null;
        }
        if (preg_match('#^/media/(?P<id>[^/]+)/stream$#', $wr->path(), $m) !== 1) {
            return null;
        }

        // Authorise before touching the filesystem: a resolved session
        // (Bearer/cookie) OR a valid signed-URL token. Returning a 401 here
        // (rather than null) stops the request — a null would fall through to the
        // router and 404, masking the auth failure.
        if (!$this->isMediaStreamAuthorized($wr, $userId)) {
            return new WorkermanResponse(
                401,
                ['Content-Type' => 'text/plain; charset=utf-8'],
                'Unauthorized',
            );
        }

        /** @var ItemRepository $repo */
        $repo = $this->container->get(ItemRepository::class);
        $item = $repo->findById($m['id']);
        $path = is_array($item) && is_string($item['path'] ?? null) ? $item['path'] : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return new WorkermanResponse(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Media not found');
        }

        $fileSize = (int) filesize($path);
        $mime = self::videoMimeFor($path);

        $rangeHeader = $wr->header('range');
        if (is_string($rangeHeader) && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $rm) === 1) {
            $start = (int) $rm[1];
            $end = ($rm[2] !== '' ? (int) $rm[2] : $fileSize - 1);
            if ($fileSize === 0 || $start >= $fileSize || $end >= $fileSize || $start > $end) {
                return new WorkermanResponse(416, [
                    'Content-Type' => $mime,
                    'Content-Range' => "bytes */{$fileSize}",
                ]);
            }
            $resp = new WorkermanResponse(206, ['Content-Type' => $mime]);
            // withFile() with a non-zero offset/length makes Workerman emit
            // 206 + Content-Range automatically.
            $resp->withFile($path, $start, $end - $start + 1);
            return $resp;
        }

        $resp = new WorkermanResponse(200, ['Content-Type' => $mime]);
        $resp->withFile($path);
        return $resp;
    }

    /**
     * Whether a `/media/{id}/stream` request is allowed to proceed.
     *
     * Accepts an already-resolved session user id (from the Bearer/cookie block
     * in {@see self::__invoke()}) OR a valid `?exp&sig` signed-URL token. This is
     * the inline equivalent of {@see \Phlix\Server\Http\Middleware\SignedUrlMiddleware}
     * for the one byte-serving route that bypasses the router.
     */
    private function isMediaStreamAuthorized(WorkermanRequest $wr, ?string $userId): bool
    {
        if ($userId !== null && $userId !== '') {
            return true;
        }

        $exp = $wr->get('exp');
        $sig = $wr->get('sig');

        return \Phlix\Auth\SignedUrl::fromEnv()->verify(
            $wr->path(),
            is_string($exp) ? $exp : null,
            is_string($sig) ? $sig : null,
        );
    }

    /**
     * Content-Type for a video file we're about to direct-play.
     *
     * Extension-first so the browser gets a deterministic, playable MIME for
     * the formats `<video>` understands; unknown extensions fall back to a
     * binary default.
     */
    private static function videoMimeFor(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return [
            'mp4'  => 'video/mp4',
            'm4v'  => 'video/mp4',
            'mov'  => 'video/mp4',
            'webm' => 'video/webm',
            'ogv'  => 'video/ogg',
            'mkv'  => 'video/x-matroska',
            'avi'  => 'video/x-msvideo',
            'ts'   => 'video/mp2t',
        ][$ext] ?? 'application/octet-stream';
    }

    /**
     * Best-guess Content-Type for a file we're about to serve.
     *
     * Extension first — `mime_content_type()` sniffs file content via
     * libmagic and returns `text/plain` for any text format, so CSS, JS,
     * SVG, and JSON would all be mis-typed by the browser if we trusted
     * it. For everything not in the explicit map, fall back to libmagic
     * (good for images / archives) and finally to a safe binary default.
     */
    private static function mimeFor(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $byExt = [
            'css'   => 'text/css; charset=utf-8',
            'js'    => 'application/javascript; charset=utf-8',
            'mjs'   => 'application/javascript; charset=utf-8',
            'json'  => 'application/json; charset=utf-8',
            'map'   => 'application/json; charset=utf-8',
            'html'  => 'text/html; charset=utf-8',
            'htm'   => 'text/html; charset=utf-8',
            'txt'   => 'text/plain; charset=utf-8',
            'xml'   => 'application/xml; charset=utf-8',
            'svg'   => 'image/svg+xml',
            'webmanifest' => 'application/manifest+json',
            'ico'   => 'image/x-icon',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'avif'  => 'image/avif',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
            'eot'   => 'application/vnd.ms-fontobject',
            'mp3'   => 'audio/mpeg',
            'm4a'   => 'audio/mp4',
            'ogg'   => 'audio/ogg',
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            'pdf'   => 'application/pdf',
            'wasm'  => 'application/wasm',
        ];
        if (isset($byExt[$ext])) {
            return $byExt[$ext];
        }
        // Guard is_file(): mime_content_type() emits a "Failed to open stream"
        // warning (and PHPUnit exits non-zero on warnings) when handed a path that
        // doesn't exist — fall through to the generic type instead.
        if (function_exists('mime_content_type') && is_file($path)) {
            $detected = mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }
        return 'application/octet-stream';
    }

    /**
     * Dispatch the page-rendering routes that aren't registered on the
     * {@see Application} router. The Application router owns every
     * `/api/*`, `/health`, `/.well-known`, `/hls/*`, `/dash/*`,
     * `/stream/*`, `/opds/*` and the browser-form `/auth/login` style
     * aliases — `__invoke()` tries it first. Anything that 404s there
     * falls through to here.
     */
    private function dispatch(Request $request): Response
    {
        $path = $request->path;

        /** @var PageRenderer $renderer */
        $renderer = $this->container->get(PageRenderer::class);

        if ($path === '/' || $path === '') {
            // The redesigned Vue SPA is the front door — send the bare root to
            // /app. Old SSR pages (/login, /library, /player/{id}, …) stay
            // reachable at their own paths.
            return (new Response())->redirect('/app');
        }
        if ($path === '/login') {
            return $renderer->renderLogin($request);
        }
        if ($path === '/register' || $path === '/auth/register') {
            return $renderer->renderRegister($request);
        }
        if ($path === '/library' || $path === '/library/') {
            return $renderer->renderLibrariesOverview($request);
        }
        // Single media-item detail (linked from media_card.tpl / the player's
        // back button). MUST be matched before the single-segment library
        // route below.
        if (preg_match('#^/library/item/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $renderer->renderItem($request, ['id' => $m['id']]);
        }
        // Web video player page (linked from the detail page "Play" button).
        if (preg_match('#^/player/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $renderer->renderPlayer($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/library/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $renderer->renderLibrary($request, ['id' => $m['id']]);
        }
        if ($path === '/search') {
            return $renderer->renderSearch($request);
        }
        if ($path === '/settings') {
            return $renderer->renderSettings($request);
        }
        if ($path === '/settings/security') {
            return $renderer->renderWebAuthnSettings($request);
        }
        if (str_starts_with($path, '/admin/plugins')) {
            return $this->dispatchAdminPlugins($renderer, $request, $path);
        }
        if ($path === '/admin/dashboard') {
            return $this->dispatchAdminDashboard($renderer, $request);
        }
        if (str_starts_with($path, '/music')) {
            return $this->dispatchMusic($request, $path);
        }
        if (str_starts_with($path, '/books')) {
            return $this->dispatchBooks($request, $path);
        }
        if (str_starts_with($path, '/audiobooks')) {
            return $this->dispatchAudiobooks($request, $path);
        }
        if (str_starts_with($path, '/photo')) {
            return $this->dispatchPhoto($request, $path);
        }
        if ($path === '/app' || str_starts_with($path, '/app/')) {
            return $this->dispatchSharedUi($request);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchAdminPlugins(PageRenderer $renderer, Request $request, string $path): Response
    {
        /** @var AdminMiddleware $admin */
        $admin = $this->container->get(AdminMiddleware::class);
        $gate = $admin->checkAccess($request);
        if ($gate === 401) {
            return (new Response())->status(401)->html('<h1>401 — admin authentication required</h1>');
        }
        if ($gate === 403) {
            return (new Response())->status(403)->html('<h1>403 — administrator privileges required</h1>');
        }
        /** @var PluginLoader $loader */
        $loader = $this->container->get(PluginLoader::class);
        $page = new PluginAdminPageController($loader, $this->publicRoot . '/templates');
        if ($path === '/admin/plugins') {
            return $page->index($request, []);
        }
        if ($path === '/admin/plugins/install') {
            return $page->install($request, []);
        }
        if (preg_match('#^/admin/plugins/(?P<name>[^/]+)$#', $path, $m) === 1) {
            return $page->detail($request, ['name' => $m['name']]);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchAdminDashboard(PageRenderer $renderer, Request $request): Response
    {
        /** @var AdminMiddleware $admin */
        $admin = $this->container->get(AdminMiddleware::class);
        $gate = $admin->checkAccess($request);
        if ($gate === 401) {
            return (new Response())->status(401)->html('<h1>401 — admin authentication required</h1>');
        }
        if ($gate === 403) {
            return (new Response())->status(403)->html('<h1>403 — administrator privileges required</h1>');
        }
        return $renderer->renderDashboard($request);
    }

    /**
     * Serve the shared Vue 3 SPA shell for `/app` + `/app/*` (Phase C).
     *
     * Reached after all specific page routes. The SPA has no auth gate here —
     * it handles authentication itself via `ApiClient` + `tokenStore`.
     * A missing bundle returns 503 with an actionable message.
     */
    private function dispatchSharedUi(Request $request): Response
    {
        $app = new \Phlix\Server\WebPortal\Controllers\SharedUiController($this->publicRoot);
        return $app->shell($request, []);
    }

    private function dispatchMusic(Request $request, string $path): Response
    {
        /** @var MusicPageController $music */
        $music = $this->container->get(MusicPageController::class);
        if ($path === '/music' || $path === '/music/albums') {
            return $music->albums($request, []);
        }
        if (preg_match('#^/music/albums/(?P<name>.+)$#', $path, $m) === 1) {
            return $music->album($request, ['name' => urldecode($m['name'])]);
        }
        if ($path === '/music/artists') {
            return $music->artists($request, []);
        }
        if (preg_match('#^/music/artists/(?P<name>.+)$#', $path, $m) === 1) {
            return $music->artist($request, ['name' => urldecode($m['name'])]);
        }
        if ($path === '/music/tracks') {
            return $music->tracks($request, []);
        }
        if ($path === '/music/player') {
            return $music->player($request, []);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchBooks(Request $request, string $path): Response
    {
        if (preg_match('#^/books/(?P<id>[^/]+)/cover$#', $path, $m) === 1) {
            /** @var BookController $api */
            $api = $this->container->get(BookController::class);
            return $api->getCover($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/books/(?P<id>[^/]+)/download$#', $path, $m) === 1) {
            /** @var BookController $api */
            $api = $this->container->get(BookController::class);
            return $api->downloadBook($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/books/(?P<id>[^/]+)/read$#', $path, $m) === 1) {
            /** @var BookPageController $page */
            $page = $this->container->get(BookPageController::class);
            return $page->reader($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/books/(?P<id>[^/]+)$#', $path, $m) === 1) {
            /** @var BookPageController $page */
            $page = $this->container->get(BookPageController::class);
            return $page->detail($request, ['id' => $m['id']]);
        }
        if ($path === '/books') {
            /** @var BookPageController $page */
            $page = $this->container->get(BookPageController::class);
            return $page->index($request, []);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchAudiobooks(Request $request, string $path): Response
    {
        /** @var AudiobookPageController $audiobook */
        $audiobook = $this->container->get(AudiobookPageController::class);
        if ($path === '/audiobooks') {
            return $audiobook->index($request, []);
        }
        if (preg_match('#^/audiobooks/(?P<id>[^/]+)/read$#', $path, $m) === 1) {
            return $audiobook->player($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/audiobooks/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $audiobook->detail($request, ['id' => $m['id']]);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchPhoto(Request $request, string $path): Response
    {
        if (preg_match('#^/photo/photos/(?P<id>[^/]+)/thumbnail$#', $path, $m) === 1) {
            /** @var PhotoController $api */
            $api = $this->container->get(PhotoController::class);
            return $api->getThumbnail($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/photo/photos/(?P<id>[^/]+)/full$#', $path, $m) === 1) {
            /** @var PhotoController $api */
            $api = $this->container->get(PhotoController::class);
            return $api->getFull($request, ['id' => $m['id']]);
        }
        /** @var PhotoPageController $page */
        $page = $this->container->get(PhotoPageController::class);
        if ($path === '/photo/albums') {
            return $page->albums($request, []);
        }
        if (preg_match('#^/photo/album/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $page->album($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/photo/photo/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $page->photo($request, ['id' => $m['id']]);
        }
        if ($path === '/photo/slideshow') {
            return $page->slideshow($request, []);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }
}
