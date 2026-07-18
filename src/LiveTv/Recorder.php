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
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use Phlix\LiveTv\TimeShift\TimeShiftSession;
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

    /** @var array<string, array{id:string, session_id:string, channel_id:string, started_at:int, buffer_start:int, buffer_end:int, buffer_dir?:string, pid?:int|null, current_position?:int}> Active time-shift sessions */
    private array $activeTimeShifts = [];

    /** @var DbTimeShiftSessionStore Cross-worker DB-backed time-shift session store (SV-3.1 f-b) */
    private DbTimeShiftSessionStore $timeShiftStore;

    /** @var callable[] Post-complete callbacks (media_item_id, recording_path) => void */
    private array $onCompleteCallbacks = [];

    /**
     * Stop hooks fired on ANY terminal transition of a recording — a timed
     * stop, a manual stop, a cancel, or a delete. Unlike {@see $onCompleteCallbacks}
     * (which carry "completed successfully" semantics and drive comskip /
     * media-item registration) these are purely for stop-time housekeeping —
     * chiefly cancelling the {@see \Phlix\LiveTv\Recording\RecordingScheduler}'s
     * per-recording one-shot stop timer so its count returns to baseline on a
     * manual stop. Consumers MUST be idempotent (a hook may fire more than once).
     *
     * @var callable[] (string $recordingId) => void
     */
    private array $onStopCallbacks = [];

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
     * Target duration of each rolling time-shift HLS segment, in seconds.
     *
     * The rolling window keeps roughly {@see TIMESHIFT_BUFFER_SECONDS} of
     * broadcast on disk as `ceil(TIMESHIFT_BUFFER_SECONDS / TIMESHIFT_SEGMENT_SECONDS)`
     * segments; ffmpeg auto-prunes older ones via `hls_flags delete_segments`,
     * so no separate prune Timer is needed.
     *
     * @var int
     */
    public const TIMESHIFT_SEGMENT_SECONDS = 6;

    /**
     * Filename of the rolling time-shift HLS media playlist inside a session's
     * buffer directory. The stream controller (SV-3.1 f-c) serves this playlist
     * plus its `seg_*.ts` segments.
     *
     * @var string
     */
    public const TIMESHIFT_PLAYLIST_NAME = 'buffer.m3u8';

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
     * @param DbTimeShiftSessionStore $timeShiftStore Cross-worker time-shift session store (SV-3.1 f-b)
     * @param string $storagePath Base path for recording files (default: /var/recordings)
     * @param int $maxStorageBytes Maximum storage limit in bytes (0 = unlimited)
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     * @param ComskipLifecycleManager|null $comskipManager Optional comskip lifecycle manager
     * @param string $ffmpegPath Path to FFmpeg binary for recording spawns
     * @param LiveTvManager|null $liveTvManager Optional LiveTV manager for tuner stream URL resolution
     */
    public function __construct(
        Connection $db,
        DbTimeShiftSessionStore $timeShiftStore,
        string $storagePath = '/var/recordings',
        int $maxStorageBytes = 0,
        ?StructuredLogger $logger = null,
        ?ComskipLifecycleManager $comskipManager = null,
        string $ffmpegPath = '/usr/bin/ffmpeg',
        ?\Phlix\LiveTv\LiveTvManager $liveTvManager = null
    ) {
        $this->db = $db;
        $this->timeShiftStore = $timeShiftStore;
        $this->storagePath = $storagePath;
        $this->maxStorageBytes = $maxStorageBytes;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
        $this->ffmpegPath = $ffmpegPath;
        $this->liveTvManager = $liveTvManager;

        // Register ComskipLifecycleManager::enqueue as an onComplete callback
        if ($comskipManager !== null) {
            $this->onCompleteCallbacks[] = function (
                string $recordingId,
                string $filePath
            ) use ($comskipManager): void {
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
     * Register a callback invoked whenever a recording reaches a terminal state.
     *
     * Fires on every stop path — {@see stopRecording()}, {@see cancelRecording()}
     * and {@see deleteRecording()} — regardless of whether the recording actually
     * completed. Its purpose is stop-time housekeeping that must run on ANY stop
     * (e.g. the {@see \Phlix\LiveTv\Recording\RecordingScheduler} cancelling a
     * pending one-shot stop timer for the recording), so it complements — it does
     * NOT replace — {@see onComplete()}. The callback MUST be idempotent: it may
     * be invoked more than once for the same recording id.
     *
     * @param callable $callback (string $recordingId) => void
     *
     * @return void
     *
     * @since SV-3.1c
     */
    public function onStop(callable $callback): void
    {
        $this->onStopCallbacks[] = $callback;
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
        // When it is not provided we ask the LiveTvManager (if wired) for the
        // tuner stream URL.
        if ($streamUrl === null || $streamUrl === '') {
            $streamUrl = $this->resolveTunerStreamUrl($channelId);
        }

        // SV-3.1a: When no tuner stream URL can be resolved there is no source to
        // capture. Do NOT fabricate an "active" recording with a fake PID
        // (previously `getmypid()`) — that reported a phantom recording with no
        // ffmpeg process behind it. Instead mark the recording FAILED, leaving
        // `pid` NULL and adding no in-memory active entry, so the DVR reflects
        // reality and the scheduler can surface / retry the failure.
        if ($streamUrl === null || $streamUrl === '') {
            $this->logger->warning('No tuner stream URL available; marking recording failed', [
                'recording_id' => $recordingId,
                'channel_id' => $channelId,
            ]);
            $this->updateRecordingStatus($recordingId, self::STATUS_FAILED, 'No tuner available');
            return false;
        }

        // Spawn the detached ffmpeg recording process.
        // Uses the same nohup...& pattern as FfmpegRunner::startDetached() so
        // that PHP returning does not kill the child.
        $logDir = $this->storagePath;
        $outputPath = $this->getRecordingPath($recordingId);

        $pid = $this->spawnRecording($streamUrl, $outputPath, $logDir);

        if ($pid <= 0) {
            $this->updateRecordingStatus($recordingId, self::STATUS_FAILED, 'Failed to spawn ffmpeg recording process');
            return false;
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

        // Atomic completion compare-and-swap: only flip a row that is STILL
        // `recording`. The Workerman MySQL client returns the affected-row count
        // for an UPDATE (see PhlixMySQLConnection::query()), so affected < 1 means
        // a concurrent completer — the per-recording one-shot stop timer or the
        // safety-net scan, which can interleave with this call at the yields in
        // terminateRecording() under the Swoole runtime — already finalised this
        // recording. Guarding onComplete on affected==1 makes the completion side
        // effects (comskip / media-item registration under SV-3.1d) run EXACTLY
        // once regardless of timing.
        $affected = $this->db->query(
            "UPDATE livetv_recordings
             SET status = ?, end_time = ?, storage_size = ?, updated_at = NOW()
             WHERE recording_id = ? AND status = ?",
            [self::STATUS_COMPLETED, time(), $fileSize, $recordingId, self::STATUS_RECORDING]
        );

        // Cancel any armed one-shot stop timer for this recording regardless of
        // who won the completion race (idempotent housekeeping).
        $this->fireOnStopCallbacks($recordingId);

        if (!is_int($affected) || $affected < 1) {
            // Lost the race — another completer already transitioned this row.
            $this->logger->debug('Recording already finalised by a concurrent completer; skipping duplicate', [
                'recording_id' => $recordingId,
            ]);
            return false;
        }

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
     * End a scheduled recording at its effective end (end_time + post_padding).
     *
     * This is the AUTHORITATIVE timed-stop path and the single place the
     * post-padding is applied when finishing a recording. It is invoked by the
     * {@see \Phlix\LiveTv\Recording\RecordingScheduler} — either the
     * per-recording one-shot stop timer armed when the capture starts, or the
     * safety-net scan ({@see getRecordingsDueToStop()}) — only once
     * `time()` has reached the effective end.
     *
     * NOTE: {@see scheduleRecording()} persists the RAW programme `end_time`
     * (the guide's scheduled end), NOT `end_time + post_padding`. The padding is
     * therefore applied at stop time here (and mirrored, for the SQL scan, in
     * {@see getRecordingsDueToStop()} and, for the timer delay, via
     * {@see effectiveEndTime()}) — never folded into the stored `end_time`, so a
     * displayed schedule keeps its true programme boundaries and padding is never
     * double-counted.
     *
     * When the recording is live in this worker's memory the real ffmpeg process
     * is terminated via {@see stopRecording()}. As a safety net, when the row is
     * still `recording` in the DB but has no in-memory handle on this worker
     * (e.g. its owning one-shot timer was lost across a restart), any stored pid
     * that is still alive is killed and the row is transitioned to `completed`
     * so the periodic scan cannot keep re-selecting it forever.
     *
     * @param string $recordingId The recording to end
     * @return bool True if ended (stopped or reconciled), false otherwise
     *
     * @since SV-3.1
     * @since SV-3.1c Authoritative post-padding + orphan reconcile.
     */
    public function endRecording(string $recordingId): bool
    {
        $recording = $this->getRecording($recordingId);
        if ($recording === null) {
            return false;
        }

        $endTime = is_int($recording['end_time'] ?? null) ? $recording['end_time'] : 0;
        $postPadding = is_int($recording['post_padding_seconds'] ?? null)
            ? $recording['post_padding_seconds']
            : 60;
        $effectiveEnd = $this->effectiveEndTime($endTime, $postPadding);

        $this->logger->info('Ending recording at effective end (end_time + post_padding)', [
            'recording_id' => $recordingId,
            'scheduled_end' => $endTime,
            'post_padding' => $postPadding,
            'effective_end' => $effectiveEnd,
        ]);

        // Live in this worker's memory: stop the real ffmpeg process.
        if (isset($this->activeRecordings[$recordingId])) {
            return $this->stopRecording($recordingId);
        }

        // Safety-net reconcile: still `recording` in the DB but no in-memory
        // handle on this worker. Kill any stored pid still alive and finalise the
        // row so the scan does not loop on it.
        if ($recording['status'] === self::STATUS_RECORDING) {
            $pid = self::asPid($recording['pid'] ?? null);
            if ($pid !== null && $this->isPidAlive($pid)) {
                $this->terminateRecording($pid);
            }

            $filePath = $this->getRecordingPath($recordingId);
            $fileSize = file_exists($filePath) ? filesize($filePath) : 0;

            // Atomic completion compare-and-swap (see stopRecording()): only the
            // completer that flips the still-`recording` row fires onComplete, so
            // a one-shot timer and a concurrent scan tick reconciling the same
            // orphaned row cannot double-complete it.
            $affected = $this->db->query(
                "UPDATE livetv_recordings
                 SET status = ?, end_time = ?, storage_size = ?, updated_at = NOW()
                 WHERE recording_id = ? AND status = ?",
                [self::STATUS_COMPLETED, time(), $fileSize, $recordingId, self::STATUS_RECORDING]
            );

            if (!is_int($affected) || $affected < 1) {
                $this->logger->debug('Reconcile lost the completion race; already finalised', [
                    'recording_id' => $recordingId,
                ]);
                return false;
            }

            $this->fireOnCompleteCallbacks($recordingId, $filePath);

            $this->logger->info('Recording ended via reconcile (no in-memory handle)', [
                'recording_id' => $recordingId,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Compute the authoritative effective end time of a recording.
     *
     * A recording runs until its scheduled programme `end_time` PLUS the
     * configured post-padding. This is the single source of truth for the
     * post-padding formula: both the per-recording one-shot stop timer
     * (armed by {@see \Phlix\LiveTv\Recording\RecordingScheduler}) and
     * {@see getRecordingsDueToStop()} derive the stop moment from it, so the
     * padding is never re-derived ad-hoc at a call site.
     *
     * @param int $endTime Scheduled programme end time (unix seconds).
     * @param int $postPaddingSeconds Post-roll padding in seconds.
     * @return int Effective stop time (unix seconds) = end_time + max(0, padding).
     *
     * @since SV-3.1c
     */
    public function effectiveEndTime(int $endTime, int $postPaddingSeconds): int
    {
        return $endTime + max(0, $postPaddingSeconds);
    }

    /**
     * Find in-progress recordings whose effective end has already passed.
     *
     * The effective end is `end_time + post_padding_seconds` (the authoritative
     * post-padding formula, expressed here in SQL). This is the safety-net scan
     * the {@see \Phlix\LiveTv\Recording\RecordingScheduler} runs each tick to
     * stop recordings whose per-recording one-shot timer never fired — e.g.
     * recordings re-attached by boot recovery (whose in-memory timer was lost)
     * or any missed timer.
     *
     * @param int|null $now Reference time (defaults to time()); injectable for tests.
     * @return array<int, string> recording_ids due to be stopped.
     *
     * @since SV-3.1c
     */
    public function getRecordingsDueToStop(?int $now = null): array
    {
        $now ??= time();

        // GREATEST(0, …) mirrors effectiveEndTime()'s max(0, padding) clamp so a
        // (mis-stored) negative post_padding cannot make the scan fire earlier
        // than the timer path.
        $result = $this->db->query(
            "SELECT recording_id FROM livetv_recordings
             WHERE status = ?
               AND (end_time + GREATEST(0, COALESCE(post_padding_seconds, 60))) <= ?
             ORDER BY end_time ASC",
            [self::STATUS_RECORDING, $now]
        );

        $ids = [];
        foreach (RowQuery::rows($result) as $row) {
            $id = self::asString($row['recording_id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
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

        $pid = $this->launchDetached($cmd, $logFile);
        if ($pid <= 0) {
            $this->logger->error('Failed to spawn ffmpeg recording process');
            return 0;
        }

        $this->logger->debug('Recording process spawned', [
            'pid' => $pid,
            'stream_url' => $streamUrl,
            'output_path' => $outputPath,
        ]);

        return $pid;
    }

    /**
     * Spawn a DETACHED ffmpeg process that writes a rolling on-disk HLS window.
     *
     * The window is bounded to {@see TIMESHIFT_BUFFER_SECONDS} of broadcast:
     * ffmpeg emits ~{@see TIMESHIFT_SEGMENT_SECONDS}-second segments and, with
     * `hls_flags delete_segments+append_list` and an `hls_list_size` of
     * `ceil(TIMESHIFT_BUFFER_SECONDS / TIMESHIFT_SEGMENT_SECONDS)`, auto-prunes
     * anything older than the window — so no separate prune Timer is required
     * (avoiding the SV-0.5 timer-storm class entirely). Reuses the same detached
     * launch machinery as {@see spawnRecording()} (nohup + optional `timeout`
     * wrapper + `echo $!`), so PHP returning does not kill the child and the
     * event loop is never blocked (the PROC hook is excluded by design; §0.3).
     *
     * `protected` so a test double can override it to avoid spawning real ffmpeg.
     *
     * @param string $streamUrl Tuner source URL to capture
     * @param string $bufferDir Per-session buffer directory (already created)
     *
     * @return int The child PID (0 if the spawn failed)
     *
     * @since SV-3.1 f-b
     */
    protected function spawnTimeShiftBuffer(string $streamUrl, string $bufferDir): int
    {
        $listSize = (int) ceil(self::TIMESHIFT_BUFFER_SECONDS / self::TIMESHIFT_SEGMENT_SECONDS);
        if ($listSize < 1) {
            $listSize = 1;
        }

        $playlistPath = $bufferDir . '/' . self::TIMESHIFT_PLAYLIST_NAME;
        $segmentPattern = $bufferDir . '/seg_%05d.ts';

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -c copy -f hls'
            . ' -hls_time %d -hls_list_size %d -hls_flags delete_segments+append_list'
            . ' -hls_segment_type mpegts -hls_segment_filename %s %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($streamUrl),
            self::TIMESHIFT_SEGMENT_SECONDS,
            $listSize,
            escapeshellarg($segmentPattern),
            escapeshellarg($playlistPath)
        );

        $logFile = $bufferDir . '/ffmpeg_timeshift.log';

        $pid = $this->launchDetached($cmd, $logFile);
        if ($pid <= 0) {
            return 0;
        }

        $this->logger->debug('Time-shift buffer process spawned', [
            'pid' => $pid,
            'stream_url' => $streamUrl,
            'buffer_dir' => $bufferDir,
            'hls_list_size' => $listSize,
        ]);

        return $pid;
    }

    /**
     * Launch a shell command DETACHED and return the child PID.
     *
     * Shared by {@see spawnRecording()} and {@see spawnTimeShiftBuffer()}: wraps
     * the command in `timeout <transcode_timeout>` (when configured) so a leaked
     * capture cannot hold a tuner forever, then `nohup … & echo $!` so SIGHUP on
     * PHP-shell exit does not kill the child and the real PID is returned. The
     * spawn is fire-and-return (the single Workerman worker is never blocked; the
     * PROC hook is excluded by design per §0.3).
     *
     * `protected` (not `private`) so a test double can capture the fully-built,
     * escaped command WITHOUT executing a shell — letting the command-injection
     * guard assert the emitted string is properly `escapeshellarg`-quoted.
     *
     * @param string $ffmpegCmd The fully-escaped ffmpeg command to run
     * @param string $logFile   Absolute path for the combined stdout/stderr log
     *
     * @return int The child PID (0 if the spawn failed)
     *
     * @since SV-3.1 f-b
     */
    protected function launchDetached(string $ffmpegCmd, string $logFile): int
    {
        // SV-4.2: wrap in timeout to enforce transcode_timeout (7200s default) so
        // a never-stopped capture cannot hold a tuner / run unbounded.
        $timeoutSecs = $this->getTranscodeTimeout();
        $timeoutCmd = $timeoutSecs > 0
            ? 'timeout ' . (int) $timeoutSecs . ' sh -c ' . escapeshellarg($ffmpegCmd)
            : 'sh -c ' . escapeshellarg($ffmpegCmd);

        // nohup so SIGHUP doesn't kill ffmpeg when the PHP shell returns.
        // Redirect both stdout+stderr to the log file.
        $full = sprintf(
            'nohup %s > %s 2>&1 & echo $!',
            $timeoutCmd,
            escapeshellarg($logFile)
        );

        $pidStr = shell_exec($full);
        if (!is_string($pidStr) || trim($pidStr) === '') {
            return 0;
        }

        return (int) trim($pidStr);
    }

    /**
     * Absolute per-session rolling time-shift buffer directory.
     *
     * Nested under `<storage_path>/timeshift/<timeShiftId>` so
     * {@see removeBufferDir()} can jail deletions to the time-shift subtree.
     *
     * @param string $timeShiftId The time-shift session id (UUID)
     *
     * @return string
     *
     * @since SV-3.1 f-b
     */
    private function timeShiftBufferDir(string $timeShiftId): string
    {
        return $this->timeShiftRoot() . '/' . $timeShiftId;
    }

    /**
     * Root directory holding all per-session time-shift buffers.
     *
     * @return string
     *
     * @since SV-3.1 f-b
     */
    private function timeShiftRoot(): string
    {
        return rtrim($this->storagePath, '/') . '/timeshift';
    }

    /**
     * Recursively delete a rolling time-shift buffer directory.
     *
     * Failure-safe (a missing directory is a no-op) and PATH-JAILED: only paths
     * under `<storage_path>/timeshift/` are ever removed, so a corrupt / spoofed
     * buffer_dir cannot delete anything outside the time-shift subtree.
     *
     * @param string $bufferDir The buffer directory to remove
     *
     * @return void
     *
     * @since SV-3.1 f-b
     */
    private function removeBufferDir(string $bufferDir): void
    {
        $root = $this->timeShiftRoot();

        // Jail: the buffer dir must live strictly under the time-shift root.
        // Use the string prefix (dirs we constructed) plus a realpath check as
        // defence-in-depth when the path exists on disk.
        if (!str_starts_with($bufferDir, $root . '/')) {
            $this->logger->warning('Refusing to remove time-shift buffer outside jail', [
                'buffer_dir' => $bufferDir,
            ]);
            return;
        }

        if (!is_dir($bufferDir)) {
            return;
        }

        $real = realpath($bufferDir);
        $realRoot = realpath($root);
        if ($real !== false && $realRoot !== false && !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
            $this->logger->warning('Refusing to remove time-shift buffer outside jail (realpath)', [
                'buffer_dir' => $bufferDir,
            ]);
            return;
        }

        $entries = @scandir($bufferDir);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $bufferDir . '/' . $entry;
                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    // No nested dirs are created, but stay defensive.
                    @unlink($path);
                }
            }
        }

        @rmdir($bufferDir);
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
     * Fire all registered onStop callbacks (housekeeping on ANY stop path).
     *
     * Invoked from {@see stopRecording()}, {@see cancelRecording()} and
     * {@see deleteRecording()} so stop-time housekeeping (e.g. cancelling the
     * scheduler's pending one-shot stop timer) runs whenever a recording reaches
     * a terminal state. Callbacks are idempotent by contract; exceptions are
     * swallowed and logged so one hook cannot abort a stop.
     *
     * @param string $recordingId The recording ID.
     *
     * @return void
     *
     * @since SV-3.1c
     */
    private function fireOnStopCallbacks(string $recordingId): void
    {
        foreach ($this->onStopCallbacks as $callback) {
            try {
                $callback($recordingId);
            } catch (\Throwable $e) {
                $this->logger->error('onStop callback threw exception', [
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

        // A live capture is stopped via stopRecording(), which already fires the
        // onStop hooks (cancelling any armed one-shot stop timer). When the row
        // is not live in this worker's memory, fire the hooks ourselves so the
        // timer is still cancelled and activeStopTimerCount() returns to baseline.
        $wasActive = isset($this->activeRecordings[$recordingId]);
        if ($recording['status'] === self::STATUS_RECORDING) {
            $this->stopRecording($recordingId);
        }

        $this->updateRecordingStatus($recordingId, self::STATUS_CANCELLED);

        if (!$wasActive) {
            $this->fireOnStopCallbacks($recordingId);
        }

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

        // Stop a live capture (fires onStop → cancels any armed stop timer). When
        // the row is not live here, fire the hooks ourselves so a pending timer
        // is still cancelled on delete.
        $wasActive = isset($this->activeRecordings[$recordingId]);
        if ($wasActive) {
            $this->stopRecording($recordingId);
        }

        $filePath = $this->getRecordingPath($recordingId);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $this->db->query("DELETE FROM livetv_recordings WHERE recording_id = ?", [$recordingId]);

        if (!$wasActive) {
            $this->fireOnStopCallbacks($recordingId);
        }

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
     * Resolves the channel's tuner stream URL and spawns a DETACHED ffmpeg
     * process that writes a rolling on-disk HLS window (bounded to
     * {@see TIMESHIFT_BUFFER_SECONDS} via `hls_flags delete_segments`) into a
     * per-session buffer directory under the DVR storage path. The session —
     * including its `buffer_dir` and the real capture `pid` — is persisted to the
     * DB-backed {@see DbTimeShiftSessionStore} so ANY worker (notably a
     * `/livetv/timeshift/{session}/stream` request routed elsewhere) can resolve
     * it; the in-memory {@see $activeTimeShifts} entry is kept as the same-worker
     * fast path (SV-3.1 f-b).
     *
     * Failure-safe: if no tuner is available (or the spawn fails) the session is
     * still recorded with a NULL pid and an empty buffer rather than throwing, so
     * the caller gets a consistent response and cross-worker lookup still works.
     *
     * @param string $sessionId The playback session ID
     * @param string $channelId The channel to time-shift
     * @return array{time_shift_id:string, stream_url:string, buffer_start:int, buffer_end:int} Time-shift info
     */
    public function startTimeShift(string $sessionId, string $channelId): array
    {
        // Tear down any prior session for this playback session (in-memory + store
        // + capture process + buffer dir) so a restart is idempotent.
        $this->stopTimeShift($sessionId);

        $timeShiftId = $this->generateUuid();
        $bufferDir = $this->timeShiftBufferDir($timeShiftId);
        $now = time();

        // CLAIM the session_id FIRST — persist a NULL-pid row via a plain INSERT
        // (an EXCLUSIVE claim, not the silent save() upsert), BEFORE the capture is
        // spawned. This does two things at once:
        //  1. Closes the crash-orphan window: a running capture is always preceded
        //     by a durable, reapable record (worker death between spawn and persist
        //     can no longer leak an untracked ffmpeg).
        //  2. Closes the concurrent-duplicate window: exactly one caller can win
        //     the UNIQUE(session_id) INSERT; a racing loser aborts WITHOUT spawning
        //     (below), so two same-session starts can never leave two live ffmpeg
        //     processes / two untracked buffer dirs. The dir is created only AFTER
        //     a won claim, so a loser leaves nothing behind.
        // Failure-safe: a genuine DB error (not a collision) still best-effort
        // spawns (bounded by the timeout wrapper + same-worker in-memory reap).
        $session = new TimeShiftSession(
            id: $timeShiftId,
            session_id: $sessionId,
            channel_id: $channelId,
            buffer_dir: $bufferDir,
            buffer_start_at: $now,
            buffer_end_at: $now,
            window_seconds: self::TIMESHIFT_BUFFER_SECONDS,
            cursor_position: 0,
            pid: null,
            status: TimeShiftSession::STATUS_ACTIVE,
        );
        // Default true so a DB-down error (claim() re-throws) still best-effort
        // spawns; only a genuine, detected collision sets this false.
        $claimed = true;
        $persisted = false;
        try {
            $claimed = $this->timeShiftStore->claim($session);
            $persisted = $claimed;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist time-shift session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        if (!$claimed) {
            // Lost the race to a concurrent start for the same playback session.
            // Do NOT spawn a second capture and do NOT create a buffer dir — return
            // the session the winner already claimed so this caller still points at
            // the live buffer. No resource is leaked (nothing was spawned/created).
            $this->logger->info('Time-shift start superseded by an existing session', [
                'session_id' => $sessionId,
                'channel_id' => $channelId,
            ]);

            return $this->existingTimeShiftResponse($sessionId, $now);
        }

        // Won the claim (or a DB error left us best-effort): create the buffer dir
        // now, then spawn the capture into it.
        if (!is_dir($bufferDir)) {
            @mkdir($bufferDir, 0755, true);
        }

        // Resolve the tuner stream URL and spawn the rolling-buffer capture.
        $streamUrl = $this->resolveTunerStreamUrl($channelId);
        $pid = null;
        if ($streamUrl !== null && $streamUrl !== '') {
            $spawned = $this->spawnTimeShiftBuffer($streamUrl, $bufferDir);
            if ($spawned > 0) {
                $pid = $spawned;
            } else {
                $this->logger->error('Failed to spawn time-shift buffer process', [
                    'session_id' => $sessionId,
                    'channel_id' => $channelId,
                ]);
            }
        } else {
            $this->logger->warning('No tuner stream URL; time-shift buffer will not fill', [
                'session_id' => $sessionId,
                'channel_id' => $channelId,
            ]);
        }

        // Record the real capture pid onto the surviving row — keyed on SESSION_ID
        // (not the transient $timeShiftId). The row that won the UNIQUE(session_id)
        // claim/upsert may carry a different id than the fresh one this caller
        // generated; keying on session_id guarantees the pid lands on the row
        // findBySessionId()/getTimeShift() will return, never matching zero rows.
        // Second half of the two-phase write that closes the orphan window.
        // Failure-safe.
        if ($pid !== null && $persisted) {
            try {
                $this->timeShiftStore->updatePidBySessionId($sessionId, $pid);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to record time-shift capture pid', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Same-worker fast path (mirrors getTimeShift()'s store-fallback shape,
        // including current_position, so both paths return the same keys).
        $this->activeTimeShifts[$sessionId] = [
            'id' => $timeShiftId,
            'session_id' => $sessionId,
            'channel_id' => $channelId,
            'started_at' => $now,
            'buffer_start' => $now,
            'buffer_end' => $now,
            'buffer_dir' => $bufferDir,
            'pid' => $pid,
            'current_position' => $now,
        ];

        $this->logger->info('Time-shift started', [
            'session_id' => $sessionId,
            'channel_id' => $channelId,
            'buffer_dir' => $bufferDir,
            'pid' => $pid,
        ]);

        return [
            'time_shift_id' => $timeShiftId,
            'stream_url' => "/livetv/timeshift/$sessionId/stream",
            'buffer_start' => $now,
            'buffer_end' => $now,
        ];
    }

    /**
     * Build the start-response for a caller that LOST the exclusive claim race —
     * resolve the session a concurrent start already claimed so the caller still
     * points at the live rolling buffer, without having spawned a duplicate
     * capture. Falls back to the caller's own time as a safe default if the
     * winning row can no longer be resolved.
     *
     * @param string $sessionId   The owning playback session id (URL route key)
     * @param int    $fallbackNow Epoch to use if the winner's row is unresolvable
     * @return array{time_shift_id:string, stream_url:string, buffer_start:int, buffer_end:int}
     *
     * @since SV-3.1 f
     */
    private function existingTimeShiftResponse(string $sessionId, int $fallbackNow): array
    {
        $timeShiftId = $sessionId;
        $bufferStart = $fallbackNow;
        $bufferEnd = $fallbackNow;

        try {
            $existing = $this->timeShiftStore->findBySessionId($sessionId);
            if ($existing !== null) {
                $timeShiftId = $existing->id;
                $bufferStart = $existing->buffer_start_at;
                $bufferEnd = $existing->buffer_end_at;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve superseding time-shift session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'time_shift_id' => $timeShiftId,
            'stream_url' => "/livetv/timeshift/$sessionId/stream",
            'buffer_start' => $bufferStart,
            'buffer_end' => $bufferEnd,
        ];
    }

    /**
     * Stop time-shifting for a session.
     *
     * Reaps EVERY store row for the session_id (not just the newest): terminates
     * each detached capture pid (via {@see terminateRecording()}, which works
     * cross-process on the same host), deletes each on-disk rolling buffer
     * directory, and deletes each row so no worker can resolve a torn-down
     * session. Reaping all rows — rather than only the newest — guarantees a
     * crash-left or legacy duplicate row cannot orphan a still-running ffmpeg.
     * Falls back to this worker's in-memory entry when the store has no row for
     * the session (e.g. a persist failure left only the same-worker fast path).
     *
     * Failure-safe: a missing pid, an already-gone buffer directory, or a store
     * error never throws.
     *
     * @param string $sessionId The session to stop
     * @return bool True if a session (in-memory OR in the store) was stopped
     */
    public function stopTimeShift(string $sessionId): bool
    {
        $inMemory = $this->activeTimeShifts[$sessionId] ?? null;

        $stored = [];
        try {
            $stored = $this->timeShiftStore->reapBySessionId($sessionId);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve time-shift sessions for stop', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($inMemory === null && $stored === []) {
            return false;
        }

        // Reap EVERY store row: terminate its capture, clean its buffer dir, then
        // delete it. terminateRecording()/removeBufferDir() are both idempotent
        // (a dead pid / missing dir is a fast no-op) so this is safe to repeat.
        foreach ($stored as $session) {
            if (is_int($session->pid) && $session->pid > 0) {
                $this->terminateRecording($session->pid);
            }
            if ($session->buffer_dir !== '') {
                $this->removeBufferDir($session->buffer_dir);
            }
            try {
                $this->timeShiftStore->delete($session->id);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to delete time-shift session row', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Persist-failure edge: the store had no row but the same-worker fast path
        // does — reap it directly so its capture/buffer are not left behind.
        if ($stored === [] && $inMemory !== null) {
            $memPid = $inMemory['pid'] ?? null;
            if (is_int($memPid) && $memPid > 0) {
                $this->terminateRecording($memPid);
            }
            $memDir = $inMemory['buffer_dir'] ?? null;
            if (is_string($memDir) && $memDir !== '') {
                $this->removeBufferDir($memDir);
            }
        }

        unset($this->activeTimeShifts[$sessionId]);

        $this->logger->info('Time-shift stopped', ['session_id' => $sessionId]);

        return true;
    }

    /**
     * Get time-shift info for a session.
     *
     * Prefers this worker's in-memory entry (fast path) — but SELF-VALIDATES it
     * first: a time-shift restart handled on ANOTHER worker deletes this session's
     * original buffer_dir (and its row) and creates a fresh dir under a new row,
     * without ever touching THIS worker's in-memory entry. An unconditional
     * fast-path read would then shadow the authoritative store forever, serving a
     * deleted dir → a persistent 503/404 on this worker only. So if the cached
     * buffer_dir no longer exists on disk the entry is stale: drop it and fall
     * through to the cross-worker store (the source of truth). A single is_dir()
     * stat is cheap and does not block the event loop; the common valid case still
     * returns from memory without a DB read per segment.
     *
     * When the store is used, both paths return the SAME keys — id, session_id,
     * channel_id, started_at, buffer_start, buffer_end, buffer_dir, pid and
     * current_position — so a consumer resolving a same-worker vs cross-worker
     * session sees an identical shape (the in-memory fast path seeds
     * current_position in startTimeShift(); the store path maps cursor_position).
     *
     * @param string $sessionId The session identifier
     * @return array<string, mixed>|null Time-shift data or null
     */
    public function getTimeShift(string $sessionId): ?array
    {
        $inMemory = $this->activeTimeShifts[$sessionId] ?? null;
        if ($inMemory !== null) {
            $bufferDir = $inMemory['buffer_dir'] ?? null;
            // Valid fast path: the cached buffer dir still exists on disk.
            if (is_string($bufferDir) && $bufferDir !== '' && is_dir($bufferDir)) {
                return $inMemory;
            }
            // Stale (or malformed) fast-path entry — a cross-worker restart
            // reclaimed this dir. Invalidate so it cannot mask the live store row,
            // then fall through to the authoritative cross-worker lookup.
            unset($this->activeTimeShifts[$sessionId]);
        }

        try {
            $stored = $this->timeShiftStore->findBySessionId($sessionId);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve time-shift session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($stored === null || $stored->status !== TimeShiftSession::STATUS_ACTIVE) {
            return null;
        }

        return [
            'id' => $stored->id,
            'session_id' => $stored->session_id,
            'channel_id' => $stored->channel_id,
            'started_at' => $stored->buffer_start_at,
            'buffer_start' => $stored->buffer_start_at,
            'buffer_end' => $stored->buffer_end_at,
            'buffer_dir' => $stored->buffer_dir,
            'pid' => $stored->pid,
            'current_position' => $stored->cursor_position,
        ];
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
     * @return array{used_bytes: int, available_bytes: int, max_bytes: int, active_recordings: int,
     *     active_timeshifts: int, recordings_by_status: array<string, int>}
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
