<?php

/**
 * Phlix media server component: Trickplay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming\Trickplay;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Trickplay Controller — HTTP handler for serving trickplay artefacts.
 *
 * Serves the three files the background media-asset worker writes per item:
 * `sprite.jpg` and `timeline.json` (the web player's scrubber preview) and
 * `thumbs.bif` (the Roku trick-mode archive).
 *
 * Every artefact is streamed from disk with {@see Response::withFile()}, so a
 * multi-hundred-KB archive is never buffered into worker memory. Each response
 * carries an `ETag`, a hash of the file's inode, size, mtime and ctime (see
 * {@see self::etagFor()}), and a `Last-Modified`, and a request whose `If-None-Match` or
 * `If-Modified-Since` already matches is answered `304` with no body. The
 * cache lifetime is unchanged.
 *
 * S275 removed `getIndex()`/`getThumbnail()` and their routes. They served
 * `index.xml` and `bif_NN.jpg`, which only the deleted `TrickplayGenerator`
 * could ever have produced — proven at runtime never to have run — so they were
 * live routes over files nothing writes. Real BIF data is now `thumbs.bif`.
 *
 * @since 0.11.0
 */
class TrickplayController
{
    /**
     * File name of the Roku BIF archive within a job's trickplay directory.
     *
     * Aliased from {@see BifWriter::FILENAME} rather than re-spelled, so the
     * name the producer writes and the name this class serves cannot drift.
     */
    public const BIF_FILENAME = BifWriter::FILENAME;

    /**
     * Cache policy for every trickplay artefact. The lifetime is what it has
     * always been; the validators make revalidation after it cheap.
     */
    public const CACHE_CONTROL = 'public, max-age=86400';

    /** @var string Base directory for trickplay files */
    private string $trickplayDir;

    /** @var string Base URL for trickplay endpoints */
    private string $baseUrl;

    /**
     * Each configured root's canonical path, keyed by the configured spelling.
     *
     * Application::getTrickplayController() builds a new controller on every call, so the
     * root's realpath() would otherwise run once per request. The map holds one entry per
     * distinct configured root (one, in practice), so it is bounded by configuration and
     * not by traffic. An entry is added only after a successful realpath().
     *
     * @var array<string, string>
     */
    private static array $resolvedRoots = [];

