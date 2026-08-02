<?php

/**
 * Phlix media server component: Workerman.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Workerman;

use Phlix\Auth\RateLimitException;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Controllers\ByteRangeParser;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\Http\Controllers\TranscodeFileServer;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Server\Http\Middleware\CorsManager;
use Phlix\Server\Http\Middleware\SecurityHeaders;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\Controllers\SharedUiController;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Stats\Metrics\MetricsCollector;
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

    /**
     * Prefix for the per-request direct-LAN cancel id (SV-4.2-disconnect).
     *
     * A distinct prefix from any relay channel id (those are bare integer
     * strings minted in a different worker/process/registry) — belt-and-braces,
     * since the direct and relay registries are never the same instance anyway.
     */
    private const DIRECT_CANCEL_PREFIX = 'dl-';

    /**
     * Per-worker monotonic sequence backing {@see mintDirectCancelId()}.
     *
     * Deliberately a resident-process `static` (NOT request state in
     * {@see RequestContext}): the id must be unique across every request the
     * worker handles — both concurrent connections AND sequential keep-alive
     * requests on ONE connection (bare `spl_object_id($connection)` would repeat
     * across sequential keep-alive requests, so it is not used). Under Swoole,
     * coroutines are cooperatively scheduled and a `++` carries no yield point,
     * so the increment is effectively atomic across the worker's coroutines.
     * Overflow is a non-issue (64-bit int).
     */
    private static int $directCancelSeq = 0;

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
        // SV-4.2-disconnect F3: the exact onClose closure THIS request armed (null
        // until/unless it reaches the arm site). Captured so the finally's disarm
        // compares identity and never nulls a parked sibling request's live hook on
        // a keep-alive connection. Declared here so it is defined on EVERY finally
        // path, including an early return before the hook is armed.
        $armedOnClose = null;
        // Declared before the try so the RateLimitException catch (SV-4.15 F4) can
        // reuse it to CORS-decorate the 429; stays null if the throw beat its
        // assignment (in which case the 429 ships without an Origin echo).
        $request = null;

        // [DEBUG] Log incoming request - request uid will be generated after Request creation
        $requestUid = sprintf('%08x', mt_rand(0, 0xffffffff));
        $httpLogger = LoggerFactory::get(LogChannels::HTTP);
        $httpLogger->debug("HttpHandler.__invoke START {$wr->method()} {$wr->path()} [uid={$requestUid}]");

        try {
            $request = Request::fromWorkerman($wr, $connection);

            // Update uid with more entropy now that we have request context
            $requestUid = substr(md5((string)($request->userId ?? '') . $wr->path() . (string)microtime(true)), 0, 16);
            $httpLogger->debug("HttpHandler.__invoke Request parsed [uid={$requestUid}] [userId={$request->userId}]");

            // CORS: answer a credentialed preflight for an allowlisted origin
            // before any dispatch (shared seam with public/index.php). With an
            // empty allowlist this is always null and behavior is unchanged.
            $cors = CorsManager::fromEnv();
            $securityHeaders = new SecurityHeaders();
            $preflight = $cors->preflightResponse($request);
            if ($preflight !== null) {
                $responseStatus = $preflight->statusCode;
                $httpLogger->debug("HttpHandler.__invoke CORS preflight [uid={$requestUid}]");
                $connection->send($preflight->toWorkermanResponse());
                return;
            }

            $static = $this->serveStatic($wr);
            if ($static !== null) {
                $responseStatus = $static->getStatusCode();
                $httpLogger->debug("HttpHandler.__invoke Static file served [uid={$requestUid}]");
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

            // Try artwork (poster) serving (signed URL or authed session)
            $artworkResp = $this->serveArtwork($wr, $request->userId);
            if ($artworkResp !== null) {
                $responseStatus = $artworkResp->getStatusCode();
                $connection->send($artworkResp);
                return;
            }

            // SV-4.2-disconnect: arm the direct-LAN disconnect→kill hook before
            // any dispatch that could launch an on-demand ffmpeg segment encode
            // (the /hls, /dash, /stream routes below are owned by this
            // Application router). A direct-LAN client — hitting this :8096 HTTP
            // worker directly, NOT via the hub relay — that disconnects mid-encode
            // otherwise leaves ffmpeg running to natural completion or the
            // `timeout ... 7200` backstop. The hook mints a per-request cancel id,
            // publishes it as the request's cancel group (the SAME RequestContext
            // key the relay path uses — the two transports never share a
            // coroutine, so TranscodeManager::produceSegment registers the encode
            // under this id with ZERO extra wiring), and sets a per-connection
            // onClose that killGroup()s it if the socket FINs/RSTs while this
            // handler coroutine is parked in produceSegment's yieldable poll. The
            // kill is waiter-aware (Chunk 1): a piggybacking peer still waiting on
            // the same segment defers it. Torn down in the finally.
            $this->armDirectCancelHook($connection);
            // F3: remember EXACTLY the closure we just armed so the finally's disarm
            // only nulls onClose when it is still ours — a pipelined 2nd request on
            // this keep-alive connection must not null a parked 1st request's hook.
            $armedOnClose = $connection->onClose;

            // 1) Try the fully-populated Application router first. It
            //    owns every /api/*, /health, /system/info, /.well-known,
            //    /hls/, /dash/, /stream/, /opds/, and the browser-form
            //    auth aliases (/auth/login, /auth/register, /auth/refresh).
            $httpLogger->debug("HttpHandler.__invoke Application::dispatch [uid={$requestUid}]");
            $appResponse = $this->application->dispatch($request);
            $httpLogger->debug(
                "HttpHandler.__invoke Application::dispatch done"
                . " [uid={$requestUid}] [status={$appResponse->statusCode}]"
            );
            if ($appResponse->statusCode !== 404) {
                $responseStatus = $appResponse->statusCode;
                $decorated = $cors->decorate($request, $appResponse);
                $decorated = $securityHeaders->decorate($decorated);
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
                $httpLogger->debug("HttpHandler.__invoke WebPortalRouter::dispatch [uid={$requestUid}]");
                $apiResponse = $webPortalRouter->dispatch($request);
                $httpLogger->debug(
                    "HttpHandler.__invoke WebPortalRouter::dispatch done"
                    . " [uid={$requestUid}] [status={$apiResponse->statusCode}]"
                );
                $responseStatus = $apiResponse->statusCode;
                $decorated = $cors->decorate($request, $apiResponse);
                $decorated = $securityHeaders->decorate($decorated);
                $this->compressResponse($wr, $decorated);
                $connection->send($decorated->toWorkermanResponse());
                return;
            }

            // 2) Fall through to the page-rendering routes (home, login,
            //    library, search, settings, admin SSR pages, /music,
            //    /books, /audiobooks, /photo). These aren't in
            //    Application's router, so dispatch them here.
            //
            //    S84: this call used to be wrapped in ThemeMiddleware, which
            //    string-replaced the Smarty placeholders `{$theme_css|raw}` /
            //    `{$theme_js|raw}` in the rendered body. Nothing has emitted
            //    those since the Smarty page renderer was deleted, so the
            //    wrapper was a no-op on every response; it was removed with
            //    the middleware rather than left running.
            $httpLogger->debug("HttpHandler.__invoke page rendering [uid={$requestUid}]");
            $response = $this->dispatch($request);
            $httpLogger->debug(
                "HttpHandler.__invoke page rendering done [uid={$requestUid}] [status={$response->statusCode}]"
            );
            $responseStatus = $response->statusCode;
            $decorated = $cors->decorate($request, $response);
            $decorated = $securityHeaders->decorate($decorated);
            $this->compressResponse($wr, $decorated);
            $connection->send($decorated->toWorkermanResponse());
        } catch (RateLimitException $e) {
            // SV-4.15(c): central 429 mapping for any rate-limiter trip that
            // bubbles out of dispatch (e.g. the existing login limiter in
            // AuthManager/DbLoginRateLimitStore, which no controller catches —
            // previously it fell through to the generic 500 below with no
            // Retry-After). Emit the shared canonical envelope so the Workerman,
            // CGI (public/index.php), and Application::run() paths are identical.
            //
            // SV-4.15 F4: route the 429 through the SAME CORS + security-header +
            // compression decoration the success branches use. A cross-origin XHR
            // needs the CORS headers to even READ the 429 (and its Retry-After);
            // without them the browser surfaces an opaque network error instead of
            // the rate-limit signal. Rebuild the decorators locally (cheap,
            // deterministic) so this is robust even if the throw beat their
            // in-try assignment; CORS-decorate only when $request is available.
            $responseStatus = 429;
            $rateResponse = Application::rateLimitResponse($e);
            $rateCors = CorsManager::fromEnv();
            if ($request instanceof Request) {
                $rateResponse = $rateCors->decorate($request, $rateResponse);
            }
            $rateResponse = (new SecurityHeaders())->decorate($rateResponse);
            $this->compressResponse($wr, $rateResponse);
            $connection->send($rateResponse->toWorkermanResponse());
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
            // SV-4.2-disconnect: neutralise the per-connection disconnect→kill hook
            // and clear the request's cancel group now the request has fully
            // completed (the coroutine is no longer parked mid-encode; the encode,
            // if any, has already published + released). Idempotent and safe on
            // every path — including requests that returned before the hook was
            // armed. Nulling onClose is IDENTITY-GUARDED (F3): only our own armed
            // closure is cleared, so a later real socket close on this connection
            // cannot fire a stale killGroup for an already-gone encode, AND a
            // pipelined sibling request's live hook is never clobbered.
            $this->disarmDirectCancelHook($connection, $armedOnClose);

            // [DEBUG] Log request completion with duration
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $httpLogger->debug(
                "HttpHandler.__invoke END {$wr->method()} {$wr->path()}"
                . " [uid={$requestUid}] [status={$responseStatus}]"
                . " [duration=" . round($durationMs, 2) . "ms]"
            );

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
     * Arm the per-connection direct-LAN disconnect→kill hook for this request
     * (SV-4.2-disconnect).
     *
     * Mints a unique per-request cancel id, publishes it as the request's cancel
     * group (via {@see RequestContext::setCancelGroup()} — the same
     * {@see RequestContext::KEY_RELAY_CANCEL_GROUP} key
     * {@see \Phlix\Media\Transcoding\TranscodeManager::produceSegment()} reads,
     * so a segment encode launched during dispatch is registered under this id
     * with no extra wiring), and sets a per-connection `onClose` that kills that
     * group when the socket closes. Because `start.php` wires only the worker's
     * `onMessage` (no worker-level `onClose`), setting the per-connection hook
     * clobbers nothing. Idempotently torn down by {@see disarmDirectCancelHook()}
     * in the caller's `finally`.
     *
     * The kill closure calls {@see SegmentProcessRegistry::killGroup()}, which is
     * O(1) and a no-op when nothing is registered under the id (every
     * non-streaming request), and is waiter-aware (Chunk 1) so a piggybacking
     * peer still waiting on the same segment defers the kill.
     *
     * @param TcpConnection $connection The live connection for this request.
     *
     * @return string The minted cancel id (returned for tests / observability).
     */
    private function armDirectCancelHook(TcpConnection $connection): string
    {
        $id = self::mintDirectCancelId();
        RequestContext::setCancelGroup($id);

        /** @var SegmentProcessRegistry $registry */
        $registry = $this->container->get(SegmentProcessRegistry::class);
        $connection->onClose = static function () use ($registry, $id): void {
            $registry->killGroup($id);
        };

        return $id;
    }

    /**
     * Tear down the direct-LAN disconnect→kill hook after the request completes
     * (SV-4.2-disconnect).
     *
     * Clears the request's (per-coroutine) cancel group unconditionally, then
     * nulls the per-connection `onClose` ONLY when it is still the exact closure
     * THIS request armed (F3 identity guard). This restores the worker default so
     * a later real socket close on a keep-alive connection cannot fire a stale
     * {@see SegmentProcessRegistry::killGroup()} for an already-gone encode, while
     * NOT clobbering a pipelined sibling request's live hook: if a 2nd request is
     * delivered on the same connection while a 1st is parked mid-encode, the 1st's
     * armed closure differs from `$armed` here, so it is left intact. When this
     * request never armed (an early return before the arm site), `$armed` is null
     * and the identity check is a safe no-op. Fails safe either way — a missed
     * null is at worst a stale hook the next arm overwrites.
     *
     * @param TcpConnection $connection The connection whose hook to reset.
     * @param mixed         $armed      The onClose closure this request armed
     *                                  (null when it never armed).
     */
    private function disarmDirectCancelHook(TcpConnection $connection, mixed $armed): void
    {
        RequestContext::clearCancelGroup();
        if ($connection->onClose === $armed) {
            $connection->onClose = null;
        }
    }

    /**
     * Mint a per-request unique direct-LAN cancel id (SV-4.2-disconnect).
     *
     * Uses the per-worker monotonic {@see $directCancelSeq} so the id is unique
     * across BOTH concurrent connections AND sequential keep-alive requests on
     * one connection — the property `spl_object_id($connection)` lacks (it
     * repeats across sequential requests reusing a connection object). Prefixed
     * to keep it visually distinct from a relay channel id.
     *
     * @return string The minted id, e.g. `"dl-42"`.
     */
    private static function mintDirectCancelId(): string
    {
        return self::DIRECT_CANCEL_PREFIX . (string) (++self::$directCancelSeq);
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
     * Byte-serve a media item's artwork (poster) image.
     *
     * GET /api/v1/artwork/{itemId}?size={size}
     *
     * Authorised by: resolved session (Bearer/cookie) OR valid signed-URL token
     * (so <img src="..."> works without a Bearer header).
     *
     * Uses Workerman's native {@see WorkermanResponse::withFile()} so the image
     * streams through the event loop without being read into worker memory.
     *
     * @param WorkermanRequest $wr     The Workerman request
     * @param string|null      $userId The authenticated user ID (null if not authenticated)
     * @return WorkermanResponse|null Response or null if not an artwork request
     */
    private function serveArtwork(WorkermanRequest $wr, ?string $userId = null): ?WorkermanResponse
    {
        if ($wr->method() !== 'GET') {
            return null;
        }

        // Match /api/v1/artwork/{itemId} — captures the itemId
        if (preg_match('#^/api/v1/artwork/([^/]+)$#', $wr->path(), $m) !== 1) {
            return null;
        }

        $itemId = $m[1];

        // Get size parameter (default to 'original')
        $size = is_string($wr->get('size')) ? $wr->get('size') : 'original';

        // Validate size parameter
        if (!$this->isValidArtworkSize($size)) {
            return new WorkermanResponse(
                400,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode(['error' => 'Invalid size parameter']) ?: '{"error":"Invalid size parameter"}',
            );
        }

        // Authorise: resolved session OR valid signed-URL token.
        if ($userId === null || $userId === '') {
            $signer = \Phlix\Auth\SignedUrl::fromEnv();
            $exp = $wr->get('exp');
            $sig = $wr->get('sig');
            $resourcePath = '/api/v1/artwork/' . $itemId . '?size=' . $size;
            if (!$signer->verify($resourcePath, is_string($exp) ? $exp : null, is_string($sig) ? $sig : null)) {
                return new WorkermanResponse(
                    401,
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                    'Unauthorized',
                );
            }
        }

        // Get artwork path from ArtworkStorage
        /** @var ArtworkStorage $artworkStorage */
        $artworkStorage = $this->container->get(ArtworkStorage::class);
        $artworkPath = $artworkStorage->variantPath($itemId, $size);

        if ($artworkPath === null || !is_file($artworkPath) || !is_readable($artworkPath)) {
            return new WorkermanResponse(
                404,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode(['error' => 'Artwork not found']) ?: '{"error":"Artwork not found"}',
            );
        }

        // Compute the validators for conditional caching (SV-2.5 pattern).
        // ETag is the existing "<size>-<mtime>" hex tag (immutable-cache is kept);
        // Last-Modified is derived from the same stat so both stay consistent.
        $stat = stat($artworkPath);
        $mtime = $stat !== false ? (int) $stat['mtime'] : 0;
        $etag = $stat !== false ? sprintf('"%x-%x"', $stat['size'], $stat['mtime']) : '';
        $lastModified = $mtime > 0 ? gmdate('D, d M Y H:i:s', $mtime) . ' GMT' : '';

        // Honor conditional GET AFTER auth + size validation + the 404 existence
        // check above — freshness is only ever decided for a request that would
        // otherwise be served. If-None-Match (ETag) is authoritative; If-Modified-Since
        // (Last-Modified) is the fallback for clients that don't send an ETag.
        $ifNoneMatch = $wr->header('if-none-match');
        $ifModifiedSince = $wr->header('if-modified-since');
        $etagMatch = $etag !== '' && $ifNoneMatch === $etag;
        $imsTs = is_string($ifModifiedSince) && $ifModifiedSince !== ''
            ? strtotime($ifModifiedSince)
            : false;
        $notModified = ($ifNoneMatch === null || $ifNoneMatch === '')
            && $mtime > 0
            && $imsTs !== false
            && $imsTs >= $mtime;

        if ($etagMatch || $notModified) {
            $headers304 = ['Cache-Control' => 'public, max-age=31536000, immutable'];
            if ($etag !== '') {
                $headers304['ETag'] = $etag;
            }
            if ($lastModified !== '') {
                $headers304['Last-Modified'] = $lastModified;
            }
            // 304 carries the validators but NO body (do not attach the file).
            return new WorkermanResponse(304, $headers304);
        }

        $headers = [
            // The title logo (`size=logo`) is a transparency-preserving PNG; the
            // poster variants are JPEG.
            'Content-Type'  => $size === ArtworkStorage::LOGO_SIZE ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];
        if ($etag !== '') {
            $headers['ETag'] = $etag;
        }
        if ($lastModified !== '') {
            $headers['Last-Modified'] = $lastModified;
        }

        $resp = new WorkermanResponse(200, $headers);
        $resp->withFile($artworkPath);
        return $resp;
    }

    /**
     * Validate artwork size parameter against known variants.
     */
    private function isValidArtworkSize(string $size): bool
    {
        // 'original' and the transparency-safe title logo ('logo') are both valid.
        if ($size === 'original' || $size === ArtworkStorage::LOGO_SIZE) {
            return true;
        }

        if (preg_match('/^w\d+$/', $size) !== 1) {
            return false;
        }

        // Validate against known widths
        $widths = ArtworkStorage::WIDTHS;
        $width = (int) substr($size, 1);
        return in_array($width, $widths, true);
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
    /**
     * Resolve the shared parental-control {@see RatingGate} from the container,
     * or null when it cannot be built (never blocks the stream on wiring error —
     * a null gate is a strict no-op, owner-safe).
     */
    private function ratingGate(): ?RatingGate
    {
        try {
            $gate = $this->container->get(RatingGate::class);
            return $gate instanceof RatingGate ? $gate : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function serveMediaStream(WorkermanRequest $wr, ?string $userId = null): ?WorkermanResponse
    {
        $method = $wr->method();
        // Accept both GET and HEAD — HEAD is used by clients to check media
        // availability without downloading the full body.
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return null;
        }
        $isHead = $method === 'HEAD';

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

        // P5-S3: Enforce per-profile concurrent stream limits (direct-play path).
        // StreamLimitMiddleware can't be applied as router middleware here because
        // this route bypasses the router to use Workerman's native withFile()
        // streaming (essential for multi-GB videos). The check is inlined instead.
        // Signed-URL access (userId=null) skips the stream limit — the signed URL
        // itself is the access control; stream limits only apply to authenticated
        // sessions where we have a profileId to enforce against.
        if ($userId !== null) {
            $streamLimitResponse = $this->checkStreamLimit($wr, $userId);
            if ($streamLimitResponse !== null) {
                return $streamLimitResponse;
            }
        }

        /** @var ItemRepository $repo */
        $repo = $this->container->get(ItemRepository::class);
        $item = $repo->findById($m['id']);

        // Parental-control ACCESS gate (Finding 1). For an authenticated session
        // (userId set) whose ACTIVE profile is capped, deny an over-cap item (by
        // EFFECTIVE rating — own content_rating, else the inherited series
        // rating) with the SAME 404 used for "not found" below, so existence is
        // never confirmed and no bytes are served. Signed-URL access (userId
        // null) is governed by the signed URL itself — and the mint paths
        // (detail/download) are already gated — so it is intentionally not
        // re-checked here. Owner / no-profile / un-capped → null filter → no-op.
        if ($userId !== null && $userId !== '' && is_array($item)) {
            $gate = $this->ratingGate();
            $filter = $gate?->resolveFilterForUser($userId);
            if ($filter !== null && $gate !== null && !$gate->isAllowed($item, $filter)) {
                return new WorkermanResponse(
                    404,
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                    'Media not found',
                );
            }
        }

        $path = is_array($item) && is_string($item['path'] ?? null) ? $item['path'] : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return new WorkermanResponse(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Media not found');
        }

        $fileSize = (int) filesize($path);
        $mime = self::streamMimeFor($path);

        // HEAD requests: return headers only (no Range support, no body).
        //
        // BodylessResponse, not WorkermanResponse: Workerman's encoder appends its
        // own `Content-Length: strlen($body)` unconditionally, so a plain
        // WorkermanResponse with a real Content-Length and an empty body puts TWO
        // conflicting Content-Length fields on the wire (the bogus `0` LAST) —
        // invalid per RFC 9110 §8.6. See {@see BodylessResponse}.
        //
        // Named explicitly rather than selected by a `headOnly` flag, because this
        // method returns WORKERMAN responses (it runs before Application::dispatch()
        // and never builds a Phlix Response), so `Response::$headOnly` — the flag
        // that selects this encoder for router-dispatched HEAD replies — does not
        // exist on this path.
        if ($isHead) {
            $resp = new BodylessResponse(200, ['Content-Type' => $mime]);
            $resp->header('Content-Length', (string) $fileSize);
            return $resp;
        }

        $rangeHeader = $wr->header('range');
        $range = ByteRangeParser::parse(is_string($rangeHeader) ? $rangeHeader : null, $fileSize);
        if ($range !== null) {
            if (!$range['satisfiable']) {
                return new WorkermanResponse(416, [
                    'Content-Type' => $mime,
                    'Content-Range' => "bytes */{$fileSize}",
                ]);
            }
            $resp = new WorkermanResponse(206, ['Content-Type' => $mime]);
            // withFile() with a non-zero offset/length makes Workerman emit
            // 206 + Content-Range automatically.
            $resp->withFile($path, $range['start'], $range['end'] - $range['start'] + 1);
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
     * Enforce per-profile concurrent stream limits for direct-play requests.
     *
     * P5-S3: This is the direct-play analogue of StreamLimitMiddleware, inlined
     * here because the /media/{id}/stream route bypasses the router (and its
     * middleware chain) to use Workerman's native withFile() streaming.
     *
     * @param WorkermanRequest $wr    The Workerman request.
     * @param string           $userId The authenticated user's ID.
     *
     * @return WorkermanResponse|null 429 with StreamLimitExceeded body on limit
     *                                exceeded; null to continue serving.
     */
    private function checkStreamLimit(WorkermanRequest $wr, string $userId): ?WorkermanResponse
    {
        /** @var \Phlix\Auth\UserProfileManager $profileManager */
        $profileManager = $this->container->get(\Phlix\Auth\UserProfileManager::class);
        $profile = $profileManager->getActiveProfile($userId);
        if ($profile === null) {
            // No profile — fail closed (deny) rather than letting an unprofiled
            // user through without stream tracking.
            return new WorkermanResponse(
                403,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode([
                    'error' => 'StreamLimitExceeded',
                    'denial_type' => 'profile_not_found',
                    'message' => 'Profile not found; access denied',
                ], JSON_THROW_ON_ERROR),
            );
        }

        $profileId = $this->resolveStreamProfileId($profile);
        if ($profileId === null) {
            return new WorkermanResponse(
                403,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode([
                    'error' => 'StreamLimitExceeded',
                    'denial_type' => 'profile_not_found',
                    'message' => 'Profile not found; access denied',
                ], JSON_THROW_ON_ERROR),
            );
        }

        $deviceId = $this->getStreamDeviceId($wr);
        $sessionId = $this->getStreamSessionId($wr);
        if ($sessionId === null || $deviceId === null) {
            // Missing session/device info — skip stream limit enforcement and let
            // the request proceed (stream won't be tracked, but we don't block).
            return null;
        }

        /** @var \Phlix\Access\StreamSessionService $streamSessionService */
        $streamSessionService = $this->container->get(\Phlix\Access\StreamSessionService::class);
        $registered = $streamSessionService->registerStream($profileId, $deviceId, $sessionId);
        if (!$registered) {
            return new WorkermanResponse(
                429,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode([
                    'error' => 'StreamLimitExceeded',
                    'denial_type' => 'stream_limit_exceeded',
                    'message' => 'Maximum concurrent streams reached for this profile',
                    'profile_id' => $profileId,
                ], JSON_THROW_ON_ERROR),
            );
        }

        // Register (or refresh) the heartbeat timer for this streaming session.
        // Keyed + deduped per session inside the service, so repeated requests
        // (incl. every HLS segment) never accumulate timers; the timer is torn
        // down on stream release.
        $streamSessionService->registerHeartbeatTimer($sessionId);

        return null;
    }

    /**
     * Resolve the profile ID from a profile array (inline helper for stream limiting).
     *
     * @param array<string, mixed> $profile Profile array from UserProfileManager.
     *
     * @return string|null Profile ID as string, or null if cannot resolve.
     */
    private function resolveStreamProfileId(array $profile): ?string
    {
        $id = $profile['id'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Extract the device ID from a Workerman request for stream tracking.
     */
    private function getStreamDeviceId(WorkermanRequest $wr): ?string
    {
        $deviceId = $wr->header('x-device-id');
        if (is_string($deviceId) && $deviceId !== '') {
            return $deviceId;
        }

        $userAgent = $wr->header('user-agent');
        if (is_string($userAgent) && $userAgent !== '') {
            return hash('sha256', $userAgent);
        }

        return null;
    }

    /**
     * Extract the session ID from a Workerman request for stream tracking.
     */
    private function getStreamSessionId(WorkermanRequest $wr): ?string
    {
        // Query param first (used by HLS clients)
        $sessionId = $wr->get('session_id');
        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        // X-Session-ID header
        $sessionId = $wr->header('x-session-id');
        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        return null;
    }

    /**
     * Content-Type for a media file we're about to direct-play.
     *
     * Extension-first so the browser gets a deterministic, playable MIME for
     * the video/audio formats `<video>`/`<audio>` understand; unknown
     * extensions fall back to a binary default. Audio mappings unblock music
     * track direct-play over GET /media/{id}/stream (X8) — without them audio
     * files were served as application/octet-stream and would not play.
     */
    private static function streamMimeFor(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return [
            // Video
            'mp4'  => 'video/mp4',
            'm4v'  => 'video/mp4',
            'mov'  => 'video/mp4',
            'webm' => 'video/webm',
            'ogv'  => 'video/ogg',
            'mkv'  => 'video/x-matroska',
            'avi'  => 'video/x-msvideo',
            'ts'   => 'video/mp2t',
            // Audio
            'mp3'  => 'audio/mpeg',
            'm4a'  => 'audio/mp4',
            'aac'  => 'audio/aac',
            'flac' => 'audio/flac',
            'ogg'  => 'audio/ogg',
            'oga'  => 'audio/ogg',
            'opus' => 'audio/opus',
            'wav'  => 'audio/wav',
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

        if ($path === '/' || $path === '') {
            // The redesigned Vue SPA is the front door — send the bare root to
            // /app. Old SSR pages (/login, /library, /player/{id}, …) stay
            // reachable at their own paths.
            return (new Response())->redirect('/app');
        }
        // D-SRV-DEL: the former Smarty SSR pages are fully replaced by the Vue
        // SPA under /app — redirect the legacy paths to their /app equivalents.
        if ($path === '/login') {
            return (new Response())->redirect('/app/login');
        }
        if ($path === '/register' || $path === '/auth/register') {
            return (new Response())->redirect('/app/signup');
        }
        if ($path === '/library' || $path === '/library/') {
            return (new Response())->redirect('/app');
        }
        // Single media-item detail (legacy link target). MUST be matched before
        // the single-segment library route below.
        if (preg_match('#^/library/item/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return (new Response())->redirect('/app/media/' . $m['id']);
        }
        if (preg_match('#^/player/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return (new Response())->redirect('/app/player/' . $m['id']);
        }
        if (preg_match('#^/library/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return (new Response())->redirect('/app/library/' . $m['id']);
        }
        if ($path === '/search') {
            // D-SRV-1: Search is now a Vue SPA — redirect legacy Smarty route
            return (new Response())->redirect('/app/search');
        }
        if ($path === '/settings') {
            return (new Response())->redirect('/app/settings');
        }
        if ($path === '/settings/security') {
            // D-SRV-2: Security settings are now a Vue SPA — redirect legacy Smarty route
            return (new Response())->redirect('/app/settings/security');
        }
        if (str_starts_with($path, '/admin/plugins')) {
            return (new Response())->redirect('/app/admin/plugins');
        }
        if ($path === '/admin/dashboard') {
            return (new Response())->redirect('/app/admin/dashboard');
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
        if ($path === '/music' || $path === '/music/albums') {
            // D-SRV-DEL: music landing is now a Vue SPA page
            return (new Response())->redirect('/app/music');
        }
        if (preg_match('#^/music/albums/(?P<name>.+)$#', $path, $m) === 1) {
            // Redirect to the Vue SPA album detail page
            return (new Response())->redirect('/app/music/album/' . urldecode($m['name']));
        }
        if ($path === '/music/artists') {
            // Redirect to the Vue SPA artists listing
            return (new Response())->redirect('/app/music/artists');
        }
        if (preg_match('#^/music/artists/(?P<name>.+)$#', $path, $m) === 1) {
            // Redirect to the Vue SPA artist detail page
            return (new Response())->redirect('/app/music/artist/' . urldecode($m['name']));
        }
        if ($path === '/music/tracks') {
            // Redirect to the Vue SPA tracks listing
            return (new Response())->redirect('/app/music/tracks');
        }
        if ($path === '/music/player') {
            // Redirect to the Vue SPA player page
            return (new Response())->redirect('/app/music/player');
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
            // Redirect to the Vue SPA reader page
            return (new Response())->redirect('/app/books/' . $m['id'] . '/read');
        }
        if (preg_match('#^/books/(?P<id>[^/]+)$#', $path, $m) === 1) {
            // Redirect to the Vue SPA book detail page
            return (new Response())->redirect('/app/books/' . $m['id']);
        }
        if ($path === '/books') {
            // Redirect to the Vue SPA books listing
            return (new Response())->redirect('/app/books');
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchAudiobooks(Request $request, string $path): Response
    {
        if (preg_match('#^/audiobooks/(?P<id>[^/]+)/read$#', $path, $m) === 1) {
            // Redirect to the Vue SPA player page
            return (new Response())->redirect('/app/audiobooks/' . $m['id'] . '/read');
        }
        if (preg_match('#^/audiobooks/(?P<id>[^/]+)$#', $path, $m) === 1) {
            // Redirect to the Vue SPA audiobook detail page
            return (new Response())->redirect('/app/audiobooks/' . $m['id']);
        }
        if ($path === '/audiobooks') {
            // Redirect to the Vue SPA audiobooks listing
            return (new Response())->redirect('/app/audiobooks');
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
        // D-SRV-10: Photo pages are now Vue SPA — redirect to /app/photo/*
        if ($path === '/photo/albums') {
            $query = $request->query;
            $qs = count($query) > 0 ? '?' . http_build_query($query) : '';
            return (new Response())->redirect('/app/photo/albums' . $qs);
        }
        if (preg_match('#^/photo/album/(?P<id>[^/]+)$#', $path, $m) === 1) {
            $query = $request->query;
            $qs = count($query) > 0 ? '?' . http_build_query($query) : '';
            return (new Response())->redirect('/app/photo/album/' . $m['id'] . $qs);
        }
        if (preg_match('#^/photo/photo/(?P<id>[^/]+)$#', $path, $m) === 1) {
            $query = $request->query;
            $qs = count($query) > 0 ? '?' . http_build_query($query) : '';
            return (new Response())->redirect('/app/photo/photo/' . $m['id'] . $qs);
        }
        if ($path === '/photo/slideshow') {
            $query = $request->query;
            $qs = count($query) > 0 ? '?' . http_build_query($query) : '';
            return (new Response())->redirect('/app/photo/slideshow' . $qs);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }
}
