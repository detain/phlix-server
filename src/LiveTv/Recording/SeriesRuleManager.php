<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Recording;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Dto\RowAccess;
use Phlix\LiveTv\Dto\RowQuery;
use Phlix\LiveTv\GuideManager;
use Phlix\LiveTv\Recorder;
use Workerman\MySQL\Connection;

/**
 * Manages series recording rules for automatic DVR scheduling.
 *
 * Series rules define which programs to record automatically based on
 * series ID. The matchAndSchedule() method queries upcoming EPG data
 * and schedules recordings for unmatched episodes.
 *
 * @since 0.12.0
 */
class SeriesRuleManager
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var Recorder DVR recorder instance */
    private Recorder $recorder;

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /**
     * Creates a new SeriesRuleManager instance.
     *
     * @param Connection $db Database connection
     * @param Recorder $recorder DVR recorder instance
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     *
     * @since 0.12.0
     */
    public function __construct(
        Connection $db,
        Recorder $recorder,
        ?StructuredLogger $logger = null
    ) {
        $this->db = $db;
        $this->recorder = $recorder;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
    }

    /**
     * Create a series rule to record all future episodes of a show.
     *
     * @param string $seriesId The series identifier from EPG data
     * @param string $channelId The channel to record from (null = any channel)
     * @param array<string, mixed> $options Rule options including:
     *   - title: string Recording title
     *   - priority: int Recording priority (default: PRIORITY_NORMAL)
     *   - pre_padding_seconds: int Pre-recording padding (default: 60)
     *   - post_padding_seconds: int Post-recording padding (default: 60)
     *   - max_recordings: int|null Maximum recordings (null = unlimited)
     *   - days_ahead: int Days ahead to schedule (default: 14)
     * @return array<string, mixed> The created rule
     *
     * @throws \RuntimeException When the rule row cannot be retrieved after insert
     *
     * @since 0.12.0
     */
    public function createRule(string $seriesId, string $channelId, array $options = []): array
    {
        $ruleId = $this->generateUuid();

        $this->db->query(
            "INSERT INTO livetv_series_rules
             (rule_id, series_id, channel_id, title, priority, pre_padding_seconds,
              post_padding_seconds, max_recordings, days_ahead, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
            [
                $ruleId,
                $seriesId,
                $channelId,
                $options['title'] ?? 'Series Recording',
                $options['priority'] ?? Recorder::PRIORITY_NORMAL,
                $options['pre_padding_seconds'] ?? 60,
                $options['post_padding_seconds'] ?? 60,
                $options['max_recordings'] ?? null,
                $options['days_ahead'] ?? 14,
            ]
        );

        $this->logger->info('Series rule created', [
            'rule_id' => $ruleId,
            'series_id' => $seriesId,
            'channel_id' => $channelId,
        ]);

        $rule = $this->getRule($ruleId);
        if ($rule === null) {
            throw new \RuntimeException(
                "Series rule {$ruleId} was inserted but could not be re-read"
            );
        }

        return $rule;
    }

    /**
     * Get all active series rules.
     *
     * @return array<int, array<string, mixed>> All active rules
     *
     * @since 0.12.0
     */
    public function getRules(): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_series_rules WHERE is_active = 1 ORDER BY created_at DESC"
        );

        $rules = [];
        foreach (RowQuery::rows($result) as $row) {
            $rules[] = $this->mapRule($row);
        }

        return $rules;
    }

    /**
     * Get rule by series ID.
     *
     * @param string $seriesId The series identifier
     * @return array<string, mixed>|null The rule or null if not found
     *
     * @since 0.12.0
     */
    public function getRuleBySeries(string $seriesId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_series_rules WHERE series_id = ? AND is_active = 1 LIMIT 1",
            [$seriesId]
        );

        $row = RowQuery::firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->mapRule($row);
    }

    /**
     * Get a rule by its ID.
     *
     * @param string $ruleId The rule identifier
     * @return array<string, mixed>|null The rule or null if not found
     *
     * @since 0.12.0
     */
    public function getRule(string $ruleId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_series_rules WHERE rule_id = ? LIMIT 1",
            [$ruleId]
        );

        $row = RowQuery::firstRow($result);
        if ($row === null) {
            return null;
        }

        return $this->mapRule($row);
    }

    /**
     * Update a series rule.
     *
     * @param string $ruleId The rule to update
     * @param array<string, mixed> $updates Fields to update
     * @return array<string, mixed>|null The updated rule or null if not found
     *
     * @since 0.12.0
     */
    public function updateRule(string $ruleId, array $updates): ?array
    {
        $rule = $this->getRule($ruleId);
        if (!$rule) {
            return null;
        }

        $allowedFields = [
            'title', 'priority', 'pre_padding_seconds', 'post_padding_seconds',
            'max_recordings', 'days_ahead', 'is_active', 'channel_id',
        ];

        $setClause = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $updates)) {
                $setClause[] = "$field = ?";
                $params[] = $updates[$field];
            }
        }

        if (empty($setClause)) {
            return $rule;
        }

        $params[] = $ruleId;
        $this->db->query(
            "UPDATE livetv_series_rules SET " . implode(', ', $setClause) . ", updated_at = NOW() WHERE rule_id = ?",
            $params
        );

        $this->logger->info('Series rule updated', [
            'rule_id' => $ruleId,
            'updates' => array_keys($updates),
        ]);

        return $this->getRule($ruleId);
    }

    /**
     * Delete a series rule.
     *
     * @param string $ruleId The rule to delete
     * @return bool True if deleted, false if not found
     *
     * @since 0.12.0
     */
    public function deleteRule(string $ruleId): bool
    {
        $rule = $this->getRule($ruleId);
        if (!$rule) {
            return false;
        }

        $this->db->query("DELETE FROM livetv_series_rules WHERE rule_id = ?", [$ruleId]);

        $this->logger->info('Series rule deleted', ['rule_id' => $ruleId]);

        return true;
    }

    /**
     * Match upcoming EPG programs against all active rules and schedule recordings.
     *
     * This method queries GuideManager::getUpcomingBySeries() for each active rule
     * and calls Recorder::scheduleRecording() for episodes not already scheduled.
     *
     * @param GuideManager $guideManager The guide manager for EPG data
     * @return array{scheduled: int, skipped: int, errors: int} Statistics
     *
     * @since 0.12.0
     */
    public function matchAndSchedule(GuideManager $guideManager): array
    {
        $rules = $this->getRules();
        $stats = ['scheduled' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($rules as $rule) {
            $seriesId = is_string($rule['series_id'] ?? null) ? $rule['series_id'] : '';
            $ruleChannelId = is_string($rule['channel_id'] ?? null) ? $rule['channel_id'] : null;
            $ruleTitle = is_string($rule['title'] ?? null) ? $rule['title'] : '';
            $rulePriority = is_int($rule['priority'] ?? null) ? $rule['priority'] : Recorder::PRIORITY_NORMAL;
            $rulePrePadding = is_int($rule['pre_padding_seconds'] ?? null) ? $rule['pre_padding_seconds'] : 60;
            $rulePostPadding = is_int($rule['post_padding_seconds'] ?? null) ? $rule['post_padding_seconds'] : 60;
            $ruleDaysAhead = is_int($rule['days_ahead'] ?? null) ? $rule['days_ahead'] : 14;
            $ruleId = is_string($rule['rule_id'] ?? null) ? $rule['rule_id'] : '';
            $ruleMaxRecordings = $rule['max_recordings'] ?? null;
            if ($ruleMaxRecordings !== null && !is_int($ruleMaxRecordings)) {
                $ruleMaxRecordings = null;
            }

            $upcoming = $guideManager->getUpcomingBySeries($seriesId, 50);

            foreach ($upcoming as $program) {
                $programChannelId = is_string($program['channel_id'] ?? null) ? $program['channel_id'] : '';
                $programId = is_string($program['program_id'] ?? null) ? $program['program_id'] : '';
                $programTitle = is_string($program['title'] ?? null) ? $program['title'] : '';
                $programStart = is_int($program['start_time'] ?? null) ? $program['start_time'] : 0;
                $programEnd = is_int($program['end_time'] ?? null) ? $program['end_time'] : 0;
                $programDescription = $program['description'] ?? null;
                if ($programDescription !== null && !is_string($programDescription)) {
                    $programDescription = null;
                }

                // Skip if channel doesn't match (if channel-specific rule)
                if ($ruleChannelId !== null && $programChannelId !== $ruleChannelId) {
                    $stats['skipped']++;
                    continue;
                }

                // Skip if already outside days_ahead window
                $maxTime = time() + ($ruleDaysAhead * 86400);
                if ($programEnd > $maxTime) {
                    $stats['skipped']++;
                    continue;
                }

                try {
                    // Check if already scheduled
                    if ($this->isProgramScheduled($programId, $programChannelId)) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Check max recordings limit
                    if ($ruleMaxRecordings !== null) {
                        $currentCount = $this->getScheduledCountForRule($ruleId);
                        if ($currentCount >= $ruleMaxRecordings) {
                            $stats['skipped']++;
                            continue;
                        }
                    }

                    // Schedule the recording
                    $this->recorder->scheduleRecording([
                        'channel_id' => $programChannelId,
                        'program_id' => $programId,
                        'title' => $ruleTitle . ' - ' . $programTitle,
                        'description' => $programDescription,
                        'start_time' => $programStart,
                        'end_time' => $programEnd,
                        'priority' => $rulePriority,
                        'series_rule_id' => $ruleId,
                        'pre_padding_seconds' => $rulePrePadding,
                        'post_padding_seconds' => $rulePostPadding,
                    ]);

                    $stats['scheduled']++;
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to schedule recording for rule', [
                        'rule_id' => $ruleId,
                        'program_id' => $programId,
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;
                }
            }
        }

        $this->logger->info('Series rule matching complete', $stats);

        return $stats;
    }

    /**
     * Check if a program is already scheduled for recording.
     *
     * @param string $programId The program identifier
     * @param string $channelId The channel identifier
     * @return bool True if already scheduled
     *
     * @since 0.12.0
     */
    private function isProgramScheduled(string $programId, string $channelId): bool
    {
        $result = $this->db->query(
            "SELECT recording_id FROM livetv_recordings
             WHERE program_id = ? AND channel_id = ? AND status IN ('scheduled', 'recording')
             LIMIT 1",
            [$programId, $channelId]
        );

        return RowQuery::hasRows($result);
    }

    /**
     * Get the count of scheduled recordings for a rule.
     *
     * @param string $ruleId The rule identifier
     * @return int Number of scheduled recordings
     *
     * @since 0.12.0
     */
    private function getScheduledCountForRule(string $ruleId): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM livetv_recordings
             WHERE series_rule_id = ? AND status IN ('scheduled', 'recording')",
            [$ruleId]
        );

        $row = RowQuery::firstRow($result);
        if ($row === null) {
            return 0;
        }

        return RowAccess::int($row, 'cnt');
    }

    /**
     * Map a database row to a rule array.
     *
     * @param array<string, mixed> $row Raw database row
     * @return array<string, mixed> Normalized rule data
     *
     * @since 0.12.0
     */
    private function mapRule(array $row): array
    {
        return [
            'rule_id' => RowAccess::string($row, 'rule_id'),
            'series_id' => RowAccess::string($row, 'series_id'),
            'channel_id' => RowAccess::stringOrNull($row, 'channel_id'),
            'title' => RowAccess::string($row, 'title'),
            'priority' => RowAccess::int($row, 'priority'),
            'pre_padding_seconds' => RowAccess::int($row, 'pre_padding_seconds', 60),
            'post_padding_seconds' => RowAccess::int($row, 'post_padding_seconds', 60),
            'max_recordings' => RowAccess::intOrNull($row, 'max_recordings'),
            'days_ahead' => RowAccess::int($row, 'days_ahead', 14),
            'is_active' => RowAccess::bool($row, 'is_active'),
            'created_at' => RowAccess::stringOrNull($row, 'created_at'),
            'updated_at' => RowAccess::stringOrNull($row, 'updated_at'),
        ];
    }

    /**
     * Generate a unique UUID v4 string.
     *
     * @return string A UUID in the format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     *
     * @since 0.12.0
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
