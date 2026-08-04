<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Common\Database\WriteResult;
use Workerman\MySQL\Connection;

/**
 * Database-backed login rate limit store.
 *
 * Replaces the per-worker static array in AuthManager with a central MySQL
 * table, ensuring the brute-force budget is unified across all workers
 * (previously ×14 with 14 workers) and bounded by TTL cleanup.
 *
 * Schema:
 *   - ip (VARCHAR(45) PK): client IP address
 *   - attempts (INT): failed login count in current window
 *   - reset_at (INT UNSIGNED): Unix timestamp when window expires
 *   - created_at (TIMESTAMP): entry creation time
 *
 * Cleanup strategy: lazy deletion on check() — expired entries are removed
 * when encountered, plus a configurable batch sweep to prevent table bloat.
 *
 * @since 0.44.0
 */
final class DbLoginRateLimitStore
{
    /**
     * Default TTL for rate limit windows in seconds (15 minutes).
     */
    private const int DEFAULT_WINDOW_SECONDS = 900;

    /**
     * Number of expired entries to delete per cleanup batch.
     */
    private const int CLEANUP_BATCH_SIZE = 100;

    private Connection $db;
    private int $windowSeconds;

    /**
     * Create a new DbLoginRateLimitStore instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     * @param int        $windowSeconds Optional window duration in seconds
     */
    public function __construct(Connection $db, int $windowSeconds = self::DEFAULT_WINDOW_SECONDS)
    {
        $this->db = $db;
        $this->windowSeconds = $windowSeconds > 0 ? $windowSeconds : self::DEFAULT_WINDOW_SECONDS;
    }

    /**
     * Check if the given IP is rate-limited.
     *
     * Removes the entry if the window has expired and returns false.
     * Throws RateLimitException if the attempt limit is reached.
     *
     * @param string $ip            Client IP address
     * @param int    $maxAttempts   Maximum allowed attempts in the window
     *
     * @return bool true if the IP is allowed (under limit, or window expired)
     *
     * @throws RateLimitException When the attempt limit is reached
     */
    public function check(string $ip, int $maxAttempts): bool
    {
        $now = time();

        // Fetch the entry
        $result = $this->db->query(
            'SELECT attempts, reset_at FROM login_rate_limit WHERE ip = ?',
            [$ip]
        );

        if (!is_array($result) || !isset($result[0])) {
            // No record = not rate limited
            return true;
        }

        /** @var array{attempts: int, reset_at: int} $row */
        $row = $result[0];
        $resetAt = (int) $row['reset_at'];
        $attempts = (int) $row['attempts'];

        // Window expired — delete and allow
        if ($resetAt <= $now) {
            $this->db->query('DELETE FROM login_rate_limit WHERE ip = ?', [$ip]);
            return true;
        }

        // Over limit
        if ($attempts >= $maxAttempts) {
            throw new RateLimitException(
                resetAt: $resetAt,
                remaining: 0
            );
        }

        return true;
    }

    /**
     * Record a failed login attempt for the given IP.
     *
     * Creates a new entry if none exists, or increments the attempt count
     * if the window has not yet expired.
     *
     * @param string $ip Client IP address
     *
     * @return void
     */
    public function recordFailedAttempt(string $ip): void
    {
        $now = time();
        $resetAt = $now + $this->windowSeconds;

        // Try to insert — on duplicate key, update the attempts count
        $result = $this->db->query(
            'INSERT INTO login_rate_limit (ip, attempts, reset_at) VALUES (?, 1, ?) '
            . 'ON DUPLICATE KEY UPDATE attempts = attempts + 1, reset_at = IF(reset_at <= ?, ?, reset_at)',
            [$ip, $resetAt, $now, $resetAt]
        );

        // This was `$result === false`, which could never fire: the client
        // THROWS on a real error and has no `return false` at all.
        //
        // The arm that replaces it is `null`, which for an INSERT means MySQL
        // reported zero affected rows. For THIS statement that is currently
        // unreachable by a second route as well: `ON DUPLICATE KEY UPDATE
        // attempts = attempts + 1` always changes the value, so MySQL reports 1
        // (inserted) or 2 (updated), never 0. The guard is kept and widened
        // because a brute-force budget must fail SAFE — if the upsert ever
        // stops writing (a reformat that hides the `insert` keyword from
        // {@see WriteResult} trap 3, or a future SQL change that can be a
        // no-op), the fallback UPDATE below still charges the attempt rather
        // than silently granting a free retry.
        if (WriteResult::wroteNothing($result)) {
            // Fallback: try to update existing row
            $this->db->query(
                'UPDATE login_rate_limit SET attempts = attempts + 1, reset_at = ? WHERE ip = ? AND reset_at > ?',
                [$resetAt, $ip, $now]
            );
        }

        // Cleanup expired entries (lazy cleanup to prevent bloat)
        $this->cleanupExpiredEntries();
    }

    /**
     * Clear rate limit data for a client IP after successful auth.
     *
     * @param string $ip Client IP address
     *
     * @return void
     */
    public function clear(string $ip): void
    {
        $this->db->query('DELETE FROM login_rate_limit WHERE ip = ?', [$ip]);
    }

    /**
     * Delete expired entries in batches to prevent table bloat.
     *
     * Called after each recordFailedAttempt() to lazily clean up stale entries.
     *
     * @return void
     */
    private function cleanupExpiredEntries(): void
    {
        // Bind BOTH params as integers. The project DB layer
        // (PhlixMySQLConnection/PooledMySQLConnection) uses emulated prepares with
        // type-aware binding: a PHP string maps to PDO::PARAM_STR, which PDO QUOTES,
        // so a stringified LIMIT would render `LIMIT '100'` → MySQL error 1064.
        // `reset_at` is INT UNSIGNED (migration 074), so time() is the correct type too.
        $this->db->query(
            'DELETE FROM login_rate_limit WHERE reset_at <= ? LIMIT ?',
            [time(), self::CLEANUP_BATCH_SIZE]
        );
    }
}
