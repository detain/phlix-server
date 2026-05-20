<?php

declare(strict_types=1);

namespace Phlix\LiveTv;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
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

    /** @var array<string, array{id:string, started_at:int, channel_id:string, stream_url:string, effective_start?:int, pid?:int|null}> Currently active recordings */
    private array $activeRecordings = [];

    /** @var array<string, array{id:string, session_id:string, channel_id:string, started_at:int, buffer_start:int, buffer_end:int, current_position?:int}> Active time-shift sessions */
    private array $activeTimeShifts = [];

    /** @var callable[] Post-complete callbacks (media_item_id, recording_path) => void */
    private array $onCompleteCallbacks = [];

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
     * Creates a new Recorder instance.
     *
     * @param Connection $db Database connection
     * @param string $storagePath Base path for recording files (default: /var/recordings)
     * @param int $maxStorageBytes Maximum storage limit in bytes (0 = unlimited)
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     */
    public function __construct(
        Connection $db,
        string $storagePath = '/var/recordings',
        int $maxStorageBytes = 0,
        ?StructuredLogger $logger = null,
        ?ComskipLifecycleManager $comskipManager = null
    ) {
        $this->db = $db;
        $this->storagePath = $storagePath;
        $this->maxStorageBytes = $maxStorageBytes;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);

        // Register ComskipLifecycleManager::enqueue as an onComplete callback
        if ($comskipManager !== null) {
            $this->onCompleteCallbacks[] = function (string $recordingId, string $filePath) use ($comskipManager): void {
                $comskipManager->enqueue($recordingId, $filePath);
            };
        }
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
     * @return array<int, array<string, mixed>> List of recordings
     */
    public function getAllRecordings(string $status = null): array
    {
        if ($status) {
            $result = $this->db->query(
                "SELECT * FROM livetv_recordings WHERE status = ? ORDER BY start_time DESC",
                [$status]
            );
        } else {
            $result = $this->db->query(
                "SELECT * FROM livetv_recordings ORDER BY start_time DESC"
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
    public function startRecording(string $recordingId, ?int $pid = null): bool
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

        // Check available storage
        if (!$this->hasStorageSpace($effectiveStart, $endTime + $prePadding)) {
            $this->updateRecordingStatus($recordingId, self::STATUS_FAILED, 'Insufficient storage space');
            return false;
        }

        $effectivePid = $pid ?? getmypid();
        if ($effectivePid === false) {
            $effectivePid = null;
        }

        $this->db->query(
            "UPDATE livetv_recordings
             SET status = ?, pid = ?, error_message = NULL, updated_at = NOW()
             WHERE recording_id = ?",
            [self::STATUS_RECORDING, $effectivePid, $recordingId]
        );

        $this->activeRecordings[$recordingId] = [
            'id' => $recordingId,
            'started_at' => time(),
            'effective_start' => $effectiveStart,
            'channel_id' => $channelId,
            'stream_url' => "/livetv/recording/$recordingId/stream",
            'pid' => $effectivePid,
        ];

        $this->logger->info('Recording started', [
            'recording_id' => $recordingId,
            'pre_padding' => $prePadding,
            'effective_start' => date('Y-m-d H:i:s', $effectiveStart),
            'pid' => $effectivePid,
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
     * Updates the recording status to COMPLETED and records
     * the actual duration and file size.
     *
     * @param string $recordingId The recording to stop
     * @return bool True if stopped successfully, false if not active
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

        // Fire post-complete callbacks
        $this->fireOnCompleteCallbacks($recordingId, $filePath);

        return true;
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
     * @param int $startTime Recording start time
     * @param int $endTime Recording end time
     * @return bool True if space is available
     */
    private function hasStorageSpace(int $startTime, int $endTime): bool
    {
        if ($this->maxStorageBytes <= 0) {
            return true;
        }

        $usedStorage = $this->getUsedStorageBytes();
        $estimatedSize = $this->estimateRecordingSize($startTime, $endTime);

        return ($usedStorage + $estimatedSize) <= $this->maxStorageBytes;
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
