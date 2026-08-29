<?php

/**
 * Phlix media server component: Server\Http\FastPath.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\FastPath;

use Phlix\Auth\SignedUrl;
use Phlix\Auth\UserProfileManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Controllers\ByteRangeParser;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Psr\Container\ContainerInterface;
use Throwable;

use function filesize;
use function gmdate;
use function hash;
use function in_array;
use function is_file;
use function is_readable;
use function is_string;
use function json_encode;
use function pathinfo;
use function preg_match;
use function sprintf;
use function stat;
use function strtolower;
use function strtotime;
use function substr;

/**
 * The byte-serving endpoints that run BEFORE the route table, expressed
 * once in a transport-neutral form so BOTH entry points can serve them.
 *
 * ## Why this class exists (S238 + S301)
 *
 * `GET /api/v1/artwork/{id}`, `GET /api/v1/users/{id}/avatar` and
 * `GET /media/{id}/stream` used to live as private methods on
 * {@see \Phlix\Server\Workerman\HttpHandler}, invoked inline before
 * `Router::dispatch()` and registered in NO route table. That is fine for the
 * Workerman HTTP daemon, which runs those methods itself — but it made all
 * three endpoints invisible to {@see \Phlix\Hub\RelayRequestDispatcher}, which
 * only ever consults the two route tables. Measured through the real composed
 * container (345 `Application` routes + 47 `WebPortalRouter` routes), a relayed
 * request for either image path 404'd, so **relayed inline-browse could render
 * no posters and no avatars at all** (S238) — and a relayed direct-play request
 * 404'd the same way (S301), killing playback over the relay.
 *
 * ⚠ The gate was NOT the one S164 found for `/media/{id}/stream`. The two
 * image paths DO start with `/api/`, so the `WebPortalRouter` second-chance
 * fallback in `RelayRequestDispatcher::dispatch()` fires for them — and 404s
 * too, because the route is absent from both tables. There is exactly ONE gate
 * here (missing registration), where `/media/{id}/stream` has two (missing
 * registration AND the `/api/`-only fallback guard it cannot satisfy).
 *
 * ## The mechanism
 *
 * One implementation, two transports, consulted at the SAME pipeline position in
 * each:
 *
 *  - {@see \Phlix\Server\Workerman\HttpHandler::__invoke()} — after static files,
 *    authentication and the CSRF check, before the application router;
 *  - {@see \Phlix\Hub\RelayRequestDispatcher::dispatch()} — after the DLNA hard
 *    deny, before the application router.
 *
 * Because the input is {@see Request} and the output is {@see Response} (which
 * carries `filePath`, so `Response::toWorkermanResponse()` still hands the file
 * to Workerman's event-loop `withFile()` and
 * `RelayConsumer::streamFileChunks()` still chunks it over the tunnel) neither
 * transport buffers media bytes into worker memory. Returning `null` means "not
 * my request" and the caller falls through to its router exactly as before.
 *
 * ## The third fast path: `/media/{id}/stream` (S301)
 *
 * `HttpHandler::serveMediaStream()` — the direct-play byte stream — is the
 * THIRD pre-router fast path and now lives here, translated into this same
 * `?Response`-returning form, for the same reason the two image endpoints
 * moved (S238): a relayed direct-play request 404'd because the stream path is
 * in NO route table and `RelayRequestDispatcher::dispatch()` only ever
 * consulted the two route tables.
 *
 * It deliberately is NOT a peer of the two image endpoints: it carries
 * `Range`/206 handling, a concurrent-stream limit
 * ({@see self::checkStreamLimit()}) and the parental {@see RatingGate}, none of
 * which the image endpoints have. Those heavier collaborators are resolved
 * LAZILY from the container — exactly as the old `HttpHandler` method did —
 * and only once the stream pattern has matched, so the image paths never pay
 * for them and non-stream requests never touch them.
 *
 * ## Auth is unchanged, on both transports
 *
 * Each endpoint authorises inline — a resolved session (`$request->userId`) OR a
 * valid signed-URL token — exactly as it did as a private `HttpHandler` method,
 * and it is still checked BEFORE any file is stat'd or streamed. Nothing here
 * grants access that the router would have refused: these paths were never in the
 * route table, so no middleware was ever applied to them on either transport.
 *
 * @package Phlix\Server\Http\FastPath
 * @since 0.10.0
 */
