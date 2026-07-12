<?php

/**
 * Phlix media server component: Recording.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 * ## Timed stop (SV-3.1c)
 *
 * Each tick ({@see tick()}) runs two passes:
 * 1. {@see processDueRecordings()} — start recordings whose start_time (minus
 *    pre-padding) has arrived, and arm a per-recording one-shot Workerman timer
 *    that stops them at their effective end (`end_time + post_padding`).
 * 2. {@see processCompletedRecordings()} — the safety-net scan: stop any
 *    in-progress recording whose effective end has already passed but whose
 *    one-shot timer never fired (e.g. recordings re-attached by boot recovery,
 *    whose in-memory timer was lost across a restart, or any missed timer).
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
     * Per-recording one-shot stop timers, keyed by recording_id → Workerman
     * timer id, so a manual stop / completion can cancel a pending timer and
     * the count is observable for tests.
     *
     * @var array<string, int>
     */
    private array $stopTimerIds = [];

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
                    // Primary timed-stop: arm a per-recording one-shot timer that
                    // stops this capture at its effective end (end_time +
                    // post_padding). The safety-net scan
                    // ({@see processCompletedRecordings()}) covers the case where
                    // this timer is lost (e.g. across a worker restart).
                    $this->scheduleStopTimer($recordingId, $row);

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
     * Run one full scheduler tick: start due recordings AND stop completed ones.
     *
     * Invoked by the periodic Workerman timer wired in `start.php` (worker 0
     * only). Combines the start pass ({@see processDueRecordings()}) with the
     * timed-stop safety-net scan ({@see processCompletedRecordings()}).
     *
     * @return array{
     *     due: array{started: int, skipped: int, errors: int},
     *     completed: array{ended: int, errors: int}
     * } Combined per-pass statistics.
     *
     * @since SV-3.1c
     */
    public function tick(): array
    {
        return [
            'due' => $this->processDueRecordings(),
            'completed' => $this->processCompletedRecordings(),
        ];
    }

    /**
     * Safety-net scan: stop recordings whose effective end has passed.
     *
     * Delegates the post-padding formula to
     * {@see Recorder::getRecordingsDueToStop()} (which selects in-progress rows
     * where `end_time + post_padding <= now`) and calls the authoritative
     * {@see Recorder::endRecording()} for each. Catches recordings whose
     * per-recording one-shot timer was lost — e.g. re-attached by boot recovery
     * after a restart, or a missed timer. Each stop is wrapped in try/catch so a
     * single failure cannot abort the whole scan.
     *
     * @return array{ended: int, errors: int} Processing statistics.
     *
     * @since SV-3.1c
     */
    public function processCompletedRecordings(): array
    {
        $stats = ['ended' => 0, 'errors' => 0];

        foreach ($this->recorder->getRecordingsDueToStop() as $recordingId) {
            try {
                if ($this->recorder->endRecording($recordingId)) {
                    $stats['ended']++;
                    $this->logger->info('Recording stopped by scheduler (effective end reached)', [
                        'recording_id' => $recordingId,
                    ]);
                }
                // A pending one-shot timer for this recording is now redundant.
                $this->cancelStopTimer($recordingId);
            } catch (\Throwable $e) {
                $this->logger->error('Error ending due recording', [
                    'recording_id' => $recordingId,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Number of pending per-recording one-shot stop timers.
     *
     * Exposed for observability / tests (the timers self-clear on fire and are
     * cancelled when a recording is stopped by the scan).
     *
     * @return int
     *
     * @since SV-3.1c
     */
    public function activeStopTimerCount(): int
    {
        return count($this->stopTimerIds);
    }

    /**
     * Arm a one-shot timer that stops a recording at its effective end.
     *
     * The effective end (`end_time + post_padding`) is computed via the
     * authoritative {@see Recorder::effectiveEndTime()} so the padding formula is
     * never duplicated here. A prior timer for the same recording is replaced
     * (idempotent re-arm). No-op outside a Workerman runtime.
     *
     * @param string               $recordingId The recording to stop.
     * @param array<string, mixed>  $row         The raw `livetv_recordings` row.
     *
     * @return void
     *
     * @since SV-3.1c
     */
    private function scheduleStopTimer(string $recordingId, array $row): void
    {
        if (!class_exists(\Workerman\Timer::class)) {
            return;
        }

        $endTime = RowAccess::int($row, 'end_time');
        $postPadding = RowAccess::int($row, 'post_padding_seconds', 60);
        $effectiveEnd = $this->recorder->effectiveEndTime($endTime, $postPadding);

        // Fire at the effective end, but never sooner than 1s from now.
        $delay = max(1, $effectiveEnd - time());

        // Replace any prior timer for this recording (idempotent re-arm).
        $this->cancelStopTimer($recordingId);

        $timerId = \Workerman\Timer::add(
            $delay,
            function () use ($recordingId): void {
                $this->fireStopTimer($recordingId);
            },
            [],
            false, // one-shot
        );

        if ($timerId > 0) {
            $this->stopTimerIds[$recordingId] = $timerId;
        }
    }

    /**
     * One-shot stop-timer callback: end the recording, tolerating failures.
     *
     * Runs inside a coroutine under the Workerman Swoole event adapter (timer
     * callbacks are wrapped in Coroutine::create()), so the DB work + process
     * kill performed by {@see Recorder::endRecording()} run in a valid context.
     * Wrapped in try/catch so a stop failure can never bubble out of the loop.
     *
     * @param string $recordingId The recording to end.
     *
     * @return void
     *
     * @since SV-3.1c
     */
    private function fireStopTimer(string $recordingId): void
    {
        unset($this->stopTimerIds[$recordingId]);

        try {
            $this->recorder->endRecording($recordingId);
        } catch (\Throwable $e) {
            $this->logger->error('Timed stop timer failed', [
                'recording_id' => $recordingId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel and forget a pending per-recording stop timer.
     *
     * @param string $recordingId The recording whose timer to cancel.
     *
     * @return void
     *
     * @since SV-3.1c
     */
    private function cancelStopTimer(string $recordingId): void
    {
        if (!isset($this->stopTimerIds[$recordingId])) {
            return;
        }

        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::del($this->stopTimerIds[$recordingId]);
        }

        unset($this->stopTimerIds[$recordingId]);
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
