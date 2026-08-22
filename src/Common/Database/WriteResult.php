<?php

/**
 * Phlix media server component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Database;

/**
 * The one place that knows what `Workerman\MySQL\Connection::query()` returns
 * for a WRITE statement, and the one predicate every caller tests it with.
 *
 * ## The measured contract
 *
 * Read straight out of `vendor/workerman/mysql/src/Connection.php::query()`
 * (`:1854-1869` at the pinned version) — it dispatches on the FIRST
 * space-delimited token of the trimmed SQL:
 *
 * | first token                     | returns                                       |
 * |---------------------------------|-----------------------------------------------|
 * | `select` / `show`               | `array` from `PDOStatement::fetchAll()`       |
 * | `update` / `delete` / `replace` | `int` from `PDOStatement::rowCount()`         |
 * | `insert`, rowCount > 0          | `string` from `PDO::lastInsertId()`           |
 * | `insert`, rowCount = 0          | **`null`** (falls out of the `if`)            |
 * | anything else                   | **`null`** (the `else` arm)                   |
 *
 * A genuine error never reaches any of those rows: `execute()` (`:1742-1786`)
 * rethrows every `PDOException` after one reconnect attempt, so a failed write
 * arrives as a **throw**, not as a return value.
 *
 * ### Trap 1 — `false` is a value this client does not produce
 *
 * There is no `return false` anywhere in `query()`. `PDO::lastInsertId()` is
 * declared `string|false`, but the MySQL driver answers with a string. So a
 * `$result === false` check is **simultaneously unreachable and blind to
 * `null`** — it can never fire, and it misses the one falsy value that IS
 * returned. That shape is what this class replaced. The `false` arm is kept
 * for defensive breadth (a driver swap, or a `lastInsertId()` that honours its
 * declared `false`), NOT because it is reachable today; do not "simplify" it
 * away on the grounds that nothing produces it.
 *
 * ### Trap 2 — 🔴 a SUCCESSFUL insert can return the string `'0'`, which is FALSY
 *
 * Almost every Phlix primary key is a `CHAR(36)` UUID minted in PHP, with **no
 * `AUTO_INCREMENT` column on the table**. `PDO::lastInsertId()` therefore
 * answers `'0'` — and `'0'` is falsy in PHP. So the obvious "simplification"
 * `if (!$result)` reads a *successful* insert as a failure. Live examples:
 * `media_items`, `library_scan_jobs`, `oauth_state_store`, and
 * `profile_stream_limits` (`migrations/063_device_stream_limits.sql:6-11` —
 * `profile_id CHAR(36) PRIMARY KEY`). **Always test identity, never
 * truthiness.**
 *
 * ### Trap 3 — the keyword match is whitespace-sensitive
 *
 * `explode(" ", $query)[0]` splits on a literal SPACE, so a statement whose
 * verb is followed by a newline rather than a space — `"INSERT\nINTO …"`, the
 * natural heredoc layout — does not match `'insert'` and falls to the `else`
 * arm, returning `null` for a write that succeeded. Reformatting SQL can
 * therefore move a site from one row of the table above to another without
 * changing a single semantic. This is why the `null` arm matters at sites
 * where the driver's own contract says `null` is impossible.
 *
 * ## What this predicate is, and what it is NOT
 *
 * {@see self::wroteNothing()} answers exactly one question — *"did this
 * statement demonstrably write nothing?"* — and deliberately does **not**
 * decide what that means. The interpretation is per-site, and it is not
 * uniform:
 *
 *  - {@see \Phlix\Media\Music\MusicLibraryScanner} treats it as a LOSS and
 *    charges the file as failed.
 *  - {@see \Phlix\Media\Library\ScanJobRepository::startRunningIfIdle()} and
 *    {@see \Phlix\Media\Library\ScanJobRepository::enqueueIfNoneActiveOfType()}
 *    adopt this predicate as their decision rule: a "wrote nothing" answer is a
 *    deliberate REFUSAL (`INSERT … WHERE NOT EXISTS` wrote nothing because a
 *    job is already active), and both hand "no job created" back to the caller.
 *  - {@see \Phlix\Access\StreamSessionService::updateStreamLimit()} treats it
 *    as SUCCESS: an `INSERT … ON DUPLICATE KEY UPDATE` whose values are
 *    already current returns `null` (measured against real MySQL 8), and
 *    "the row already says what you asked for" is not a failure. That site
 *    therefore must NOT invert this predicate into its return value.
 *
 * @since 0.37.0 (S131 — promoted from `MusicLibraryScanner::statementWroteNothing()`,
 *        which S96 added privately and which now delegates here)
 */
final class WriteResult
{
    /**
     * Not instantiable — this is a namespaced predicate, not an object.
     */
    private function __construct()
    {
    }

    /**
     * True when a write statement demonstrably wrote nothing.
     *
     * Both arms are load-bearing and are pinned separately by
     * `tests/Unit/Common/Database/WriteResultTest.php`:
     *
     *  - `null` is what the client actually returns for a zero-row INSERT and
     *    for any statement whose leading keyword it did not recognise;
     *  - `false` is the defensive arm described in the class docblock.
     *
     * Every other value — including the falsy string `'0'`, `int 0` from a
     * `rowCount()` and an empty array from a SELECT — is NOT "wrote nothing"
     * as far as this predicate is concerned. `'0'` in particular is a
     * SUCCESSFUL insert (trap 2 above); an `int 0` from a DELETE means the
     * statement ran and matched no rows, which is a different question this
     * predicate deliberately does not answer.
     *
     * @param mixed $result Whatever `Connection::query()` returned for a write.
     *
     * @return bool True when the statement demonstrably wrote nothing.
     */
    public static function wroteNothing(mixed $result): bool
    {
        return $result === false || $result === null;
    }
}
