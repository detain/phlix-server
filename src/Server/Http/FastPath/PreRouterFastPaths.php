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
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

use function gmdate;
use function in_array;
use function is_file;
use function is_readable;
use function is_string;
use function json_encode;
use function preg_match;
use function sprintf;
use function stat;
use function strtotime;
use function substr;

/**
 * The image byte-serving endpoints that run BEFORE the route table, expressed
 * once in a transport-neutral form so BOTH entry points can serve them.
 *
 * ## Why this class exists (S238)
 *
 * `GET /api/v1/artwork/{id}` and `GET /api/v1/users/{id}/avatar` used to live as
 * private methods on {@see \Phlix\Server\Workerman\HttpHandler}, invoked inline
 * before `Router::dispatch()` and registered in NO route table. That is fine for
 * the Workerman HTTP daemon, which runs those methods itself — but it made both
 * endpoints invisible to {@see \Phlix\Hub\RelayRequestDispatcher}, which only
 * ever consults the two route tables. Measured through the real composed
 * container (345 `Application` routes + 47 `WebPortalRouter` routes), a relayed
 * request for either path 404'd, so **relayed inline-browse could render no
 * posters and no avatars at all**.
 *
 * ⚠ The gate was NOT the one S164 found for `/media/{id}/stream`. Both of these
 * paths DO start with `/api/`, so the `WebPortalRouter` second-chance fallback in
 * `RelayRequestDispatcher::dispatch()` fires for them — and 404s too, because the
 * route is absent from both tables. There is exactly ONE gate here (missing
 * registration), where `/media/{id}/stream` has two (missing registration AND the
 * `/api/`-only fallback guard it cannot satisfy).
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
 * transport buffers image bytes into worker memory. Returning `null` means "not
 * my request" and the caller falls through to its router exactly as before.
 *
 * ## What is deliberately NOT here: `/media/{id}/stream`
 *
 * The third pre-router fast path, `HttpHandler::serveMediaStream()`, is left
 * where it is on purpose — this is a deliberate non-uniformity, not an oversight:
 *
 *  1. Whether direct-play should be relayable AT ALL is an open product question
 *     owned by S164 (the inline-browse design has playback PRIMARY = a direct
 *     signed URL with the relay only as a fallback). Moving it here would decide
 *     that question silently, and would start carrying whole video files over the
 *     hub tunnel.
 *  2. It is not a peer of these two: it carries `Range`/206 handling, a
 *     concurrent-stream limit and the parental {@see \Phlix\Media\RatingGate},
 *     none of which these image endpoints have.
 *
 * When S164 decides, the move is: translate `serveMediaStream()` into a
 * `?Response`-returning method here and add one line to {@see dispatch()}. Note
 * that `RelayRequestDispatcherTest::allowedPaths()` currently asserts
 * `/media/{id}/stream` reaches the application router over the relay, so that
 * expectation moves with it.
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

    /**
     * @param ArtworkStorage $artworkStorage Poster/variant store on local disk.
     * @param AvatarStorage  $avatarStorage  User avatar store on local disk.
     */
    public function __construct(
        private readonly ArtworkStorage $artworkStorage,
        private readonly AvatarStorage $avatarStorage,
    ) {
    }

    /**
     * Serve `$request` if it is one of the pre-router image endpoints.
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
            ?? $this->serveArtwork($request);
    }

    /**
     * Whether `$request` is one of these endpoints AT ALL — decided from the same
     * two patterns {@see dispatch()} uses, so the two can never disagree.
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
     * @param Request $request The request to classify.
     *
     * @since 0.10.0
     */
    public static function couldHandle(Request $request): bool
    {
        if ($request->method !== 'GET') {
            return false;
        }

        return preg_match(self::AVATAR_PATTERN, $request->path) === 1
            || preg_match(self::ARTWORK_PATTERN, $request->path) === 1;
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
}
