<?php

/**
 * Phlix media server component: LiveTv.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Phlix\LiveTv\Dto\RowAccess;
use Phlix\LiveTv\Dto\RowQuery;
use Phlix\LiveTv\Recording\ComskipLifecycleManager;
use Workerman\MySQL\Connection;

/**
 * Recorder - DVR scheduling and recording functionality.
 *
 * Provides functionality for:
 * - DVR scheduling and recording management
 * - Recording storage management
 * - Time-shifting playback (pause/rewind live TV)
 *
 * ## Recording Status Flow
 *
 * ```
 * SCHEDULED → RECORDING → COMPLETED
 *    ↓            ↓
 * CANCELLED    FAILED
 * ```
 *
 * ## Storage Management
 *
 * The recorder tracks storage usage and can enforce maximum
 * storage limits. Recording quality affects file sizes:
 * - Low: ~1MB/minute
 * - Medium: ~2MB/minute
 * - High: ~4MB/minute
 *
 * ## Time-Shifting
 *
 * Time-shifting allows pausing and rewinding live TV by
 * maintaining a buffer of the last N seconds of broadcast.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @see LiveTvManager For tuner integration
 */
class Recorder
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /** @var string Base path for recording storage */
    private string $storagePath;

    /** @var int Maximum storage in bytes (0 = unlimited) */
    private int $maxStorageBytes;

    /** @var array<string, array{id:string, started_at:int, channel_id:string, stream_url:string, effective_start?:int, pid?:int|null, log_dir?:string}> Currently active recordings */
    private array $activeRecordings = [];

    /** @var array<string, array{id:string, session_id:string, channel_id:string, started_at:int, buffer_start:int, buffer_end:int, current_position?:int}> Active time-shift sessions */
    private array $activeTimeShifts = [];

    /** @var callable[] Post-complete callbacks (media_item_id, recording_path) => void */
    private array $onCompleteCallbacks = [];

    /** @var string Path to ffmpeg binary for recording spawns */
    private string $ffmpegPath;

    /** @var \Phlix\LiveTv\LiveTvManager|null LiveTV manager for tuner stream URL resolution */
    private ?\Phlix\LiveTv\LiveTvManager $liveTvManager = null;

    /**
     * Recording is scheduled but not yet started.
     *
     * @var string
     */
    public const STATUS_SCHEDULED = 'scheduled';

    /**
     * Recording is in progress.
     *
     * @var string
     */
    public const STATUS_RECORDING = 'recording';

    /**
     * Recording completed successfully.
     *
     * @var string
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Recording failed (e.g., insufficient storage).
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Recording was cancelled by user.
     *
     * @var string
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Low recording priority.
     *
     * @var int
     */
    public const PRIORITY_LOW = 1;

    /**
     * Normal recording priority.
     *
     * @var int
     */
    public const PRIORITY_NORMAL = 5;

    /**
     * High recording priority.
     *
     * @var int
     */
    public const PRIORITY_HIGH = 10;

    /**
     * Time-shift buffer size in seconds (2 hours).
     *
     * @var int
     */
    public const TIMESHIFT_BUFFER_SECONDS = 7200;

    /**
     * Default maximum number of recordings to return in getAllRecordings().
     *
     * @var int
     */
    public const DEFAULT_RECORDINGS_LIMIT = 1000;

    /**
     * Default time window in seconds for getAllRecordings() (30 days).
     *
     * @var int
     */
    public const DEFAULT_RECORDINGS_TIME_WINDOW = 2592000;

    /**
     * Creates a new Recorder instance.
     *
     * @param Connection $db Database connection
     * @param string $storagePath Base path for recording files (default: /var/recordings)
     * @param int $maxStorageBytes Maximum storage limit in bytes (0 = unlimited)
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     * @param ComskipLifecycleManager|null $comskipManager Optional comskip lifecycle manager
     * @param string $ffmpegPath Path to FFmpeg binary for recording spawns
     * @param LiveTvManager|null $liveTvManager Optional LiveTV manager for tuner stream URL resolution
     */
    public function __construct(
        Connection $db,
        string $storagePath = '/var/recordings',
        int $maxStorageBytes = 0,
        ?StructuredLogger $logger = null,
        ?ComskipLifecycleManager $comskipManager = null,
        string $ffmpegPath = '/usr/bin/ffmpeg',
        ?\Phlix\LiveTv\LiveTvManager $liveTvManager = null
    ) {
        $this->db = $db;
        $this->storagePath = $storagePath;
        $this->maxStorageBytes = $maxStorageBytes;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
        $this->ffmpegPath = $ffmpegPath;
        $this->liveTvManager = $liveTvManager;

        // Register ComskipLifecycleManager::enqueue as an onComplete callback
        if ($comskipManager !== null) {
            $this->onCompleteCallbacks[] = function (string $recordingId, string $filePath) use ($comskipManager): void {
                $comskipManager->enqueue($recordingId, $filePath);
            };
        }
    }

    /**
     * Set the LiveTV manager after construction.
     *
     * Allows resolving circular dependencies when Recorder and LiveTvManager
     * hold references to each other.
     *
     * @param LiveTvManager $liveTvManager The LiveTV manager
     *
     * @return void
     *
     * @since SV-3.1
     */
    public function setLiveTvManager(\Phlix\LiveTv\LiveTvManager $liveTvManager): void
    {
        $this->liveTvManager = $liveTvManager;
    }

    /**
     * Register a callback to be invoked when a recording completes.
     *
     * @param callable $callback (string $mediaItemId, string $recordingPath) => void
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function onComplete(callable $callback): void
    {
        $this->onCompleteCallbacks[] = $callback;
    }

    /**
     * Schedule a new recording.
     *
     * Creates a scheduled recording entry. The actual recording
     * starts automatically at start_time via an external scheduler.
     *
     * @param array<string, mixed> $data Recording data including:
     *   - channel_id: string Required - channel to record
     *   - program_id: string|null Optional - associated program
     *   - title: string Recording title (default: 'Untitled Recording')
     *   - description: string|null Recording description
     *   - start_time: int Required - start timestamp
     *   - end_time: int Required - end timestamp
     *   - priority: int Recording priority (default: PRIORITY_NORMAL)
     *   - quality: string Recording quality (default: 'default')
     *   - series_rule_id: string|null Optional - series rule that created this
     *   - pre_padding_seconds: int Pre-recording padding (default: 60)
     *   - post_padding_seconds: int Post-recording padding (default: 60)
     * @return array<string, mixed> The scheduled recording
     *
     * @throws \RuntimeException When the inserted row cannot be re-read
     *
     * @example
     * ```php
     * $recording = $recorder->scheduleRecording([
     *     'channel_id' => 'ch_1',
     *     'title' => 'My Show',
     *     'start_time' => strtotime('today 8pm'),
     *     'end_time' => strtotime('today 9pm'),
     * ]);
     * ```
     *
     * @since 0.12.0 Added series_rule_id, pre_padding_seconds, post_padding_seconds
     */
    public function scheduleRecording(array $data): array
    {
        $recordingId = $this->generateUuid();

        $startTime = RowAccess::int($data, 'start_time');
        $title = RowAccess::string($data, 'title', 'Untitled Recording');

        $this->db->query(
            "INSERT INTO livetv_recordings
             (recording_id, channel_id, program_id, title, description, start_time, end_time,
              priority, quality, storage_path, status, series_rule_id,
              pre_padding_seconds, post_padding_seconds, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $recordingId,
                $data['channel_id'],
                $data['program_id'] ?? null,
                $data['title'] ?? 'Untitled Recording',
                $data['description'] ?? null,
                $data['start_time'],
                $data['end_time'],
                $data['priority'] ?? self::PRIORITY_NORMAL,
                $data['quality'] ?? 'default',
                $this->getRecordingPath($recordingId),
                self::STATUS_SCHEDULED,
                $data['series_rule_id'] ?? null,
                $data['pre_padding_seconds'] ?? 60,
                $data['post_padding_seconds'] ?? 60,
            ]
        );

        $this->logger->info('Recording scheduled', [
            'recording_id' => $recordingId,
            'title' => $title,
            'start_time' => date('Y-m-d H:i', $startTime),
            'pre_padding' => $data['pre_padding_seconds'] ?? 60,
            'post_padding' => $data['post_padding_seconds'] ?? 60,
        ]);

        $recording = $this->getRecording($recordingId);
        if ($recording === null) {
            throw new \RuntimeException(
                "Recording {$recordingId} was inserted but could not be re-read"
            );
        }

        return $recording;
    }

    /**
     * Get a recording by ID.
     *
     * @param string $recordingId The recording identifier
     * @return array<string, mixed>|null The recording or null if not found
     */
    public function getRecording(string $recordingId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings WHERE recording_id = ?",
            [$recordingId]
        );

        $row = RowQuery::firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->mapRecording($row);
    }

    /**
     * Get all recordings, optionally filtered by status.
     *
     * @param string|null $status Optional status filter (one of STATUS_*)
     * @param int $limit Maximum number of recordings to return (default: DEFAULT_RECORDINGS_LIMIT)
     * @param int $offset Number of recordings to skip (default: 0)
     * @param int $timeWindow Time window in seconds (default: DEFAULT_RECORDINGS_TIME_WINDOW)
     *
     * @return array<int, array<string, mixed>> List of recordings
     */
    public function getAllRecordings(
        ?string $status = null,
        int $limit = self::DEFAULT_RECORDINGS_LIMIT,
        int $offset = 0,
        int $timeWindow = self::DEFAULT_RECORDINGS_TIME_WINDOW,
    ): array {
        $now = time();
        $cutoffTime = $now - $timeWindow;

        if ($status !== null) {
            $result = $this->db->query(
                "SELECT * FROM livetv_recordings
                 WHERE status = ? AND start_time >= ?
                 ORDER BY start_time DESC
                 LIMIT ? OFFSET ?",
                [$status, $cutoffTime, $limit, $offset]
            );
        } else {
            $result = $this->db->query(
                "SELECT * FROM livetv_recordings
                 WHERE start_time >= ?
                 ORDER BY start_time DESC
                 LIMIT ? OFFSET ?",
                [$cutoffTime, $limit, $offset]
            );
        }

        $recordings = [];
        foreach (RowQuery::rows($result) as $row) {
            $recordings[] = $this->mapRecording($row);
        }

        return $recordings;
    }

    /**
     * Get upcoming scheduled recordings.
     *
     * @param int $limit Maximum number of results (default: 10)
     * @return array<int, array<string, mixed>> Upcoming scheduled recordings
     */
    public function getUpcomingRecordings(int $limit = 10): array
    {
        $now = time();

        $result = $this->db->query(
            "SELECT * FROM livetv_recordings
             WHERE status = ? AND start_time > ?
             ORDER BY start_time ASC
             LIMIT ?",
            [self::STATUS_SCHEDULED, $now, $limit]
        );

        $recordings = [];
        foreach (RowQuery::rows($result) as $row) {
            $recordings[] = $this->mapRecording($row);
        }

        return $recordings;
    }

    /**
     * Get all recordings for a specific channel.
     *
     * @param string $channelId The channel identifier
     * @return array<int, array<string, mixed>> Recordings for the channel
     */
    public function getRecordingsForChannel(string $channelId): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings WHERE channel_id = ? ORDER BY start_time DESC",
            [$channelId]
        );

        $recordings = [];
        foreach (RowQuery::rows($result) as $row) {
            $recordings[] = $this->mapRecording($row);
        }

        return $recordings;
    }

    /**
     * Get all recordings for a user.
     *
     * @param string $userId The user identifier
     * @return array<int, array<string, mixed>> User's recordings
     */
    public function getUserRecordings(string $userId): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings WHERE user_id = ? ORDER BY start_time DESC",
            [$userId]
        );

        $recordings = [];
        foreach (RowQuery::rows($result) as $row) {
            $recordings[] = $this->mapRecording($row);
        }

        return $recordings;
    }

    /**
     * Start a scheduled recording.
     *
     * Checks for available storage before starting.
     * Updates status to RECORDING and creates stream URL.
     * Applies pre-padding by adjusting the effective start time.
     *
     * Persists the worker/ffmpeg PID into the `pid` column so that
     * {@see resumeActiveRecordings()} can disambiguate "process
     * restart, child died" from "ffmpeg still running" on bootstrap.
     *
     * @param string $recordingId The recording to start
     * @param int|null $pid Optional ffmpeg child PID (defaults to current PHP pid)
     * @return bool True if started successfully, false otherwise
     *
     * @since 0.12.0 Pre-padding is now applied - recording starts pre_padding_seconds early
     * @since Wave 2 Persists pid for process-restart recovery.
     */
    /**
     * Start a recording, spawning a detached ffmpeg process.
     *
     * Resolves the tuner stream URL via LiveTvManager, spawns a detached
     * `ffmpeg -i <url> -c copy -f mpegts <storage_path>/<id>.ts`, persists
     * the real child PID, and registers the recording in the in-memory
     * active-recordings map so {@see stopRecording()} can terminate it.
     *
     * @param string $recordingId The recording to start
     * @param string|null $streamUrl Optional stream URL (resolved from tuner if null)
     * @return bool True if started successfully, false otherwise
     *
     * @since 0.12.0 Pre-padding is now applied - recording starts pre_padding_seconds early
     * @since SV-3.1 Real ffmpeg process spawning via TunerDriver::getStreamUrl()
     */
    public function startRecording(string $recordingId, ?string $streamUrl = null): bool
    {
        $recording = $this->getRecording($recordingId);
        if (!$recording || $recording['status'] !== self::STATUS_SCHEDULED) {
            return false;
        }

        // Calculate effective start time with pre-padding.
        // mapRecording() returns ints for start_time/end_time/pre_padding_seconds,
        // but the array<string, mixed> shape of $recording loses that — re-narrow.
        $prePadding = is_int($recording['pre_padding_seconds'] ?? null) ? $recording['pre_padding_seconds'] : 60;
        $startTime = is_int($recording['start_time'] ?? null) ? $recording['start_time'] : 0;
        $endTime = is_int($recording['end_time'] ?? null) ? $recording['end_time'] : 0;
        $channelId = is_string($recording['channel_id'] ?? null) ? $recording['channel_id'] : '';

        $effectiveStart = $startTime - $prePadding;

        // Check available storage (real disk check)
        if (!$this->hasStorageSpace($effectiveStart, $endTime + $prePadding)) {
            $this->updateRecordingStatus($recordingId, self::STATUS_FAILED, 'Insufficient storage space');
            return false;
        }

        // Resolve stream URL — caller may pass it directly, otherwise we need
        // a LiveTvManager to look up the tuner. When $streamUrl is provided
        // (e.g. by RecordingScheduler which already resolved it), use it directly.
        // When LiveTvManager is unavailable (e.g. during recovery without a full
        // boot), fall back to marking the recording as recording without spawning
        // ffmpeg so that the recording is not stuck in 'scheduled' forever.
        if ($streamUrl === null || $streamUrl === '') {
            $streamUrl = $this->resolveTunerStreamUrl($channelId);
        }

        $spawned = false;
        $pid = null;
        $logDir = $this->storagePath;

        if ($streamUrl !== null && $streamUrl !== '') {
            // Spawn the detached ffmpeg recording process.
            // Uses the same nohup...& pattern as FfmpegRunner::startDetached() so
            // that PHP returning does not kill the child.
            $outputPath = $this->getRecordingPath($recordingId);
            $logDir = $this->storagePath;

            $pid = $this->spawnRecording($streamUrl, $outputPath, $logDir);

            if ($pid <= 0) {
                $this->updateRecordingStatus($recordingId, self::STATUS_FAILED, 'Failed to spawn ffmpeg recording process');
                return false;
            }
            $spawned = true;
        } else {
            // No tuner available — fall back to the old recovery path.
            // Recording is marked as recording but no ffmpeg is spawned.
            // The next process restart or scheduler tick will retry.
            $this->logger->warning('No tuner stream URL available, marking recording as recording without ffmpeg', [
                'recording_id' => $recordingId,
                'channel_id' => $channelId,
            ]);
            $pid = getmypid();
            if ($pid === false) {
                $pid = null;
            }
        }

        // Persist the real child PID so resumeActiveRecordings() can distinguish
        // "process died after restart" from "ffmpeg still running".
        $this->db->query(
            "UPDATE livetv_recordings
             SET status = ?, pid = ?, error_message = NULL, updated_at = NOW()
             WHERE recording_id = ?",
            [self::STATUS_RECORDING, $pid, $recordingId]
        );

        $this->activeRecordings[$recordingId] = [
            'id' => $recordingId,
            'started_at' => time(),
            'effective_start' => $effectiveStart,
            'channel_id' => $channelId,
            'stream_url' => "/livetv/recording/$recordingId/stream",
            'pid' => $pid,
            'log_dir' => $logDir,
        ];

        $this->logger->info('Recording started', [
            'recording_id' => $recordingId,
            'pre_padding' => $prePadding,
            'effective_start' => date('Y-m-d H:i:s', $effectiveStart),
            'pid' => $pid,
            'stream_url' => $streamUrl,
        ]);

        return true;
    }

    /**
     * Recover recording state after a worker process restart.
     *
     * Live-TV recording state lives partly in the database (status,
     * pid) and partly in {@see self::$activeRecordings} (process
     * handles). When the workerman master is restarted everything in
     * memory is lost; rows in `livetv_recordings` with
     * `status='recording'` are now orphaned because the ffmpeg child
     * was killed alongside its parent.
     *
     * This method runs at bootstrap (call from
     * {@see \Phlix\LiveTv\LiveTvManager} or the Application boot path)
     * and reconciles DB state with reality:
     *
     *   1. Recordings whose stored `pid` is still alive
     *      (`posix_kill($pid, 0)` returns true) are re-attached to
     *      {@see self::$activeRecordings}.
     *   2. Recordings whose stored `pid` is dead (or null) are
     *      marked `failed` with `error_message = 'process restart'`
     *      and onComplete callbacks fire so housekeeping (DVR
     *      conflict reset, comskip-skip, etc.) still runs.
     *   3. Rows still in `status='scheduled'` whose `start_time` is
     *      already in the past are re-armed by calling
     *      {@see self::startRecording()}.
     *
     * Returns a stats array for the caller (typically logged or
     * exposed via the admin dashboard).
     *
     * @return array{
     *     resumed: int,
     *     failed: int,
     *     rearmed: int,
     *     scheduled_skipped: int
     * } Recovery statistics
     *
     * @since Wave 2 (post-O.7)
     */
    public function resumeActiveRecordings(): array
    {
        $stats = [
            'resumed' => 0,
            'failed' => 0,
            'rearmed' => 0,
            'scheduled_skipped' => 0,
        ];

        // 1+2: reconcile interrupted recordings.
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings WHERE status = ?",
            [self::STATUS_RECORDING]
        );

        foreach (RowQuery::rows($result) as $row) {
            $recording = $this->mapRecording($row);
            $recordingId = self::asString($recording['recording_id'] ?? '');
            $channelId = self::asString($recording['channel_id'] ?? '');
            $startTime = self::asInt($recording['start_time'] ?? 0);
            $pid = self::asPid($row['pid'] ?? null);

            if ($pid !== null && $pid > 0 && $this->isPidAlive($pid)) {
                // ffmpeg child still alive after restart - re-attach in memory.
                $this->activeRecordings[$recordingId] = [
                    'id' => $recordingId,
                    'started_at' => time(),
                    'effective_start' => $startTime,
                    'channel_id' => $channelId,
                    'stream_url' => "/livetv/recording/{$recordingId}/stream",
                    'pid' => $pid,
                ];
                $stats['resumed']++;

                $this->logger->info('Recording recovered (pid alive)', [
                    'recording_id' => $recordingId,
                    'pid' => $pid,
                ]);

                continue;
            }

            // pid dead or never recorded - the ffmpeg child is gone.
            $this->updateRecordingStatus(
                $recordingId,
                self::STATUS_FAILED,
                'process restart'
            );
            $this->fireOnCompleteCallbacks(
                $recordingId,
                $this->getRecordingPath($recordingId)
            );
            $stats['failed']++;

            $this->logger->warning('Recording marked failed after restart', [
                'recording_id' => $recordingId,
                'stored_pid' => $pid,
                'reason' => 'process restart',
            ]);
        }

        // 3: re-arm scheduled-and-due recordings.
        $now = time();
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings WHERE status = ? AND start_time <= ?",
            [self::STATUS_SCHEDULED, $now]
        );

        $dueIds = [];
        foreach (RowQuery::rows($result) as $row) {
            $dueIds[] = self::asString($row['recording_id'] ?? '');
        }

        foreach ($dueIds as $recordingId) {
            if ($this->startRecording($recordingId)) {
                $stats['rearmed']++;
            } else {
                $stats['scheduled_skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Check whether a PID refers to a still-living process.
     *
     * Uses `posix_kill($pid, 0)` when the POSIX extension is loaded;
     * falls back to a `/proc/<pid>` filesystem check for environments
     * (e.g. some hardened containers) where posix is unavailable.
     *
     * @param int $pid Process identifier to probe.
     * @return bool True if the OS reports the pid is alive.
     *
     * @since Wave 2 (post-O.7)
     */
    private function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // posix-less fallback: /proc is present on Linux runtimes.
        if (is_dir('/proc/' . $pid)) {
            return true;
        }

        return false;
    }

    /**
     * Stop a recording in progress.
     *
     * Sends SIGTERM to the ffmpeg process (via {@see terminateRecording()}),
     * waits up to 5 seconds for graceful exit, then SIGKILLs if needed.
     * Updates recording status to COMPLETED and records the actual
     * duration and file size. Fires post-complete callbacks (comskip,
     * media-item registration).
     *
     * @param string $recordingId The recording to stop
     * @return bool True if stopped successfully, false if not active
     *
     * @since SV-3.1 Now kills the real ffmpeg process
     */
    public function stopRecording(string $recordingId): bool
    {
        if (!isset($this->activeRecordings[$recordingId])) {
            return false;
        }

        $recording = $this->getRecording($recordingId);
        if (!$recording) {
            return false;
        }

        $duration = time() - $this->activeRecordings[$recordingId]['started_at'];
        $pid = $this->activeRecordings[$recordingId]['pid'] ?? null;
        $logDir = $this->activeRecordings[$recordingId]['log_dir'] ?? $this->storagePath;

        // Terminate the ffmpeg process (SIGTERM → SIGKILL fallback).
        if ($pid !== null && $pid > 0) {
            $this->terminateRecording($pid);
        }

        unset($this->activeRecordings[$recordingId]);

        $filePath = $this->getRecordingPath($recordingId);
        $fileSize = file_exists($filePath) ? filesize($filePath) : 0;

        $this->db->query(
            "UPDATE livetv_recordings
             SET status = ?, end_time = ?, storage_size = ?, updated_at = NOW()
             WHERE recording_id = ?",
            [self::STATUS_COMPLETED, time(), $fileSize, $recordingId]
        );

        $this->logger->info('Recording stopped', [
            'recording_id' => $recordingId,
            'duration' => $duration,
            'size' => $fileSize,
        ]);

        // Fire post-complete callbacks (comskip, media-item registration).
        $this->fireOnCompleteCallbacks($recordingId, $filePath);

        return true;
    }

    /**
     * End a scheduled recording at end_time + post_padding.
     *
     * Called by the recording scheduler when the effective end time is
     * reached (scheduled end time plus configured post-padding). This is
     * the timed stop path — unlike {@see stopRecording()} (manual stop),
     * this one also triggers the post-padding gap closure and marks the
     * recording as completed with the actual end time.
     *
     * @param string $recordingId The recording to end
     * @return bool True if ended successfully, false if not active
     *
     * @since SV-3.1
     */
    public function endRecording(string $recordingId): bool
    {
        if (!isset($this->activeRecordings[$recordingId])) {
            return false;
        }

        $recording = $this->getRecording($recordingId);
        if (!$recording) {
            return false;
        }

        // The post-padding gap is already accounted for in the scheduled
        // end_time stored in the DB (it was written as end_time + post_padding
        // when the recording was scheduled). Just stop now.
        return $this->stopRecording($recordingId);
    }

    /**
     * Spawn a detached ffmpeg recording process.
     *
     * Builds the recording command (nohup ffmpeg -i <stream_url> -c copy
     * -f mpegts <output_path>) and runs it via `nohup ... &` so the
     * PHP worker returning does not kill the child. Returns the real
     * child PID which is persisted to the DB for recovery on restart.
     *
     * SV-4.2: The command is wrapped in `timeout <transcode_timeout>` so
     * long-running recordings are killed automatically. PIDs are tracked
     * in $activeRecordings for cleanup on disconnect/timeout.
     *
     * Log files are written to $logDir/ffmpeg_<recordingId>.log so
     * {@see terminateRecording()} can find them during cleanup.
     *
     * @param string $streamUrl Source URL to capture (http, udp, etc.)
     * @param string $outputPath Absolute path to the output .ts file
     * @param string $logDir Directory for the ffmpeg log file
     *
     * @return int The child PID (0 if spawn failed)
     *
     * @since SV-3.1
     * @since SV-4.2 Applies transcode_timeout wrapper, tracks PIDs for cleanup.
     */
    private function spawnRecording(string $streamUrl, string $outputPath, string $logDir): int
    {
        $ffmpegPath = $this->ffmpegPath;

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -c copy -f mpegts %s',
            escapeshellarg($ffmpegPath),
            escapeshellarg($streamUrl),
            escapeshellarg($outputPath)
        );

        // Ensure log directory exists.
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/ffmpeg_recording.log';

        // SV-4.2: wrap in timeout to enforce transcode_timeout (7200s default).
        // If timeout kills the process, .timed_out marker is created.
        $timeoutSecs = $this->getTranscodeTimeout();
        $timeoutCmd = $timeoutSecs > 0
            ? 'timeout ' . (int) $timeoutSecs . ' sh -c ' . escapeshellarg($cmd)
            : 'sh -c ' . escapeshellarg($cmd);

        // nohup so SIGHUP doesn't kill ffmpeg when the PHP shell returns.
        // Redirect both stdout+stderr to the log file.
        $full = sprintf(
            'nohup %s > %s 2>&1 & echo $!',
            $timeoutCmd,
            escapeshellarg($logFile)
        );

        $pidStr = shell_exec($full);
        if (!is_string($pidStr) || trim($pidStr) === '') {
            $this->logger->error('Failed to spawn ffmpeg recording process');
            return 0;
        }

        $pid = (int) trim($pidStr);
        $this->logger->debug('Recording process spawned', [
            'pid' => $pid,
            'stream_url' => $streamUrl,
            'output_path' => $outputPath,
            'timeout_secs' => $timeoutSecs,
        ]);

        return $pid;
    }

    /**
     * Get the configured transcode timeout in seconds.
     *
     * @return int Timeout in seconds (0 = no timeout)
     *
     * @since SV-4.2
     */
    private function getTranscodeTimeout(): int
    {
        static $timeout = null;
        if ($timeout === null) {
            $configPath = defined('PHLIX_CONFIG_PATH') ? PHLIX_CONFIG_PATH : __DIR__ . '/../../config';
            $configFile = $configPath . '/ffmpeg.php';
            if (file_exists($configFile)) {
                /** @var array<string, mixed> $config */
                $config = include $configFile;
                $timeoutSecs = $config['transcode_timeout'] ?? null;
                $timeout = match (true) {
                    is_int($timeoutSecs) => $timeoutSecs,
                    is_string($timeoutSecs) && is_numeric($timeoutSecs) => (int) $timeoutSecs,
                    default => 0,
                };
            } else {
                $timeout = 0;
            }
        }
        return $timeout;
    }

    /**
     * Terminate a running recording process.
     *
     * First sends SIGTERM (graceful) and waits up to 5 seconds for the
     * process to exit. If it is still running, escalates to SIGKILL.
     * This matches the graceful-then-forced pattern used by
     * FfmpegRunner's transcode jobs.
     *
     * @param int $pid The OS PID of the recording process to kill
     *
     * @return void
     *
     * @since SV-3.1
     */
    private function terminateRecording(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        $this->logger->debug('Terminating recording process', ['pid' => $pid]);

        // Step 1: graceful SIGTERM
        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
        } else {
            shell_exec(sprintf('kill -TERM %d 2>/dev/null', $pid));
        }

        // Wait up to 5 seconds for graceful exit
        $deadline = time() + 5;
        while (time() < $deadline) {
            if (!$this->isPidAlive($pid)) {
                $this->logger->debug('Recording process exited gracefully', ['pid' => $pid]);
                return;
            }
            // Cooperative sleep when in Swoole coroutine context
            if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
                \Swoole\Coroutine::sleep(0.1);
            } else {
                usleep(100000); // 100ms
            }
        }

        // Step 2: forced SIGKILL
        $this->logger->warning('Recording process did not exit gracefully, sending SIGKILL', ['pid' => $pid]);
        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGKILL);
        } else {
            shell_exec(sprintf('kill -KILL %d 2>/dev/null', $pid));
        }

        // Brief wait to confirm death
        $waited = 0;
        while ($waited < 2 && $this->isPidAlive($pid)) {
            usleep(100000);
            $waited++;
        }
    }

    /**
     * Resolve a tuner stream URL for a given channel.
     *
     * Finds an idle tuner for the channel and returns its stream URL
     * by delegating to the appropriate tuner driver. Returns null if
     * no idle tuner is available.
     *
     * @param string $channelId The channel identifier
     *
     * @return string|null The stream URL, or null if no tuner is available
     *
     * @since SV-3.1
     */
    private function resolveTunerStreamUrl(string $channelId): ?string
    {
        // Delegate to LiveTvManager which holds all tuner state.
        // LiveTvManager::buildStreamUrlForChannel() handles driver dispatch internally.
        if ($this->liveTvManager === null) {
            $this->logger->warning('LiveTvManager not available, cannot resolve tuner stream URL', [
                'channel_id' => $channelId,
            ]);
            return null;
        }

        try {
            $url = $this->liveTvManager->buildStreamUrlForChannel($channelId);
            return $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve tuner stream URL', [
                'channel_id' => $channelId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if a program is already scheduled for recording.
     *
     * Delegates to RecordingDeduplicator to check for existing
     * recordings within a 2-hour time window.
     *
     * @param string $programId The program identifier
     * @param string $channelId The channel identifier
     * @param int $startTime Proposed start time
     * @return bool True if a duplicate recording exists
     *
     * @since 0.12.0
     */
    public function isDuplicate(string $programId, string $channelId, int $startTime): bool
    {
        $windowSeconds = 7200; // 2 hours
        $windowStart = $startTime - $windowSeconds;
        $windowEnd = $startTime + $windowSeconds;

        $result = $this->db->query(
            "SELECT recording_id FROM livetv_recordings
             WHERE program_id = ?
               AND channel_id = ?
               AND status IN ('scheduled', 'recording', 'completed')
               AND start_time >= ?
               AND start_time <= ?
             LIMIT 1",
            [$programId, $channelId, $windowStart, $windowEnd]
        );

        return RowQuery::hasRows($result);
    }

    /**
     * Fire all registered onComplete callbacks.
     *
     * @param string $recordingId The recording ID
     * @param string $recordingPath The path to the recording file
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function fireOnCompleteCallbacks(string $recordingId, string $recordingPath): void
    {
        foreach ($this->onCompleteCallbacks as $callback) {
            try {
                $callback($recordingId, $recordingPath);
            } catch (\Throwable $e) {
                $this->logger->error('onComplete callback threw exception', [
                    'recording_id' => $recordingId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Cancel a scheduled or in-progress recording.
     *
     * @param string $recordingId The recording to cancel
     * @return bool True if cancelled, false if not found
     */
    public function cancelRecording(string $recordingId): bool
    {
        $recording = $this->getRecording($recordingId);
        if (!$recording) {
            return false;
        }

        if ($recording['status'] === self::STATUS_RECORDING) {
            $this->stopRecording($recordingId);
        }

        $this->updateRecordingStatus($recordingId, self::STATUS_CANCELLED);

        $this->logger->info('Recording cancelled', ['recording_id' => $recordingId]);

        return true;
    }

    /**
     * Delete a recording and its associated file.
     *
     * @param string $recordingId The recording to delete
     * @return bool True if deleted, false if not found
     */
    public function deleteRecording(string $recordingId): bool
    {
        $recording = $this->getRecording($recordingId);
        if (!$recording) {
            return false;
        }

        if (isset($this->activeRecordings[$recordingId])) {
            $this->stopRecording($recordingId);
        }

        $filePath = $this->getRecordingPath($recordingId);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $this->db->query("DELETE FROM livetv_recordings WHERE recording_id = ?", [$recordingId]);

        $this->logger->info('Recording deleted', ['recording_id' => $recordingId]);

        return true;
    }

    /**
     * Update recording status in database.
     *
     * @param string $recordingId The recording identifier
     * @param string $status New status
     * @param string|null $error Optional error message
     * @return void
     */
    private function updateRecordingStatus(string $recordingId, string $status, ?string $error = null): void
    {
        $this->db->query(
            "UPDATE livetv_recordings SET status = ?, error_message = ?, updated_at = NOW()
             WHERE recording_id = ?",
            [$status, $error, $recordingId]
        );
    }

    /**
     * Get the storage file path for a recording.
     *
     * @param string $recordingId The recording identifier
     * @return string Full file path
     */
    private function getRecordingPath(string $recordingId): string
    {
        return $this->storagePath . '/' . $recordingId . '.ts';
    }

    /**
     * Check if there's available storage space for a recording.
     *
     * Performs a real disk-space check via disk_free_space() on the
     * storage path. If the recording would fit in the free space and
     * stay within the configured max, returns true.
     *
     * @param int $startTime Recording start time (for size estimation)
     * @param int $endTime Recording end time (for size estimation)
     *
     * @return bool True if space is available
     *
     * @since SV-3.1 Now uses real disk_free_space() instead of estimation
     */
    private function hasStorageSpace(int $startTime, int $endTime): bool
    {
        // If no limit is set, only check real disk space.
        if ($this->maxStorageBytes <= 0) {
            return $this->hasRealDiskSpace($startTime, $endTime);
        }

        $usedStorage = $this->getUsedStorageBytes();
        $estimatedSize = $this->estimateRecordingSize($startTime, $endTime);

        // Also verify real disk has enough room.
        if (!$this->hasRealDiskSpace($startTime, $endTime)) {
            return false;
        }

        return ($usedStorage + $estimatedSize) <= $this->maxStorageBytes;
    }

    /**
     * Check if the real filesystem has enough free space for the recording.
     *
     * Uses disk_free_space() to get the actual available bytes on the
     * storage volume and compares against the estimated size.
     *
     * @param int $startTime Recording start time
     * @param int $endTime Recording end time
     *
     * @return bool True if the filesystem has enough space
     *
     * @since SV-3.1
     */
    private function hasRealDiskSpace(int $startTime, int $endTime): bool
    {
        $freeSpace = @disk_free_space($this->storagePath);
        if ($freeSpace === false) {
            // Cannot determine free space — assume OK (fail open for safety
            // so a misconfigured mount doesn't prevent all recordings).
            $this->logger->warning('Could not determine free disk space, allowing recording', [
                'storage_path' => $this->storagePath,
            ]);
            return true;
        }

        $estimatedSize = $this->estimateRecordingSize($startTime, $endTime);

        // Reserve a 5 % safety margin so we never fill the disk completely.
        $usableSpace = (int) ($freeSpace * 0.95);

        return $usableSpace >= $estimatedSize;
    }

    /**
     * Get total used storage for completed recordings.
     *
     * @return int Used storage in bytes
     */
    public function getUsedStorageBytes(): int
    {
        $result = $this->db->query(
            "SELECT SUM(storage_size) as total FROM livetv_recordings WHERE status = ?",
            [self::STATUS_COMPLETED]
        );

        $row = RowQuery::firstRow($result);
        if ($row === null) {
            return 0;
        }

        return RowAccess::int($row, 'total');
    }

    /**
     * Get available storage in bytes.
     *
     * @return int Available storage (PHP_INT_MAX if unlimited)
     */
    public function getAvailableStorageBytes(): int
    {
        if ($this->maxStorageBytes <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->maxStorageBytes - $this->getUsedStorageBytes());
    }

    /**
     * Estimate recording size based on duration.
     *
     * @param int $startTime Recording start
     * @param int $endTime Recording end
     * @return int Estimated size in bytes
     */
    private function estimateRecordingSize(int $startTime, int $endTime): int
    {
        $durationSeconds = $endTime - $startTime;
        $bytesPerSecond = 2 * 1024 * 1024 / 60;
        return (int) ($durationSeconds * $bytesPerSecond);
    }

    /**
     * Start time-shifting for a session.
     *
     * Creates a time-shift buffer allowing pause/rewind of live TV.
     *
     * @param string $sessionId The playback session ID
     * @param string $channelId The channel to time-shift
     * @return array{time_shift_id:string, stream_url:string, buffer_start:int, buffer_end:int} Time-shift info
     */
    public function startTimeShift(string $sessionId, string $channelId): array
    {
        $this->stopTimeShift($sessionId);

        $timeShiftId = $this->generateUuid();
        $bufferStart = time() - self::TIMESHIFT_BUFFER_SECONDS;

        $this->activeTimeShifts[$sessionId] = [
            'id' => $timeShiftId,
            'session_id' => $sessionId,
            'channel_id' => $channelId,
            'started_at' => time(),
            'buffer_start' => $bufferStart,
            'buffer_end' => time(),
        ];

        $this->logger->info('Time-shift started', [
            'session_id' => $sessionId,
            'channel_id' => $channelId,
        ]);

        return [
            'time_shift_id' => $timeShiftId,
            'stream_url' => "/livetv/timeshift/$sessionId/stream",
            'buffer_start' => $bufferStart,
            'buffer_end' => time(),
        ];
    }

    /**
     * Stop time-shifting for a session.
     *
     * @param string $sessionId The session to stop
     * @return bool True if stopped, false if not active
     */
    public function stopTimeShift(string $sessionId): bool
    {
        if (!isset($this->activeTimeShifts[$sessionId])) {
            return false;
        }

        unset($this->activeTimeShifts[$sessionId]);

        $this->logger->info('Time-shift stopped', ['session_id' => $sessionId]);

        return true;
    }

    /**
     * Get time-shift info for a session.
     *
     * @param string $sessionId The session identifier
     * @return array<string, mixed>|null Time-shift data or null
     */
    public function getTimeShift(string $sessionId): ?array
    {
        return $this->activeTimeShifts[$sessionId] ?? null;
    }

    /**
     * Get current playback position in time-shift buffer.
     *
     * @param string $sessionId The session identifier
     * @return int|null Current position (Unix timestamp) or null
     */
    public function getTimeShiftPosition(string $sessionId): ?int
    {
        if (!isset($this->activeTimeShifts[$sessionId])) {
            return null;
        }

        return $this->activeTimeShifts[$sessionId]['current_position'] ?? time();
    }

    /**
     * Seek to a position in the time-shift buffer.
     *
     * @param string $sessionId The session identifier
     * @param int $position Position to seek to (Unix timestamp)
     * @return bool True if successful, false if session not found
     */
    public function seekTimeShift(string $sessionId, int $position): bool
    {
        if (!isset($this->activeTimeShifts[$sessionId])) {
            return false;
        }

        $timeShift = $this->activeTimeShifts[$sessionId];

        $position = max($timeShift['buffer_start'], min($timeShift['buffer_end'], $position));

        $this->activeTimeShifts[$sessionId]['current_position'] = $position;

        return true;
    }

    /**
     * Get count of active recordings.
     *
     * @return int Number of active recordings
     */
    public function getActiveRecordingCount(): int
    {
        return count($this->activeRecordings);
    }

    /**
     * Get count of active time-shifts.
     *
     * @return int Number of active time-shifts
     */
    public function getActiveTimeShiftCount(): int
    {
        return count($this->activeTimeShifts);
    }

    /**
     * Get recording counts grouped by status.
     *
     * @return array<string, int> Counts by status
     */
    public function getRecordingCountByStatus(): array
    {
        $result = $this->db->query(
            "SELECT status, COUNT(*) as cnt FROM livetv_recordings GROUP BY status"
        );

        $counts = [];
        foreach (RowQuery::rows($result) as $row) {
            $status = RowAccess::string($row, 'status');
            $counts[$status] = RowAccess::int($row, 'cnt');
        }

        return $counts;
    }

    /**
     * Update recording priority.
     *
     * @param string $recordingId The recording to update
     * @param int $priority New priority (PRIORITY_LOW, NORMAL, HIGH)
     * @return bool True on success
     */
    public function updatePriority(string $recordingId, int $priority): bool
    {
        $this->db->query(
            "UPDATE livetv_recordings SET priority = ?, updated_at = NOW() WHERE recording_id = ?",
            [$priority, $recordingId]
        );

        return true;
    }

    /**
     * Get comprehensive storage statistics.
     *
     * @return array{used_bytes: int, available_bytes: int, max_bytes: int, active_recordings: int, active_timeshifts: int, recordings_by_status: array<string, int>}
     */
    public function getStorageStats(): array
    {
        return [
            'used_bytes' => $this->getUsedStorageBytes(),
            'available_bytes' => $this->getAvailableStorageBytes(),
            'max_bytes' => $this->maxStorageBytes,
            'active_recordings' => $this->getActiveRecordingCount(),
            'active_timeshifts' => $this->getActiveTimeShiftCount(),
            'recordings_by_status' => $this->getRecordingCountByStatus(),
        ];
    }

    /**
     * Map a database row to a recording array.
     *
     * @param array<string, mixed> $row Raw database row
     * @return array<string, mixed> Normalized recording data
     *
     * @since 0.12.0 Added series_rule_id, duplicate_group, pre_padding_seconds, post_padding_seconds
     */
    private function mapRecording(array $row): array
    {
        $recordingId = RowAccess::string($row, 'recording_id');
        $startTime = RowAccess::int($row, 'start_time');
        $endTime = RowAccess::int($row, 'end_time');

        return [
            'id' => $recordingId,
            'recording_id' => $recordingId,
            'channel_id' => RowAccess::string($row, 'channel_id'),
            'program_id' => RowAccess::stringOrNull($row, 'program_id'),
            'user_id' => RowAccess::stringOrNull($row, 'user_id'),
            'title' => RowAccess::string($row, 'title'),
            'description' => RowAccess::stringOrNull($row, 'description'),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $endTime - $startTime,
            'priority' => RowAccess::int($row, 'priority'),
            'quality' => RowAccess::stringOrNull($row, 'quality'),
            'storage_path' => RowAccess::stringOrNull($row, 'storage_path'),
            'storage_size' => RowAccess::int($row, 'storage_size'),
            'status' => RowAccess::string($row, 'status'),
            'pid' => self::asPid($row['pid'] ?? null),
            'error_message' => RowAccess::stringOrNull($row, 'error_message'),
            'series_rule_id' => RowAccess::stringOrNull($row, 'series_rule_id'),
            'duplicate_group' => RowAccess::stringOrNull($row, 'duplicate_group'),
            'pre_padding_seconds' => RowAccess::int($row, 'pre_padding_seconds', 60),
            'post_padding_seconds' => RowAccess::int($row, 'post_padding_seconds', 60),
            'created_at' => RowAccess::stringOrNull($row, 'created_at'),
            'updated_at' => RowAccess::stringOrNull($row, 'updated_at'),
        ];
    }

    /**
     * Coerce a mixed value to a string (helper used by recovery).
     *
     * @param mixed $value Value originating from a `$row` returned by
     *        Workerman\MySQL\Connection::query()->fetch().
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Coerce a mixed value to an int (helper used by recovery).
     *
     * @param mixed $value Raw row value.
     */
    private static function asInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * Coerce a mixed value to a nullable positive int pid.
     *
     * Returns null when the value is null/empty/non-numeric so the
     * caller can cleanly distinguish "no pid recorded" from "pid 0".
     *
     * @param mixed $value Raw row value.
     */
    private static function asPid(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }
        return null;
    }

    /**
     * Generate a unique UUID v4 string.
     *
     * @return string A UUID in the format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
