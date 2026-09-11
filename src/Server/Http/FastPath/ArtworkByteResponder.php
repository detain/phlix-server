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
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

use function gmdate;
use function is_string;
use function json_encode;
use function sprintf;
use function stat;
use function strtotime;

/**
 * The one byte-serving implementation every locally-cached image endpoint shares (S73).
 *
 * Extracted VERBATIM from the inline body of
 * {@see \Phlix\Server\Http\FastPath\PreRouterFastPaths::serveArtwork()} so the new
 * `GET /api/v1/people/{personId}/photo` route serves through the SAME conditional-GET
 * machinery rather than a second copy of it. Two implementations of a cache
 * contract drift; one cannot. The behaviour pinned by the 22 artwork caching
 * tests is unchanged by construction:
 *
 * - ETag is the existing `"<size-hex>-<mtime-hex>"` tag; Last-Modified derives
 *   from the same stat so both stay consistent.
 * - If-None-Match is authoritative; If-Modified-Since is the fallback only when
 *   no ETag was sent.
 * - 304 carries the validators and NO body; 200 attaches the file via
 *   {@see Response::withFile()} (event-loop sendfile, never buffered).
 * - `Cache-Control: public, max-age=31536000, immutable` on both, because the
 *   variant file's identity is (key, size) and it is only ever rewritten whole.
 *
 * @package Phlix\Server\Http\FastPath
 */
final class ArtworkByteResponder
{
    /** Non-exhaustive: every entry point is static and stateless. */
    private function __construct()
    {
    }

    /**
     * The flat 404 JSON a variant miss yields (the pre-S73 shape, kept byte-identical).
     */
    public static function notFound(): Response
    {
        return (new Response())
            ->status(404)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode(['error' => 'Artwork not found']) ?: '{"error":"Artwork not found"}');
    }

    /**
     * The flat 400 JSON a malformed size parameter yields (checked BEFORE auth
     * and BEFORE any storage lookup — the landmine ordering this endpoint family
     * documents: an unknown size must never reach a filesystem path build).
     */
    public static function invalidSize(): Response
    {
        return (new Response())
            ->status(400)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode(['error' => 'Invalid size parameter']) ?: '{"error":"Invalid size parameter"}');
    }

    /**
     * Shared inline authorisation: resolved session OR a valid signed-URL token.
     *
     * ⚠ `$signedResource` is canonicalised by {@see SignedUrl::canonicalResource()}
     * before hashing, which strips any query string. Passing a resource WITH a
     * query is therefore harmless but not load-bearing — see the measured note in
     * {@see PreRouterFastPaths::serveArtwork()}. The PATH is what binds.
     *
     * @param Request $request        The request being authorised.
     * @param string  $signedResource The resource spelling the URL was minted
     *                                over (query, if any, is not hashed).
     *
     * @return Response|null A 401 to return immediately, or null to proceed.
     */
    public static function rejectUnlessSigned(Request $request, string $signedResource): ?Response
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
     * Conditional-GET byte-serve of an existing local variant file.
     *
     * Honours conditional requests AFTER auth, size validation and the caller's
     * existence check — freshness is only ever decided for a request that would
     * otherwise be served.
     *
     * @param Request $request     The authorised request.
     * @param string  $artworkPath Absolute path to the stored variant (caller proved it exists).
     * @param string  $size        The validated size name ('logo' selects image/png, everything else image/jpeg).
     */
    public static function serve(Request $request, string $artworkPath, string $size): Response
    {
        // Compute the validators for conditional caching (SV-2.5 pattern).
        // ETag is the existing "<size>-<mtime>" hex tag (immutable-cache is kept);
        // Last-Modified is derived from the same stat so both stay consistent.
        $stat = stat($artworkPath);
        $mtime = $stat !== false ? (int) $stat['mtime'] : 0;
        $etag = $stat !== false ? sprintf('"%x-%x"', $stat['size'], $stat['mtime']) : '';
        $lastModified = $mtime > 0 ? gmdate('D, d M Y H:i:s', $mtime) . ' GMT' : '';

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
}
