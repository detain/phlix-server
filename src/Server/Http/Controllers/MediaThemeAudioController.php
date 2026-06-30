<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Streams series theme audio files with proper Range support.
 *
 * Serves `GET /api/v1/media/{id}/theme-audio`:
 * - Auth-gated via SignedUrlMiddleware (session cookie OR signed-URL token)
 * - Looks up `metadata_json.theme_audio_url` on the series item
 * - Anti-SSRF: path must resolve to under /var/www/phlix/ or be a var/ relative path
 * - Range-aware via WorkermanResponse::withFile()
 *
 * @since 0.55.0
 */
class MediaThemeAudioController
{
    private const DOC_ROOT = '/var/www/phlix';

    public function __construct(
        private readonly ItemRepository $itemRepository
    ) {
    }

    /**
     * Stream theme audio for a media item.
     *
     * GET /api/v1/media/{id}/theme-audio
     *
     * @param Request             $request The incoming request
     * @param array<string,string> $params  Path params, expects 'id'
     *
     * @return WorkermanResponse Binary audio stream with Range support
     */
    public function streamThemeAudio(Request $request, array $params): WorkermanResponse
    {
        $itemId = $params['id'] ?? '';

        // Guard: missing id → 400
        if ($itemId === '') {
            return $this->error(400, 'Media item ID is required');
        }

        // Look up the item
        $item = $this->itemRepository->findById($itemId);

        // Guard: item not found → 404
        if ($item === null) {
            return $this->error(404, 'Media item not found');
        }

        // Extract theme_audio_url from the hydrated metadata array.
        // ItemRepository::hydrateItem() decodes metadata_json into metadata.
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $themeAudioUrl = is_string($metadata['theme_audio_url'] ?? null) && $metadata['theme_audio_url'] !== ''
            ? $metadata['theme_audio_url']
            : null;

        // Guard: no theme audio → 404
        if ($themeAudioUrl === null) {
            return $this->error(404, 'Theme audio not configured for this item');
        }

        // Anti-SSRF: resolve the path and verify it stays within the allowed root
        $resolvedPath = $this->resolvePath($themeAudioUrl);
        if ($resolvedPath === null) {
            return $this->error(400, 'Invalid theme audio path');
        }

        // Guard: file must exist and be readable
        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            return $this->error(404, 'Theme audio file not found on disk');
        }

        $fileSize = (int) filesize($resolvedPath);
        $mime = $this->audioMimeFor($resolvedPath);

        // Range request support via WorkermanResponse::withFile()
        $rangeHeader = is_string($_SERVER['HTTP_RANGE'] ?? null) ? $_SERVER['HTTP_RANGE'] : null;
        if ($rangeHeader !== null && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $rm) === 1) {
            $start = (int) $rm[1];
            $end = $rm[2] !== '' ? (int) $rm[2] : $fileSize - 1;

            if ($fileSize === 0 || $start >= $fileSize || $end >= $fileSize || $start > $end) {
                $resp = new WorkermanResponse(416, ['Content-Type' => $mime]);
                $resp->header('Content-Range', "bytes */{$fileSize}");
                return $resp;
            }

            $resp = new WorkermanResponse(206, ['Content-Type' => $mime]);
            // withFile() with offset/length makes Workerman emit 206 + Content-Range automatically
            $resp->withFile($resolvedPath, $start, $end - $start + 1);
            return $resp;
        }

        // Full file response
        $resp = new WorkermanResponse(200, ['Content-Type' => $mime]);
        $resp->withFile($resolvedPath);
        return $resp;
    }

    /**
     * Resolve a theme_audio_url to an absolute path, validating anti-SSRF constraints.
     *
     * Permitted paths:
     * - Absolute paths starting with /var/www/phlix/
     * - Relative paths starting with "var/" (resolved relative to DOC_ROOT)
     *
     * @param string $url The theme_audio_url value from metadata_json
     *
     * @return string|null Resolved absolute path, or null if the path escapes the sandbox
     */
    private function resolvePath(string $url): ?string
    {
        if (str_starts_with($url, '/var/www/phlix/')) {
            $resolved = realpath($url);
            if ($resolved !== false) {
                return str_starts_with($resolved, self::DOC_ROOT . '/') ? $resolved : null;
            }
            // Non-existent file: fall back to parent-dir check.
            // If even the parent doesn't exist, let is_file() downstream decide.
            $dir = realpath(dirname($url));
            if ($dir !== false && str_starts_with($dir, self::DOC_ROOT . '/')) {
                return $url;
            }
            // Parent doesn't exist either → reject (likely broken path / SSRF probe)
            if ($dir === false && is_dir(self::DOC_ROOT)) {
                // DOC_ROOT exists but parent doesn't — still suspicious, reject
                return null;
            }
            // DOC_ROOT doesn't exist in this environment (e.g. test machines);
            // let is_file() downstream make the final call.
            return str_starts_with($url, self::DOC_ROOT . '/') ? $url : null;
        }

        if (str_starts_with($url, 'var/')) {
            $fullPath = self::DOC_ROOT . '/' . $url;
            $resolved = realpath($fullPath);
            if ($resolved !== false) {
                return $resolved;
            }
            // Non-existent: validate via parent dir
            $dir = realpath(dirname($fullPath));
            if ($dir !== false) {
                return str_starts_with($dir, self::DOC_ROOT . '/') ? $fullPath : null;
            }
            // Parent doesn't exist; if full path starts with DOC_ROOT, accept it
            // and let is_file() downstream reject
            return str_starts_with($fullPath, self::DOC_ROOT . '/') ? $fullPath : null;
        }

        // Explicitly reject any other scheme or path pattern (absolute paths
        // outside DOC_ROOT, URLs, etc.)
        return null;
    }

    /**
     * Build an error response.
     *
     * @param int    $status HTTP status code
     * @param string $message Error message
     */
    private function error(int $status, string $message): WorkermanResponse
    {
        $body = json_encode(['error' => $message], JSON_THROW_ON_ERROR);
        return new WorkermanResponse($status, ['Content-Type' => 'application/json; charset=utf-8'], $body);
    }

    /**
     * Determine the MIME type for an audio file.
     */
    private function audioMimeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'opus' => 'audio/opus',
            default => 'application/octet-stream',
        };
    }
}
