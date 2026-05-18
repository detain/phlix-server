<?php

declare(strict_types=1);

namespace Phlex\Media\Extras;

use Phlex\Media\Transcoding\FfmpegRunner;
use SplFileInfo;

/**
 * TrailerFinder scans the filesystem for local trailers.
 *
 * Scans for trailers in two locations:
 * - <mediaDir>/Trailers/<name>-trailer.mkv (or .mp4, .mkv, .avi)
 * - <mediaDir>/<name>-trailer.mkv (same level as main file)
 *
 * The suffix (-trailer, -teaser, -clip, -featurette) is extracted as the display title.
 *
 * @since 0.14.0
 */
class TrailerFinder
{
    /** @var array<string> Supported video file extensions */
    private const SUPPORTED_EXTENSIONS = ['mkv', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', 'ts'];

    /** @var array<string> Suffix patterns mapped to display titles */
    private const SUFFIX_PATTERNS = [
        '-trailer' => 'Trailer',
        '-teaser' => 'Teaser',
        '-clip' => 'Clip',
        '-featurette' => 'Featurette',
        '-behind-the-scenes' => 'Behind the Scenes',
        '-interview' => 'Interview',
        '-deleted-scene' => 'Deleted Scene',
        '-deleted-scenes' => 'Deleted Scenes',
    ];

    /** @var FfmpegRunner|null FFmpeg runner for probing files */
    private ?FfmpegRunner $ffmpegRunner;

    /**
     * @param FfmpegRunner|null $ffmpegRunner Optional FFmpeg runner for duration/quality probing
     */
    public function __construct(?FfmpegRunner $ffmpegRunner = null)
    {
        $this->ffmpegRunner = $ffmpegRunner;
    }

    /**
     * Find local trailers for a media directory.
     *
     * Scans both same-level and Trailers/ subfolder for trailer files.
     *
     * @param string $mediaDir The directory containing the media file
     * @param string $mediaFilename The main media filename (e.g., "Avatar (2009).mkv")
     *
     * @return array<int, array{path: string, title: string, duration: int, quality: int}> Found trailers
     */
    public function findLocalTrailers(string $mediaDir, string $mediaFilename): array
    {
        $trailers = [];

        // Build the base name without extension (e.g., "Avatar (2009)")
        $baseName = pathinfo($mediaFilename, PATHINFO_FILENAME);

        // Check same-level trailer: <mediaDir>/<baseName>-trailer.ext
        $sameLevelTrailers = $this->findSameLevelTrailers($mediaDir, $baseName);
        foreach ($sameLevelTrailers as $trailer) {
            $trailers[] = $trailer;
        }

        // Check Trailers/ subfolder
        $trailersFolder = rtrim($mediaDir, '/') . '/Trailers';
        if (is_dir($trailersFolder)) {
            $subfolderTrailers = $this->findSubfolderTrailers($trailersFolder, $baseName);
            foreach ($subfolderTrailers as $trailer) {
                $trailers[] = $trailer;
            }
        }

        return $trailers;
    }

    /**
     * Find same-level trailer file.
     *
     * @param string $mediaDir The media directory
     * @param string $baseName The base name to search for
     *
     * @return array<int, array{path: string, title: string, duration: int, quality: int}>
     */
    private function findSameLevelTrailers(string $mediaDir, string $baseName): array
    {
        $results = [];

        foreach (self::SUPPORTED_EXTENSIONS as $ext) {
            $trailerFile = $mediaDir . '/' . $baseName . '-trailer.' . $ext;
            if (file_exists($trailerFile)) {
                $results[] = $this->createTrailerData($trailerFile, 'Trailer');
                break; // Only one same-level trailer per type
            }
        }

        return $results;
    }

    /**
     * Find trailers in the Trailers/ subfolder.
     *
     * @param string $trailersFolder The Trailers directory path
     * @param string $baseName The base name to match
     *
     * @return array<int, array{path: string, title: string, duration: int, quality: int}>
     */
    private function findSubfolderTrailers(string $trailersFolder, string $baseName): array
    {
        $results = [];

        $iterator = new \DirectoryIterator($trailersFolder);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            // Skip hidden files
            if (str_starts_with($filename, '.')) {
                continue;
            }

            // Check if it matches the base name with any extra suffix
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            // Match: <baseName>-<suffix>.<ext>
            $pattern = '/^' . preg_quote($baseName, '/') . '-(.+)\.' . preg_quote($extension, '/') . '$/';
            if (preg_match($pattern, $filename, $matches)) {
                $suffix = $matches[1];
                $title = $this->extractTitleFromSuffix($suffix);
                $results[] = $this->createTrailerData($file->getPathname(), $title);
            }
        }

        return $results;
    }

