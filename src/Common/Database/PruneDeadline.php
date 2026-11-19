<?php

/**
 * PruneDeadline — the deadline half of a retention-prune predicate.
 *
 * Part of the D-14 retention-prune SSOT ({@see RetentionPrune}). A deadline is
 * the pair (comparator, SQL time expression, bound params) every prune site
 * previously re-decided by hand: `<= ?` with a PHP time, `<= NOW()`,
 * `< NOW() - INTERVAL 86400 SECOND`, `< DATE_SUB(NOW(), INTERVAL ? DAY)`.
 * Encoding it once removes the drift class the dossier named (each copy
 * choosing its own operator/inlining on the same semantic window).
 *
 * ## Why some renderings inline and some bind
 *
 * `beforeNowMinusSeconds()`/`beforeDateSubNowSeconds()` inline their interval
 * (safe by construction: guard-checked non-negative int from a class const or
 * a constructor-bounded property) because every site that uses them inlines
 * today — byte-identity of the generated SQL is the migration contract.
 * `beforeDateSubNowDays()` BINDS `?` because the stats sweeper's committed
 * pin law requires the days to travel as a parameter. `atMostUnixTime()` binds
 * the timestamp for the same emulated-prepares reason the sites document.
 *
 * Instances are immutable value objects: factories only, no setters.
 */

declare(strict_types=1);

namespace Phlix\Common\Database;

use InvalidArgumentException;

final class PruneDeadline
{
    /**
     * @param string      $comparator '<' or '<=' — the row matches when
     *                                `column $comparator $expression`.
     * @param string      $expression SQL time expression for the right side.
     * @param list<int>   $params     Values the expression binds (in order).
     */
    private function __construct(
        public readonly string $comparator,
        public readonly string $expression,
        public readonly array $params
    ) {
    }

    /**
     * Rows whose timestamp column is at most the given Unix time.
     *
     * The integer travels as a bound parameter (`col <= ?`), matching the
     * rate-limit stores' committed binding law: these columns are INT
     * UNSIGNED, and under the project's emulated prepares the value must bind
     * as a native int (a string would quote it).
     *
     * @throws InvalidArgumentException on a negative Unix time
     */
    public static function atMostUnixTime(int $timestamp): self
    {
        if ($timestamp < 0) {
            throw new InvalidArgumentException(
                'Prune deadline Unix time must be non-negative, got ' . $timestamp . '.'
            );
        }

        return new self('<=', '?', [$timestamp]);
    }

    /**
     * Rows whose timestamp column is at most the database clock now.
     *
     * DB-clock posture (`expires_at <= NOW()`): the same law the OAuth state
     * stores document — cutoff and row write both use the DB clock so a
     * host/DB timezone split cannot skew the window.
     */
    public static function notAfterNow(): self
    {
        return new self('<=', 'NOW()', []);
    }

    /**
     * Rows older than `seconds` before the database clock, as
     * `col < NOW() - INTERVAL {seconds} SECOND`.
     *
     * @throws InvalidArgumentException on a negative interval
     */
    public static function beforeNowMinusSeconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Prune deadline interval must be non-negative, got ' . $seconds . '.');
        }

        return new self('<', 'NOW() - INTERVAL ' . $seconds . ' SECOND', []);
    }

    /**
     * Rows older than `seconds` before the database clock, expressed through
     * the portable `DATE_SUB` spelling:
     * `col < DATE_SUB(NOW(), INTERVAL {seconds} SECOND)`.
     *
     * Same window as {@see beforeNowMinusSeconds()}; the spelling is preserved
     * for the site (casting_sessions) that documents `DATE_SUB` on purpose.
     *
     * @throws InvalidArgumentException on a negative interval
     */
    public static function beforeDateSubNowSeconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Prune deadline interval must be non-negative, got ' . $seconds . '.');
        }

        return new self('<', 'DATE_SUB(NOW(), INTERVAL ' . $seconds . ' SECOND)', []);
    }

    /**
     * Rows older than `days` before the database clock, as
     * `col < DATE_SUB(NOW(), INTERVAL ? DAY)` with the days BOUND as a
     * parameter — the stats sweeper's committed cutoff law.
     *
     * @throws InvalidArgumentException on a negative interval
     */
    public static function beforeDateSubNowDays(int $days): self
    {
        if ($days < 0) {
            throw new InvalidArgumentException('Prune deadline interval must be non-negative, got ' . $days . '.');
        }

        return new self('<', 'DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
    }
}
