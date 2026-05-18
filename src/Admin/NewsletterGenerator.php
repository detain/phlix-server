<?php

declare(strict_types=1);

namespace Phlex\Admin;

use DateTimeInterface;
use Smarty;
use Phlex\Media\Library\LibraryManager;
use Phlex\Stats\StatsCollector;
use Workerman\MySQL\Connection;

/**
 * NewsletterGenerator generates weekly newsletter email content for users.
 *
 * This class aggregates watch statistics, new media items, and top media
 * into a personalized HTML email using Smarty templates.
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Generates weekly newsletter email content
 */
class NewsletterGenerator
{
    /** @var StatsCollector Statistics collector for watch data */
    private StatsCollector $stats;

    /** @var LibraryManager Media library manager */
    private LibraryManager $library;

    /** @var Connection Database connection */
    private Connection $db;

    /** @var string Smarty template directory path */
    private string $templateDir;

    /** @var array<string, mixed> Newsletter configuration */
    private array $config;

    /**
     * Create a new NewsletterGenerator instance.
     *
     * @param StatsCollector $stats Statistics collector service
     * @param LibraryManager $library Library manager service
     * @param Connection $db Database connection
     * @param string $templateDir Smarty template directory path
     * @param array<string, mixed> $config Newsletter configuration array
     */
    public function __construct(
        StatsCollector $stats,
        LibraryManager $library,
        Connection $db,
        string $templateDir,
        array $config = []
    ) {
        $this->stats = $stats;
        $this->library = $library;
        $this->db = $db;
        $this->templateDir = $templateDir;
        $this->config = array_merge([
            'active_user_days' => 30,
            'top_media_limit' => 5,
            'subject_template' => 'Your Phlex Weekly Watch Report',
            'from_email' => 'phlex@example.com',
            'from_name' => 'Phlex Media Server',
        ], $config);
    }

    /**
     * Get the stats collector instance.
     *
     * Exposed for testing and external access to statistics data.
     * Note: Internal implementation queries DB directly for per-user
     * per-week stats rather than using the collector's aggregated methods.
     *
     * @return StatsCollector
     */
    public function getStatsCollector(): StatsCollector
    {
        return $this->stats;
    }

    /**
     * Get the library manager instance.
     *
     * Exposed for testing and external access to library operations.
     *
     * @return LibraryManager
     */
    public function getLibraryManager(): LibraryManager
    {
        return $this->library;
    }

    /**
     * Generate email content for a specific user and week.
     *
     * @param string $userId User UUID to generate newsletter for
     * @param DateTimeInterface $weekStart Start date of the week to report on
     *
     * @return array{
     *     subject: string,
     *     html_body: string,
     *     plain_text: string,
     *     week_watch_time_minutes: int,
     *     new_items_count: int,
     *     top_media: array<int, array{media_item_id: string, name: string, poster_url: string|null, play_count: int}>
     * }
     */
    public function generateForUser(string $userId, DateTimeInterface $weekStart): array
    {
        $weekEnd = clone $weekStart;
        if ($weekEnd instanceof \DateTime) {
            $weekEnd->modify('+7 days');
        }

        $watchTimeMinutes = $this->getUserWatchTimeMinutes($userId, $weekStart, $weekEnd);
        $newItemsCount = $this->getNewItemsCount($weekStart);
        $topMedia = $this->getTopMedia($userId, $weekStart, $weekEnd);

        $smarty = $this->createSmarty();
        $smarty->assign([
            'week_start' => $weekStart->format('M j, Y'),
            'week_end' => $weekEnd->format('M j, Y'),
            'week_watch_time_minutes' => $watchTimeMinutes,
            'week_watch_time_hours' => round($watchTimeMinutes / 60, 1),
            'new_items_count' => $newItemsCount,
            'top_media' => $topMedia,
            'user_id' => $userId,
            'year' => (int) date('Y'),
        ]);

        $subject = $this->renderSubject($smarty);
        $htmlBody = $smarty->fetch('emails/newsletter.tpl');

        return [
            'subject' => $subject,
            'html_body' => $htmlBody,
            'plain_text' => $this->generatePlainText($watchTimeMinutes, $newItemsCount, $topMedia),
            'week_watch_time_minutes' => $watchTimeMinutes,
            'new_items_count' => $newItemsCount,
            'top_media' => $topMedia,
        ];
    }

    /**
     * Get list of user IDs eligible for newsletter delivery.
     *
     * Returns users who have logged in within the configured active period.
     *
     * @return array<int, string> Array of user UUIDs
     */
    public function getRecipientUserIds(): array
    {
        $activeDays = $this->config['active_user_days'] ?? 30;

        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT DISTINCT user_id
             FROM stats_user_activity
             WHERE activity_type = 'login'
               AND occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY user_id",
            [$activeDays]
        );

