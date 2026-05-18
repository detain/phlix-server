<?php

declare(strict_types=1);

namespace Phlex\Media\Streaming;

/**
 * HLS Streamer - Generates HLS playlists and manages segment files.
 *
 * Handles HTTP Live Streaming (HLS) playlist generation including master playlists
 * for adaptive bitrate streaming and variant playlists for individual quality levels.
 * Manages segment file paths and content retrieval for video streaming.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description HLS playlist generator and segment manager for adaptive bitrate streaming
 * @see https://developer.apple.com/documentation/http-live-streaming
 */
class HlsStreamer
{
    /** @var string Directory path where segments are stored */
    private string $segmentDir;

    /** @var string Base URL for streaming endpoints */
    private string $baseUrl;

    /** @var QualitySelector Quality selection engine */
    private QualitySelector $qualitySelector;

    /** @var array<string, array{url: string, bandwidth: int, resolution: string}> Variant playlist cache */
    private array $variantPlaylists = [];

    /**
     * Creates a new HLS streamer instance.
     *
     * @param string $segmentDir Base directory for storing segment files
     * @param string $baseUrl Base URL for streaming endpoints (will be rtrim'd)
     * @param QualitySelector $qualitySelector Quality selection engine
     *
     * @example
     * ```php
     * $streamer = new HlsStreamer('/var/segments', 'http://localhost:8096', new QualitySelector());
     * ```
     */
    public function __construct(string $segmentDir, string $baseUrl, QualitySelector $qualitySelector)
    {
        $this->segmentDir = $segmentDir;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->qualitySelector = $qualitySelector;
    }

    /**
     * Generates an HLS master playlist with variant streams.
     *
     * Creates the main manifest file that lists all available quality variants
     * for adaptive bitrate streaming. Clients use this to select appropriate quality.
     *
     * @param string $jobId Transcode job identifier
     * @param array<int, array{bandwidth: int, width: int, height: int, name: string}> $qualityLevels
     *        Array of quality level definitions with bandwidth in bits/sec
     *
     * @return string Complete master playlist content with #EXTM3U header
     *
     * @example
     * ```php
     * $levels = [
     *     ['bandwidth' => 5000000, 'width' => 1920, 'height' => 1080, 'name' => '1080p'],
     *     ['bandwidth' => 2500000, 'width' => 1280, 'height' => 720, 'name' => '720p'],
     * ];
     * $playlist = $streamer->generateMasterPlaylist('job-123', $levels);
     * ```
     */
    public function generateMasterPlaylist(string $jobId, array $qualityLevels): string
    {
        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:3\n";

        foreach ($qualityLevels as $index => $level) {
            $playlist .= sprintf(
                "#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d,NAME=\"%s\"\n",
                $level['bandwidth'],
                $level['width'],
                $level['height'],
                $level['name']
            );
            $playlist .= "stream_{$index}.m3u8\n";
        }

        return $playlist;
    }

    /**
     * Generates an HLS variant playlist for a specific quality level.
     *
     * Creates a variant playlist containing segment references for a single
     * quality level. Includes EXTINF tags with segment durations.
     *
     * @param string $jobId Transcode job identifier
     * @param int $variantIndex Index of the variant (0-based)
     * @param array<int, array{duration?: float}> $segments Array of segment definitions
     * @param int $targetDuration Target segment duration in seconds
     *
     * @return string Complete variant playlist content
     *
     * @example
     * ```php
     * $segments = [
     *     ['duration' => 6.0],
     *     ['duration' => 6.0],
     *     ['duration' => 4.5],
     * ];
     * $playlist = $streamer->generateVariantPlaylist('job-123', 0, $segments, 6);
     * ```
     */
    public function generateVariantPlaylist(string $jobId, int $variantIndex, array $segments, int $targetDuration): string
    {
        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:3\n";
        $playlist .= "#EXT-X-TARGETDURATION:{$targetDuration}\n";
        $playlist .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        $playlist .= "#EXT-X-PLAYLIST-TYPE:VOD\n";

        foreach ($segments as $i => $segment) {
            $duration = $segment['duration'] ?? $targetDuration;
            $playlist .= "#EXTINF:{$duration},\n";
            $playlist .= "segment_{$variantIndex}_" . sprintf('%03d', $i) . ".ts\n";
        }

        $playlist .= "#EXT-X-ENDLIST\n";

        return $playlist;
    }

    /**
     * Gets the filesystem path for a segment file.
     *
     * @param string $jobId Transcode job identifier
     * @param int $variantIndex Variant index (0-based)
     * @param int $segmentNumber Segment number within the variant
     *
     * @return string Full filesystem path to the segment
     */
    public function getSegmentPath(string $jobId, int $variantIndex, int $segmentNumber): string
    {
        return "{$this->segmentDir}/{$jobId}/segment_{$variantIndex}_" . sprintf('%03d', $segmentNumber) . ".ts";
    }

    /**
     * Checks if a segment file exists.
     *
     * @param string $jobId Transcode job identifier
     * @param int $variantIndex Variant index (0-based)
     * @param int $segmentNumber Segment number
     *
     * @return bool True if segment file exists and is readable
     */
    public function segmentExists(string $jobId, int $variantIndex, int $segmentNumber): bool
    {
        $path = $this->getSegmentPath($jobId, $variantIndex, $segmentNumber);
        return file_exists($path);
    }

