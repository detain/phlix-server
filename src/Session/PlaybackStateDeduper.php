<?php

/**
 * Phlix media server component: Session.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Session;

use Throwable;
use Workerman\MySQL\Connection;

/**
 * Merges duplicate `playback_state` rows so the
 * `(session_id, media_item_id)` UNIQUE KEY can be added.
 *
 * WHY THIS EXISTS: `PlaybackController::reportProgress()` (and
 * `StreamManager::persistStreamState()`) write progress via
 * `INSERT ... ON DUPLICATE KEY UPDATE` whose intended conflict target is the
 * `(session_id, media_item_id)` pair. Until migration 090 there was NO unique
 * key on that pair — only the `id` PRIMARY KEY, which is a fresh random UUID on
 * every call — so the `ON DUPLICATE KEY` clause never fired and every ~15s
 * progress tick INSERTed a brand-new row, bloating the table and breaking
 * resume / continue-watching semantics.
 *
 * Adding `UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)`
 * makes the upsert update-not-insert. But any production DB already holds many
 * duplicate rows (the whole reason this exists), so an inline `ADD UNIQUE KEY`
 * would fail with error 1062 (`Duplicate entry`) — which the migration runner
 * treats as a hard error. The duplicates must be merged FIRST.
 *
 * This is the direct analogue of {@see \Phlix\Media\Library\PathDeduper} +
 * `migrations/cleanup_072.php` for the `media_items` `(library_id, path_hash)`
 * unique index: a batched dedupe pass, then the unique key. It is deliberately
 * MUCH simpler than PathDeduper, for two reasons:
 *
 *   1. Keeper selection is a fixed rule — keep the row with the greatest
 *      `updated_at`, ties broken by the greatest `id` — not a value-based score.
 *   2. `playback_state` is a LEAF table: nothing references `playback_state.id`
 *      (it only has outbound FKs to `sessions` / `media_items`), so a loser row
 *      is simply DELETEd — there is no reference-repointing to do, and therefore
 *      no need for a multi-statement per-group transaction (each group is
 *      resolved by a single atomic DELETE).
 *
 * The dedupe is BATCHED at the group level: {@see findDuplicateGroups()} returns
 * at most `$batchSize` duplicate groups per call, so a bloated production table
 * is drained in bounded passes instead of one giant table-wide self-join DELETE
 * that would lock the table and blow memory. {@see dedupeAll()} loops until no
 * duplicate group remains.
 *
 * ⚠ **This class is no longer what puts the unique key on a fresh install.** As
 * of S156, `migrations/097_playback_state_unique_key.sql` adds
 * `uq_playback_state_session_media` from inside the migration chain; 097 refuses
 * to alter a table that still holds duplicates and names
 * `migrations/cleanup_090.php` in its error. So `cleanup_090.php` — and this
 * class — now own **de-duplication only**, as the recovery path for an install
 * that accumulated duplicates while the key was missing. (Before 097, migration
 * 090 carried no executable statement and this class was the sole creator, which
 * meant a chain-built database had no key at all: the S156 defect.)
 *
 * Run post-deploy via `migrations/cleanup_090.php` when 097 tells you to. Safe to
 * re-run: {@see dedupeAll()} is a no-op once no duplicates remain and
 * {@see addUniqueKey()} treats an already-present key as success.
 */
final class PlaybackStateDeduper
{
    /** Name of the unique key this class exists to make addable. */
    public const UNIQUE_KEY_NAME = 'uq_playback_state_session_media';

    /**
     * How many duplicate `(session_id, media_item_id)` groups to resolve per
     * batch. Bounds the working set on a large table (mirrors PathDeduper's
     * batched drain / `DuplicateFinder::DEFAULT_BATCH_SIZE`).
     */
    public const DEFAULT_BATCH_SIZE = 500;

    private Connection $db;

    /**
     * @param Connection $db The `Workerman\MySQL\Connection` to operate on.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Find up to `$limit` `(session_id, media_item_id)` groups that hold more
     * than one `playback_state` row.
     *
     * The `LIMIT` bounds the working set so a bloated table is drained in
     * batches (see {@see dedupeAll()}). It is inlined rather than bound because
     * it is a validated positive int (never request input) and workerman/mysql's
     * emulated prepares stringify a bound `LIMIT` value, which MySQL then rejects
     * with a 1064 syntax error. Starts with `SELECT` so `query()` returns rows.
     *
     * @param int $limit Maximum number of duplicate groups to return (>= 1).
     *
     * @return list<array{session_id: string, media_item_id: string, cnt: int}>
     */
    public function findDuplicateGroups(int $limit = self::DEFAULT_BATCH_SIZE): array
    {
        $limit = max(1, $limit);

        $rows = $this->db->query(
            "SELECT session_id, media_item_id, COUNT(*) AS cnt
             FROM playback_state
             GROUP BY session_id, media_item_id
             HAVING COUNT(*) > 1
             ORDER BY cnt DESC
             LIMIT {$limit}"
        );

        if (!is_array($rows)) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $groups[] = [
                'session_id' => self::asString($row['session_id'] ?? ''),
                'media_item_id' => self::asString($row['media_item_id'] ?? ''),
                'cnt' => self::asInt($row['cnt'] ?? 0),
            ];
        }

