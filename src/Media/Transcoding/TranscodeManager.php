<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Phlix\Common\Util\RowMap;
use Phlix\Media\Streaming\StreamState;
use Phlix\Media\Transcoding\Subtitles\SubtitleExtractor;
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

    /** @var int Target HLS segment duration in seconds */
    private int $segmentSeconds;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var SubtitleExtractor Detects + builds extraction for embedded text subs */
    private SubtitleExtractor $subtitleExtractor;

    /** @var string Absolute php binary path used by the detached VTT cleaner step */
    private string $phpBinary;

    /** @var string Absolute path to the scripts/clean-vtt.php cleaner CLI */
    private string $cleanVttScript;

    /**
     * Profile resolution caps used to decide downscaling for HLS jobs.
     *
     * Mirrors {@see \Phlix\Media\Streaming\QualitySelector}'s max_resolution per
     * profile so a job can be encoded for a device class without coupling to the
     * full selector. Unknown profiles fall back to 'web'.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const PROFILE_MAX_RESOLUTION = [
        'generic' => [3840, 2160],
        'mobile-low' => [854, 480],
        'mobile-high' => [1280, 720],
        'web' => [1920, 1080],
        'tv-4k' => [3840, 2160],
    ];

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
     * @param int $segmentSeconds Target HLS segment duration in seconds (default 6)
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
        ?LoggerInterface $logger = null,
        int $segmentSeconds = 6,
        ?SubtitleExtractor $subtitleExtractor = null,
        ?string $phpBinary = null,
        ?string $cleanVttScript = null
    ) {
        $this->db = $db;
        $this->ffmpeg = $ffmpeg;
        $this->encodingHelper = $encodingHelper;
        $this->transcodeDir = $transcodeDir;
        $this->segmentDir = $segmentDir;
        $this->maxConcurrentTranscodes = 4;
        $this->logger = $logger ?? new NullLogger();
        $this->segmentSeconds = $segmentSeconds > 0 ? $segmentSeconds : 6;
        $this->subtitleExtractor = $subtitleExtractor ?? new SubtitleExtractor();
        // PHP_BINARY is the absolute path to the running interpreter, used by the
        // detached job to invoke the VTT-cleaner CLI.
        $this->phpBinary = $phpBinary ?? PHP_BINARY;
        $this->cleanVttScript = $cleanVttScript ?? (dirname(__DIR__, 3) . '/scripts/clean-vtt.php');
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
        $this->reapStaleRunningJobs();

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

        $this->persistProbedDuration($state->mediaItemId, $item, $sourceInfoRaw);

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
     * Ensures an HLS transcode job exists for a media item + device profile.
     *
     * This is the entry point the playback layer calls when a file can't be
     * direct-played. It is idempotent: a still-valid job for the same
     * (media item, profile) is reused rather than spawning a duplicate FFmpeg.
     * Otherwise it probes the source, decides per-stream copy-vs-encode, creates
     * the job directory under the shared segment dir, records the row and launches
     * a DETACHED ffmpeg CMAF encode (one pass → both DASH `.mpd` and HLS `.m3u8`
     * from shared `.m4s` segments), so the event loop never blocks.
     *
     * @param string $mediaItemId Media item to transcode.
     * @param string $profileName Device profile name (e.g. 'web', 'mobile-high').
     *
     * @return array{
     *     job_id: string, status: string, master_url: string, hls_url: string, dash_url: string, reused: bool,
     *     subtitles: list<array{index: int, language: string, label: string, default: bool, url: string}>
     * }
     *
     * @throws \InvalidArgumentException If the media item is not found.
     * @throws \RuntimeException If concurrency is exhausted, probing fails, or the
     *                          encode could not be launched.
     *
     * @since 0.23.0
     */
    public function ensureHlsJob(string $mediaItemId, string $profileName = 'web'): array
    {
        $keyHash = sha1($mediaItemId . '|' . $profileName);

        // Self-heal first: drop any dead 'running' ghosts so a fresh play request
        // is not refused by the previous worker's leftovers (see reapStaleRunningJobs).
        $this->reapStaleRunningJobs();

        $existing = $this->findReusableJob($keyHash);
        if ($existing !== null) {
            return [
                'job_id' => $existing,
                'status' => $this->statusOf($existing),
                'master_url' => "/hls/{$existing}/master.m3u8",
                'hls_url' => "/hls/{$existing}/master.m3u8",
                'dash_url' => "/dash/{$existing}/manifest.mpd",
                'reused' => true,
                'subtitles' => $this->subtitleTracksFor($existing),
            ];
        }

        if ($this->getRunningJobCount() >= $this->maxConcurrentTranscodes) {
            throw new \RuntimeException(
                "Maximum concurrent transcodes ({$this->maxConcurrentTranscodes}) reached"
            );
        }

        $item = $this->getMediaItem($mediaItemId);
        if (!$item) {
            throw new \InvalidArgumentException('Media item not found');
        }
        $itemPath = is_string($item['path'] ?? null) ? (string) $item['path'] : '';
        if ($itemPath === '') {
            throw new \RuntimeException('Media item has no source path');
        }

        $probe = $this->ffmpeg->probe($itemPath);
        if (!$probe) {
            throw new \RuntimeException('Failed to probe media file');
        }

        // Record the precise source duration so the UI shows a correct length.
        $this->persistProbedDuration($mediaItemId, $item, $probe);

        $params = $this->computeHlsParams($probe, $profileName);

        $jobId = $this->generateUuid();
        $hlsDir = "{$this->segmentDir}/{$jobId}";
        if (!mkdir($hlsDir, 0755, true) && !is_dir($hlsDir)) {
            throw new \RuntimeException("Failed to create HLS directory: {$hlsDir}");
        }

        $width = is_int($params['variant_width'] ?? null) ? $params['variant_width'] : null;
        $height = is_int($params['variant_height'] ?? null) ? $params['variant_height'] : null;
        $bandwidth = is_int($params['variant_bandwidth'] ?? null) ? $params['variant_bandwidth'] : null;
        // CMAF master playlist (HLS); the DASH manifest.mpd lands in the same dir.
        $playlistPath = "{$hlsDir}/master.m3u8";

        // Detect embedded TEXT subtitle tracks (ASS/SRT/mov_text — bitmap PGS/
        // VobSub are skipped). Detection is a cheap parse of the in-memory probe;
        // the actual extraction runs in the detached job below.
        $tracks = $this->subtitleExtractor->detectTextTracks($probe);
        $extractCmds = [];
        foreach ($tracks as $track) {
            $extractCmds[] = $this->subtitleExtractor->buildExtractCommand(
                $this->ffmpeg->getFfmpegPath(),
                $this->phpBinary,
                $this->cleanVttScript,
                $itemPath,
                $hlsDir,
                $track['index']
            );
        }
        $tracksJson = $tracks === [] ? null : json_encode($tracks);

        $this->db->query(
            "INSERT INTO transcode_jobs
                (id, media_item_id, input_path, output_path, hls_dir, status, profile, key_hash,
                 variant_width, variant_height, variant_bandwidth, subtitle_tracks, started_at)
             VALUES (?, ?, ?, ?, ?, 'running', ?, ?, ?, ?, ?, ?, NOW())",
            [$jobId, $mediaItemId, $itemPath, $playlistPath, $hlsDir, $profileName, $keyHash,
                $width, $height, $bandwidth, $tracksJson]
        );

        $pid = $this->ffmpeg->startCmafTranscodeWithSubtitles($itemPath, $hlsDir, $params, $extractCmds);
        if ($pid <= 0) {
            $this->db->query(
                "UPDATE transcode_jobs SET status = 'failed', error = ? WHERE id = ?",
                ['Failed to launch ffmpeg', $jobId]
            );
            throw new \RuntimeException('Failed to launch transcode');
        }

        $this->logger->info('CMAF transcode started', [
            'job_id' => $jobId,
            'media_item_id' => $mediaItemId,
            'profile' => $profileName,
            'pid' => $pid,
            'video_codec' => $params['video_codec'] ?? null,
            'audio_codec' => $params['audio_codec'] ?? null,
            'subtitle_tracks' => count($tracks),
        ]);

        return [
            'job_id' => $jobId,
            'status' => self::STATUS_RUNNING,
            'master_url' => "/hls/{$jobId}/master.m3u8",
            'hls_url' => "/hls/{$jobId}/master.m3u8",
            'dash_url' => "/dash/{$jobId}/manifest.mpd",
            'reused' => false,
            'subtitles' => $this->subtitleTrackUrls($jobId, $tracks),
        ];
    }

    /**
     * Builds the public subtitle-track descriptors for a job from detector output.
     *
     * Maps each detected track to the playable shape the API returns: the sidecar
     * `url` points at the same /hls/{job}/{file} route that serves the segments.
     *
     * @param string $jobId  Job identifier.
     * @param list<array{index: int, language: string, label: string, default: bool, codec: string, filename: string}> $tracks
     *
     * @return list<array{index: int, language: string, label: string, default: bool, url: string}>
     */
    private function subtitleTrackUrls(string $jobId, array $tracks): array
    {
        $out = [];
        foreach ($tracks as $track) {
            $out[] = [
                'index' => $track['index'],
                'language' => $track['language'],
                'label' => $track['label'],
                'default' => $track['default'],
                'url' => "/hls/{$jobId}/{$track['filename']}",
            ];
        }
        return $out;
    }

    /**
     * Reads the stored subtitle-track descriptors for an existing job.
     *
     * Decodes the `subtitle_tracks` JSON persisted at job creation and maps it to
     * the public {index, language, label, default, url} shape. Returns an empty
     * list when the job has no text subtitles or the column is empty.
     *
     * @param string $jobId Job identifier.
     *
     * @return list<array{index: int, language: string, label: string, default: bool, url: string}>
     */
    public function subtitleTracksFor(string $jobId): array
    {
        $row = $this->getJobRow($jobId);
        if ($row === null) {
            return [];
        }
        return $this->decodeSubtitleTracks($jobId, $row);
    }

    /**
     * Decodes a job row's `subtitle_tracks` JSON into public track descriptors.
     *
     * Subtitle extraction runs detached/async AFTER the encode (and a track may
     * fail to convert), so the persisted descriptor list can advertise more
     * tracks than actually materialized. To guarantee every advertised `url`
     * resolves (no 404s), a track is only returned when its `sub-{index}.vtt`
     * sidecar exists on disk in the job directory at response time.
     *
     * @param string               $jobId Job identifier (for the sidecar URLs).
     * @param array<string, mixed> $row   The transcode_jobs row.
     *
     * @return list<array{index: int, language: string, label: string, default: bool, url: string}>
     */
    private function decodeSubtitleTracks(string $jobId, array $row): array
    {
        $raw = $row['subtitle_tracks'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $dir = is_string($row['hls_dir'] ?? null) && $row['hls_dir'] !== ''
            ? (string) $row['hls_dir']
            : "{$this->segmentDir}/{$jobId}";

        $out = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $index = is_int($entry['index'] ?? null)
                ? $entry['index']
                : (is_numeric($entry['index'] ?? null) ? (int) $entry['index'] : null);
            $filename = is_string($entry['filename'] ?? null) ? $entry['filename'] : null;
            if ($index === null || $filename === null) {
                continue;
            }
            // Only advertise a track whose .vtt actually exists on disk so the
            // returned url is guaranteed to resolve (extraction is async/may fail).
            if (!file_exists($dir . '/' . $filename)) {
                continue;
            }
            $out[] = [
                'index' => $index,
                'language' => is_string($entry['language'] ?? null) ? $entry['language'] : 'und',
                'label' => is_string($entry['label'] ?? null) ? $entry['label'] : 'Unknown',
                'default' => (bool) ($entry['default'] ?? false),
                'url' => "/hls/{$jobId}/{$filename}",
            ];
        }
        return $out;
    }

    /**
     * Reports on-disk readiness of an HLS job for client polling.
     *
     * Reads the completion markers and segment directory written by the detached
     * FFmpeg process (no in-memory process handle is needed, so this survives
     * worker reloads) and syncs the DB row's status on terminal transitions.
     *
     * @param string $jobId Job identifier.
     *
     * @return array{job_id: string, status: string, segments: int, playlist_ready: bool, progress: float,
     *     subtitles: list<array{index: int, language: string, label: string, default: bool, url: string}>}
     *
     * @since 0.23.0
     */
    public function getJobReadiness(string $jobId): array
    {
        $row = $this->getJobRow($jobId);
        if ($row === null) {
            return [
                'job_id' => $jobId,
                'status' => 'not_found',
                'segments' => 0,
                'playlist_ready' => false,
                'progress' => 0.0,
                'subtitles' => [],
            ];
        }

        $dir = is_string($row['hls_dir'] ?? null) ? (string) $row['hls_dir'] : "{$this->segmentDir}/{$jobId}";
        $dbStatus = is_string($row['status'] ?? null) ? (string) $row['status'] : self::STATUS_RUNNING;

        $segments = $this->countSegments($dir);
        $playlistReady = file_exists("{$dir}/master.m3u8") && $segments > 0;

        $status = $dbStatus;
        if ($dbStatus !== self::STATUS_CANCELLED) {
            if (file_exists("{$dir}/.failed")) {
                $status = self::STATUS_FAILED;
            } elseif (file_exists("{$dir}/.complete")) {
                $status = self::STATUS_COMPLETED;
            } elseif ($playlistReady) {
                $status = self::STATUS_RUNNING;
            }
        }

        if ($status !== $dbStatus) {
            if ($status === self::STATUS_COMPLETED) {
                $this->db->query(
                    "UPDATE transcode_jobs SET status = 'completed', progress = 100, completed_at = NOW() WHERE id = ?",
                    [$jobId]
                );
            } elseif ($status === self::STATUS_FAILED) {
                $error = $this->readFailureReason($dir);
                $this->db->query(
                    "UPDATE transcode_jobs SET status = 'failed', error = ? WHERE id = ?",
                    [$error, $jobId]
                );
            }
        }

        $progress = $status === self::STATUS_COMPLETED
            ? 100.0
            : ($playlistReady ? min(95.0, (float) $segments) : 0.0);

        return [
            'job_id' => $jobId,
            'status' => $status,
            'segments' => $segments,
            'playlist_ready' => $playlistReady,
            'progress' => $progress,
            'subtitles' => $this->decodeSubtitleTracks($jobId, $row),
        ];
    }

    /**
     * Computes HLS encode parameters from a probe + device profile.
     *
     * Copies a browser-compatible stream when possible (h264 video without a
     * downscale, AAC audio) for a fast remux, otherwise encodes to H.264 / AAC.
     *
     * @param array<string, mixed> $probe       Raw ffprobe result.
     * @param string               $profileName Device profile name.
     *
     * @return array<string, mixed> Parameters for {@see FfmpegRunner::buildHlsCommand()}
     *                              plus variant_width/height/bandwidth descriptors.
     */
    private function computeHlsParams(array $probe, string $profileName): array
    {
        $video = $this->firstStreamOfType($probe, 'video');
        $audio = $this->firstStreamOfType($probe, 'audio');

        $srcV = strtolower(is_string($video['codec_name'] ?? null) ? (string) $video['codec_name'] : '');
        $srcA = strtolower(is_string($audio['codec_name'] ?? null) ? (string) $audio['codec_name'] : '');
        $srcPixFmt = strtolower(is_string($video['pix_fmt'] ?? null) ? (string) $video['pix_fmt'] : '');
        $srcProfile = strtolower(is_string($video['profile'] ?? null) ? (string) $video['profile'] : '');
        $width = $this->intVal($video['width'] ?? null);
        $height = $this->intVal($video['height'] ?? null);
        $channels = $this->intVal($audio['channels'] ?? null);

        [$maxW, $maxH] = self::PROFILE_MAX_RESOLUTION[$profileName] ?? self::PROFILE_MAX_RESOLUTION['web'];

        $needScale = ($width > 0 && $height > 0) && ($width > $maxW || $height > $maxH);
        $targetW = $needScale ? $maxW : $width;
        $targetH = $needScale ? $maxH : $height;

        $params = [
            'variant_index' => 0,
            'segment_seconds' => $this->segmentSeconds,
            // VOD, not 'event': the source is a fixed-length file, so the HLS
            // playlist must advertise EXT-X-PLAYLIST-TYPE:VOD and terminate with
            // EXT-X-ENDLIST. An 'event' (live) playlist makes hls.js treat the
            // stream as open-ended, so the player reports a duration that only
            // grows as segments arrive instead of the real total.
            'playlist_type' => 'vod',
        ];

        // Remux (copy) only a stream the browser can actually decode: 8-bit 4:2:0
        // H.264. A 10-bit (High 10) or 4:2:2/4:4:4 H.264 source copied as-is would
        // load but fail to decode in MSE/hls.js, so it must be re-encoded.
        if ($srcV === 'h264' && self::isBrowserSafeH264($srcPixFmt, $srcProfile) && !$needScale) {
            $params['video_codec'] = 'copy';
        } else {
            $params['video_codec'] = 'libx264';
            $params['preset'] = 'veryfast';
            $params['crf'] = 23;
            // 8-bit 4:2:0 High@4.1 — the browser-decodable baseline (see
            // FfmpegRunner::browserSafeVideoFlags). Explicit here so the policy
            // lives with the rest of the encode decision.
            $params['pix_fmt'] = 'yuv420p';
            $params['profile'] = 'high';
            $params['level'] = '4.1';
            if ($needScale) {
                $params['width'] = $maxW;
                $params['height'] = $maxH;
            }
        }

        if ($srcA === 'aac') {
            $params['audio_codec'] = 'copy';
        } else {
            $params['audio_codec'] = 'aac';
            $params['audio_bitrate'] = '128k';
            $params['audio_sample_rate'] = 48000;
            if ($channels > 0) {
                $params['audio_channels'] = min($channels, 6);
            }
        }

        $params['variant_width'] = $targetW > 0 ? $targetW : null;
        $params['variant_height'] = $targetH > 0 ? $targetH : null;
        $params['variant_bandwidth'] = self::estimateBandwidth($targetH > 0 ? $targetH : 720);

        return $params;
    }

    /**
     * Estimates a nominal HLS variant bandwidth (bits/sec) from height.
     *
     * Advisory only — used for the master playlist's BANDWIDTH attribute.
     *
     * @param int $height Variant pixel height.
     *
     * @return int Nominal bandwidth in bits per second.
     */
    private static function estimateBandwidth(int $height): int
    {
        return match (true) {
            $height >= 2160 => 16000000,
            $height >= 1080 => 5000000,
            $height >= 720 => 2800000,
            $height >= 480 => 1100000,
            default => 700000,
        };
    }

    /**
     * Finds a reusable (running or completed) job whose output still exists.
     *
     * @param string $keyHash sha1(media_item_id|profile) reuse key.
     *
     * @return string|null Job id to reuse, or null when none is usable.
     */
    private function findReusableJob(string $keyHash): ?string
    {
        $result = $this->db->query(
            "SELECT id, hls_dir, status FROM transcode_jobs
             WHERE key_hash = ? AND status IN ('running', 'completed')
             ORDER BY started_at DESC LIMIT 1",
            [$keyHash]
        );
        $rows = RowMap::listFromMixed($result);
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $id = is_string($row['id'] ?? null) ? (string) $row['id'] : '';
        $dir = is_string($row['hls_dir'] ?? null) ? (string) $row['hls_dir'] : '';
        if ($id === '' || $dir === '' || !is_dir($dir)) {
            return null;
        }
        return $id;
    }

    /**
     * Counts the running jobs as recorded in the database.
     *
     * @return int Number of jobs with status='running'.
     */
    private function getRunningJobCount(): int
    {
        $result = $this->db->query("SELECT COUNT(*) AS c FROM transcode_jobs WHERE status = 'running'");
        $rows = RowMap::listFromMixed($result);
        $c = $rows[0]['c'] ?? 0;
        return is_numeric($c) ? (int) $c : 0;
    }

    /**
     * Reads a single transcode_jobs row as an associative array.
     *
     * @param string $jobId Job identifier.
     *
     * @return array<string, mixed>|null The row, or null if not found.
     */
    private function getJobRow(string $jobId): ?array
    {
        $result = $this->db->query("SELECT * FROM transcode_jobs WHERE id = ?", [$jobId]);
        $rows = RowMap::listFromMixed($result);
        return $rows[0] ?? null;
    }

    /**
     * Returns the current DB status of a job (defaults to 'running').
     *
     * @param string $jobId Job identifier.
     *
     * @return string Status string.
     */
    private function statusOf(string $jobId): string
    {
        $row = $this->getJobRow($jobId);
        return is_string($row['status'] ?? null) ? (string) $row['status'] : self::STATUS_RUNNING;
    }

    /**
     * Counts produced CMAF media segments in a job directory.
     *
     * @param string $dir Job output directory.
     *
     * @return int Number of chunk-*.m4s files (across all representations).
     */
    private function countSegments(string $dir): int
    {
        $files = glob("{$dir}/chunk-*.m4s");
        return $files === false ? 0 : count($files);
    }

    /**
     * Reads a short failure reason from the job's ffmpeg log.
     *
     * @param string $dir Job HLS directory.
     *
     * @return string Trimmed tail of the ffmpeg log, or a generic message.
     */
    private function readFailureReason(string $dir): string
    {
        $log = "{$dir}/ffmpeg.log";
        if (is_file($log)) {
            $content = file_get_contents($log);
            if (is_string($content) && $content !== '') {
                return substr(trim($content), -500);
            }
        }
        return 'Transcode failed';
    }

    /**
     * Whether an H.264 stream is one browsers can decode via MSE/hls.js.
     *
     * Browser H.264 decoders handle only 8-bit 4:2:0 (Baseline/Main/High). A
     * 10-/12-bit pixel format (e.g. `yuv420p10le`), a 4:2:2/4:4:4 chroma layout,
     * or a "High 10 / High 4:2:2 / High 4:4:4" profile cannot be decoded and must
     * be re-encoded rather than copied. Unknown/empty values are treated as safe
     * to preserve the fast copy path for ordinary 8-bit files (ffprobe reliably
     * reports `pix_fmt` for H.264, so empty only happens when probing failed).
     */
    private static function isBrowserSafeH264(string $pixFmt, string $profile): bool
    {
        $pix = strtolower($pixFmt);
        if (str_contains($pix, '10') || str_contains($pix, '12')) {
            return false; // 10-/12-bit
        }
        if (str_starts_with($pix, 'yuv422') || str_starts_with($pix, 'yuv444')) {
            return false; // non-4:2:0 chroma
        }
        $prof = strtolower($profile);
        foreach (['high 10', 'high 4:2:2', 'high 4:4:4'] as $unsafe) {
            if (str_contains($prof, $unsafe)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the first stream of the given codec type from a raw probe.
     *
     * @param array<string, mixed> $probe Raw ffprobe result.
     * @param string               $type  'video' or 'audio'.
     *
     * @return array<string, mixed> The stream, or an empty array.
     */
    private function firstStreamOfType(array $probe, string $type): array
    {
        $streams = $probe['streams'] ?? [];
        if (!is_array($streams)) {
            return [];
        }
        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === $type) {
                /** @var array<string, mixed> $stream */
                return $stream;
            }
        }
        return [];
    }

    /**
     * Coerces a mixed probe value to a non-negative int (0 when not numeric).
     *
     * @param mixed $value Raw value.
     *
     * @return int Parsed int, or 0.
     */
    private function intVal(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return 0;
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

        // Also reap ghosts recorded only in the DB (see reapStaleRunningJobs).
        $this->reapStaleRunningJobs($maxAgeSeconds);
    }

    /**
     * Reap DB-recorded 'running' jobs that are no longer alive, marking them
     * 'failed' so they stop counting against {@see $maxConcurrentTranscodes}.
     *
     * The concurrency gate counts `status='running'` rows in the DB, but
     * {@see cleanupStaleJobs()} historically only knew about the in-memory
     * {@see $activeJobs} map. When the worker restarts (e.g. after a deploy) that
     * map is empty while the DB still holds the previous run's 'running' rows —
     * ghosts with no live ffmpeg behind them. Enough of them
     * ({@see $maxConcurrentTranscodes}) and every new playback request is refused
     * with "Maximum concurrent transcodes reached", which surfaces to the user as
     * a video that simply will not play. A job is considered dead when:
     *
     *   - its working directory is gone (a live job creates that dir before it
     *     inserts the row, so a 'running' row without one is a corpse), or
     *   - it started more than $maxAgeSeconds ago (a wedged / abandoned encode).
     *
     * @param int $maxAgeSeconds Age after which a still-'running' job is stale.
     *
     * @return int Number of jobs reaped.
     */
    public function reapStaleRunningJobs(int $maxAgeSeconds = 3600): int
    {
        $result = $this->db->query(
            "SELECT id, hls_dir, output_path, started_at FROM transcode_jobs WHERE status = 'running'"
        );
        $rows = RowMap::listFromMixed($result);
        $cutoff = time() - max(0, $maxAgeSeconds);
        $reaped = 0;

        foreach ($rows as $row) {
            $id = is_string($row['id'] ?? null) ? (string) $row['id'] : '';
            if ($id === '') {
                continue;
            }

            $dir = is_string($row['hls_dir'] ?? null) && $row['hls_dir'] !== ''
                ? (string) $row['hls_dir']
                : (is_string($row['output_path'] ?? null) ? dirname((string) $row['output_path']) : '');
            $dirGone = $dir !== '' && !is_dir($dir);

            $startedAt = is_string($row['started_at'] ?? null) ? strtotime((string) $row['started_at']) : false;
            $tooOld = $startedAt !== false && $startedAt < $cutoff;

            if (!$dirGone && !$tooOld) {
                continue;
            }

            $this->db->query(
                "UPDATE transcode_jobs SET status = 'failed', error = ? WHERE id = ? AND status = 'running'",
                [$dirGone ? 'reaped: working directory missing (dead process)' : 'reaped: exceeded max age', $id]
            );
            unset($this->activeJobs[$id]);
            $reaped++;
        }

        if ($reaped > 0) {
            $this->logger->warning('Reaped stale transcode jobs', ['count' => $reaped]);
        }

        return $reaped;
    }

    /**
     * Persist the precise source duration (seconds) probed at transcode time onto
     * the media item's metadata, so the rest of the app has an authoritative
     * length without re-probing. Stored under `duration_seconds` to avoid
     * clobbering TMDB's `runtime` (which is in minutes). Idempotent: a value that
     * is already present is left untouched.
     *
     * @param string               $mediaItemId Media item UUID.
     * @param array<string, mixed> $item        The media_items row (carries metadata_json).
     * @param array<string, mixed> $probe       Raw ffprobe result (expects format.duration).
     */
    private function persistProbedDuration(string $mediaItemId, array $item, array $probe): void
    {
        $format = is_array($probe['format'] ?? null) ? $probe['format'] : [];
        $rawDuration = $format['duration'] ?? null;
        if (!is_numeric($rawDuration)) {
            return;
        }
        $duration = (int) round((float) $rawDuration);
        if ($duration <= 0) {
            return;
        }

        $metaRaw = $item['metadata_json'] ?? null;
        $meta = [];
        if (is_string($metaRaw) && $metaRaw !== '') {
            $decoded = json_decode($metaRaw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($metaRaw)) {
            $meta = $metaRaw;
        }

        if (isset($meta['duration_seconds']) && is_numeric($meta['duration_seconds'])) {
            return;
        }
        $meta['duration_seconds'] = $duration;

        $encoded = json_encode($meta);
        if ($encoded === false) {
            return;
        }

        $this->db->query(
            "UPDATE media_items SET metadata_json = ? WHERE id = ?",
            [$encoded, $mediaItemId]
        );
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
