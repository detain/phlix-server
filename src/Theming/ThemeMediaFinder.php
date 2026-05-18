<?php

declare(strict_types=1);

namespace Phlex\Theming;

use Phlex\Media\Transcoding\FfmpegRunner;

/**
 * ThemeMediaFinder scans the filesystem for library theme media files.
 *
 * Scans for theme audio (theme.mp3, theme.mp4, theme.ogg) and theme video
 * (backdrop.mp4, backdrop.webm) at the library root level. Uses FFprobe
 * to extract duration and dimensions for discovered files.
 *
 * @since 0.14.0
 */
class ThemeMediaFinder
{
    /** @var array<string> Supported audio file extensions */
    private const SUPPORTED_AUDIO_EXTENSIONS = ['mp3', 'mp4', 'ogg'];

    /** @var array<string> Supported video file extensions */
    private const SUPPORTED_VIDEO_EXTENSIONS = ['mp4', 'webm'];

    /** @var FfmpegRunner|null FFmpeg runner for probing files */
    private ?FfmpegRunner $ffmpegRunner;

    /**
     * @param FfmpegRunner|null $ffmpegRunner Optional FFmpeg runner for duration/quality probing
     *
     * @since 0.14.0
     */
    public function __construct(?FfmpegRunner $ffmpegRunner = null)
    {
        $this->ffmpegRunner = $ffmpegRunner;
    }

    /**
     * Find theme media for a library root path.
     *
     * Scans for theme.mp3, theme.mp4, theme.ogg (audio) and
     * backdrop.mp4, backdrop.webm (video) at the library root.
     *
     * @param string $libraryId The library identifier
     * @param string $libraryPath The absolute path to the library root
     *
     * @return ThemeMedia|null Theme media found, or null if none exists
     *
     * @since 0.14.0
     */
    public function findForLibrary(string $libraryId, string $libraryPath): ?ThemeMedia
    {
        $audio = $this->findAudio($libraryPath);
        $video = $this->findVideo($libraryPath);

        if ($audio === null && $video === null) {
            return null;
        }

        return new ThemeMedia(
            libraryId: $libraryId,
            audio: $audio,
            video: $video,
            scannedAt: new \DateTimeImmutable()
        );
    }

    /**
     * Find theme media for a media item directory.
     *
     * For TV shows: per-season or per-series theme. Searches in the
     * parent directory for theme media files.
     *
     * @param string $libraryId The library identifier
     * @param string $itemDir The directory containing the media item
     *
     * @return ThemeMedia|null Theme media found, or null if none exists
     *
     * @since 0.14.0
     */
    public function findForMediaItem(string $libraryId, string $itemDir): ?ThemeMedia
    {
        // For media items, scan the parent directory (series/season level)
        $parentDir = dirname($itemDir);
        return $this->findForLibrary($libraryId, $parentDir);
    }

    /**
     * Find audio theme file in a directory.
     *
     * Searches for theme.mp3, theme.mp4, or theme.ogg in that order.
     *
     * @param string $directory The directory to scan
     *
     * @return ThemeAudio|null Audio theme found, or null
     *
     * @since 0.14.0
     */
    private function findAudio(string $directory): ?ThemeAudio
    {
        foreach (self::SUPPORTED_AUDIO_EXTENSIONS as $extension) {
            $filePath = $directory . '/theme.' . $extension;
            if (file_exists($filePath)) {
                return $this->createAudioData($filePath, $extension);
            }
        }

        return null;
    }

    /**
     * Find video theme file in a directory.
     *
     * Searches for backdrop.mp4 or backdrop.webm in that order.
     *
     * @param string $directory The directory to scan
     *
     * @return ThemeVideo|null Video theme found, or null
     *
     * @since 0.14.0
     */
    private function findVideo(string $directory): ?ThemeVideo
    {
        foreach (self::SUPPORTED_VIDEO_EXTENSIONS as $extension) {
            $filePath = $directory . '/backdrop.' . $extension;
            if (file_exists($filePath)) {
                return $this->createVideoData($filePath, $extension);
            }
        }

        return null;
    }

    /**
     * Create audio data from file path.
     *
     * @param string $path Absolute filesystem path
     * @param string $format Audio format extension
     *
     * @return ThemeAudio
     *
     * @since 0.14.0
     */
    private function createAudioData(string $path, string $format): ThemeAudio
    {
        $duration = 0;
        $url = $this->buildStreamUrl($path, 'audio');

        if ($this->ffmpegRunner !== null) {
            $probe = $this->ffmpegRunner->probe($path);
            if ($probe !== null) {
                $duration = $this->extractDuration($probe);
            }
        }

        return new ThemeAudio(
            path: $path,
            url: $url,
            duration: $duration,
            format: $format
        );
    }

    /**
     * Create video data from file path.
     *
     * @param string $path Absolute filesystem path
     * @param string $format Video format extension
     *
     * @return ThemeVideo
     *
     * @since 0.14.0
     */
    private function createVideoData(string $path, string $format): ThemeVideo
    {
        $duration = 0;
        $width = 0;
        $height = 0;
        $url = $this->buildStreamUrl($path, 'video');

        if ($this->ffmpegRunner !== null) {
            $probe = $this->ffmpegRunner->probe($path);
            if ($probe !== null) {
                $duration = $this->extractDuration($probe);
                $dimensions = $this->extractDimensions($probe);
                $width = $dimensions['width'];
                $height = $dimensions['height'];
            }
        }

        return new ThemeVideo(
            path: $path,
            url: $url,
            duration: $duration,
            width: $width,
            height: $height,
            format: $format
        );
    }

    /**
     * Build streaming URL for a theme media file.
     *
     * @param string $path Absolute filesystem path
     * @param string $type Media type (audio|video)
     *
     * @return string Internal streaming URL
     *
     * @since 0.14.0
     */
    private function buildStreamUrl(string $path, string $type): string
    {
        // URL encoding the path to handle spaces and special characters
        $encodedPath = urlencode($path);
        return "/stream/theme-media/{$type}?path={$encodedPath}";
    }

    /**
     * Extract duration from probe result.
     *
     * @param array<string, mixed> $probe FFprobe result
     *
     * @return int Duration in seconds
     *
     * @since 0.14.0
     */
    private function extractDuration(array $probe): int
    {
        $format = $probe['format'] ?? [];
        if (!is_array($format)) {
            return 0;
        }
        $durationStr = $format['duration'] ?? null;

        if (is_string($durationStr) || is_numeric($durationStr)) {
            return (int) floor((float) $durationStr);
        }

        return 0;
    }

    /**
     * Extract video dimensions from probe result.
     *
     * @param array<string, mixed> $probe FFprobe result
     *
     * @return array{width: int, height: int} Dimensions
     *
     * @since 0.14.0
     */
    private function extractDimensions(array $probe): array
    {
        $streams = $probe['streams'] ?? [];
        if (!is_array($streams)) {
            return ['width' => 0, 'height' => 0];
        }
        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            if (($stream['codec_type'] ?? '') === 'video') {
                $width = $stream['width'] ?? 0;
                $height = $stream['height'] ?? 0;
                return [
                    'width' => is_numeric($width) ? (int) $width : 0,
                    'height' => is_numeric($height) ? (int) $height : 0,
                ];
            }
        }

        return ['width' => 0, 'height' => 0];
    }
}