final class PreRouterFastPaths
{
    /** `GET /api/v1/users/{id}/avatar`. */
    private const AVATAR_PATTERN = '#^/api/v1/users/([^/]+)/avatar$#';

    /** `GET /api/v1/artwork/{itemId}`. */
    private const ARTWORK_PATTERN = '#^/api/v1/artwork/([^/]+)$#';

    /** `GET /media/{id}/stream` — the direct-play byte stream (S301). */
    private const STREAM_PATTERN = '#^/media/(?P<id>[^/]+)/stream$#';

    /**
     * @param ArtworkStorage      $artworkStorage Poster/variant store on local disk.
     * @param AvatarStorage       $avatarStorage  User avatar store on local disk.
     * @param ContainerInterface|null $container  Container for the stream path's
     *                                            heavier collaborators (S301), resolved
     *                                            lazily ONLY after the stream pattern
     *                                            matches. Optional so the image-only
     *                                            construction sites (tests, wiring) stay
     *                                            unchanged; a null container on an actual
     *                                            stream request is a loud failure, never
     *                                            a silent 404.
     */
    public function __construct(
        private readonly ArtworkStorage $artworkStorage,
        private readonly AvatarStorage $avatarStorage,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * Serve `$request` if it is one of the pre-router byte-serving endpoints.
     *
     * @param Request $request The request, with `$request->userId` already
     *                         resolved by the transport (the authenticator on the
     *                         HTTP daemon, the hub-validated relay user on the
     *                         tunnel).
     *
     * @return Response|null The response, or null when the caller should fall
     *                       through to its route table.
     *
     * @since 0.10.0
     */
    public function dispatch(Request $request): ?Response
    {
        return $this->serveUserAvatar($request)
            ?? $this->serveArtwork($request)
            ?? $this->serveMediaStream($request);
    }

    /**
     * Whether `$request` is one of these endpoints AT ALL — decided from the same
     * patterns {@see dispatch()} uses, so the two can never disagree.
     *
     * ⚠ Not redundant with {@see dispatch()}, and not a "simplification" to
     * delete. It exists so {@see \Phlix\Server\Workerman\HttpHandler} can decide
     * whether to BUILD this collaborator at all. Before S238 the two endpoints
     * were private methods that pulled their storage out of the container only
     * once the path had already matched; a handler that eagerly resolved both
     * storages on EVERY request — including the overwhelming majority that are
     * neither — would be a behaviour change, and it would make every unrelated
     * HttpHandler caller depend on two services it has nothing to do with.
     *
     * S301 widened the gate from `GET` to `GET`/`HEAD`: the stream path's HEAD
     * probe (a player checking size before opening the stream) must reach the
     * fast stage on both transports, exactly like its GET twin.
     *
     * @param Request $request The request to classify.
     *
     * @since 0.10.0
     */
    public static function couldHandle(Request $request): bool
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return false;
        }