        return $groups;
    }

    /**
     * Resolve ONE duplicate group: keep the row with the greatest `updated_at`
     * (ties broken by the greatest `id`) and delete every other row in the
     * group. A single atomic DELETE — no transaction needed (leaf table).
     *
     * @param string $sessionId    The group's `session_id`.
     * @param string $mediaItemId  The group's `media_item_id`.
     *
     * @return int Number of (loser) rows deleted.
     */
    public function dedupeGroup(string $sessionId, string $mediaItemId): int
    {
        $keeperId = $this->findKeeperId($sessionId, $mediaItemId);
        if ($keeperId === null) {
            // Group vanished between discovery and here (concurrent delete).
            return 0;
        }

        $deleted = $this->db->query(
            "DELETE FROM playback_state
             WHERE session_id = ? AND media_item_id = ? AND id <> ?",
            [$sessionId, $mediaItemId, $keeperId]
        );

        // For a DELETE, Connection::query() returns the affected-row count.
        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Drain every duplicate group in bounded batches until none remain.
     *
     * Each iteration fetches at most `$batchSize` groups and resolves each one;
     * resolved groups drop out of the next {@see findDuplicateGroups()} scan (a
     * resolved group has one row and no longer satisfies `HAVING COUNT(*) > 1`),
     * so the loop makes monotonic progress and terminates. A group whose DELETE
     * throws is skipped (logged by the caller via the return totals) rather than
     * aborting the whole pass. If an iteration finds groups but deletes nothing,
     * the loop stops instead of spinning forever.
     *
     * @param int $batchSize     Groups to resolve per iteration.
     * @param int $maxIterations Safety backstop against a non-converging loop.
     *
     * @return array{groups: int, deleted: int, iterations: int, skipped: int}
     */
    public function dedupeAll(
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        int $maxIterations = 1000000
    ): array {
        $totalGroups = 0;
        $totalDeleted = 0;
        $totalSkipped = 0;
        $iterations = 0;

        do {
            $groups = $this->findDuplicateGroups($batchSize);
            if ($groups === []) {
                break;
            }

            $iterations++;
            $deletedThisIteration = 0;

            foreach ($groups as $group) {
                try {
                    $deleted = $this->dedupeGroup($group['session_id'], $group['media_item_id']);
                    $totalGroups++;
                    $totalDeleted += $deleted;
                    $deletedThisIteration += $deleted;
                } catch (Throwable) {
                    $totalSkipped++;
                }
            }

            // No progress despite duplicates remaining → stop rather than spin.
            if ($deletedThisIteration === 0) {
                break;
            }
        } while ($iterations < $maxIterations);

        return [
            'groups' => $totalGroups,
            'deleted' => $totalDeleted,
            'iterations' => $iterations,
            'skipped' => $totalSkipped,
        ];
    }

    /**
     * Add the `(session_id, media_item_id)` UNIQUE KEY that makes the
     * `INSERT ... ON DUPLICATE KEY UPDATE` upsert update-not-insert.
     *
     * Idempotent on replay: an already-present key ("Duplicate key name") is
     * treated as success. Any other failure — most importantly a lingering
     * "Duplicate entry" 1062 meaning duplicates were NOT fully merged — is
     * re-thrown so the caller can surface it and the operator can re-run.
     *
     * @return bool True if the key was created, false if it already existed.
     *
     * @throws Throwable When the key cannot be created (e.g. duplicates remain).
     */
    public function addUniqueKey(): bool
    {
        try {
            $this->db->query(
                'ALTER TABLE playback_state
                    ADD UNIQUE KEY ' . self::UNIQUE_KEY_NAME . ' (session_id, media_item_id)'
            );

            return true;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name')) {
                return false; // already present — nothing to do
            }
            throw $e;
        }
    }

    /**
     * Whether the `(session_id, media_item_id)` unique key is already present.
     */
    public function hasUniqueKey(): bool
    {
        try {
            $rows = $this->db->query(
                'SHOW INDEX FROM playback_state WHERE Key_name = ?',
                [self::UNIQUE_KEY_NAME]
            );

            return is_array($rows) && $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The id of the row to KEEP for a group: greatest `updated_at`, ties broken
     * by the greatest `id`.
     *
     * @return string|null The keeper id, or null if the group has no rows.
     */
    private function findKeeperId(string $sessionId, string $mediaItemId): ?string
    {
        $rows = $this->db->query(
            "SELECT id FROM playback_state
             WHERE session_id = ? AND media_item_id = ?
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [$sessionId, $mediaItemId]
        );

        if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
            $id = $rows[0]['id'] ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * Coerce a mixed DB cell to a string (empty string for non-scalars).
     * Mirrors {@see \Phlix\Media\Library\PathDeduper::asString()}.
     */
    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Coerce a mixed DB cell to an int (0 for non-numeric values).
     * Mirrors {@see \Phlix\Media\Library\PathDeduper::asInt()}.
     */
    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
