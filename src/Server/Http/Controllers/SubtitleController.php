<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
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
 * item's embedded TEXT subtitle tracks and extract one to WebVTT on request via
 * ffmpeg (transcoding ASS/SRT/mov_text → WebVTT, then stripping ASS markup with
 * {@see AssWebVttCleaner}). Bitmap subtitles (PGS/VobSub) have no text and are
 * not listed.
 */
class SubtitleController
{
    public function __construct(
        private ItemRepository $itemRepository,
        private FfmpegRunner $ffmpeg,
        private SubtitleExtractor $extractor,
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
     * @param array<string, string> $params
     */
    public function getTrack(Request $request, array $params): Response
    {
        $path = $this->itemPath($params['id'] ?? '');
        if ($path === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $indexRaw = $params['index'] ?? '0';
        if ($path === '' || !is_file($path) || !ctype_digit($indexRaw)) {
            return (new Response())->status(404)->json(['error' => 'Subtitle not found']);
        }
        $index = (int) $indexRaw;

        $vtt = $this->extractVtt($path, $index);
        if ($vtt === '') {
            return (new Response())->status(404)->json(['error' => 'Subtitle not available']);
        }

        return (new Response())
            ->header('Content-Type', 'text/vtt; charset=utf-8')
            ->body($vtt);
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
     * failure. The extraction goes through a temp file that is always removed.
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
