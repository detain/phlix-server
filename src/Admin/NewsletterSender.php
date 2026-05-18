<?php

declare(strict_types=1);

namespace Phlex\Admin;

use DateTimeInterface;
use Phlex\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * NewsletterSender handles newsletter email queue processing and delivery.
 *
 * This class manages the newsletter delivery queue, processes pending
 * emails in batches, and tracks delivery statistics.
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Handles newsletter email queue processing and delivery
 */
class NewsletterSender
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var StructuredLogger|null Optional logger for tracking operations */
    private ?StructuredLogger $logger;

    /** @var array<string, mixed> Configuration options */
    private array $config;

    /**
     * Create a new NewsletterSender instance.
     *
     * @param Connection $db Database connection
     * @param StructuredLogger|null $logger Optional structured logger
     * @param array<string, mixed> $config Configuration options
     */
    public function __construct(
        Connection $db,
        ?StructuredLogger $logger = null,
        array $config = []
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = array_merge([
            'batch_size' => 50,
            'from_email' => 'phlex@example.com',
            'from_name' => 'Phlex Media Server',
            'max_attempts' => 3,
        ], $config);
    }

    /**
     * Queue newsletter emails for all eligible users.
     *
     * Inserts one row per recipient into the newsletter_queue table
     * with status 'pending' for the specified week.
     *
     * @param array<int, string> $userIds Array of user UUIDs to queue
     * @param DateTimeInterface $weekStart Start date of the week
     *
     * @return int Number of queue entries created
     */
    public function queueAll(array $userIds, DateTimeInterface $weekStart): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $count = 0;
        $weekStartStr = $weekStart->format('Y-m-d');

        $this->log('info', 'Queueing newsletters', [
            'user_count' => count($userIds),
            'week_start' => $weekStartStr,
        ]);

        foreach ($userIds as $userId) {
            $id = $this->generateUuid();
            $this->db->query(
                "INSERT INTO newsletter_queue
                 (id, user_id, week_start, status, attempts, created_at)
                 VALUES (?, ?, ?, 'pending', 0, NOW())",
                [$id, $userId, $weekStartStr]
            );
            $count++;
        }

        $this->log('info', 'Newsletter queue entries created', ['count' => $count]);

        return $count;
    }

    /**
     * Process pending newsletter queue entries.
     *
     * Processes up to batchSize pending rows, attempting to send each
     * newsletter and marking as 'sent' on success or 'failed' on error.
     *
     * @param int $batchSize Maximum number of entries to process
     *
     * @return int Number of entries processed
     */
    public function processQueue(int $batchSize = 50): int
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, user_id, week_start
             FROM newsletter_queue
             WHERE status = 'pending'
               AND attempts < ?
             ORDER BY created_at ASC
             LIMIT ?",
            [$this->config['max_attempts'] ?? 3, $batchSize]
        );

        if (empty($rows)) {
            return 0;
        }

        $processed = 0;

        foreach ($rows as $row) {
            $queueId = is_string($row['id']) || is_numeric($row['id']) ? (string) $row['id'] : '';
            $userId = is_string($row['user_id']) || is_numeric($row['user_id']) ? (string) $row['user_id'] : '';
            $weekStartStr = is_string($row['week_start']) ? $row['week_start'] : '';

            $this->db->query(
                "UPDATE newsletter_queue
                 SET attempts = attempts + 1,
                     last_attempt_at = NOW()
                 WHERE id = ?",
                [$queueId]
            );

            try {
                $this->sendNewsletter($userId, $weekStartStr);

                $this->db->query(
                    "UPDATE newsletter_queue
                     SET status = 'sent', sent_at = NOW()
                     WHERE id = ?",
                    [$queueId]
                );

                $processed++;

                $this->log('debug', 'Newsletter sent', [
                    'queue_id' => $queueId,
                    'user_id' => $userId,
                ]);
            } catch (\Throwable $e) {
                $this->db->query(
                    "UPDATE newsletter_queue
                     SET status = 'failed', error_message = ?
                     WHERE id = ?",
                    [$e->getMessage(), $queueId]
                );

                $this->log('error', 'Newsletter send failed', [
                    'queue_id' => $queueId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->log('info', 'Newsletter batch processed', [
            'processed' => $processed,
            'attempted' => count($rows),
        ]);

        return $processed;
    }

    /**
     * Get delivery statistics for the newsletter queue.
     *
     * @return array{
     *     pending: int,
     *     sent: int,
     *     failed: int,
     *     total: int
     * }
     */
    public function getDeliveryStats(): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT status, COUNT(*) AS count
             FROM newsletter_queue
             GROUP BY status"
        );

        $stats = [
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = is_string($row['status']) ? $row['status'] : '';
            $count = is_numeric($row['count']) ? (int) $row['count'] : 0;

            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * Get pending queue count.
     *
     * @return int Number of pending newsletter entries
     */
    public function getPendingCount(): int
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT COUNT(*) AS count
             FROM newsletter_queue
             WHERE status = 'pending'"
        );

        $count = 0;
        if (isset($rows[0]['count']) && is_numeric($rows[0]['count'])) {
            $count = (int) $rows[0]['count'];
        }
        return $count;
    }

    /**
     * Send a newsletter email to a specific user.
     *
     * This method should be overridden in production to use an actual
     * email sending service. Currently uses PHP mail() function.
     *
     * @param string $userId User UUID to send to
     * @param string $weekStartStr Week start date string
     *
     * @return bool True on success
     *
     * @throws \RuntimeException If email sending fails
     */
    protected function sendNewsletter(string $userId, string $weekStartStr): bool
    {
        $userEmail = $this->getUserEmail($userId);

        if ($userEmail === null) {
            throw new \RuntimeException("User email not found for user: {$userId}");
        }

        $user = $this->getUser($userId);
        $weekStart = new \DateTime($weekStartStr);

        $configTemplateDir = $this->config['template_dir'] ?? null;
        $templateDir = is_string($configTemplateDir) ? $configTemplateDir : 'public/templates';
        $generator = new \Phlex\Admin\NewsletterGenerator(
            new \Phlex\Stats\StatsCollector($this->db),
            $this->createLibraryManager(),
            $this->db,
            $templateDir,
            $this->config
        );

        $content = $generator->generateForUser($userId, $weekStart);

        $userName = isset($user['username']) && is_string($user['username']) ? $user['username'] : $userEmail;
        $fromName = is_string($this->config['from_name'] ?? null) ? $this->config['from_name'] : 'Phlex Media Server';
        $fromEmail = is_string($this->config['from_email'] ?? null) ? $this->config['from_email'] : 'phlex@example.com';
        $toAddress = $userName;

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            "From: {$fromName} <{$fromEmail}>",
            "To: {$toAddress}",
            'X-Mailer: Phlex-Media-Server',
        ];

        $result = @mail(
            $userEmail,
            $content['subject'],
            $content['html_body'],
            implode("\r\n", $headers)
        );

        if (!$result) {
            throw new \RuntimeException('Failed to send email');
        }

        return true;
    }

    /**
     * Get user email address by user ID.
     *
     * @param string $userId User UUID
     *
     * @return string|null Email address or null if not found
     */
    private function getUserEmail(string $userId): ?string
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT email FROM users WHERE id = ?",
            [$userId]
        );

        $email = $rows[0]['email'] ?? null;
        return is_string($email) ? $email : null;
    }

    /**
     * Get user data by user ID.
     *
     * @param string $userId User UUID
     *
     * @return array<string, mixed> User data array
     */
    private function getUser(string $userId): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, username, email FROM users WHERE id = ?",
            [$userId]
        );

        return $rows[0] ?? [];
    }

    /**
     * Create a library manager instance for newsletter generation.
     *
     * @return \Phlex\Media\Library\LibraryManager Library manager instance
     */
    private function createLibraryManager(): \Phlex\Media\Library\LibraryManager
    {
        return new \Phlex\Media\Library\LibraryManager(
            $this->db,
            new \Phlex\Media\Library\MediaScanner(
                $this->db,
                new \Phlex\Media\Library\ItemRepository($this->db)
            ),
            new \Phlex\Media\Library\FolderWatcher()
        );
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string Formatted UUID string
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

    /**
     * Log a message if logger is available.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Log context
     *
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level($message, $context);
        }
    }
}
