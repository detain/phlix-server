<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Theming\ThemeMediaRepository;

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
            $audio->duration
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
            $video->duration
        );
    }

    /**
     * Stream a file with content-type and range support.
     *
     * @param string $filePath Absolute path to the file
     * @param string $contentType MIME content type
     * @param int $duration Duration hint in seconds (unused but available)
     *
     * @return Response
     *
     * @since 0.14.0
     */
    private function streamFile(string $filePath, string $contentType, int $duration): Response
    {
        $fileSize = filesize($filePath);

        if ($fileSize === false) {
            return (new Response())->status(500)->json([
                'error' => 'Could not determine file size',
            ]);
        }

        // Handle range requests for seeking
        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;

        if ($rangeHeader !== null) {
            return $this->handleRangeRequest($filePath, $fileSize, $contentType);
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
     * Handle HTTP range request for seeking.
     *
     * @param string $filePath File path
     * @param int $fileSize Total file size
     * @param string $contentType Content type
     *
     * @return Response
     *
     * @since 0.14.0
     */
    private function handleRangeRequest(
        string $filePath,
        int $fileSize,
        string $contentType
    ): Response {
        // Parse Range header: "bytes=start-end"
        $rangeHeader = is_string($_SERVER['HTTP_RANGE'] ?? null) ? $_SERVER['HTTP_RANGE'] : '';
        if (!preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
            return (new Response())
                ->status(416)
                ->header('Content-Range', "bytes */{$fileSize}");
        }

        // preg_match matched, so $matches[1] and $matches[2] are guaranteed to be set
        /** @var int $start */
        $start = (int) $matches[1];
        // $matches[2] is ''|numeric-string, both of which can be cast to string
        $end = ($matches[2] !== '' ? (int) $matches[2] : $fileSize - 1);

        // Validate range
        if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
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
