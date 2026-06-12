<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Response;

/**
 * Shared file-serving for the HLS and DASH controllers.
 *
 * Both protocols are produced by a single CMAF encode into one job directory and
 * reference their segments by relative filename, so serving is identical: validate
 * the requested filename (no path traversal), read it from the job dir, and reply
 * with the right content type / caching. Playlists & manifests are `no-cache`
 * (they grow while the encode runs); segments are immutable and cached hard.
 */
trait TranscodeFileServer
{
    /**
     * Serves a single file from a transcode job directory.
     *
     * @param string $dir  Absolute job directory (empty → 400).
     * @param string $file Requested filename within the job dir.
     *
     * @return Response The file response, or a 400/404/500 error.
     */
    private function serveJobFile(string $dir, string $file): Response
    {
        if ($dir === '' || !$this->isSafeFilename($file)) {
            return (new Response())->status(400)->json(['error' => 'invalid request']);
        }

        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            return (new Response())->status(404)->json(['error' => 'Not found']);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to read file']);
        }

        return (new Response())
            ->header('Content-Type', self::contentTypeFor($file))
            ->header('Cache-Control', self::cacheControlFor($file))
            ->header('Content-Length', (string) strlen($content))
            ->header('Accept-Ranges', 'bytes')
            ->body($content);
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
