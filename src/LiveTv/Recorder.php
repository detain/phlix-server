<?php

declare(strict_types=1);

namespace Phlex\LiveTv;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
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
 * @author Phlex Development Team
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

    /** @var array<string, array{id:string, started_at:int, channel_id:string, stream_url:string}> Currently active recordings */
    private array $activeRecordings = [];

    /** @var array<string, array{id:string, session_id:string, channel_id:string, started_at:int, buffer_start:int, buffer_end:int, current_position?:int}> Active time-shift sessions */
    private array $activeTimeShifts = [];

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
    public function __construct(Connection $db, string $storagePath = '/var/recordings', int $maxStorageBytes = 0, ?StructuredLogger $logger = null)
    {
        $this->db = $db;
        $this->storagePath = $storagePath;
        $this->maxStorageBytes = $maxStorageBytes;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
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
     * @return array<string, mixed>|null The scheduled recording or null on failure
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
     */
    public function scheduleRecording(array $data): array
    {
        $recordingId = $this->generateUuid();

        $this->db->query(
            "INSERT INTO livetv_recordings
             (recording_id, channel_id, program_id, title, description, start_time, end_time,
              priority, quality, storage_path, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
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
            ]
        );

        $this->logger->info('Recording scheduled', [
            'recording_id' => $recordingId,
            'title' => $data['title'],
            'start_time' => date('Y-m-d H:i', $data['start_time']),
        ]);

        return $this->getRecording($recordingId);
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

        if ($result->num_rows === 0) {
            return null;
        }

        return $this->mapRecording($result->fetch());
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
        while ($row = $result->fetch()) {
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
        while ($row = $result->fetch()) {
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
        while ($row = $result->fetch()) {
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
        while ($row = $result->fetch()) {
            $recordings[] = $this->mapRecording($row);
        }

        return $recordings;
    }

    /**
     * Start a scheduled recording.
     *
     * Checks for available storage before starting.
     * Updates status to RECORDING and creates stream URL.
     *
     * @param string $recordingId The recording to start
     * @return bool True if started successfully, false otherwise
     */
    public function startRecording(string $recordingId): bool
    {
        $recording = $this->getRecording($recordingId);
        if (!$recording || $recording['status'] !== self::STATUS_SCHEDULED) {
            return false;
        }

        // Check available storage
        if (!$this->hasStorageSpace($recording['start_time'], $recording['end_time'])) {
            $this->updateRecordingStatus($recordingId, self::STATUS_FAILED, 'Insufficient storage space');
            return false;
        }

        $this->updateRecordingStatus($recordingId, self::STATUS_RECORDING);

        $this->activeRecordings[$recordingId] = [
            'id' => $recordingId,
            'started_at' => time(),
            'channel_id' => $recording['channel_id'],
            'stream_url' => "/livetv/recording/$recordingId/stream",
        ];

        $this->logger->info('Recording started', ['recording_id' => $recordingId]);

        return true;
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

        return true;
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

        $row = $result->fetch();
        return (int) ($row['total'] ?? 0);
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
        while ($row = $result->fetch()) {
            $counts[$row['status']] = (int) $row['cnt'];
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
     * @return array{used_bytes:int, available_bytes:int, max_bytes:int, active_recordings:int, active_timeshifts:int, recordings_by_status:array<string,int>} Storage stats
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
     */
    private function mapRecording(array $row): array
    {
        return [
            'id' => $row['recording_id'],
            'recording_id' => $row['recording_id'],
            'channel_id' => $row['channel_id'],
            'program_id' => $row['program_id'],
            'user_id' => $row['user_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'start_time' => (int) $row['start_time'],
            'end_time' => (int) $row['end_time'],
            'duration' => (int) $row['end_time'] - (int) $row['start_time'],
            'priority' => (int) $row['priority'],
            'quality' => $row['quality'],
            'storage_path' => $row['storage_path'],
            'storage_size' => (int) ($row['storage_size'] ?? 0),
            'status' => $row['status'],
            'error_message' => $row['error_message'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
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