    /**
     * Retrieves segment file content.
     *
     * @param string $jobId Transcode job identifier
     * @param int $variantIndex Variant index (0-based)
     * @param int $segmentNumber Segment number
     *
     * @return string|null Segment content or null if not found
     *
     * @throws \RuntimeException If file exists but cannot be read
     */
    public function getSegmentContent(string $jobId, int $variantIndex, int $segmentNumber): ?string
    {
        $path = $this->getSegmentPath($jobId, $variantIndex, $segmentNumber);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read segment file: {$path}");
        }

        return $content;
    }

    /**
     * Gets the full playlist URL for a job.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string Full URL to the master playlist
     */
    public function getPlaylistUrl(string $jobId): string
    {
        return "{$this->baseUrl}/hls/{$jobId}/playlist.m3u8";
    }

    /**
     * Gets the variant playlist URL for a specific quality level.
     *
     * @param string $jobId Transcode job identifier
     * @param int $variantIndex Variant index (0-based)
     *
     * @return string Full URL to the variant playlist
     */
    public function getVariantPlaylistUrl(string $jobId, int $variantIndex): string
    {
        return "{$this->baseUrl}/hls/{$jobId}/stream_{$variantIndex}.m3u8";
    }

    /**
     * Gets the HLS stream URL for a media item.
     *
     * Extracts the item ID from the media item array and generates
     * the full HLS streaming URL.
     *
     * @param array<string, mixed> $item Media item with at least 'id' key
     *
     * @return string Full URL to the HLS playlist
     *
     * @since 0.12.0
     */
    public function getStreamUrl(array $item): string
    {
        $itemId = $item['id'] ?? '';
        return $this->getPlaylistUrl($itemId);
    }

    /**
     * Gets the segment URL path.
     *
     * Note: Returns a path, not a full URL, for internal use.
     *
     * @param string $jobId Transcode job identifier
     * @param int $variantIndex Variant index (0-based)
     * @param int $segmentNumber Segment number
     *
     * @return string Relative URL path to the segment
     */
    public function getSegmentUrl(string $jobId, int $variantIndex, int $segmentNumber): string
    {
        return "{$this->segmentDir}/{$jobId}/segment_{$variantIndex}_" . sprintf('%03d', $segmentNumber) . ".ts";
    }

    /**
     * Gets quality levels appropriate for a device profile.
     *
     * Filters the available quality levels based on the device profile's
     * maximum resolution. Returns only levels at or below the max.
     *
     * @param array{max_resolution?: array<int, int>} $profile Device profile with max_resolution
     * @param array<string, mixed> $sourceInfo Source media information
     *
     * @return array<int, array{index: int, name: string, width: int, height: int, bandwidth: int}> Filtered quality levels
     */
    public function getQualityLevelsForProfile(array $profile, array $sourceInfo): array
    {
        $maxHeight = min($profile['max_resolution'][1] ?? 1080, 2160);

        $levels = [
            ['index' => 0, 'name' => '1080p', 'width' => 1920, 'height' => 1080, 'bandwidth' => 5000000],
            ['index' => 1, 'name' => '720p', 'width' => 1280, 'height' => 720, 'bandwidth' => 2500000],
            ['index' => 2, 'name' => '480p', 'width' => 854, 'height' => 480, 'bandwidth' => 1000000],
        ];

        return array_filter($levels, fn($level) => $level['height'] <= $maxHeight);
    }

    /**
     * Saves a playlist file to the job directory.
     *
     * Creates the job directory if it doesn't exist and writes the playlist content.
     *
     * @param string $jobId Transcode job identifier
     * @param string $content Playlist file content
     * @param string $filename Playlist filename (e.g., 'playlist.m3u8', 'stream_0.m3u8')
     *
     * @throws \RuntimeException If directory creation or file write fails
     */
    public function savePlaylist(string $jobId, string $content, string $filename): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $path = "{$dir}/{$filename}";
        $result = file_put_contents($path, $content);
        if ($result === false) {
            throw new \RuntimeException("Failed to write playlist file: {$path}");
        }
    }

    /**
     * Gets the job directory path.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string Full path to the job's segment directory
     */
    public function getJobDirectory(string $jobId): string
    {
        return "{$this->segmentDir}/{$jobId}";
    }

    /**
     * Cleans up all segment files for a job.
     *
     * Deletes all files in the job directory and removes the directory itself.
     * Safe to call even if directory doesn't exist.
     *
     * @param string $jobId Transcode job identifier
     */
    public function cleanupJob(string $jobId): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            return;
        }

        $files = glob("{$dir}/*");
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    /**
     * Saves segment content to a file.
     *
     * This method allows the segment writer to store once and have both
     * HLS and DASH streamers reference the same files (with DASH using
     * .m4s containers).
     *
     * @param string $jobId Transcode job identifier
     * @param int $variantIndex Variant index (0-based)
     * @param int $segmentNumber Segment number
     * @param string $content Segment content
     *
     * @throws \RuntimeException If file write fails
     */
    public function setSegmentContent(string $jobId, int $variantIndex, int $segmentNumber, string $content): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $path = $this->getSegmentPath($jobId, $variantIndex, $segmentNumber);
        $result = file_put_contents($path, $content);
        if ($result === false) {
            throw new \RuntimeException("Failed to write segment file: {$path}");
        }
    }

    /**
     * Counts the number of segments for a variant.
     *
     * @param string $jobId Transcode job identifier
     * @param int $variantIndex Variant index (0-based)
     *
     * @return int Number of segment files found (0 if none)
     */
    public function getSegmentCount(string $jobId, int $variantIndex): int
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        $pattern = "{$dir}/segment_{$variantIndex}_*.ts";
        $files = glob($pattern);
        return count($files);
    }
}
