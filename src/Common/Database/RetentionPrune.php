<?php

/**
 * RetentionPrune — the estate's one LIMIT-bounded retention-prune engine.
 *
 * D-14 SSOT: every hand-rolled `DELETE FROM ... WHERE <time> ... LIMIT n`
 * janitor in phlix-server (13 statements across 11 methods) converged here.
 * The generalized exemplar is the stats sweeper {@see
 * \Phlix\Stats\StatsCollector::sweepRetention()} — batch size, deadline, and
 * rows-deleted return — lifted verbatim so each site no longer re-decides the
 * batch/limit/clamp drift class the dupe-scan named.
 *
 * ## Two arms, one law each
 *
 *  - {@see sweep()} — the one-phase opportunistic batch delete used by the
 *    user-facing janitors (rate-limit sweeps, OAuth-state purges, session
 *    prunes). Single statement, single batch, bounded by `LIMIT ?`.
 *  - {@see sweepByIds()} — the two-phase loop the stats sweeper pioneered:
 *    non-locking `SELECT id ... ORDER BY id LIMIT n` harvest then one
 *    `DELETE ... WHERE id IN (...)`, never a DELETE with an unbounded range
 *    predicate (gap locks), retried on deadlock, capped batches per call.
 *
 * ## Failure posture (B-03.3 / HUB-5 janitor law, closed list)
 *
 * `$failLoud` encodes which law a call-site lives under:
 *
 *  - `false` (SOFT) — user-facing write paths (auth refresh, pairing polls,
 *    rate-limit decisions, OAuth consumes): a sweep that cannot run must never
 *    sink the request that triggered it. Doctrine source:
 *    QuickConnectStateStore::purgeExpired(), completed by the HUB-5.2 law:
 *    the swallow is LOUD on the error channel (error_log) — the mint/decision
 *    proceeds, the integrity signal is never silent. The engine swallows
 *    Throwable, logs, and returns what it deleted so far; the next invocation
 *    retries.
 *  - `true` (HARD) — maintenance timers (stats retention): the caller's timer
 *    catch owns logging + retry, so the engine propagates. Call-sites that
 *    chose soft-but-throwing behavior before this file existed were cured to
 *    soft during migration (disclosed per site in the D-14 register).
 *
 * ## Deadlock retry law
 *
 * {@see sweepByIds()} retries a deadlock victim a bounded number of times
 * (wire code authoritative per D-16 — `MysqlError::driverCode()` over
 * errorInfo/SQLSTATE segment, English `stripos 'deadlock'` belt only when the
 * wire is silent; 50 ms * attempt backoff), propagating anything else
 * and the final attempt — the hub ServerReaper idiom, ported here unchanged
 * from the stats exemplar. The estate-wide D-16 wire-code classifier
 * (MysqlError::driverCode authoritative over the English belt) LANDED in the
 * MYSQLERR lane and its StatsCollector refresh was applied here as the
 * one-expression swap the handoff asked for (uncommitted file — the D-14 lane
 * owns this file's commit).
 *
 * ## Identifier trust
 *
 * `$table`/`$column`/`$extraWhere` interpolate into SQL. They carry the
 * exemplar's trust law: they arrive only from class constants and literals at
 * the engine's call-sites, never from request data (phpstan-enforced: the
 * sites pass `self::CONST`/literals). `$batch` is guard-checked and bound.
 */

declare(strict_types=1);

namespace Phlix\Common\Database;

use PDOException;
use Throwable;
use Workerman\MySQL\Connection;

final class RetentionPrune
{
    /** Microseconds slept before deadlock retry N (50_000 * attempt). */
    private const DEADLOCK_RETRY_BACKOFF_MICROSECONDS = 50_000;

