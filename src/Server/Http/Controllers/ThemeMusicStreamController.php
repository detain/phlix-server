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
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Theming\ThemeMediaFinder;

/**
 * Serves the per-item theme-audio file resolved by the M3 producer.
 *
 * GET /stream/theme-media/item/{mediaItemId}
 *
 * The `metadata_json.theme_audio_url` slot populated at match time points here.
 * At request time the controller re-resolves the concrete file for the item:
 *   1. a local Emby/Kodi `theme.{mp3,ogg}` next to the item ({@see ThemeMediaFinder});
 *   2. else the cached Plex-archive theme `{cache_dir}/{tvdbId}.mp3`, keyed on the
 *      item's `metadata_json.external_ids.tvdb`.
 *
 * Files are served with the correct audio Content-Type and HTTP Range support
 * (mirroring {@see ThemeMediaStreamController}). No filesystem path is ever taken
 * from the request — only the numeric TVDB id / the item's own stored path — so
 * there is no traversal surface.
 *
 * @since 0.66.0
 */
final class ThemeMusicStreamController
{
    public function __construct(
        private readonly ItemRepository $items,
        private readonly ThemeMediaFinder $finder,
        private readonly ThemeMusicConfig $config,
    ) {
    }

    /**
     * Stream the resolved theme audio for a media item.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Path params including `mediaItemId`.
     */
    public function streamItemTheme(Request $request, array $params): Response
    {
        $itemId = $params['mediaItemId'] ?? '';
        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID is required']);
        }

        $item = $this->items->findById($itemId);
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Media item not found']);
        }

        $file = $this->resolveFile($item);
        if ($file === null) {
            return (new Response())->status(404)->json(['error' => 'Theme audio not found']);
        }

        // Read the Range from the PARSED request headers, never $_SERVER — under the
        // Workerman daemon $_SERVER is not repopulated per request, so a $_SERVER
        // read left seeking dead (every ranged request fell through to a full 200).
        return $this->streamFile($file, $this->contentTypeFor($file), $request->getHeader('Range'));
    }

    /**
     * Resolve the concrete on-disk theme file for an item: local theme first,
     * cached Plex theme second. Returns an existing readable path or null.
     *
     * @param array<string, mixed> $item Hydrated media-item row.
     */
    private function resolveFile(array $item): ?string
    {
        // (1) Local Emby/Kodi theme file next to the item.
        $path = $item['path'] ?? null;
        if (is_string($path) && $path !== '') {
            $media = $this->finder->findForMediaItem('', $path);
            if ($media !== null && $media->audio !== null && is_file($media->audio->path)) {
                return $media->audio->path;
            }
        }

        // (2) Cached Plex-archive theme by TVDB id.
        $tvdbId = $this->tvdbIdFor($item);
        if ($tvdbId !== null) {
            $cachePath = $this->config->cacheDir . '/' . $tvdbId . '.mp3';
            if (is_file($cachePath) && filesize($cachePath) !== false && filesize($cachePath) > 0) {
                return $cachePath;
            }
        }

        return null;
    }

    /**
     * Pull a positive-integer TheTVDB id from the item's
     * `metadata_json.external_ids.tvdb`, or null when absent/invalid.
     *
     * @param array<string, mixed> $item Hydrated media-item row.
     */
    private function tvdbIdFor(array $item): ?int
    {
        $meta = $item['metadata'] ?? null;
        if (!is_array($meta)) {
            return null;
        }
        $external = $meta['external_ids'] ?? null;
        if (!is_array($external)) {
            return null;
        }
        $value = $external['tvdb'] ?? null;
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }
        return null;
    }

    /** MIME type for a theme file, keyed on its extension. */
    private function contentTypeFor(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'mp4', 'm4a' => 'audio/mp4',
            default => 'application/octet-stream',
        };
    }

    /**
     * Serve a file with Content-Type + HTTP Range support.
     *
     * @param ?string $rangeHeader The request's Range header value ({@see Request::getHeader()}),
     *                             or null when absent. Passed in rather than read from
     *                             $_SERVER so it works under the Workerman daemon.
     */
    private function streamFile(string $filePath, string $contentType, ?string $rangeHeader): Response
    {
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            return (new Response())->status(500)->json(['error' => 'Could not determine file size']);
        }

        if ($rangeHeader !== null && $rangeHeader !== '') {
            return $this->handleRangeRequest($filePath, $fileSize, $contentType, $rangeHeader);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return (new Response())->status(500)->json(['error' => 'Could not read file']);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', $contentType)
            ->header('Content-Length', (string) $fileSize)
            ->header('Accept-Ranges', 'bytes')
            ->header('Cache-Control', 'public, max-age=86400')
            ->body($content);
    }

    /**
     * Serve a byte range (HTTP 206) for seeking.
     *
     * Accepts `bytes=start-end`, open-ended `bytes=start-`, and suffix `bytes=-N`
     * (the last N bytes). An end that runs past EOF is clamped rather than rejected
     * (RFC 7233); a malformed or unsatisfiable range yields 416 with
     * `Content-Range: bytes * /{size}`. $fileSize is passed in so filesize() is
     * only stat'd once by the caller.
     */
    private function handleRangeRequest(
        string $filePath,
        int $fileSize,
        string $contentType,
        string $rangeHeader
    ): Response {
        if ($fileSize <= 0 || preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches) !== 1) {
            return (new Response())
                ->status(416)
                ->header('Content-Range', "bytes */{$fileSize}");
        }

        $startRaw = $matches[1];
        $endRaw   = $matches[2];

        if ($startRaw === '') {
            // Suffix range "bytes=-N": the last N bytes (N >= file size => whole file).
            $suffix = (int) $endRaw;
            if ($suffix <= 0) {
                return (new Response())
                    ->status(416)
                    ->header('Content-Range', "bytes */{$fileSize}");
            }
            $start = max(0, $fileSize - $suffix);
            $end   = $fileSize - 1;
        } else {
            $start = (int) $startRaw;
            $end   = ($endRaw !== '') ? (int) $endRaw : $fileSize - 1;
            // Clamp an end that runs past EOF instead of rejecting the request.
            if ($end > $fileSize - 1) {
                $end = $fileSize - 1;
            }
        }

        if ($start > $end || $start >= $fileSize) {
            return (new Response())
                ->status(416)
                ->header('Content-Range', "bytes */{$fileSize}");
        }

        /** @var int<1, max> $length */
        $length = $end - $start + 1;

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return (new Response())->status(500)->json(['error' => 'Could not open file']);
        }
        fseek($handle, $start);
        $content = fread($handle, $length);
        fclose($handle);

        if ($content === false) {
            return (new Response())->status(500)->json(['error' => 'Could not read file range']);
        }

        return (new Response())
            ->status(206)
            ->header('Content-Type', $contentType)
            ->header('Content-Length', (string) $length)
            ->header('Content-Range', "bytes {$start}-{$end}/{$fileSize}")
            ->header('Accept-Ranges', 'bytes')
            ->body($content);
    }
}
