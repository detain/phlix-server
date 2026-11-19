<?php

/**
 * Phlix media server component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Database;

/**
 * The single canonical detector for MySQL driver errors (refactor D-16).
 *
 * Before this class, every site that needed to know "was this failure a
 * duplicate key / a deadlock / a gone-away" hand-rolled its own read of the
 * exception — eight different forms across the estate (loose `(int)` casts
 * over `errorInfo[1]`, `=== 1213` strict identity, `str_contains('1062')`
 * anywhere in the message, `stripos('deadlock')` English text, a private
 * SQLSTATE regex). Those forms disagreed with each other on the two shapes
 * this connection actually produces:
 *
 *  1. the RAW `PDOException` from PDO — `errorInfo = [SQLSTATE, errno, text]`
 *     (vendor re-throws the caught instance verbatim on the reconnect-retry
 *     path and on `beginTrans()` failures, so `errorInfo` is intact); and
 *  2. the vendor RE-WRAP — `new PDOException('SQL:' . lastSQL() . ' ' . $msg,
 *     (int) SQLSTATE)` with `errorInfo === null` (Workerman\MySQL\Connection::
 *     execute()), so only the rendered message and the SQLSTATE-as-int
 *     survive. Our `execute()` override is the deliberate exception since
 *     TXMUTEX-A A7: its wrapper carries the original `errorInfo` and the
 *     previous exception, so `driverCode()` keeps wire authority through it.
 *
 * A message-rendered errno (`...: 1062 Duplicate entry`) is DRIVER TEXT, not
 * driver data: `lastSQL()` echoes the raw statement template, so digits in a
 * message without an `SQLSTATE[...]` segment can only come from query text or
 * row payloads. "1062 appears somewhere in the string" therefore mislabels an
 * unrelated failure as a duplicate key whenever a literal `1062`/`1213` rides
 * in the SQL or the echoed text — the exact defect class the hub ServerReaper
 * sweep-law (HUB-m56) already condemned for the English word "deadlock".
 *
 * This class reads wire identity only: `driverCode()` extracts the driver
 * errno from either shape, the predicates compare that code against named
 * constants, and message TEXT is consulted nowhere in this class (sites that
 * genuinely need a phrase belt — copy selection, vendor-re-wrap ambiguity —
 * keep it explicitly at the call site, which the migration comments call out).
 *
 * Twin law: `phlix-hub` carries this class at
 * {@see \Phlix\Hub\Common\Database\MysqlError} with an identical body
 * (namespace-adjusted only); a future lift into `phlix-shared` (DESIGN M-30)
 * retires both twins in one step.
 */
final class MysqlError
{
    /** MySQL `ER_DUP_ENTRY`: a UNIQUE/PRIMARY key violation. */
    public const ER_DUP_ENTRY = 1062;

    /** MySQL `ER_LOCK_WAIT_TIMEOUT`: the InnoDB lock-wait ceiling elapsed. */
    public const ER_LOCK_WAIT_TIMEOUT = 1205;

    /** MySQL `ER_LOCK_DEADLOCK`: this transaction was the deadlock victim. */
    public const ER_LOCK_DEADLOCK = 1213;

    /** MySQL client `CR_SERVER_GONE_ERROR`: "MySQL server has gone away". */
    public const CR_SERVER_GONE_ERROR = 2006;

    /** MySQL client `CR_SERVER_LOST`: lost connection during a query. */
    public const CR_SERVER_LOST = 2013;

    /** SQLSTATE class for integrity-constraint violations (carries 1062). */
    public const SQLSTATE_INTEGRITY = '23000';

    /** SQLSTATE class for serialization failures (carries 1213). */
    public const SQLSTATE_SERIALIZATION_FAILURE = '40001';

    /**
     * The English driver-message fragment every real 1062 rendering carries.
     * Shared so the message-text belts that survive at call sites read from
     * one declaration instead of four private string literals.
     */
    public const DUPLICATE_ENTRY_PHRASE = 'Duplicate entry';

