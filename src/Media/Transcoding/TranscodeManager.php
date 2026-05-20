<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Phlix\Common\Util\RowMap;
use Phlix\Media\Streaming\StreamState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Transcode Manager - Manages media transcoding jobs and lifecycle.
 *
 * Coordinates transcoding operations by creating FFmpeg jobs, tracking their
 * status, managing concurrent job limits, and cleaning up stale jobs.
 * Integrates with the streaming system to provide transcoded content.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Job management for FFmpeg-based media transcoding with concurrency limits
 * @see FfmpegRunner For FFmpeg process execution
 * @see EncodingHelper For encoding parameter generation
 */
class TranscodeManager
{
    /** @var Connection Database connection for job persistence */
    private Connection $db;

    /** @var FfmpegRunner FFmpeg execution engine */
    private FfmpegRunner $ffmpeg;

    /** @var EncodingHelper Encoding parameter calculator */
    private EncodingHelper $encodingHelper;

    /** @var string Base directory for transcoded output files */
    private string $transcodeDir;

    /** @var string Base directory for HLS segments */
    private string $segmentDir;

    /** @var array<string, array{id: string, state: StreamState, output_path: string, encoding_params: array<string, mixed>, started_at: int}> Active jobs */
    private array $activeJobs = [];

    /** @var int Maximum concurrent transcode jobs allowed */
    private int $maxConcurrentTranscodes;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    // Job status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Creates a new TranscodeManager instance.
     *
     * @param Connection $db Database connection
     * @param FfmpegRunner $ffmpeg FFmpeg execution engine
     * @param EncodingHelper $encodingHelper Encoding parameter calculator
     * @param string $transcodeDir Output directory for transcoded files
     * @param string $segmentDir Directory for HLS segments
     * @param LoggerInterface|null $logger Optional PSR logger
     *
     * @example
     * ```php
     * $manager = new TranscodeManager($db, $ffmpeg, $helper, '/var/transcodes', '/var/segments');
     * ```
     */
    public function __construct(
        Connection $db,
        FfmpegRunner $ffmpeg,
        EncodingHelper $encodingHelper,
        string $transcodeDir,
        string $segmentDir,
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->ffmpeg = $ffmpeg;
        $this->encodingHelper = $encodingHelper;
        $this->transcodeDir = $transcodeDir;
        $this->segmentDir = $segmentDir;
        $this->maxConcurrentTranscodes = 4;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Starts a transcode job for a stream.
     *
     * Creates the output directory, probes the source, calculates encoding
     * parameters, and initiates the transcode process.
     *
     * @param StreamState $state Stream state containing media item reference
     * @param array<string, mixed> $options Additional options (device_profile, etc.)
     *
     * @return string Job ID for tracking
     *
     * @throws \InvalidArgumentException If media item not found
     * @throws \RuntimeException If probing fails or transcode fails to start
     *
     * @example
     * ```php
     * $jobId = $manager->startTranscode($streamState, ['device_profile' => 'mobile-high']);
     * ```
     */
    public function startTranscode(StreamState $state, array $options = []): string
    {
        if (count($this->activeJobs) >= $this->maxConcurrentTranscodes) {
            throw new \RuntimeException(
                "Maximum concurrent transcodes ({$this->maxConcurrentTranscodes}) reached"
            );
        }

        $jobId = $this->generateUuid();

        $outputDir = "{$this->transcodeDir}/{$jobId}";
        if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Failed to create transcode directory: {$outputDir}");
        }

        $item = $this->getMediaItem($state->mediaItemId);
        if (!$item) {
            throw new \InvalidArgumentException("Media item not found");
        }

        $itemPath = is_string($item['path'] ?? null) ? (string) $item['path'] : '';
        $sourceInfoRaw = $this->ffmpeg->probe($itemPath);
        if (!$sourceInfoRaw) {
            throw new \RuntimeException("Failed to probe media file");
        }

        $sourceInfo = $this->normalizeSourceInfo($sourceInfoRaw);

        $profileRaw = $options['device_profile'] ?? [];
        $profile = $this->normalizeProfile(is_array($profileRaw) ? $profileRaw : []);

        $encodingParams = $this->encodingHelper->getEncodingParams($sourceInfo, $profile, $options);

        $container = is_string($encodingParams['container'] ?? null)
            ? (string) $encodingParams['container']
            : 'ts';
        $outputPath = "{$outputDir}/output.{$container}";

        $this->db->query(
            "INSERT INTO transcode_jobs (id, media_item_id, input_path, output_path, status) VALUES (?, ?, ?, ?, 'running')",
            [$jobId, $state->mediaItemId, $itemPath, $outputPath]
        );

        $success = $this->ffmpeg->transcode($itemPath, $outputPath, $encodingParams);

        if (!$success) {
            $this->db->query("UPDATE transcode_jobs SET status = 'failed' WHERE id = ?", [$jobId]);
            throw new \RuntimeException("Transcode failed");
        }

        $this->activeJobs[$jobId] = [
            'id' => $jobId,
            'state' => $state,
            'output_path' => $outputPath,
            'encoding_params' => $encodingParams,
            'started_at' => time(),
        ];

        $this->logger->info('Transcode started', ['job_id' => $jobId]);

        return $jobId;
    }