        return preg_match(self::AVATAR_PATTERN, $request->path) === 1
            || preg_match(self::ARTWORK_PATTERN, $request->path) === 1
            || preg_match(self::STREAM_PATTERN, $request->path) === 1;
    }

    /**
     * Byte-serve a user avatar image.
     *
     * GET /api/v1/users/{id}/avatar
     *
     * Authorised by: resolved session (Bearer/cookie, or the hub-validated relay
     * user) OR a valid signed-URL token (so `<img src="...">` works without an
     * Authorization header).
     *
     * @param Request $request The request to match and serve.
     *
     * @return Response|null Null when this is not an avatar request.
     */
    private function serveUserAvatar(Request $request): ?Response
    {
        if ($request->method !== 'GET') {
            return null;
        }

        // Match /api/v1/users/{id}/avatar — captures the userId
        if (preg_match(self::AVATAR_PATTERN, $request->path, $m) !== 1) {
            return null;
        }

        $targetUserId = $m[1];

        // Authorise: resolved session OR valid signed-URL token.
        // A missing/invalid/expired token → 401 so the browser shows a broken image
        // rather than a raw JSON error body on an <img> src.
        $unauthorized = $this->rejectUnlessSigned($request, $request->path);
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $avatarPath = $this->avatarStorage->path($targetUserId);

        if ($avatarPath === null || !is_file($avatarPath) || !is_readable($avatarPath)) {
            return (new Response())->status(404)->text('Avatar not found');
        }

        return (new Response())
            ->status(200)
            // ⚠ CORRECTED IN THE MOVE, deliberately. `HttpHandler::serveUserAvatar()`
            // did `mimeFor(pathinfo($avatarPath, PATHINFO_EXTENSION))`, i.e. it
            // handed the shared helper the bare EXTENSION `"jpg"`. That helper
            // starts with `pathinfo($path, PATHINFO_EXTENSION)` of its own, which
            // on a dot-less `"jpg"` is `""` — so no map entry ever matched and
            // EVERY avatar was served as `application/octet-stream`. It rendered
            // anyway only because these fast-path responses carry no
            // `X-Content-Type-Options: nosniff`, so browsers content-sniffed it;
            // over the hub relay that header survives to a consumer that may not.
            // {@see AvatarStorage} re-encodes every upload to JPEG and `path()`
            // only ever returns `<userId>.jpg`, so the type is a constant.
            ->header('Content-Type', 'image/jpeg')
            ->withFile($avatarPath);
    }

    /**
     * Byte-serve a media item's artwork (poster) image.
     *
     * GET /api/v1/artwork/{itemId}?size={size}
     *
     * Authorised by: resolved session (Bearer/cookie, or the hub-validated relay
     * user) OR a valid signed-URL token, minted by {@see ArtworkStorage::url()}
     * via {@see \Phlix\Auth\SignedUrl::mint()}.
     *
     * @param Request $request The request to match and serve.
     *
     * @return Response|null Null when this is not an artwork request.
     */
    private function serveArtwork(Request $request): ?Response
    {
        if ($request->method !== 'GET') {
            return null;
        }

        // Match /api/v1/artwork/{itemId} — captures the itemId
        if (preg_match(self::ARTWORK_PATTERN, $request->path, $m) !== 1) {
            return null;
        }

        $itemId = $m[1];

        // Get size parameter (default to 'original')
        $rawSize = $request->query['size'] ?? null;
        $size = is_string($rawSize) ? $rawSize : 'original';

        // Validate size parameter
        if (!self::isValidArtworkSize($size)) {
            return (new Response())
                ->status(400)
                ->header('Content-Type', 'application/json; charset=utf-8')
                ->body(json_encode(['error' => 'Invalid size parameter']) ?: '{"error":"Invalid size parameter"}');
        }

        // Authorise: resolved session OR valid signed-URL token.
        //
        // ⚠ MEASURED, because the surrounding comment used to claim otherwise and
        // a whole verification premise was built on it: the `?size=` suffix below
        // is NOT signed material. {@see \Phlix\Auth\SignedUrl::canonicalResource()}
        // strips everything from the first `?` — deliberately, so the verifier
        // (which only ever sees the query-less {@see Request::$path}) and the
        // minter agree — and {@see \Phlix\Auth\SignedUrl::mint()} likewise signs
        // only the path. Proven by execution: a token minted for
        // `…/item-1?size=w342` verifies TRUE against `?size=original` and against
        // the bare path, and FALSE against `…/item-2`. So the ITEM is bound and
        // the VARIANT is not — one signed poster URL is valid for every size of
        // that item, which is the intended, documented design.
        //
        // The suffix is kept verbatim rather than dropped so this call site still
        // reads as "the resource this URL was minted over", and so that a future
        // change to `canonicalResource()` that DOES bind the query keeps working
        // here unchanged. Do not "simplify" it away, and do not write a test that
        // asserts the size is signed — it is not, and never was.
        $unauthorized = $this->rejectUnlessSigned(
            $request,
            '/api/v1/artwork/' . $itemId . '?size=' . $size,
        );
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $artworkPath = $this->artworkStorage->variantPath($itemId, $size);

        if ($artworkPath === null || !is_file($artworkPath) || !is_readable($artworkPath)) {
            return (new Response())
                ->status(404)
                ->header('Content-Type', 'application/json; charset=utf-8')
                ->body(json_encode(['error' => 'Artwork not found']) ?: '{"error":"Artwork not found"}');
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
        $ifNoneMatch = $request->getHeader('if-none-match');
        $ifModifiedSince = $request->getHeader('if-modified-since');
        $etagMatch = $etag !== '' && $ifNoneMatch === $etag;
        $imsTs = is_string($ifModifiedSince) && $ifModifiedSince !== ''
            ? strtotime($ifModifiedSince)
            : false;
        $notModified = ($ifNoneMatch === null || $ifNoneMatch === '')
            && $mtime > 0
            && $imsTs !== false
            && $imsTs >= $mtime;

        if ($etagMatch || $notModified) {
            // 304 carries the validators but NO body (do not attach the file).
            $notModifiedResponse = (new Response())
                ->status(304)
                ->header('Cache-Control', 'public, max-age=31536000, immutable');
            if ($etag !== '') {
                $notModifiedResponse->header('ETag', $etag);
            }
            if ($lastModified !== '') {
                $notModifiedResponse->header('Last-Modified', $lastModified);
            }

            return $notModifiedResponse;
        }

        $response = (new Response())
            ->status(200)
            // The title logo (`size=logo`) is a transparency-preserving PNG; the
            // poster variants are JPEG.
            ->header('Content-Type', $size === ArtworkStorage::LOGO_SIZE ? 'image/png' : 'image/jpeg')
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
        if ($etag !== '') {
            $response->header('ETag', $etag);
        }
        if ($lastModified !== '') {
            $response->header('Last-Modified', $lastModified);
        }

        return $response->withFile($artworkPath);
    }

    /**
     * The shared inline authorisation both endpoints use.
     *
     * A request that already carries a resolved user is admitted. Otherwise the
     * `exp`/`sig` query pair must be a valid signature over `$signedResource` —
     * this is what lets `<img src="...">` work without an Authorization header.
     *
     * ⚠ `$signedResource` is canonicalised by {@see SignedUrl::canonicalResource()}
     * before hashing, which strips any query string. Passing a resource WITH a
     * query is therefore harmless but not load-bearing — see the measured note in
     * {@see serveArtwork()}. The PATH is what binds.
     *
     * @param Request $request        The request being authorised.
     * @param string  $signedResource The resource spelling the URL was minted
     *                                over (query, if any, is not hashed).
     *
     * @return Response|null A 401 to return immediately, or null to proceed.
     */
    private function rejectUnlessSigned(Request $request, string $signedResource): ?Response
    {
        $userId = $request->userId;
        if ($userId !== null && $userId !== '') {
            return null;
        }

        $signer = SignedUrl::fromEnv();
        $exp = $request->query['exp'] ?? null;
        $sig = $request->query['sig'] ?? null;

        if ($signer->verify($signedResource, is_string($exp) ? $exp : null, is_string($sig) ? $sig : null)) {
            return null;
        }

        return (new Response())->status(401)->text('Unauthorized');
    }

    /**
     * Validate artwork size parameter against known variants.
     *
     * @param string $size The requested variant name.
     */
    private static function isValidArtworkSize(string $size): bool
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
     * Byte-serve a media item's source file for direct play (S301).
     *
     * Backs `GET /media/{id}/stream` — the URL the web player's `<video>`
     * source points at (and what {@see \Phlix\Media\Streaming\StreamManager::buildDirectStreamUrl()}
     * builds). Returns null when the path is not a media-stream request so the
     * caller falls through to the normal router.
     *
     * Translated VERBATIM from the private `HttpHandler::serveMediaStream()` it
     * replaces, in the same transport-neutral `?Response` form the two image
     * endpoints already use: the {@see Response} carries `filePath`/`fileOffset`/
     * `fileLength`, so {@see Response::toWorkermanResponse()} still hands the
     * file to Workerman's event-loop `withFile()` and
     * {@see \Phlix\Hub\RelayConsumer::streamFileChunks()} still chunks it over
     * the tunnel — neither transport ever buffers media bytes into worker
     * memory. HTTP `Range` requests are honoured (206 + `Content-Range`) so the
     * browser can seek; an unsatisfiable range yields 416.
     *
     * @param Request $request The request to match and serve.
     *
     * @return Response|null Null when this is not a media-stream request.
     */
    private function serveMediaStream(Request $request): ?Response
    {
        $method = $request->method;
        // Accept both GET and HEAD — HEAD is used by clients to check media
        // availability without downloading the full body.
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return null;
        }
        $isHead = $method === 'HEAD';

        if (preg_match(self::STREAM_PATTERN, $request->path, $m) !== 1) {
            return null;
        }

        // Authorise before touching the filesystem: a resolved session
        // (Bearer/cookie, or the hub-validated relay user) OR a valid signed-URL
        // token. Returning a 401 here (rather than null) stops the request — a
        // null would fall through to the router and 404, masking the auth
        // failure.
        $unauthorized = $this->rejectUnlessSigned($request, $request->path);
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $userId = $request->userId;

        // P5-S3: Enforce per-profile concurrent stream limits (direct-play path).
        // StreamLimitMiddleware can't be applied as router middleware here because
        // this route bypasses the router to use the event-loop file streaming
        // (essential for multi-GB videos). The check is inlined instead.
        // Signed-URL access (userId null) skips the stream limit — the signed URL
        // itself is the access control; stream limits only apply to authenticated
        // sessions where we have a profileId to enforce against.
        if ($userId !== null && $userId !== '') {
            $streamLimitResponse = $this->checkStreamLimit($request, $userId);
            if ($streamLimitResponse !== null) {
                return $streamLimitResponse;
            }
        }

        /** @var ItemRepository $repo */
        $repo = $this->container()->get(ItemRepository::class);
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
                return (new Response())
                    ->status(404)
                    ->header('Content-Type', 'text/plain; charset=utf-8')
                    ->text('Media not found');
            }
        }

        $path = is_array($item) && is_string($item['path'] ?? null) ? $item['path'] : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return (new Response())
                ->status(404)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->text('Media not found');
        }

        $fileSize = (int) filesize($path);
        $mime = self::streamMimeFor($path);

        // HEAD requests: return headers only (no Range support, no body).
        //
        // Response::asHeadReply() — not a bare header on a buffered response —
        // pins the REAL file size as Content-Length and renders through
        // BodylessResponse on the Workerman encoder, so exactly ONE
        // Content-Length reaches the wire and no body does (RFC 9110 §9.3.2 /
        // §8.6; the S52-class duplicate-Content-Length defect the old
        // HttpHandler arm avoided with a Workerman BodylessResponse directly).
        if ($isHead) {
            return (new Response())
                ->status(200)
                ->header('Content-Type', $mime)
                ->withFile($path)
                ->asHeadReply();
        }

        $rangeHeader = $request->getHeader('range');
        $range = ByteRangeParser::parse(is_string($rangeHeader) ? $rangeHeader : null, $fileSize);
        if ($range !== null) {
            if (!$range['satisfiable']) {
                return (new Response())
                    ->status(416)
                    ->header('Content-Type', $mime)
                    ->header('Content-Range', "bytes */{$fileSize}");
            }
            $resp = (new Response())
                ->status(206)
                ->header('Content-Type', $mime);
            // withFile() with a non-zero offset/length makes Workerman emit
            // 206 + Content-Range automatically.
            $resp->withFile($path, $range['start'], $range['end'] - $range['start'] + 1);
            return $resp;
        }

        $resp = (new Response())
            ->status(200)
            ->header('Content-Type', $mime);
        $resp->withFile($path);
        return $resp;
    }

    /**
     * Enforce per-profile concurrent stream limits for direct-play requests
     * (S301, moved verbatim from {@see \Phlix\Server\Workerman\HttpHandler}).
     *
     * P5-S3: This is the direct-play analogue of StreamLimitMiddleware, inlined
     * here because the /media/{id}/stream route bypasses the router (and its
     * middleware chain) to use the event-loop file streaming.
     *
     * ⚠ The `profile_not_found` branch is the HONEST refusal, not a hole (S301):
     * a session whose user has NO active profile (which is exactly what an
     * UNMAPPED relayed principal is — a hub UUID with no server-side user row)
     * is denied with a named 403, never silently streamed untracked.
     *
     * @param Request $request The request being served.
     * @param string  $userId  The authenticated user's ID.
     *
     * @return Response|null 403 with the named denial on the profile_not_found
     *                       branch, 429 on limit exceeded; null to continue
     *                       serving.
     */
    private function checkStreamLimit(Request $request, string $userId): ?Response
    {
        // S80: prefer the profile THIS SESSION is running as. This direct-play
        // path bypasses the router entirely, so it never sees StreamLimitMiddleware
        // and has to make the same choice for itself.
        $profileId = RequestContext::getProfileId();

        if ($profileId === null || $profileId === '') {
            /** @var UserProfileManager $profileManager */
            $profileManager = $this->container()->get(UserProfileManager::class);
            $profile = $profileManager->getActiveProfile($userId);
            if ($profile === null) {
                // No profile — fail closed (deny) rather than letting an unprofiled
                // user through without stream tracking.
                return $this->profileNotFoundResponse();
            }

            $profileId = $this->resolveStreamProfileId($profile);
            if ($profileId === null) {
                return $this->profileNotFoundResponse();
            }
        }

        $deviceId = $this->getStreamDeviceId($request);
        $sessionId = $this->getStreamSessionId($request);
        if ($sessionId === null || $deviceId === null) {
            // Missing session/device info — skip stream limit enforcement and let
            // the request proceed (stream won't be tracked, but we don't block).
            return null;
        }

        /** @var \Phlix\Access\StreamSessionService $streamSessionService */
        $streamSessionService = $this->container()->get(\Phlix\Access\StreamSessionService::class);
        $registered = $streamSessionService->registerStream($profileId, $deviceId, $sessionId);
        if (!$registered) {
            return (new Response())
                ->status(429)
                ->header('Content-Type', 'application/json; charset=utf-8')
                ->body(json_encode([
                    'error' => 'StreamLimitExceeded',
                    'denial_type' => 'stream_limit_exceeded',
                    'message' => 'Maximum concurrent streams reached for this profile',
                    'profile_id' => $profileId,
                ], JSON_THROW_ON_ERROR));
        }

        // Register (or refresh) the heartbeat timer for this streaming session.
        // Keyed + deduped per session inside the service, so repeated requests
        // (incl. every HLS segment) never accumulate timers; the timer is torn
        // down on stream release.
        $streamSessionService->registerHeartbeatTimer($sessionId);

        return null;
    }

    /**
     * The named 403 for a session whose user has no resolvable active profile.
     *
     * @return Response
     */
    private function profileNotFoundResponse(): Response
    {
        return (new Response())
            ->status(403)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode([
                'error' => 'StreamLimitExceeded',
                'denial_type' => 'profile_not_found',
                'message' => 'Profile not found; access denied',
            ], JSON_THROW_ON_ERROR));
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
     * Extract the device ID from a request for stream tracking.
     *
     * @param Request $request The request being served.
     */
    private function getStreamDeviceId(Request $request): ?string
    {
        $deviceId = $request->getHeader('x-device-id');
        if (is_string($deviceId) && $deviceId !== '') {
            return $deviceId;
        }

        $userAgent = $request->getHeader('user-agent');
        if (is_string($userAgent) && $userAgent !== '') {
            return hash('sha256', $userAgent);
        }

        return null;
    }

    /**
     * Extract the session ID from a request for stream tracking.
     *
     * @param Request $request The request being served.
     */
    private function getStreamSessionId(Request $request): ?string
    {
        // Query param first (used by HLS clients)
        $sessionId = $request->query['session_id'] ?? null;
        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        // X-Session-ID header
        $sessionId = $request->getHeader('x-session-id');
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
     *
     * @param string $path The media file path.
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
     * Resolve the shared parental-control {@see RatingGate} from the container,
     * or null when it cannot be built (never blocks the stream on wiring error —
     * a null gate is a strict no-op, owner-safe).
     */
    private function ratingGate(): ?RatingGate
    {
        try {
            $gate = $this->container()->get(RatingGate::class);
            return $gate instanceof RatingGate ? $gate : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The container the stream path's heavier collaborators come from.
     *
     * Fail-loud accessor: a stream request reaching a container-less instance
     * is a wiring error that must never degrade silently back to the 404 S301
     * removed (mirrors {@see \Phlix\Server\Workerman\HttpHandler::fastPaths()}'s
     * loudness). Only the stream branch calls this — the two image endpoints
     * stay container-free.
     */
    private function container(): ContainerInterface
    {
        if ($this->container === null) {
            throw new \RuntimeException(
                'PreRouterFastPaths cannot serve /media/{id}/stream: no container was provided. '
                . 'The stream fast path must never degrade silently.',
            );
        }

        return $this->container;
    }
}
