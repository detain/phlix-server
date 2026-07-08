<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Shared file-serving for the HLS and DASH controllers.
 *
 * Both protocols are produced by a single CMAF encode into one job directory and
 * reference their segments by relative filename, so serving is identical: validate
 * the requested filename (no path traversal) and reply with the right content type
 * / caching. Playlists & manifests are `no-cache` (they may be rewritten while the
 * encode runs); segments are immutable and cached hard.
 *
 * Bodies are streamed through Workerman's event loop via {@see Response::withFile()}
 * rather than buffered into worker memory with `file_get_contents()`: multi-MB
 * segments would otherwise pin a full copy of every concurrent request's segment in
 * the resident worker, driving memory and GC pressure. Streaming also gives us real
 * HTTP `Range` support (single range → 206 + `Content-Range`, unsatisfiable → 416)
 * and `Last-Modified` / conditional-GET handling for free, mirroring the direct-play
 * `serveMediaStream()` path.
 */
trait TranscodeFileServer
{
    /**
     * Serves a single file from a transcode job directory.
     *
     * The file is streamed via {@see Response::withFile()} (event-loop file sender)
     * instead of being read into memory. HTTP semantics honoured:
     *   - `Range: bytes=A-B` / `bytes=A-` / `bytes=-N` (suffix) → 206 + `Content-Range`
     *     (Workerman derives these from the offset/length window; an over-long `B` is
     *     clamped to EOF per RFC 7233 §2.1, not rejected); unsatisfiable range → 416.
     *   - `If-Modified-Since` matching the file mtime → 304 (immutable segments
     *     only; `no-cache` playlists/manifests are never short-circuited).
     *   - Otherwise 200 with the whole file. `Content-Length`, `Accept-Ranges`, and
     *     `Last-Modified` are emitted automatically by the Workerman file sender.
     *
     * @param Request $request The incoming request (for `Range` / conditional GET).
     * @param string  $dir     Absolute job directory (empty → 400).
     * @param string  $file    Requested filename within the job dir.
     *
     * @return Response The file response, or a 304/400/404/416 status.
     */
    private function serveJobFile(Request $request, string $dir, string $file): Response
    {
        if ($dir === '' || !$this->isSafeFilename($file)) {
            return (new Response())->status(400)->json(['error' => 'invalid request']);
        }

        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            return (new Response())->status(404)->json(['error' => 'Not found']);
        }

        $contentType = self::contentTypeFor($file);
        $cacheControl = self::cacheControlFor($file);
        $fileSize = (int) filesize($path);

        // Conditional GET: immutable segments carry a year-long max-age, so a
        // revalidating client/cache can be answered 304 without re-sending the body.
        // Playlists/manifests are `no-cache` (they may be rewritten during the
        // encode) and are never short-circuited to 304.
        if ($cacheControl !== 'no-cache') {
            $mtime = filemtime($path);
            if ($mtime !== false) {
                $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
                $ims = $request->getHeader('If-Modified-Since');
                if (is_string($ims) && trim($ims) === $lastModified) {
                    return (new Response())
                        ->status(304)
                        ->header('Cache-Control', $cacheControl)
                        ->header('Last-Modified', $lastModified);
                }
            }
        }

        // Single byte-range (either `bytes=A-B`/`bytes=A-` or a suffix `bytes=-N`,
        // "last N bytes") lets the player seek within a segment and lets a browser
        // probe (`Range: bytes=0-`) get a proper 206. Workerman emits Content-Range +
        // 206 from the offset/length window passed to withFile(). Multi-range or
        // malformed values fall through to a whole-file 200 — an RFC 7233-permitted
        // fallback for a range the server won't otherwise satisfy.
        $range = self::parseRange($request->getHeader('Range'), $fileSize);
        if ($range !== null) {
            if (!$range['satisfiable']) {
                return (new Response())
                    ->status(416)
                    ->header('Content-Type', $contentType)
                    ->header('Content-Range', "bytes */{$fileSize}");
            }
            return (new Response())
                ->status(206)
                ->header('Content-Type', $contentType)
                ->header('Cache-Control', $cacheControl)
                ->withFile($path, $range['start'], $range['end'] - $range['start'] + 1);
        }