    /**
     * Normalize an untyped probe payload to the shape EncodingHelper expects.
     *
     * @param array<string, mixed> $sourceInfo
     * @return array{streams: array<int, array{codec_type: string, codec?: string, width?: int, height?: int, bitrate?: int, channels?: int}>, format?: array{format_name?: string}}
     */
    private function normalizeSourceInfo(array $sourceInfo): array
    {
        $streamsRaw = $sourceInfo['streams'] ?? [];
        $streams = [];
        if (is_array($streamsRaw)) {
            foreach ($streamsRaw as $streamRaw) {
                if (!is_array($streamRaw)) {
                    continue;
                }
                $stream = ['codec_type' => is_string($streamRaw['codec_type'] ?? null) ? (string) $streamRaw['codec_type'] : ''];
                if (isset($streamRaw['codec']) && is_string($streamRaw['codec'])) {
                    $stream['codec'] = $streamRaw['codec'];
                }
                if (isset($streamRaw['width']) && is_int($streamRaw['width'])) {
                    $stream['width'] = $streamRaw['width'];
                }
                if (isset($streamRaw['height']) && is_int($streamRaw['height'])) {
                    $stream['height'] = $streamRaw['height'];
                }
                if (isset($streamRaw['bitrate']) && is_int($streamRaw['bitrate'])) {
                    $stream['bitrate'] = $streamRaw['bitrate'];
                }
                if (isset($streamRaw['channels']) && is_int($streamRaw['channels'])) {
                    $stream['channels'] = $streamRaw['channels'];
                }
                $streams[] = $stream;
            }
        }

        $out = ['streams' => $streams];
        $formatRaw = $sourceInfo['format'] ?? null;
        if (is_array($formatRaw) && isset($formatRaw['format_name']) && is_string($formatRaw['format_name'])) {
            $out['format'] = ['format_name' => $formatRaw['format_name']];
        }

        return $out;
    }

    /**
     * Normalize an untyped device profile to the shape EncodingHelper expects.
     *
     * @param array<int|string, mixed> $profile
     * @return array{max_bitrate?: int, max_resolution?: array<int, int>, direct_play?: array<string>, transcode?: array<string>}
     */
    private function normalizeProfile(array $profile): array
    {
        $out = [];

        if (isset($profile['max_bitrate']) && is_int($profile['max_bitrate'])) {
            $out['max_bitrate'] = $profile['max_bitrate'];
        }

        if (isset($profile['max_resolution']) && is_array($profile['max_resolution'])) {
            $res = [];
            foreach ($profile['max_resolution'] as $dim) {
                if (is_int($dim)) {
                    $res[] = $dim;
                }
            }
            if (count($res) >= 2) {
                $out['max_resolution'] = [$res[0], $res[1]];
            }
        }

        foreach (['direct_play', 'transcode'] as $key) {
            if (!isset($profile[$key]) || !is_array($profile[$key])) {
                continue;
            }
            $codecs = [];
            foreach ($profile[$key] as $codec) {
                if (is_string($codec)) {
                    $codecs[] = $codec;
                }
            }
            $out[$key] = $codecs;
        }

        return $out;
    }