    /**
     * No instances: this is a vocabulary, not an object.
     */
    private function __construct()
    {
    }

    /**
     * Is this driver code the duplicate-key rejection (1062)?
     *
     * Accepts the int a site parsed out of {@see driverCode()} or a numeric
     * string (the cast the loosest legacy form performed); non-numeric
     * strings are NEVER a driver code and return FALSE.
     */
    public static function isDuplicateKey(int|string $driverCode): bool
    {
        if (is_string($driverCode)) {
            if (!is_numeric($driverCode)) {
                return false;
            }

            $driverCode = (int) $driverCode;
        }

        return $driverCode === self::ER_DUP_ENTRY;
    }

    /**
     * Is this driver code the InnoDB deadlock victim (1213)?
     */
    public static function isDeadlock(int $driverCode): bool
    {
        return $driverCode === self::ER_LOCK_DEADLOCK;
    }

    /**
     * Extract the MySQL driver errno from either shape of a query failure.
     *
     * - `array` (a `PDOException::$errorInfo`): element [1] is the driver
     *   code. Returns it when numeric, otherwise null. An `errorInfo[1]` of
     *   0 is PDO's "no driver error" sentinel and returns null.
     * - `string` (an exception MESSAGE or a driver-error segment): a segment
     *   ALREADY at position 0 is the driver's own text and is parsed from
     *   there (MigrationRunner's exact-prefix strip leaves it at the start;
     *   echo decoys inside the driver's message cannot win). Otherwise exactly
     *   ONE `SQLSTATE[...]` rendering may be parsed — vendor rewraps prefix the
     *   echoed SQL ahead of the driver text, so a lone segment is the driver's
     *   own words. Several occurrences with none anchored are AMBIGUOUS and
     *   refused (null), never a last-wins guess. The parse is anchored to the
     *   segment start:
     *   `SQLSTATE[42S21]: Column already exists: 1060 …` → 1060. Bracketed
     *   connect failures (`SQLSTATE[HY000] [2002] Connection refused`)
     *   deliberately do NOT match — their digits sit in `[...]`, not at the
     *   `: (\d+)` driver-errno position (this is the MigrationRunner
     *   contract, ported verbatim).
     * - `null` → null.
     *
     * @param array<array-key,mixed>|string|null $errorInfo A PDOException
     *        $errorInfo array, an exception message, or a stripped
     *        driver-error segment.
     */
    public static function driverCode(array|string|null $errorInfo): ?int
    {
        if (is_array($errorInfo)) {
            $raw = $errorInfo[1] ?? null;
            if (!is_int($raw) && !(is_string($raw) && is_numeric($raw))) {
                return null;
            }

            $code = (int) $raw;

            return $code !== 0 ? $code : null;
        }

        if (is_string($errorInfo)) {
            // Wire-law segment selection: a segment ALREADY at position 0 is the
            // driver's own text (MigrationRunner's exact-prefix strip leaves it
            // there) — the anchored regex reads it even when the driver's echo
            // window carries decoys after it. Otherwise exactly ONE occurrence
            // may be parsed; several occurrences with none anchored are
            // ambiguous and refused outright — never a last-wins guess (the
            // production lesson pinned in MigrationRunner's decoy docblock).
            if (strncmp($errorInfo, 'SQLSTATE[', 9) === 0) {
                $segment = $errorInfo;
            } else {
                $first = strpos($errorInfo, 'SQLSTATE[');
                if ($first === false || substr_count($errorInfo, 'SQLSTATE[') !== 1) {
                    return null;
                }
                $segment = substr($errorInfo, $first);
            }
            if (preg_match('/^SQLSTATE\[[^\]]*\]:\s*[^:]*:\s*(\d+)\b/', $segment, $m) === 1) {
                $code = (int) $m[1];

                return $code !== 0 ? $code : null;
            }
        }

        return null;
    }
}
