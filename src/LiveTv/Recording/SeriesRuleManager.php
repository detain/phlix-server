<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Recording;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\LiveTv\GuideManager;
use Phlex\LiveTv\Recorder;
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

        return $this->getRule($ruleId);
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
        while ($row = $result->fetch()) {
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

        if ($result->num_rows === 0) {
            return null;
        }

        return $this->mapRule($result->fetch());
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

        if ($result->num_rows === 0) {
            return null;
        }

        return $this->mapRule($result->fetch());
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
            $upcoming = $guideManager->getUpcomingBySeries($rule['series_id'], 50);

            foreach ($upcoming as $program) {
                // Skip if channel doesn't match (if channel-specific rule)
                if ($rule['channel_id'] !== null && $program['channel_id'] !== $rule['channel_id']) {
                    $stats['skipped']++;
                    continue;
                }

                // Skip if already outside days_ahead window
                $maxTime = time() + ($rule['days_ahead'] * 86400);
                if ($program['end_time'] > $maxTime) {
                    $stats['skipped']++;
                    continue;
                }

                try {
                    // Check if already scheduled
                    if ($this->isProgramScheduled($program['program_id'], $program['channel_id'])) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Check max recordings limit
                    if ($rule['max_recordings'] !== null) {
                        $currentCount = $this->getScheduledCountForRule($rule['rule_id']);
                        if ($currentCount >= $rule['max_recordings']) {
                            $stats['skipped']++;
                            continue;
                        }
                    }

                    // Schedule the recording
                    $this->recorder->scheduleRecording([
                        'channel_id' => $program['channel_id'],
                        'program_id' => $program['program_id'],
                        'title' => $rule['title'] . ' - ' . $program['title'],
                        'description' => $program['description'] ?? null,
                        'start_time' => $program['start_time'],
                        'end_time' => $program['end_time'],
                        'priority' => $rule['priority'],
                        'series_rule_id' => $rule['rule_id'],
                        'pre_padding_seconds' => $rule['pre_padding_seconds'],
                        'post_padding_seconds' => $rule['post_padding_seconds'],
                    ]);

                    $stats['scheduled']++;
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to schedule recording for rule', [
                        'rule_id' => $rule['rule_id'],
                        'program_id' => $program['program_id'],
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

        return $result->num_rows > 0;
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

        $row = $result->fetch();
        return (int) ($row['cnt'] ?? 0);
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
            'rule_id' => $row['rule_id'],
            'series_id' => $row['series_id'],
            'channel_id' => $row['channel_id'],
            'title' => $row['title'],
            'priority' => (int) $row['priority'],
            'pre_padding_seconds' => (int) $row['pre_padding_seconds'],
            'post_padding_seconds' => (int) $row['post_padding_seconds'],
            'max_recordings' => $row['max_recordings'] !== null ? (int) $row['max_recordings'] : null,
            'days_ahead' => (int) $row['days_ahead'],
            'is_active' => (bool) $row['is_active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
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
