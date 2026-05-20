<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Recording;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Dto\RowAccess;
use Phlix\LiveTv\Dto\RowQuery;
use Phlix\LiveTv\LiveTvManager;
use Phlix\LiveTv\Recorder;
use Workerman\MySQL\Connection;

/**
 * Priority-based recording scheduler for DVR conflict resolution.
 *
 * Decides which recording to start next when multiple are scheduled
 * simultaneously. Runs via Workerman timer (every minute) to process
 * due recordings subject to tuner availability.
 *
 * ## Conflict Resolution
 *
 * When multiple recordings are due:
 * 1. Sort by priority (higher first)
 * 2. Then by start_time (earlier first)
 * 3. Skip if no tuner is free
 *
 * @since 0.12.0
 */
class RecordingScheduler
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var Recorder DVR recorder instance */
    private Recorder $recorder;

    /** @var LiveTvManager LiveTV manager for tuner access */
    private LiveTvManager $liveTvManager;

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /**
     * Creates a new RecordingScheduler instance.
     *
     * @param Connection $db Database connection
     * @param Recorder $recorder DVR recorder instance
     * @param LiveTvManager $liveTvManager LiveTV manager for tuner access
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     *
     * @since 0.12.0
     */
    public function __construct(
        Connection $db,
        Recorder $recorder,
        LiveTvManager $liveTvManager,
        ?StructuredLogger $logger = null
    ) {
        $this->db = $db;
        $this->recorder = $recorder;
        $this->liveTvManager = $liveTvManager;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
    }

    /**
     * Find all due recordings and start them subject to tuner availability.
     *
     * Queries all SCHEDULED recordings where start_time <= now (with
     * pre_padding considered), sorts by priority then start_time,
     * and attempts to start each one if a tuner is available.
     *
     * @return array{started: int, skipped: int, errors: int} Processing statistics
     *
     * @since 0.12.0
     */
    public function processDueRecordings(): array
    {
        $now = time();

        // Get all scheduled recordings that are due (including pre-padding)
        $result = $this->db->query(
            "SELECT r.*, COALESCE(r.pre_padding_seconds, 60) as padding_start
             FROM livetv_recordings r
             WHERE r.status = 'scheduled'
               AND (r.start_time - COALESCE(r.pre_padding_seconds, 60)) <= ?
             ORDER BY r.priority DESC, r.start_time ASC",
            [$now]
        );

        $stats = ['started' => 0, 'skipped' => 0, 'errors' => 0];

        foreach (RowQuery::rows($result) as $row) {
            $recordingId = RowAccess::string($row, 'recording_id');
            $channelId = RowAccess::string($row, 'channel_id');

            try {
                // Check if a tuner is available
                $tuner = $this->getAvailableTuner($channelId);
                if ($tuner === null) {
                    $this->logger->debug('No tuner available for recording, skipping', [
                        'recording_id' => $recordingId,
                        'channel_id' => $channelId,
                    ]);
                    $stats['skipped']++;
                    continue;
                }

                // Start the recording
                $success = $this->recorder->startRecording($recordingId);
                if ($success) {
                    $this->logger->info('Recording started by scheduler', [
                        'recording_id' => $recordingId,
                        'channel_id' => $channelId,
                        'tuner_id' => $tuner['id'] ?? 'unknown',
                    ]);
                    $stats['started']++;
                } else {
                    $stats['errors']++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Error processing due recording', [
                    'recording_id' => $recordingId,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Get the next scheduled recording for display purposes.
     *
     * @return array<string, mixed>|null The next recording due or null if none
     *
     * @since 0.12.0
     */
    public function getNextRecording(): ?array
    {
        $now = time();

        $result = $this->db->query(
            "SELECT * FROM livetv_recordings
             WHERE status = 'scheduled'
               AND start_time > ?
             ORDER BY start_time ASC
             LIMIT 1",
            [$now]
        );

        $row = RowQuery::firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->mapRecording($row);
    }

    /**
     * Get upcoming recordings sorted by start time.
     *
     * @param int $limit Maximum number of results (default: 10)
     * @return array<int, array<string, mixed>> Upcoming scheduled recordings
     *
     * @since 0.12.0
     */
    public function getUpcomingRecordings(int $limit = 10): array
    {
        $now = time();

        $result = $this->db->query(
            "SELECT * FROM livetv_recordings
             WHERE status = 'scheduled' AND start_time > ?
             ORDER BY priority DESC, start_time ASC
             LIMIT ?",
            [$now, $limit]
        );

        $recordings = [];
        foreach (RowQuery::rows($result) as $row) {
            $recordings[] = $this->mapRecording($row);
        }

        return $recordings;
    }

    /**
     * Check if a tuner is available for a specific channel.
     *
     * Queries the LiveTvManager for available tuners and checks if
     * any idle tuner can receive the requested channel.
     *
     * @param string $channelId The channel to record
     * @return array<string, mixed>|null Available tuner info or null if none free
     *
     * @since 0.12.0
     */
    private function getAvailableTuner(string $channelId): ?array
    {
        $tuners = $this->liveTvManager->getTuners();

        foreach ($tuners as $tuner) {
            if ($tuner['status'] === LiveTvManager::TUNER_STATUS_IDLE) {
                return $tuner;
            }
        }

        return null;
    }

    /**
     * Get count of available (idle) tuners.
     *
     * @return int Number of idle tuners
     *
     * @since 0.12.0
     */
    public function getAvailableTunerCount(): int
    {
        $count = 0;
        $tuners = $this->liveTvManager->getTuners();

        foreach ($tuners as $tuner) {
            if ($tuner['status'] === LiveTvManager::TUNER_STATUS_IDLE) {
                $count++;
            }
        }

        return $count;
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
        $startTime = RowAccess::int($row, 'start_time');
        $endTime = RowAccess::int($row, 'end_time');

        return [
            'recording_id' => RowAccess::string($row, 'recording_id'),
            'channel_id' => RowAccess::string($row, 'channel_id'),
            'program_id' => RowAccess::stringOrNull($row, 'program_id'),
            'title' => RowAccess::string($row, 'title'),
            'description' => RowAccess::stringOrNull($row, 'description'),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $endTime - $startTime,
            'priority' => RowAccess::int($row, 'priority'),
            'status' => RowAccess::string($row, 'status'),
            'series_rule_id' => RowAccess::stringOrNull($row, 'series_rule_id'),
            'pre_padding_seconds' => RowAccess::int($row, 'pre_padding_seconds', 60),
            'post_padding_seconds' => RowAccess::int($row, 'post_padding_seconds', 60),
            'created_at' => RowAccess::stringOrNull($row, 'created_at'),
        ];
    }
}
