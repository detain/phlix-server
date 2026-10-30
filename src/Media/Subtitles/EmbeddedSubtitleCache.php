<?php

/**
 * Phlix media server component: Subtitles.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Subtitles;

/**
 * Persisted WebVTT cache for EMBEDDED subtitle tracks (direct-play extraction).
 *
 * The on-demand endpoint ({@see \Phlix\Server\Http\Controllers\SubtitleController})
 * converts one embedded TEXT track to WebVTT with ffmpeg. Downloaded remote
 * subtitles are already persisted (SubtitleStorage normalises to `.vtt` on
 * store so serving is a trivial static read); the embedded path used to re-run
 * the extraction on EVERY request — a player that re-fetches `<track>` srcs
 * (seek, reload, Safari's per-track-mode toggling) paid a cold ffmpeg
 * transcode of the same unchanged track every time (minors M-SERVER-54(a)).
 *
 * Layout mirrors {@see SubtitleStorage}'s per-item-directory idiom under its
 * own `embedded/` root so the two namespaces never intermingle:
 *
 *     <base>/<itemId>/embedded-<index>-<mtime>-<size>.vtt
 *
 * The source-file fingerprint (mtime + size, supplied by the caller) is part
 * of the FILENAME, which is the invalidation idiom: replace or re-download the
 * media file and the fingerprint changes, the next request misses cleanly and
 * re-extracts, and the stale entry simply orphans (SubtitleStorage documents
 * the same orphan-tolerant posture — sweeping is not a request-path duty).
 *
 * The base dir is INJECTED (never hard-wired) so tests point it at a temp
 * directory and never touch `/var`.
 *
 * @package Phlix\Media\Subtitles
 */
final class EmbeddedSubtitleCache
{
    /**
     * Absolute base directory, normalised WITHOUT a trailing slash.
     */
    private readonly string $baseDir;

    /**
     * @param string $baseDir Root directory for cached embedded VTT files.
     *        Defaults to `<subtitle storage root>/embedded`; wired as
     *        `subtitles.storage_path` + `/embedded` by the container, injected
     *        as a temp dir in tests.
     */
    public function __construct(string $baseDir = '/var/subtitles/embedded')
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * The configured base directory (no trailing slash).
     */
    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * The cache-file path for one (item, track, source-fingerprint) triple.
     *
     * Pure path computation — nothing is created or read. The fingerprint is
     * deliberately part of the name (see class header) rather than stored
     * beside the content, so a changed source can never alias an old entry.
     *
     * @param string $itemId      Media item UUID (a path component — sanitised).
     * @param int    $index       Per-type subtitle ordinal (0:s:{index}).
     * @param int    $sourceMtime Source file mtime (unix seconds).
     * @param int    $sourceSize  Source file size (bytes).
     */
    public function pathFor(string $itemId, int $index, int $sourceMtime, int $sourceSize): string
    {
        $name = sprintf('embedded-%d-%d-%d.vtt', $index, $sourceMtime, $sourceSize);

        return $this->baseDir . DIRECTORY_SEPARATOR . $this->safeComponent($itemId, 'item')
            . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Read a cached VTT's content, or null when absent / unreadable.
     *
     * SECURITY (path traversal): like {@see SubtitleStorage::read()}, the
     * resolved real path MUST live under the base directory — a path that
     * escapes (or predates a base-dir change) is refused, never served.
     *
     * @param string $path Absolute path previously returned by {@see pathFor()}.
     */
    public function read(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        $baseReal = realpath($this->baseDir);
        if ($baseReal === false || !str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $content = @file_get_contents($real);

        return $content === false ? null : $content;
    }

    /**
     * Persist one extracted VTT, returning whether it stuck.
     *
     * Best-effort BY DESIGN: the caller already holds the extracted content in
     * memory and serves it this request; a cache-write failure (disk full,
     * read-only mount) must not turn a playable subtitle into a 500. The next
     * request then re-extracts — which is exactly the pre-cache behavior.
     *
     * The write is atomic in the house idiom (temp file in the destination
     * directory + rename) so a concurrent reader can never observe a partially
     * written VTT. Re-writing the same path (two racing cold extractions of
     * the same track) is content-identical and benign.
     *
     * @param string $path    Absolute destination from {@see pathFor()}.
     * @param string $content The cleaned WebVTT text.
     */
    public function write(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $dir . DIRECTORY_SEPARATOR . basename($path) . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Reduce a caller-supplied string to a safe single path component.
     *
     * Strips directory separators and anything outside `[A-Za-z0-9._-]`, so an
     * item id can never introduce a traversal segment. Falls back to
     * `$fallback` when nothing usable remains. (Mirrors
     * {@see SubtitleStorage}'s private helper byte-for-byte in behavior; the
     * classes keep independent storage namespaces.)
     *
     * @param string $value    Raw value.
     * @param string $fallback Component to use when $value sanitises to empty.
     */
    private function safeComponent(string $value, string $fallback): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', basename($value));

        // Reject empty, and the traversal components `.`/`..` which survive the
        // allowlist (dots are permitted for extensions) — defense-in-depth so a
        // single component can never be a relative-path segment.
        if (!is_string($clean) || $clean === '' || $clean === '.' || $clean === '..') {
            return $fallback;
        }

        return $clean;
    }
}
