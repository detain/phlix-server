<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Recording;

use Workerman\MySQL\Connection;

/**
 * Prevents duplicate DVR recordings within a configurable time window.
 *
 * Uses a duplicate_group identifier to track recordings of the same program
 * (same program_id + channel_id). When isDuplicate() finds an existing
 * recording within a 2-hour window, it marks the new one as a duplicate.
 *
 * @since 0.12.0
 */
class RecordingDeduplicator
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var int Time window in seconds for duplicate detection (default: 2 hours) */
    private int $duplicateWindowSeconds;

    /**
     * Creates a new RecordingDeduplicator instance.
     *
     * @param Connection $db Database connection
     * @param int $duplicateWindowSeconds Time window for duplicate detection (default: 7200 = 2 hours)
     *
     * @since 0.12.0
     */
    public function __construct(
        Connection $db,
        int $duplicateWindowSeconds = 7200
    ) {
        $this->db = $db;
        $this->duplicateWindowSeconds = $duplicateWindowSeconds;
    }

    /**
     * Check if a recording for this program already exists (or is scheduled).
     *
     * Checks for existing recordings with the same program_id + channel_id
     * within the duplicate window (default: 2 hours before/after start_time).
     *
     * @param string $programId The program identifier
     * @param string $channelId The channel identifier
     * @param int $startTime Proposed recording start time
     * @return bool True if a duplicate recording exists or is scheduled
     *
     * @since 0.12.0
     */
    public function isDuplicate(string $programId, string $channelId, int $startTime): bool
    {
        $windowStart = $startTime - $this->duplicateWindowSeconds;
        $windowEnd = $startTime + $this->duplicateWindowSeconds;

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

        return $result->num_rows > 0;
    }

    /**
     * Get the canonical (earliest) recording for a program.
     *
     * When multiple recordings exist for the same program, returns the one
     * with the earliest start_time that is not cancelled.
     *
     * @param string $programId The program identifier
     * @return array<string, mixed>|null The canonical recording or null if none found
     *
     * @since 0.12.0
     */
    public function getCanonical(string $programId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings
             WHERE program_id = ? AND status != 'cancelled'
             ORDER BY start_time ASC
             LIMIT 1",
            [$programId]
        );

        if ($result->num_rows === 0) {
            return null;
        }

        return $this->mapRecording($result->fetch());
    }

    /**
     * Find all duplicate recordings for a program group.
     *
     * @param string $programId The program identifier
     * @return array<int, array<string, mixed>> All recordings for the program
     *
     * @since 0.12.0
     */
    public function findDuplicates(string $programId): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings
             WHERE program_id = ? AND status != 'cancelled'
             ORDER BY start_time ASC",
            [$programId]
        );

        $recordings = [];
        while ($row = $result->fetch()) {
            $recordings[] = $this->mapRecording($row);
        }

        return $recordings;
    }

    /**
     * Resolve duplicates by cancelling lower-priority recordings.
     *
     * Compares recordings by their series_rule_id priority (or recording
     * priority if no rule). Keeps the highest priority canonical recording
     * and cancels all others.
     *
     * @param string $preferRuleId Preferred rule ID to keep (or null for highest priority)
     * @return int Number of recordings cancelled
     *
     * @since 0.12.0
     */
    public function resolveDuplicates(string $preferRuleId = null): int
    {
        // Find all non-cancelled scheduled/recording duplicates
        $result = $this->db->query(
            "SELECT r.*, COALESCE(sr.priority, r.priority) as effective_priority
             FROM livetv_recordings r
             LEFT JOIN livetv_series_rules sr ON r.series_rule_id = sr.rule_id
             WHERE r.status IN ('scheduled', 'recording')
               AND r.duplicate_group IS NOT NULL
             ORDER BY effective_priority DESC, r.start_time ASC"
        );

        $grouped = [];
        while ($row = $result->fetch()) {
            $group = $row['duplicate_group'];
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $row;
        }

        $cancelled = 0;
        foreach ($grouped as $recordings) {
            // Keep the first (highest priority), cancel the rest
            array_shift($recordings);
            foreach ($recordings as $rec) {
                $this->db->query(
                    "UPDATE livetv_recordings SET status = 'cancelled', updated_at = NOW()
                     WHERE recording_id = ?",
                    [$rec['recording_id']]
                );
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * Assign a duplicate_group to a recording based on program_id + channel_id.
     *
     * @param string $recordingId The recording to update
     * @param string $programId The program identifier
     * @param string $channelId The channel identifier
     * @return void
     *
     * @since 0.12.0
     */
    public function assignDuplicateGroup(string $recordingId, string $programId, string $channelId): void
    {
        $duplicateGroup = $this->generateDuplicateGroup($programId, $channelId);

        $this->db->query(
            "UPDATE livetv_recordings SET duplicate_group = ?, updated_at = NOW()
             WHERE recording_id = ?",
            [$duplicateGroup, $recordingId]
        );
    }

    /**
     * Generate a deterministic duplicate group ID from program and channel.
     *
     * @param string $programId The program identifier
     * @param string $channelId The channel identifier
     * @return string The duplicate group ID
     *
     * @since 0.12.0
     */
    private function generateDuplicateGroup(string $programId, string $channelId): string
    {
        return md5($programId . ':' . $channelId);
    }

    /**
     * Map a database row to a recording array.
     *
     * @param array<string, mixed> $row Raw database row
     * @return array<string, mixed> Normalized recording data
     *
     * @since 0.12.0
     */
    private function mapRecording(array $row): array
    {
        return [
            'recording_id' => $row['recording_id'],
            'channel_id' => $row['channel_id'],
            'program_id' => $row['program_id'],
            'title' => $row['title'],
            'start_time' => (int) $row['start_time'],
            'end_time' => (int) $row['end_time'],
            'status' => $row['status'],
            'priority' => (int) $row['priority'],
            'series_rule_id' => $row['series_rule_id'],
            'duplicate_group' => $row['duplicate_group'],
            'created_at' => $row['created_at'],
        ];
    }
}
