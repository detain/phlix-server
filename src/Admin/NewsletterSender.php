<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Admin;

use DateTimeInterface;
use Phlix\Common\Database\WriteResult;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Workerman\MySQL\Connection;

/**
 * NewsletterSender handles newsletter email queue processing and delivery.
 *
 * This class manages the newsletter delivery queue, processes pending
 * emails in batches, and tracks delivery statistics.
 *
 * @author Phlix Team
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
            'from_email' => 'phlix@example.com',
            'from_name' => 'Phlix Media Server',
            'max_attempts' => 3,
        ], $config);
    }

    /**
     * Queue newsletter emails for all eligible users.
     *
     * Inserts one row per recipient into the newsletter_queue table
     * with status 'pending' for the specified week.
     *
     * Deferred-item #54: `uk_newsletter_user_week` (migration 125) makes
     * (user_id, week_start) UNIQUE — a pre-125 re-fired campaign used to
     * DOUBLE-queue (and thereby double-mail) anyone already holding a row
     * for the week. The INSERT is now IGNORE-shaped, so re-firing an
     * already-queued recipient is a no-op at the storage layer rather than a
     * second email. Workerman's `Connection::query()` returns
     * `lastInsertId()` for an INSERT that changed rows and NULL when
     * rowCount() came back 0 (vendor Connection.php:1859-1863), so
     * `!WriteResult::wroteNothing()` is exactly "this call created a row" —
     * the S342 single-idiom for a consumed INSERT result (the string '0'
     * that lastInsertId returns for this non-auto-increment PK is a REAL
     * write, which is precisely the hand-rolled-falsy-trap the helper
     * exists to end). The return count stays honest under IGNORE (IGNORE can
     * only hide duplicate-key 1062 here: id is a random UUID PK, and every column the loop binds is
     * pre-validated or generated, so no other coercible-violation class
     * reaches the statement).
     *
     * @param array<int, string> $userIds Array of user UUIDs to queue
     * @param DateTimeInterface $weekStart Start date of the week
     *
     * @return int Number of queue entries CREATED by this call (already
     *             queued duplicates are no-ops and are not counted)
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
            $created = $this->db->query(
                "INSERT IGNORE INTO newsletter_queue
                 (id, user_id, week_start, status, attempts, created_at)
                 VALUES (?, ?, ?, 'pending', 0, NOW())",
                [$id, $userId, $weekStartStr]
            );
            if (!WriteResult::wroteNothing($created)) {
                $count++;
            }
        }

        $this->log('info', 'Newsletter queue entries created', ['count' => $count]);

        return $count;
    }

    /**
     * Process one batch of pending newsletter queue entries.
     *
     * Processes up to batchSize pending rows whose attempt budget is still
     * eligible (`attempts < max_attempts` — the SAME predicate that bounds
     * {@see getPendingCount()} and {@see reapExhausted()}; the divergence
     * between the two queries was the infinite-drain defect). This method
     * NEVER drains the whole queue: each row's `@mail()` blocks the count=1
     * background-timers worker for seconds, so the caller's sweep re-calls
     * while progress continues under its own per-sweep cap.
     *
     * A row whose send raises is marked 'failed' carrying the transport
     * message; a row the process was killed mid-attempt stays 'pending' with
     * its budget decremented, becomes ineligible once the budget runs out,
     * and is landed in 'failed' by {@see reapExhausted()} — so no exhausted
     * row can hold the pending count above zero forever.
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

            // Explicit key match rather than isset($stats[$status]) with a dynamic
            // key: the dynamic write made the inferred shape open-ended, and it
            // also meant a row whose status happened to be the literal 'total'
            // would clobber the running total.
            if ($status === 'pending' || $status === 'sent' || $status === 'failed') {
                $stats[$status] = $count;
            }
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * Count pending entries that are still eligible for a send attempt.
     *
     * The predicate MUST stay identical to {@see processQueue}'s selection
     * (`status = 'pending' AND attempts < max_attempts`): a row that has
     * exhausted its attempt budget is pending in name only — no batch will
     * ever select it — and counting it is what made the timer's
     * `while (getPendingCount() > 0)` drain spin forever on a count=1 worker.
     * {@see \Phlix\Admin\NewsletterSchedule::drainBounded()} additionally keeps a
     * progress-is-zero guard, so a future predicate slip degrades to a
     * stopped sweep, never a wedge.
     *
     * @return int Number of eligible pending newsletter entries
     */
    public function getPendingCount(): int
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT COUNT(*) AS count
             FROM newsletter_queue
             WHERE status = 'pending'
               AND attempts < ?",
            [$this->config['max_attempts'] ?? 3]
        );

        $count = 0;
        if (isset($rows[0]['count']) && is_numeric($rows[0]['count'])) {
            $count = (int) $rows[0]['count'];
        }
        return $count;
    }

    /**
     * Land budget-exhausted pending rows in 'failed'.
     *
     * The boot/arm-time analog of MaintenanceQueueWorker::start() reaping
     * `running` rows orphaned by a restart: a row killed mid-attempt stays
     * 'pending' with its budget spent, invisible to processQueue and
     * (post-alignment) to getPendingCount — this method gives it a terminal,
     * operator-visible state with the reason, so the queue's accounting can
     * never hide a permanently unservable row.
     *
     * @return int Number of rows landed in 'failed' by this call
     */
    public function reapExhausted(): int
    {
        $result = $this->db->query(
            "UPDATE newsletter_queue
             SET status = 'failed', error_message = ?
             WHERE status = 'pending'
               AND attempts >= ?",
            [NewsletterSchedule::NOTE_EXHAUSTED_ATTEMPTS, $this->config['max_attempts'] ?? 3]
        );

        $reaped = is_numeric($result) ? (int) $result : 0;
        if ($reaped > 0) {
            $this->log('warning', 'Newsletter queue reaped exhausted-attempt pending rows', [
                'count' => $reaped,
            ]);
        }

        return $reaped;
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
        $generator = new \Phlix\Admin\NewsletterGenerator(
            new \Phlix\Stats\StatsCollector($this->db),
            $this->createLibraryManager(),
            $this->db,
            $templateDir,
            $this->config
        );

        $content = $generator->generateForUser($userId, $weekStart);

        $userName = isset($user['username']) && is_string($user['username']) ? $user['username'] : $userEmail;
        $fromName = is_string($this->config['from_name'] ?? null) ? $this->config['from_name'] : 'Phlix Media Server';
        $fromEmail = is_string($this->config['from_email'] ?? null) ? $this->config['from_email'] : 'phlix@example.com';

        $wire = self::composeEmail(
            $content,
            $userEmail,
            $userName,
            $fromName,
            $fromEmail,
            self::generateMimeBoundary()
        );

        $result = @mail(
            $wire['envelope_to'],
            $wire['subject'],
            $wire['body'],
            $wire['headers']
        );

        if (!$result) {
            throw new \RuntimeException('Failed to send email');
        }

        return true;
    }

    /**
     * Generate the per-message MIME boundary (RFC 2046 requires it to appear
     * nowhere else in the message; a fresh 128-bit random hex payload under a
     * fixed prefix cannot collide with generated newsletter content).
     */
    private static function generateMimeBoundary(): string
    {
        return 'phlix-' . bin2hex(random_bytes(16));
    }

    /**
     * Neutralise one display-name slot for an RFC 5322 header.
     *
     * Every ASCII control byte (CR and LF first among them — the SH-011
     * injection class: `users.username` is bounded only by trim + length
     * 3-50 in AuthManager::register, so CRLF reaches here verbatim) is
     * removed, then `"` and `\` are backslash-escaped and the result rides a
     * quoted-string, so commas, semicolons and angle brackets stay cosmetic
     * instead of forging separators or address boundaries.
     */
    private static function headerDisplayName(string $raw): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $raw);

        // Backslash must be doubled BEFORE the quote is escaped, or the
        // first pass would re-escape the second pass's escapes.
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $clean);

        return '"' . $escaped . '"';
    }

    /**
     * Neutralise one addr-spec slot inside `<...>`. Control bytes, angle
     * brackets and backslashes cannot appear in an address, so they are
     * removed outright (config-fed today, but the config read is the class's
     * own trust boundary and stays guarded like every other slot).
     */
    private static function headerAddress(string $raw): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F<>\\\\]/', '', $raw);
    }

    /**
     * Compose the newsletter's exact mail() wire set — the single place
     * header framing and MIME structure are decided (review SH-011/SH-015).
     *
     * The envelope recipient is the DB email, never a header value; the
     * display slots go through the quoted-string guard above; and the body
     * is a real `multipart/alternative` so the generator's `plain_text`
     * copy reaches text-only readers instead of dying unread in $content
     * (its previous wiring declared text/html and passed only the HTML
     * body). Parts ride base64 (RFC 2046 §5.1.4 ordering: plain first).
     *
     * @param array<string, mixed> $content generateForUser() output
     * @param string $userEmail  envelope recipient from the users row
     * @param string $userName   display name for To: (user-controlled data)
     * @param string $fromName   config display name
     * @param string $fromEmail  config address
     * @param string $boundary   MIME boundary from generateMimeBoundary()
     *
     * @return array{envelope_to: string, subject: string, body: string, headers: string}
     */
    private static function composeEmail(
        array $content,
        string $userEmail,
        string $userName,
        string $fromName,
        string $fromEmail,
        string $boundary
    ): array {
        $subject = is_string($content['subject'] ?? null) ? $content['subject'] : '';
        $htmlBody = is_string($content['html_body'] ?? null) ? $content['html_body'] : '';
        $plainText = is_string($content['plain_text'] ?? null) ? $content['plain_text'] : '';

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'From: ' . self::headerDisplayName($fromName) . ' <' . self::headerAddress($fromEmail) . '>',
            'To: ' . self::headerDisplayName($userName),
            'X-Mailer: Phlix-Media-Server',
        ];

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($plainText), 76, "\r\n")
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody), 76, "\r\n")
            . "--{$boundary}--\r\n";

        return [
            'envelope_to' => $userEmail,
            // PHP mail() puts the subject into the header block itself, so
            // it obeys the same no-control-byte law as every other slot.
            'subject' => (string) preg_replace('/[\x00-\x1F\x7F]/', '', $subject),
            'body' => $body,
            'headers' => implode("\r\n", $headers),
        ];
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
     * @return \Phlix\Media\Library\LibraryManager Library manager instance
     */
    private function createLibraryManager(): \Phlix\Media\Library\LibraryManager
    {
        $musicScanner = new \Phlix\Media\Music\MusicLibraryScanner(
            $this->db,
            new \Phlix\Media\Transcoding\FfmpegRunner()
        );
        $musicLibraryService = new \Phlix\Media\Music\MusicLibraryService($this->db, $musicScanner);

        return new \Phlix\Media\Library\LibraryManager(
            $this->db,
            new \Phlix\Media\Library\MediaScanner(
                $this->db,
                new \Phlix\Media\Library\ItemRepository($this->db)
            ),
            new \Phlix\Media\Library\FolderWatcher(),
            $musicLibraryService
        );
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string Formatted UUID string
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
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
