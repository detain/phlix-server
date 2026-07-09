<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Theming\ThemeMediaRepository;

/**
 * ThemeMediaStreamController serves theme media files for streaming.
 *
 * Provides endpoints for streaming theme audio and video files with
 * proper content types and range request support for seeking.
 *
 * @since 0.14.0
 */
class ThemeMediaStreamController
{
    /**
     * @param ThemeMediaRepository $repository Theme media repository
     *
     * @since 0.14.0
     */
    public function __construct(
        private readonly ThemeMediaRepository $repository
    ) {
    }

    /**
     * Stream theme audio for a library.
     *
     * GET /stream/theme-media/{libraryId}/audio
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters including 'libraryId'
     *
     * @return Response Binary response with audio content
     *
     * @since 0.14.0
     */
    public function streamAudio(Request $request, array $params): Response
    {
        $libraryId = $params['libraryId'] ?? '';

        if (empty($libraryId)) {
            return (new Response())->status(400)->json([
                'error' => 'Library ID is required',
            ]);
        }

        $themeMedia = $this->repository->findByLibraryId($libraryId);

        if ($themeMedia === null || $themeMedia->audio === null) {
            return (new Response())->status(404)->json([
                'error' => 'Theme audio not found',
            ]);
        }

        $audio = $themeMedia->audio;

        if (!file_exists($audio->path)) {
            return (new Response())->status(404)->json([
                'error' => 'Theme audio file not found on disk',
            ]);
        }

        return $this->streamFile(
            $audio->path,
            $this->getAudioContentType($audio->format),
            $request->getHeader('Range')
        );
    }

    /**
     * Stream theme video for a library.
     *
     * GET /stream/theme-media/{libraryId}/video
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters including 'libraryId'
     *
     * @return Response Binary response with video content
     *
     * @since 0.14.0
     */
    public function streamVideo(Request $request, array $params): Response
    {
        $libraryId = $params['libraryId'] ?? '';

        if (empty($libraryId)) {
            return (new Response())->status(400)->json([
                'error' => 'Library ID is required',
            ]);
        }

        $themeMedia = $this->repository->findByLibraryId($libraryId);

        if ($themeMedia === null || $themeMedia->video === null) {
            return (new Response())->status(404)->json([
                'error' => 'Theme video not found',
            ]);
        }

        $video = $themeMedia->video;

        if (!file_exists($video->path)) {
            return (new Response())->status(404)->json([
                'error' => 'Theme video file not found on disk',
            ]);
        }

        return $this->streamFile(
            $video->path,
            $this->getVideoContentType($video->format),
            $request->getHeader('Range')
        );
    }

    /**
     * Stream a file with content-type and HTTP Range support.
     *
     * @param string  $filePath    Absolute path to the file
     * @param string  $contentType MIME content type
     * @param ?string $rangeHeader The request's Range header value
     *                             ({@see Request::getHeader()}), or null when absent.
     *                             Passed in rather than read from $_SERVER so it works
     *                             under the Workerman daemon (which never repopulates
     *                             $_SERVER per request — a $_SERVER read left seeking
     *                             dead: every ranged request fell through to a 200).
     *
     * @return Response
     *
     * @since 0.14.0
     */
    private function streamFile(string $filePath, string $contentType, ?string $rangeHeader): Response
    {
        $fileSize = filesize($filePath);

        if ($fileSize === false) {
            return (new Response())->status(500)->json([
                'error' => 'Could not determine file size',
            ]);
        }

        if ($rangeHeader !== null && $rangeHeader !== '') {
            return $this->handleRangeRequest($filePath, $fileSize, $contentType, $rangeHeader);
        }

        // Full file response
        $content = file_get_contents($filePath);
        if ($content === false) {
            return (new Response())->status(500)->json([
                'error' => 'Could not read file',
            ]);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', $contentType)
            ->header('Content-Length', (string) $fileSize)
            ->header('Accept-Ranges', 'bytes')
            ->body($content);
    }

    /**
     * Handle an HTTP Range request for seeking (HTTP 206).
     *
     * Accepts `bytes=start-end`, open-ended `bytes=start-`, and suffix `bytes=-N`
     * (the last N bytes). An end that runs past EOF is clamped rather than rejected
     * (RFC 7233); a malformed or unsatisfiable range yields 416 with
     * `Content-Range: bytes * /{size}`. $fileSize is passed in so filesize() is only
     * stat'd once by the caller.
     *
     * @param string $filePath    File path
     * @param int    $fileSize    Total file size
     * @param string $contentType Content type
     * @param string $rangeHeader Raw Range header value
     *
     * @return Response
     *
     * @since 0.14.0
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

        // Read only the requested range
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

    /**
     * Get content type for audio format.
     *
     * @param string $format Audio format (mp3, ogg, etc.)
     *
     * @return string MIME content type
     *
     * @since 0.14.0
     */
    private function getAudioContentType(string $format): string
    {
        return match (strtolower($format)) {
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            default => 'application/octet-stream',
        };
    }

    /**
     * Get content type for video format.
     *
     * @param string $format Video format (mp4, webm, etc.)
     *
     * @return string MIME content type
     *
     * @since 0.14.0
     */
    private function getVideoContentType(string $format): string
    {
        return match (strtolower($format)) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }
}
