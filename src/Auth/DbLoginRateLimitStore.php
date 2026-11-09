<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
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
 *   - ip (VARCHAR(45) PK): client IP address                    [074]
 *   - identifier (VARCHAR(255) PK): normalized submitted account
 *     identifier — the SH-017 per-account budget                [126]
 *   - attempts (INT): failed login count in current window
 *   - reset_at (INT UNSIGNED): Unix timestamp when window expires
 *   - created_at (TIMESTAMP): entry creation time
 *
 * Both windows are charged together by recordFailedAttempt() and released
 * together by clear(); check() evaluates the IP first so the exception
 * precedence of the 074 ladder is preserved exactly.
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

    /**
     * The per-IP window table (migration 074).
     */
    private const string IP_TABLE = 'login_rate_limit';

    /**
     * The per-account window table (SH-017, migration 126).
     */
    private const string ACCOUNT_TABLE = 'login_rate_limit_account';

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
     * Check if the client IP — and, when given, the submitted account
     * identifier — are still inside their brute-force budgets.
     *
     * Removes an expired window on sight and allows the attempt. The IP
     * window is evaluated FIRST so a mixed IP-over/account-under state keeps
     * the exact exception precedence the ladder has had since migration 074
     * (SH-017 adds a second budget, it does not reorder the first).
     *
     * @param string       $ip          Client IP address
     * @param int          $maxAttempts Maximum allowed attempts in the window
     * @param string|null  $accountKey  Normalized submitted identifier, or
     *                                  null when the caller has none (then
     *                                  only the IP window applies)
     *
     * @return bool true if the attempt is allowed (both windows under limit,
     *               or their windows expired)
     *
     * @throws RateLimitException When either budget is exhausted
     */
    public function check(string $ip, int $maxAttempts, ?string $accountKey = null): bool
    {
        $this->checkWindow(self::IP_TABLE, 'ip', $ip, $maxAttempts);
        if ($accountKey !== null && $accountKey !== '') {
            $this->checkWindow(self::ACCOUNT_TABLE, 'identifier', $accountKey, $maxAttempts);
        }

        return true;
    }

    /**
     * One window-table read: expired ⇒ sweep and allow; at budget ⇒ throw.
     *
     * @param string $table  Window table (self::IP_TABLE | self::ACCOUNT_TABLE)
     * @param string $column Its key column ('ip' | 'identifier')
     * @param string $key    The bound key value
     * @param int    $maxAttempts Budget for this window
     *
     * @throws RateLimitException When this window is over budget
     */
    private function checkWindow(string $table, string $column, string $key, int $maxAttempts): void
    {
        $now = time();

        // Fetch the entry
        $result = $this->db->query(
            "SELECT attempts, reset_at FROM {$table} WHERE {$column} = ?",
            [$key]
        );

        if (!is_array($result) || !isset($result[0])) {
            // No record = not rate limited
            return;
        }

        /** @var array{attempts: int, reset_at: int} $row */
        $row = $result[0];
        $resetAt = (int) $row['reset_at'];
        $attempts = (int) $row['attempts'];

        // Window expired — delete and allow
        if ($resetAt <= $now) {
            $this->db->query("DELETE FROM {$table} WHERE {$column} = ?", [$key]);
            return;
        }

        // Over limit
        if ($attempts >= $maxAttempts) {
            throw new RateLimitException(
                resetAt: $resetAt,
                remaining: 0
            );
        }
    }

    /**
     * Record a failed login attempt against the IP window and, when given,
     * the account window (SH-017).
     *
     * Creates a new entry if none exists, or increments the attempt count
     * if the window has not yet expired.
     *
     * @param string      $ip         Client IP address
     * @param string|null $accountKey Normalized submitted identifier or null
     *
     * @return void
     */
    public function recordFailedAttempt(string $ip, ?string $accountKey = null): void
    {
        $this->recordInWindow(self::IP_TABLE, 'ip', $ip);
        if ($accountKey !== null && $accountKey !== '') {
            $this->recordInWindow(self::ACCOUNT_TABLE, 'identifier', $accountKey);
        }

        // Cleanup expired entries (lazy cleanup to prevent bloat)
        $this->cleanupExpiredEntries();
    }

    /**
     * The upsert-shaped charge against one window table.
     */
    private function recordInWindow(string $table, string $column, string $key): void
    {
        $now = time();
        $resetAt = $now + $this->windowSeconds;

        // Try to insert — on duplicate key, update the attempts count
        $result = $this->db->query(
            "INSERT INTO {$table} ({$column}, attempts, reset_at) VALUES (?, 1, ?) "
            . 'ON DUPLICATE KEY UPDATE attempts = attempts + 1, reset_at = IF(reset_at <= ?, ?, reset_at)',
            [$key, $resetAt, $now, $resetAt]
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
                "UPDATE {$table} SET attempts = attempts + 1, reset_at = ? WHERE {$column} = ? AND reset_at > ?",
                [$resetAt, $key, $now]
            );
        }
    }

    /**
     * Clear rate limit data after successful auth: the client IP window and,
     * when given, the submitted account window (SH-017 — a correct password
     * must release BOTH budgets the failure path charged).
     *
     * @param string      $ip         Client IP address
     * @param string|null $accountKey Normalized submitted identifier or null
     *
     * @return void
     */
    public function clear(string $ip, ?string $accountKey = null): void
    {
        $this->db->query('DELETE FROM login_rate_limit WHERE ip = ?', [$ip]);
        if ($accountKey !== null && $accountKey !== '') {
            $this->db->query('DELETE FROM login_rate_limit_account WHERE identifier = ?', [$accountKey]);
        }
    }

    /**
     * Delete expired entries in batches from BOTH window tables to prevent
     * bloat.
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
        $this->db->query(
            'DELETE FROM login_rate_limit_account WHERE reset_at <= ? LIMIT ?',
            [time(), self::CLEANUP_BATCH_SIZE]
        );
    }
}