    /**
     * Creates a new TrickplayController instance.
     *
     * The root is canonicalised with realpath(), because artefact paths are resolved with
     * realpath() and checked against it. A configured root that is a symlink would
     * otherwise fail every containment check. The result is memoised per process, keyed
     * by the configured spelling, so a request does not repeat the syscall.
     *
     * A root that does not exist yet keeps its configured spelling and is resolved again
     * at the next construction, until it exists. Once a root has resolved, a later change
     * to what its symlink points at is not seen until the process restarts. Artefacts under
     * the new target then fail the containment check and are served as 404 (fail closed).
     *
     * @param string $trickplayDir Base directory for trickplay files
     * @param string $baseUrl Base URL for trickplay endpoints
     */
    public function __construct(string $trickplayDir, string $baseUrl)
    {
        $this->trickplayDir = self::resolveRoot(rtrim($trickplayDir, '/'));
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Returns the canonical form of a configured trickplay root, memoised per process.
     *
     * @param string $configured The configured root, without a trailing slash.
     *
     * @return string The realpath, or the configured spelling when the root does not exist yet.
     */
    private static function resolveRoot(string $configured): string
    {
        if (isset(self::$resolvedRoots[$configured])) {
            return self::$resolvedRoots[$configured];
        }

        $real = realpath($configured);
        if ($real === false) {
            return $configured;
        }

        $resolved = rtrim($real, '/');
        self::$resolvedRoots[$configured] = $resolved;

        return $resolved;
    }

    /**
     * Validates that a job ID contains only safe characters and no path traversal.
     *
     * Job IDs are UUIDs formatted as xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx with
     * only hexadecimal characters and hyphens.
     *
     * @param string $jobId The job ID to validate
     *
     * @return bool True if the job ID is valid, false otherwise
     */
    private function isValidJobId(string $jobId): bool
    {
        // Reject empty or overly long job IDs
        if ($jobId === '' || strlen($jobId) > 64) {
            return false;
        }
        // Reject any path traversal attempts
        if (str_contains($jobId, '..') || str_contains($jobId, '/') || str_contains($jobId, '\\')) {
            return false;
        }
        // Job IDs should only contain alphanumeric characters and hyphens (UUID format)
        return preg_match('/^[a-zA-Z0-9\-]+$/', $jobId) === 1;
    }

    /**
     * Returns the URL for the Roku BIF archive.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string URL path to the `.bif` archive
     *
     * @since 0.103.0
     */
    public function getBifUrl(string $jobId): string
    {
        return $this->baseUrl . '/trickplay/' . $jobId . '/' . self::BIF_FILENAME;
    }

    /**
     * Absolute path of a job's BIF archive, whether or not it exists.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string Filesystem path to the `.bif` archive
     *
     * @since 0.103.0
     */
    public function getBifPath(string $jobId): string
    {
        return $this->getJobDir($jobId) . '/' . self::BIF_FILENAME;
    }

    /**
     * Whether a job's BIF archive is actually on disk.
     *
     * Callers advertise `trickplay_bif_url` on the strength of this and nothing
     * else — a URL offered for a file that is not there is a 404 the client has
     * been told to expect.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return bool True if the `.bif` exists and is non-empty
     *
     * @since 0.103.0
     */
    public function hasBif(string $jobId): bool
    {
        if (!$this->isValidJobId($jobId)) {
            return false;
        }

        // Same containment check as the handler, so a URL is advertised only for an
        // archive the handler will actually serve.
        $path = $this->resolveBifPath($jobId);

        return $path !== null && filesize($path) > 0;
    }

    /**
     * Returns the URL for the trickplay sprite sheet image.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string URL path to the sprite image
     */
    public function getSpriteUrl(string $jobId): string
    {
        return $this->baseUrl . '/trickplay/' . $jobId . '/' . FfmpegRunner::SPRITE_FILENAME;
    }

    /**
     * Returns the URL for the trickplay timeline JSON.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string URL path to the timeline JSON
     */
    public function getTimelineUrl(string $jobId): string
    {
        return $this->baseUrl . '/trickplay/' . $jobId . '/' . FfmpegRunner::TIMELINE_FILENAME;
    }

    /**
     * Resolves a job's BIF archive to a file that can be served.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string|null Real path of the archive, or null when the job ID is
     *         unsafe, the file is absent, or it resolves outside the trickplay directory
     */
    public function resolveBifPath(string $jobId): ?string
    {
        return $this->resolveArtefact($jobId, self::BIF_FILENAME);
    }

    /**
     * Resolves a job's sprite sheet to a file that can be served.
     *
     * Prefers `sprite.jpg` and falls back to a PNG sheet of the same name.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string|null Real path of the sprite, or null when none can be served
     */
    public function resolveSpritePath(string $jobId): ?string
    {
        return $this->resolveArtefact($jobId, FfmpegRunner::SPRITE_FILENAME)
            ?? $this->resolveArtefact($jobId, 'sprite.png');
    }

    /**
     * Resolves a job's timeline JSON to a file that can be served.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string|null Real path of the timeline, or null when it cannot be served
     */
    public function resolveTimelinePath(string $jobId): ?string
    {
        return $this->resolveArtefact($jobId, FfmpegRunner::TIMELINE_FILENAME);
    }

    /**
     * Gets the trickplay directory path for a job.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string Full path to the job's trickplay directory
     */
    public function getJobDir(string $jobId): string
    {
        return $this->trickplayDir . '/trickplay/' . $jobId;
    }

    /**
     * HTTP handler for getting the Roku BIF archive.
     *
     * GET /trickplay/{jobId}/thumbs.bif
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (jobId)
     *
     * @return Response HTTP response streaming the archive, or a 304 / 404
     *
     * @since 0.103.0
     */
    public function getBif(Request $request, array $params): Response
    {
        $path = $this->resolveBifPath($params['jobId'] ?? '');
        if ($path === null) {
            return $this->notFound('Preview data not found');
        }

        return $this->serveArtefact($request, $path, 'application/octet-stream', 'Preview data not found');
    }

    /**
     * HTTP handler for getting the trickplay sprite sheet image.
     *
     * GET /trickplay/{jobId}/sprite.jpg
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (jobId)
     *
     * @return Response HTTP response streaming the image, or a 304 / 404
     *
     * @since 0.11.0
     */
    public function getSprite(Request $request, array $params): Response
    {
        $path = $this->resolveSpritePath($params['jobId'] ?? '');
        if ($path === null) {
            return $this->notFound('Preview image not found');
        }

        // The content type follows the file actually served, not whichever
        // sibling happens to exist.
        $contentType = basename($path) === FfmpegRunner::SPRITE_FILENAME ? 'image/jpeg' : 'image/png';

        return $this->serveArtefact($request, $path, $contentType, 'Preview image not found');
    }

    /**
     * HTTP handler for getting the trickplay timeline JSON.
     *
     * GET /trickplay/{jobId}/timeline.json
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (jobId)
     *
     * @return Response HTTP response streaming the JSON, or a 304 / 404
     *
     * @since 0.11.0
     */
    public function getTimeline(Request $request, array $params): Response
    {
        $path = $this->resolveTimelinePath($params['jobId'] ?? '');
        if ($path === null) {
            return $this->notFound('Preview timeline not found');
        }

        return $this->serveArtefact($request, $path, 'application/json', 'Preview timeline not found');
    }

    /**
     * Resolves one named artefact inside a job's trickplay directory.
     *
     * Validates the job ID, then resolves the real path and verifies it still
     * sits inside the trickplay directory (defense-in-depth against traversal
     * through a symlink).
     *
     * @param string $jobId    Transcode job identifier
     * @param string $fileName Artefact file name inside the job directory
     *
     * @return string|null Real path, or null when the artefact cannot be served
     */
    private function resolveArtefact(string $jobId, string $fileName): ?string
    {
        if (!$this->isValidJobId($jobId)) {
            return null;
        }

        // The trailing separator is part of the check. Without it a real path inside
        // "<root>-sibling" string-starts with "<root>" and passes, so a job directory
        // linked to a sibling of the trickplay root would be served.
        $realPath = realpath($this->getJobDir($jobId) . '/' . $fileName);
        // Split guards (not one disjunction): psalm 6.5 cannot narrow through '||', so the
        // ternary below still saw false in its subject until this reshape — is_string()
        // keeps the value set identical to '=== false' for realpath()'s string|false domain
        // (E1 SRV-CURE 2026-11-20).
        if (!is_string($realPath)) {
            return null;
        }

        if (!str_starts_with($realPath, $this->trickplayDir . '/')) {
            return null;
        }

        return is_file($realPath) ? $realPath : null;
    }

    /**
     * Serves a file with validators and conditional-GET handling.
     *
     * The validators are derived from one `stat()`: the ETag hashes the inode, size,
     * mtime and ctime (see {@see self::etagFor()}) and Last-Modified is the mtime. A request whose conditional
     * headers already match is answered `304` carrying the validators and no
     * body. Otherwise the file is streamed with {@see Response::withFile()}.
     *
     * Residual race, not closed here. {@see Response::withFile()} takes a path, and Workerman
     * opens that path again when the response is sent: filesize() for Content-Length, then
     * file_get_contents() for a body under 2 MiB or fopen() above that. The body is therefore
     * not read from the inode that was stat'd. A publish between the `stat()` and those calls
     * can make the response disagree with its validator:
     * - Failure A: the final name is absent at filesize(), so the client gets `200`,
     *   Content-Length 0, an empty body and the new ETag. A publish never removes a final that
     *   has a previous copy (see FfmpegRunner::publishTrickplayFiles()). What remains is a
     *   rollback of an artefact with no previous copy, which removes the new file.
     * - Failure B: the file is replaced by one of a different size between filesize() and the
     *   read, so the body is a prefix of the new file, or does not match Content-Length, under
     *   the old ETag.
     * - Failure C: a rollback renames the previous copy back after the stat, so the body is
     *   the old generation under the new ETag.
     * Closing these needs the response to stream from the handle that was stat'd. withFile()
     * takes a path and Workerman's send path re-opens it, so that is a larger change to the
     * response path, not made here. The window is bounded by the browser's max-age of 24 hours
     * (86400 seconds, {@see self::CACHE_CONTROL}): a client that caches a mismatched pair, such
     * as the empty `200` of Failure A under the new ETag, does not revalidate until the entry
     * expires, and then its conditional request computes the current validator and gets a fresh
     * `200`.
     *
     * @param Request $request         The incoming request, for its conditional headers.
     * @param string  $path            Real path of the file to serve.
     * @param string  $contentType     Content type for a 200.
     * @param string  $notFoundMessage Message for the 404 if the file vanished between resolve and stat.
     *
     * @return Response A streamed 200, a 304, or a 404.
     */
    private function serveArtefact(
        Request $request,
        string $path,
        string $contentType,
        string $notFoundMessage
    ): Response {
        // The producer rewrites these files in place, and PHP's stat cache would
        // otherwise hand back the previous size/mtime for a resident worker, which
        // would mint a stale ETag that 304s a client against the old bytes.
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) {
            return $this->notFound($notFoundMessage);
        }

        $mtime = (int) $stat['mtime'];
        $etag = self::etagFor((int) $stat['ino'], (int) $stat['size'], $mtime, (int) $stat['ctime']);
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        if ($this->isNotModified($request, $etag, $mtime)) {
            return (new Response())
                ->status(304)
                ->header('Cache-Control', self::CACHE_CONTROL)
                ->header('ETag', $etag)
                ->header('Last-Modified', $lastModified);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', self::CACHE_CONTROL)
            ->header('ETag', $etag)
            ->header('Last-Modified', $lastModified)
            ->withFile($path);
    }

    /**
     * Strong validator for an artefact: a hash of its inode, size, mtime and ctime.
     *
     * mtime and ctime have one-second resolution, so a same-size rewrite within the
     * same second keeps both. The producers publish by renaming a verified temporary
     * over the final name, which gives the file a new inode, and that is what changes
     * the tag.
     *
     * @param int $inode Inode number.
     * @param int $size  File size, bytes.
     * @param int $mtime Last-modified time, seconds.
     * @param int $ctime Inode change time, seconds.
     */
    private static function etagFor(int $inode, int $size, int $mtime, int $ctime): string
    {
        return '"' . md5($inode . ':' . $size . ':' . $mtime . ':' . $ctime) . '"';
    }

    /**
     * Whether a conditional GET is satisfied by the current validators.
     *
     * `If-None-Match` is authoritative when present (RFC 9110 §13.1.2): it is
     * matched with the weak comparison GET uses, against any listed tag or `*`,
     * and `If-Modified-Since` is ignored. `If-Modified-Since` is consulted only
     * when no `If-None-Match` was sent.
     *
     * @param Request $request The incoming request.
     * @param string  $etag    The current ETag, quoted.
     * @param int     $mtime   The file's mtime, in Unix seconds.
     *
     * @return bool True when the client's copy is current and a 304 should be sent.
     */
    private function isNotModified(Request $request, string $etag, int $mtime): bool
    {
        $ifNoneMatch = $request->getHeader('If-None-Match');
        if ($ifNoneMatch !== null && trim($ifNoneMatch) !== '') {
            $current = self::opaqueTag($etag);
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || self::opaqueTag($candidate) === $current) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = $request->getHeader('If-Modified-Since');
        if ($ifModifiedSince === null || trim($ifModifiedSince) === '') {
            return false;
        }

        $since = strtotime($ifModifiedSince);

        return $since !== false && $since >= $mtime;
    }

    /**
     * Strips a weak-validator prefix, so `W/"x"` and `"x"` compare equal.
     */
    private static function opaqueTag(string $tag): string
    {
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }

    /**
     * A 404 with the JSON envelope every trickplay route has always returned.
     */
    private function notFound(string $message): Response
    {
        return (new Response())
            ->status(404)
            ->json([
                'error' => 'Not Found',
                'message' => $message,
            ]);
    }
}
