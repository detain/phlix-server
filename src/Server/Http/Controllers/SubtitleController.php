<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Subtitles\EmbeddedSubtitleCache;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Subtitles\AssWebVttCleaner;
use Phlix\Media\Transcoding\Subtitles\SubtitleExtractor;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * On-demand subtitle tracks for a media item.
 *
 * A direct-play client (which streams the original container rather than the
 * HLS transcode) has no `.vtt` sidecar to read, so these endpoints list the
 * item's embedded TEXT subtitle tracks and extract one to WebVTT via ffmpeg
 * (transcoding ASS/SRT/mov_text → WebVTT, then stripping ASS markup with
 * {@see AssWebVttCleaner}). Bitmap subtitles (PGS/VobSub) have no text and are
 * not listed.
 *
 * The extraction is PERSISTED in the {@see EmbeddedSubtitleCache} (keyed by
 * item id + track ordinal + source fingerprint), so a player that re-fetches
 * the same `<track>` src (seek, reload, per-track-mode toggling) is served a
 * static read instead of another cold ffmpeg transcode — the same contract the
 * downloaded-remote path already had via SubtitleStorage (minors M-SERVER-54).
 */
class SubtitleController
{
    public function __construct(
        private ItemRepository $itemRepository,
        private FfmpegRunner $ffmpeg,
        private SubtitleExtractor $extractor,
        private EmbeddedSubtitleCache $cache,
    ) {
    }

    /**
     * GET /api/v1/media/{id}/subtitles — list the item's text subtitle tracks.
     *
     * @param array<string, string> $params
     */
    public function listTracks(Request $request, array $params): Response
    {
        $path = $this->itemPath($params['id'] ?? '');
        if ($path === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }
        if ($path === '' || !is_file($path)) {
            return (new Response())->json(['tracks' => []]);
        }

        $probe = $this->ffmpeg->probe($path);
        $tracks = is_array($probe) ? $this->extractor->detectTextTracks($probe) : [];

        return (new Response())->json(['tracks' => $tracks]);
    }

    /**
     * GET /api/v1/media/{id}/subtitles/{index} — the track extracted as WebVTT.
     *
     * Cache-first: a persisted extraction for this exact (item, index, source
     * fingerprint) triple is served as a static read; only a miss runs ffmpeg,
     * and a successful extraction is persisted best-effort for the next call.
     * Failed extractions are deliberately NOT cached — a transient ffmpeg
     * failure must not ossify into a permanent 404 for the track.
     *
     * @param array<string, string> $params
     */
    public function getTrack(Request $request, array $params): Response
    {
        $itemId = $params['id'] ?? '';
        $path = $this->itemPath($itemId);
        if ($path === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $indexRaw = $params['index'] ?? '0';
        if ($path === '' || !is_file($path) || !ctype_digit($indexRaw)) {
            return (new Response())->status(404)->json(['error' => 'Subtitle not found']);
        }
        $index = (int) $indexRaw;

        $cachePath = $this->cachePathFor($itemId, $path, $index);
        if ($cachePath !== null) {
            $cached = $this->cache->read($cachePath);
            if ($cached !== null && trim($cached) !== '') {
                return $this->vttResponse($cached);
            }
        }

        $vtt = $this->extractVtt($path, $index);
        if ($vtt === '') {
            return (new Response())->status(404)->json(['error' => 'Subtitle not available']);
        }

        if ($cachePath !== null) {
            $this->cache->write($cachePath, $vtt);
        }

        return $this->vttResponse($vtt);
    }

    /**
     * The WebVTT reply shape, single-sourced between cache hit and fresh
     * extraction so the two paths cannot drift.
     */
    private function vttResponse(string $vtt): Response
    {
        return (new Response())
            ->header('Content-Type', 'text/vtt; charset=utf-8')
            ->body($vtt);
    }

    /**
     * The persisted-cache path for this track, or null when the source cannot
     * be fingerprinted (stat failure → serve uncached rather than risk keying
     * on a wrong generation).
     *
     * Resident workers outlive file changes, so the stat cache is cleared for
     * THIS path first: a replaced media file must fingerprint fresh (a global
     * clearstatcache() would discard unrelated pending entries for nothing).
     */
    private function cachePathFor(string $itemId, string $path, int $index): ?string
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        $size = @filesize($path);
        if ($mtime === false || $size === false) {
            return null;
        }

        return $this->cache->pathFor($itemId, $index, $mtime, $size);
    }

    /**
     * Resolve an item id to its on-disk path, or null when the item is unknown.
     */
    private function itemPath(string $id): ?string
    {
        $item = $id === '' ? null : $this->itemRepository->findById($id);
        if (!is_array($item)) {
            return null;
        }

        return is_string($item['path'] ?? null) ? $item['path'] : '';
    }

    /**
     * Extract one subtitle track to WebVTT and return the cleaned text, or '' on
     * failure. The temp file here is only the raw ffmpeg scratch (always
     * removed); persistence of the CLEANED text is the cache's job, reached
     * from {@see getTrack()} — not from here.
     */
    private function extractVtt(string $path, int $index): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phlix-sub-');
        if ($tmp === false) {
            return '';
        }

        $vtt = '';
        try {
            if ($this->ffmpeg->extractSubtitleVtt($path, $tmp, $index)) {
                $raw = @file_get_contents($tmp);
                if (is_string($raw) && trim($raw) !== '') {
                    $vtt = AssWebVttCleaner::clean($raw);
                }
            }
        } finally {
            @unlink($tmp);
        }

        return $vtt;
    }
}