    /**
     * Extract display title from filename suffix.
     *
     * @param string $suffix The suffix part of the filename (e.g., "trailer", "teaser", "official-trailer")
     *
     * @return string Display title
     */
    private function extractTitleFromSuffix(string $suffix): string
    {
        $lowerSuffix = strtolower($suffix);

        // Check for exact match patterns
        foreach (self::SUFFIX_PATTERNS as $pattern => $title) {
            if ($lowerSuffix === strtolower(substr($pattern, 1))) {
                return $title;
            }
        }

        // Check if suffix contains any known pattern
        foreach (self::SUFFIX_PATTERNS as $pattern => $title) {
            $patternWithoutDash = substr($pattern, 1);
            if (str_contains($lowerSuffix, $patternWithoutDash)) {
                return $title;
            }
        }

        // Fallback: capitalize the suffix
        return ucfirst(str_replace(['-', '_'], ' ', $suffix));
    }

    /**
     * Create trailer data array with metadata.
     *
     * @param string $path File path
     * @param string $title Display title
     *
     * @return array{path: string, title: string, duration: int, quality: int}
     */
    private function createTrailerData(string $path, string $title): array
    {
        $duration = 0;
        $quality = 0;

        if ($this->ffmpegRunner !== null) {
            $probe = $this->ffmpegRunner->probe($path);
            if ($probe !== null) {
                $duration = $this->extractDuration($probe);
                $quality = $this->extractQuality($probe);
            }
        }

        return [
            'path' => $path,
            'title' => $title,
            'duration' => $duration,
            'quality' => $quality,
        ];
    }

    /**
     * Extract duration from probe result.
     *
     * @param array<string, mixed> $probe FFprobe result
     *
     * @return int Duration in seconds
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
     * Extract video quality (height) from probe result.
     *
     * @param array<string, mixed> $probe FFprobe result
     *
     * @return int Quality as vertical resolution (480/720/1080/2160)
     */
    private function extractQuality(array $probe): int
    {
        $streams = $probe['streams'] ?? [];
        if (!is_array($streams)) {
            return 0;
        }
        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            if (($stream['codec_type'] ?? '') === 'video') {
                $height = $stream['height'] ?? 0;
                if (!is_numeric($height)) {
                    continue;
                }
                return $this->normalizeQuality((int) $height);
            }
        }

        return 0;
    }

    /**
     * Normalize height to standard quality values.
     *
     * @param int $height Vertical resolution
     *
     * @return int Normalized quality (480/720/1080/2160)
     */
    private function normalizeQuality(int $height): int
    {
        if ($height >= 2160) {
            return 2160;
        }
        if ($height >= 1080) {
            return 1080;
        }
        if ($height >= 720) {
            return 720;
        }
        if ($height >= 480) {
            return 480;
        }

        return $height;
    }

    /**
     * Extract the title from a trailer filename.
     *
     * @param string $filename The trailer filename (e.g., "Avatar (2009)-trailer.mkv")
     *
     * @return string The extracted title (e.g., "Trailer")
     */
    public function extractTitleFromFilename(string $filename): string
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        // Find the suffix pattern
        foreach (self::SUFFIX_PATTERNS as $pattern => $title) {
            $patternWithoutDash = substr($pattern, 1);
            if (str_contains(strtolower($baseName), $patternWithoutDash)) {
                return $title;
            }
        }

        return 'Trailer';
    }
}