    /**
     * One bounded delete batch: `DELETE FROM table WHERE <predicate> LIMIT ?`.
     *
     * The batch rides the statement as a bound native int — a stringified
     * LIMIT would render `LIMIT '100'` under the project's emulated prepares
     * and MySQL 1064s. A batch below 1 refuses to sweep (returns 0 without a
     * statement): `LIMIT 0` churns, `LIMIT -1` is a syntax error, and either
     * would mean a mis-set site constant, not data to delete.
     *
     * @param string          $extraWhere Optional predicate joined with AND before
     *                                    the deadline term (e.g. `'provider = ?'`),
     *                                    from site literals only.
     * @param list<mixed>     $extraParams Values bound for $extraWhere, in order.
     * @param bool            $failLoud   See class docblock failure posture.
     *
     * @return int Rows deleted by this statement (0 on refusal or soft failure).
     */
    public static function sweep(
        Connection $db,
        string $table,
        string $column,
        PruneDeadline $deadline,
        int $batch,
        string $extraWhere = '',
        array $extraParams = [],
        bool $failLoud = true
    ): int {
        if ($batch < 1) {
            return 0;
        }

        $sql = 'DELETE FROM ' . $table
            . ' WHERE ' . self::predicate($extraWhere, $column, $deadline)
            . ' LIMIT ?';
        $params = [...$extraParams, ...$deadline->params, $batch];

        try {
            /** @var mixed $result */
            $result = $db->query($sql, $params);
        } catch (Throwable $e) {
            if ($failLoud) {
                throw $e;
            }

            // HUB-5.2 posture law, both halves: the request survives, but the
            // failure is LOUD on the error channel — never a silent swallow.
            error_log('RetentionPrune soft-fail: sweep on ' . $table . '.' . $column . ' threw: ' . $e->getMessage());

            return 0;
        }

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Drain expired rows through the two-phase batch loop (the stats-sweeper
     * law, generalized): per batch, harvest ids non-locked then delete them by
     * id; stop when a harvest comes back short (expired set drained) or after
     * $maxBatches (one tick cannot hog the loop).
     *
     * Each batch is deadlock-retried up to $maxRetries attempts (class
     * docblock law). Returns the running total when the soft arm swallows a
     * failure, or when the loop ends normally; propagates under the hard arm.
     *
     * @param int  $maxRetries Deadlock retries per batch (attempt counts the first).
     * @return int Total rows deleted across all batches this call.
     */
    public static function sweepByIds(
        Connection $db,
        string $table,
        string $column,
        PruneDeadline $deadline,
        int $batch,
        int $maxBatches,
        int $maxRetries,
        bool $failLoud = true
    ): int {
        if ($batch < 1) {
            return 0;
        }

        $deleted = 0;

        for ($round = 0; $round < $maxBatches; $round++) {
            try {
                $batchDeleted = self::deleteOneBatchByIds($db, $table, $column, $deadline, $batch, $maxRetries);
            } catch (Throwable $e) {
                if ($failLoud) {
                    throw $e;
                }

                // Loud on the error channel; the caller's path proceeds (HUB-5.2).
                error_log(
                    'RetentionPrune soft-fail: sweepByIds on ' . $table . '.' . $column
                    . ' after ' . $deleted . ' deleted threw: ' . $e->getMessage()
                );

                return $deleted;
            }

            $deleted += $batchDeleted;

            if ($batchDeleted < $batch) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * Assemble the WHERE body: `[extraWhere AND ]column op expression`.
     */
    private static function predicate(string $extraWhere, string $column, PruneDeadline $deadline): string
    {
        $prefix = $extraWhere === '' ? '' : $extraWhere . ' AND ';

        return $prefix . $column . ' ' . $deadline->comparator . ' ' . $deadline->expression;
    }

    /**
     * One two-phase batch with the bounded deadlock retry.
     *
     * @return int Rows deleted by this batch (0 when nothing is expired).
     */
    private static function deleteOneBatchByIds(
        Connection $db,
        string $table,
        string $column,
        PruneDeadline $deadline,
        int $batch,
        int $maxRetries
    ): int {
        for ($attempt = 1;; $attempt++) {
            try {
                /** @var mixed $idRows */
                $idRows = $db->query(
                    'SELECT id FROM ' . $table
                        . ' WHERE ' . self::predicate('', $column, $deadline)
                        . ' ORDER BY id LIMIT ' . $batch,
                    $deadline->params
                );

                $ids = self::harvestIds($idRows);

                if ($ids === []) {
                    return 0;
                }

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                /** @var mixed $result */
                $result = $db->query(
                    'DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')',
                    $ids
                );

                return is_numeric($result) ? (int) $result : 0;
            } catch (PDOException $e) {
                // Same retry law as hub ServerReaper::sweepHeartbeats: retry the
                // deadlock victim a bounded number of times, propagate anything
                // else (and the final deadlock) immediately. D-16 handoff
                // applied: the wire code is authoritative when it speaks
                // (errorInfo, else the rendered SQLSTATE segment); the English
                // belt survives only for wireless shapes, so a message merely
                // mentioning 'deadlock' next to a contradicting errno can no
                // longer buy a retry.
                $code = MysqlError::driverCode($e->errorInfo) ?? MysqlError::driverCode($e->getMessage());
                $isDeadlock = $code !== null
                    ? MysqlError::isDeadlock($code)
                    : stripos($e->getMessage(), 'deadlock') !== false;
                if (
                    $attempt >= $maxRetries
                    || !$isDeadlock
                ) {
                    throw $e;
                }
                usleep(self::DEADLOCK_RETRY_BACKOFF_MICROSECONDS * $attempt);
            }
        }
    }

    /**
     * Filter harvested `id` cells to bindable scalars.
     *
     * Ported verbatim from the stats exemplar: these tables' real PKs are
     * CHAR(36) UUIDs but depending on driver mode SELECT can hand back ints or
     * numeric strings; accept any non-empty scalar id and bind it through
     * verbatim — the DELETE matches by placeholder, so the id never
     * interpolates into SQL.
     *
     * @param mixed $idRows Raw query result (expected list of id-keyed rows).
     * @return list<int|string>
     */
    private static function harvestIds(mixed $idRows): array
    {
        $ids = [];

        if (!is_array($idRows)) {
            return $ids;
        }

        foreach ($idRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            if ((is_int($id) && $id >= 0) || (is_string($id) && $id !== '')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