        return array_map(function (array $row): string {
            $userId = $row['user_id'] ?? null;
            return is_string($userId) || is_numeric($userId) ? (string) $userId : '';
        }, $rows);
    }

    /**
     * Get total watch time in minutes for a user in a date range.
     *
     * @param string $userId User UUID
     * @param DateTimeInterface $from Start date
     * @param DateTimeInterface $to End date
     *
     * @return int Total watch time in minutes
     */
    private function getUserWatchTimeMinutes(
        string $userId,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): int {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(duration_seconds), 0) AS total_seconds
             FROM stats_playback_events
             WHERE user_id = ?
               AND started_at >= ?
               AND started_at <= ?",
            [$userId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
        );

        $totalSeconds = 0;
        if (isset($rows[0]['total_seconds']) && is_numeric($rows[0]['total_seconds'])) {
            $totalSeconds = (int) $rows[0]['total_seconds'];
        }
        return (int) ceil($totalSeconds / 60);
    }

    /**
     * Get count of new media items added since a date.
     *
     * @param DateTimeInterface $since Count items added after this date
     *
     * @return int Number of new items
     */
    private function getNewItemsCount(DateTimeInterface $since): int
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT COUNT(*) AS item_count
             FROM media_items
             WHERE created_at >= ?
               AND library_id IS NOT NULL",
            [$since->format('Y-m-d H:i:s')]
        );

        $count = 0;
        if (isset($rows[0]['item_count']) && is_numeric($rows[0]['item_count'])) {
            $count = (int) $rows[0]['item_count'];
        }
        return $count;
    }

    /**
     * Get top media items for a user in a date range.
     *
     * @param string $userId User UUID
     * @param DateTimeInterface $from Start date
     * @param DateTimeInterface $to End date
     *
     * @return array<int, array{media_item_id: string, name: string, poster_url: string|null, play_count: int}>
     */
    private function getTopMedia(
        string $userId,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        $limit = $this->config['top_media_limit'] ?? 5;

        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                 p.media_item_id,
                 COALESCE(m.name, 'Unknown') AS name,
                 COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.metadata_json, '$.poster')), NULL) AS poster_url,
                 COUNT(*) AS play_count
             FROM stats_playback_events p
             LEFT JOIN media_items m ON m.id = p.media_item_id
             WHERE p.user_id = ?
               AND p.started_at >= ?
               AND p.started_at <= ?
             GROUP BY p.media_item_id, m.name, poster_url
             ORDER BY play_count DESC
             LIMIT ?",
            [$userId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $limit]
        );

        return array_map(function (array $row): array {
            $posterUrl = $row['poster_url'] ?? null;
            if (is_string($posterUrl) && str_starts_with($posterUrl, '"')) {
                $decoded = json_decode($posterUrl, true);
                $posterUrl = is_string($decoded) ? $decoded : $posterUrl;
            }

            return [
                'media_item_id' => is_string($row['media_item_id']) ? $row['media_item_id'] : '',
                'name' => is_string($row['name']) ? $row['name'] : 'Unknown',
                'poster_url' => is_string($posterUrl) ? $posterUrl : null,
                'play_count' => is_numeric($row['play_count']) ? (int) $row['play_count'] : 0,
            ];
        }, $rows);
    }

    /**
     * Render the email subject line using Smarty.
     *
     * @param Smarty $smarty Configured Smarty instance
     *
     * @return string Rendered subject
     */
    private function renderSubject(Smarty $smarty): string
    {
        $template = $this->config['subject_template'] ?? 'Your Phlex Weekly Watch Report';
        $smarty->assign('week_start', $smarty->getTemplateVars('week_start'));
        return is_string($template) ? $template : 'Your Phlex Weekly Watch Report';
    }

    /**
     * Create a configured Smarty instance.
     *
     * @return Smarty Configured Smarty instance
     */
    private function createSmarty(): Smarty
    {
        $smarty = new Smarty();
        $smarty->setTemplateDir($this->templateDir);

        $compileDir = sys_get_temp_dir() . '/smarty/templates_c';
        if (!is_dir($compileDir)) {
            mkdir($compileDir, 0755, true);
        }
        $smarty->setCompileDir($compileDir);

        $cacheDir = sys_get_temp_dir() . '/smarty/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $smarty->setCacheDir($cacheDir);

        $smarty->setForceCompile(false);

        return $smarty;
    }

    /**
     * Generate plain text version of the newsletter.
     *
     * @param int $watchTimeMinutes Total watch time in minutes
     * @param int $newItemsCount Number of new items added
     * @param array<int, array{media_item_id: string, name: string, play_count: int}> $topMedia Top media items
     *
     * @return string Plain text email body
     */
    private function generatePlainText(
        int $watchTimeMinutes,
        int $newItemsCount,
        array $topMedia
    ): string {
        $hours = round($watchTimeMinutes / 60, 1);

        $text = "Your Phlex Weekly Watch Report\n";
        $text .= str_repeat('=', 40) . "\n\n";
        $text .= "Watch Time: {$hours} hours\n";
        $text .= "New Items Added: {$newItemsCount}\n\n";

        if (!empty($topMedia)) {
            $text .= "Top Media This Week:\n";
            foreach ($topMedia as $index => $media) {
                $num = $index + 1;
                $text .= "  {$num}. {$media['name']} ({$media['play_count']} plays)\n";
            }
        }

        $text .= "\n---\n";
        $text .= "Sent by Phlex Media Server\n";

        return $text;
    }
}