        return (new Response())
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', $cacheControl)
            ->withFile($path);
    }

    /**
     * Parses an HTTP `Range` header into a `[start, end]` byte window against a
     * known file size, per RFC 7233 §2.1.
     *
     * Supports the two single-range forms a real client/cache can send:
     *   - `bytes=A-B` / `bytes=A-` (open-ended → to EOF). An over-long last-byte-pos
     *     (`B >= $fileSize`) is CLAMPED to `$fileSize - 1`, not rejected — treating it
     *     as unsatisfiable would be a spec deviation that can spuriously fail a
     *     conforming client's request (e.g. `bytes=0-999999999`).
     *   - `bytes=-N` (suffix range: "the last N bytes"). `N <= 0` or an empty file is
     *     unsatisfiable.
     *
     * A multi-range value (`bytes=0-1,4-5`) or anything else that doesn't match
     * either form returns null so the caller falls back to a whole-file 200 — a
     * valid RFC 7233 fallback for a range the server won't otherwise satisfy.
     *
     * @param string|null $rangeHeader The raw `Range` header value, or null.
     * @param int         $fileSize    The size of the file being served, in bytes.
     *
     * @return array{satisfiable: bool, start: int, end: int}|null Null when the
     *         header isn't a supported single-range form (whole-file 200 fallback);
     *         otherwise the resolved window, or `satisfiable: false` for a 416.
     */
    private static function parseRange(?string $rangeHeader, int $fileSize): ?array
    {
        if (!is_string($rangeHeader)) {
            return null;
        }

        if (preg_match('/^bytes=(\d+)-(\d*)$/', $rangeHeader, $rm) === 1) {
            $start = (int) $rm[1];
            $end = $rm[2] !== '' ? (int) $rm[2] : $fileSize - 1;
            // RFC 7233 §2.1: an over-long last-byte-pos is clamped to the actual
            // EOF and answered 206, not rejected with 416.
            if ($end >= $fileSize) {
                $end = $fileSize - 1;
            }
            $satisfiable = $fileSize > 0 && $start < $fileSize && $start <= $end;
            return ['satisfiable' => $satisfiable, 'start' => $start, 'end' => $end];
        }

        if (preg_match('/^bytes=-(\d+)$/', $rangeHeader, $rm) === 1) {
            // Suffix range: "the last N bytes" (RFC 7233 §2.1). A zero-length
            // suffix or an empty file has nothing satisfiable to serve.
            $suffixLength = (int) $rm[1];
            $satisfiable = $fileSize > 0 && $suffixLength > 0;
            $start = $satisfiable ? max(0, $fileSize - $suffixLength) : 0;
            return ['satisfiable' => $satisfiable, 'start' => $start, 'end' => $fileSize - 1];
        }

        return null;
    }

    /**
     * True when the filename is a plain segment/playlist name (no path traversal).
     *
     * The router already restricts `{file}` to a single non-slash segment; this
     * additionally forbids `..` and any character outside the known-safe set.
     */
    private function isSafeFilename(string $file): bool
    {
        if ($file === '' || str_contains($file, '..')) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9._-]+$/', $file) === 1;
    }

    /**
     * Maps a filename extension to its media content type.
     */
    private static function contentTypeFor(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($ext) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'mpd' => 'application/dash+xml',
            'm4s', 'mp4' => 'video/mp4',
            'ts' => 'video/mp2t',
            // Extracted subtitle sidecars (sub-{index}.vtt) served from the job
            // dir alongside the CMAF segments for the player's <track> elements.
            'vtt' => 'text/vtt',
            default => 'application/octet-stream',
        };
    }

    /**
     * Playlists/manifests must not be cached (they update during the encode);
     * immutable segments are cached for a year.
     */
    private static function cacheControlFor(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return ($ext === 'm3u8' || $ext === 'mpd') ? 'no-cache' : 'public, max-age=31536000';
    }
}