    /**
     * Stops a running transcode job.
     *
     * Terminates the job, deletes output files, and updates the database
     * status to 'cancelled'.
     *
     * @param string $jobId Job identifier
     */
    public function stopTranscode(string $jobId): void
    {
        if (!isset($this->activeJobs[$jobId])) {
            return;
        }

        $job = $this->activeJobs[$jobId];

        $dir = dirname($job['output_path']);
        if (is_dir($dir)) {
            $files = glob("{$dir}/*");
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($dir);
        }

        $this->db->query("UPDATE transcode_jobs SET status = 'cancelled' WHERE id = ?", [$jobId]);

        unset($this->activeJobs[$jobId]);

        $this->logger->info('Transcode cancelled', ['job_id' => $jobId]);
    }

    /**
     * Gets the status of a transcode job.
     *
     * @param string $jobId Job identifier
     *
     * @return array{
     *     id: string,
     *     status: string,
     *     output_path?: string
     * }|null Job status array or null if not found
     */
    public function getTranscodeStatus(string $jobId): ?array
    {
        if (isset($this->activeJobs[$jobId])) {
            return [
                'id' => $jobId,
                'status' => self::STATUS_RUNNING,
                'output_path' => $this->activeJobs[$jobId]['output_path'],
            ];
        }

        $result = $this->db->query("SELECT * FROM transcode_jobs WHERE id = ?", [$jobId]);
        $rows = RowMap::listFromMixed($result);
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $status = [
            'id' => is_string($row['id'] ?? null) ? (string) $row['id'] : $jobId,
            'status' => is_string($row['status'] ?? null) ? (string) $row['status'] : self::STATUS_PENDING,
        ];
        if (isset($row['output_path']) && is_string($row['output_path'])) {
            $status['output_path'] = $row['output_path'];
        }

        return $status;
    }

    /**
     * Gets count of currently running transcode jobs.
     *
     * Any entry in {@see self::$activeJobs} is by definition running — completed,
     * failed, and cancelled jobs are removed from the map.
     *
     * @return int Number of active transcodes
     */
    public function getActiveTranscodeCount(): int
    {
        return count($this->activeJobs);
    }

    /**
     * Returns the configured maximum concurrent transcode budget.
     *
     * @return int Concurrency limit applied by {@see self::startTranscode()}
     */
    public function getMaxConcurrentTranscodes(): int
    {
        return $this->maxConcurrentTranscodes;
    }

    /**
     * Returns the configured HLS segment directory.
     *
     * @return string Base directory where segments are written by external HLS
     *                writers when transcoding to HLS variants.
     */
    public function getSegmentDir(): string
    {
        return $this->segmentDir;
    }

    /**
     * Cleans up stale transcode jobs older than max age.
     *
     * Identifies jobs that have been running longer than the specified
     * threshold and stops them to free resources.
     *
     * @param int $maxAgeSeconds Maximum job age in seconds (default: 3600)
     */
    public function cleanupStaleJobs(int $maxAgeSeconds = 3600): void
    {
        $cutoff = time() - $maxAgeSeconds;

        foreach ($this->activeJobs as $jobId => $job) {
            if ($job['started_at'] < $cutoff) {
                $this->stopTranscode($jobId);
                $this->logger->warning('Cleaned up stale transcode job', ['job_id' => $jobId]);
            }
        }
    }

    /**
     * Retrieves media item from database.
     *
     * @param string $itemId Media item identifier
     *
     * @return array<string, mixed>|null Media item row or null
     */
    private function getMediaItem(string $itemId): ?array
    {
        $result = $this->db->query("SELECT * FROM media_items WHERE id = ?", [$itemId]);
        $rows = RowMap::listFromMixed($result);
        return $rows[0] ?? null;
    }

    /**
     * Generates a UUID v4 identifier.
     *
     * @return string UUID string
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
