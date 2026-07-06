<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Throwable;
use Workerman\MySQL\Connection;

/**
 * Folds duplicate top-level media rows (the "100 episodes + 1 episode" symptom)
 * into a single canonical row by RE-PARENTING the duplicate's children onto the
 * primary and then DELETING the now-empty duplicate shells.
 *
 * Root cause it repairs: there is NO DB UNIQUE constraint on `media_items`, so a
 * title-slug variance (separators, year bleed, a parse failure, a
 * flat→per-directory re-scan, or a concurrent-scan race) could silently fork a
 * second top-level row before the {@see MediaScanner} canonical-key prevention
 * guard (Step 1.2) landed. {@see DuplicateFinder} (Step 1.3) groups those rows;
 * this merger (Step 1.4) collapses one group at a time.
 *
 * SERIES merge — for each duplicate series:
 *   1. Index the PRIMARY's seasons by `metadata.season` number.
 *   2. For each duplicate season: if the primary already has a season with the
 *      same number, RE-PARENT every episode of the duplicate season onto the
 *      primary's matching season; otherwise RE-PARENT the whole season row onto
 *      the primary series (its episodes move with it, no re-parent needed) and
 *      register it so a later same-number season collapses into it too.
 *   3. RE-PARENT any STRAY direct episodes (an episode whose parent is the series
 *      itself, not a season) onto the primary series.
 *   4. DELETE the now-empty duplicate season shells, then the empty duplicate
 *      series shell.
 *
 * MOVIE merge — carry richer metadata to the primary where the primary is
 * MISSING it (ADD-ONLY / fill-gaps — non-empty primary fields are NEVER
 * overwritten), then DELETE the duplicate movie row.
 *
 * Transactional: the whole merge runs inside ONE real explicit transaction via
 * {@see Connection::beginTrans()} / {@see Connection::commitTrans()} /
 * {@see Connection::rollBackTrans()}. The transaction API is declared on the
 * base {@see Connection} and BOTH connection implementations Phlix ships honour
 * it correctly: the single-socket {@see \Phlix\Common\Database\PhlixMySQLConnection}
 * serialises the whole transaction with its reentrant coroutine mutex (#333), and
 * the {@see \Phlix\Common\Database\PooledMySQLConnection} leases ONE connection
 * per coroutine for the coroutine's lifetime so a `beginTrans … commitTrans`
 * stays affine to that lease. Depending on the base type therefore keeps the
 * merge live in BOTH the default single-connection mode AND the pool-enabled
 * mode (`DB_POOL_ENABLED=1`). On ANY failure the transaction rolls back so a
 * half-merge never persists. As defense-in-depth the per-duplicate work is still
 * ordered RE-PARENT-BEFORE-DELETE, so even a caller that ran this outside a
 * transaction (an environment without the Swoole coroutine runtime) never orphans
 * a child mid-failure.
 *
 * IDEMPOTENT-ish: merging an already-merged set is a no-op ({moved:0, deleted:0});
 * a duplicate id that does not exist, is not actually a duplicate, or fails the
 * same-library/same-type guard is SKIPPED, never silently corrupting the data.
 * Self-merge (the primary id appearing in the duplicate list) is rejected — that
 * id is dropped, never deleted.
 *
 * OUT OF SCOPE (deliberately): per-user playback / watch markers
 * (`playback_state`, `user_item_data`) are NOT migrated here — this step is
 * STRUCTURAL re-parent/delete only. Re-parenting an EPISODE keeps that episode's
 * id, so any `playback_state` rows pointing at it survive untouched. The only
 * rows deleted are EMPTY shells (a season with no episodes, a series with no
 * children) and the duplicate MOVIE row; a deleted duplicate's own
 * `playback_state`/`user_item_data` rows are removed by the schema's
 * `ON DELETE CASCADE`. Consolidating a duplicate movie's watch progress onto the
 * primary would require touching `playback_state` (a SECOND table) and is left to
 * a follow-up.
 *
 * Pure orchestration over {@see ItemRepository} + the connection's transaction
 * API: no `exit`/`die`, no blocking sleep, no growing static state; duplicates,
 * seasons and episodes are iterated in bounded loops.
 */
final class SeriesMerger
{
    private ItemRepository $items;
    private Connection $db;

    /**
     * @param ItemRepository $items Data-access for find/re-parent/delete.
     * @param Connection     $db    Connection providing the transaction API
     *                              (`beginTrans`/`commitTrans`/`rollBackTrans`,
     *                              declared on the base Workerman connection).
     *                              Accepts EITHER concrete connection Phlix wires:
     *                              {@see \Phlix\Common\Database\PhlixMySQLConnection}
     *                              (single-socket, reentrant txn coroutine mutex,
     *                              #333) or {@see \Phlix\Common\Database\PooledMySQLConnection}
     *                              (per-coroutine leased connection) — so the merge
     *                              works in both pool-off and pool-on modes.
     */
    public function __construct(ItemRepository $items, Connection $db)
    {
        $this->items = $items;
        $this->db = $db;
    }

    /**
     * Merge one duplicate group into its primary.
     *
     * Validates the primary exists, then folds each VALID duplicate (same
     * library + same type as the primary, not the primary itself) into it.
     * The whole operation runs in one real transaction; any failure rolls back.
     *
     * Result semantics:
     *   - `moved`   — the number of CHILD rows whose `parent_id` was re-pointed at
     *                 the primary (or a primary season): re-parented episodes
     *                 (when an episode is moved individually) PLUS whole seasons
     *                 re-parented as a unit (counted once each; their episodes
     *                 move implicitly with the season and are NOT double-counted)
     *                 PLUS stray direct episodes. A movie merge moves nothing.
     *   - `deleted` — the number of rows DELETED: emptied duplicate season shells,
     *                 the emptied duplicate series shell, and (for a movie) the
     *                 duplicate movie row itself. One per deleted row.
     *
     * @param string        $primaryId    The kept item's id (CHAR(36) UUID).
     * @param list<string>  $duplicateIds Ids of the rows to fold into the primary.
     * @return array{moved: int, deleted: int} Counts of re-parented + deleted rows.
     */
    public function merge(string $primaryId, array $duplicateIds): array
    {
        $primary = $this->items->findById($primaryId);
        if ($primary === null) {
            return ['moved' => 0, 'deleted' => 0];
        }

        $primaryType = $this->stringField($primary, 'type');
        $primaryLibrary = $this->stringField($primary, 'library_id');

        // De-duplicate the input and drop the self-merge id up front so the
        // primary can never be deleted and we never process it twice.
        $targets = [];
        foreach ($duplicateIds as $duplicateId) {
            if ($duplicateId === '' || $duplicateId === $primaryId) {
                continue;
            }
            $targets[$duplicateId] = true;
        }

        if ($targets === []) {
            return ['moved' => 0, 'deleted' => 0];
        }

        $moved = 0;
        $deleted = 0;

        $this->db->beginTrans();
        try {
            foreach (array_keys($targets) as $duplicateId) {
                $duplicate = $this->items->findById($duplicateId);
                if ($duplicate === null) {
                    // Already gone (e.g. a re-run after a prior merge) — skip,
                    // never crash.
                    continue;
                }

                // Defensive cross-type / cross-library guard. The admin API
                // (Step 1.6) also validates, but the merger must not silently
                // corrupt on bad input: only fold rows of the SAME type in the
                // SAME library as the primary.
                if (
                    $this->stringField($duplicate, 'type') !== $primaryType
                    || $this->stringField($duplicate, 'library_id') !== $primaryLibrary
                ) {
                    continue;
                }

                if ($primaryType === 'series') {
                    $result = $this->mergeSeries($primaryId, $duplicate);
                } else {
                    $result = $this->mergeLeaf($primary, $duplicate);
                }

                $moved += $result['moved'];
                $deleted += $result['deleted'];
            }

            $this->db->commitTrans();
        } catch (Throwable $e) {
            $this->db->rollBackTrans();
            throw $e;
        }

        return ['moved' => $moved, 'deleted' => $deleted];
    }

    /**
     * Fold one duplicate SERIES into the primary series: re-parent its seasons'
     * episodes (or whole seasons), re-parent stray direct episodes, then delete
     * the emptied season + series shells. Ordered re-parent-before-delete.
     *
     * @param string               $primaryId The primary series id.
     * @param array<string, mixed> $duplicate The hydrated duplicate series row.
     * @return array{moved: int, deleted: int}
     */
    private function mergeSeries(string $primaryId, array $duplicate): array
    {
        $duplicateId = $this->stringField($duplicate, 'id');
        if ($duplicateId === '') {
            return ['moved' => 0, 'deleted' => 0];
        }

        $moved = 0;
        $deleted = 0;

        // Batch fetch all children of both primary and duplicate series (replaces 2 queries with 1)
        $allChildren = $this->items->findByParents([$primaryId, $duplicateId]);
        $primaryChildren = $allChildren[$primaryId] ?? [];
        $duplicateChildren = $allChildren[$duplicateId] ?? [];

        // Index the PRIMARY's existing seasons by their season number so a
        // same-number duplicate season collapses into it (no duplicate season
        // row created). The map is also extended below when a duplicate season
        // is re-parented as a new season under the primary, so two duplicate
        // seasons of the same number still converge onto ONE primary season.
        $primarySeasonByNumber = [];
        foreach ($primaryChildren as $child) {
            if ($this->stringField($child, 'type') !== 'season') {
                continue;
            }
            $childId = $this->stringField($child, 'id');
            if ($childId === '') {
                continue;
            }
            $primarySeasonByNumber[$this->seasonNumberOf($child)] = $childId;
        }

        // Collect all duplicate season IDs for batch episode fetch
        $duplicateSeasonIds = [];
        foreach ($duplicateChildren as $child) {
            if ($this->stringField($child, 'type') === 'season') {
                $childId = $this->stringField($child, 'id');
                if ($childId !== '') {
                    $duplicateSeasonIds[] = $childId;
                }
            }
        }

        // Batch fetch all episodes for all duplicate seasons (replaces N queries with 1)
        $allEpisodesBySeason = [];
        if ($duplicateSeasonIds !== []) {
            $allEpisodesBySeason = $this->items->findByParents($duplicateSeasonIds);
        }

        foreach ($duplicateChildren as $child) {
            $childId = $this->stringField($child, 'id');
            if ($childId === '') {
                continue;
            }
            $childType = $this->stringField($child, 'type');

            if ($childType === 'season') {
                $seasonNumber = $this->seasonNumberOf($child);
                if (isset($primarySeasonByNumber[$seasonNumber])) {
                    // The primary already has this season — move the duplicate
                    // season's episodes onto it, then delete the empty shell.
                    $targetSeasonId = $primarySeasonByNumber[$seasonNumber];
                    foreach ($allEpisodesBySeason[$childId] ?? [] as $episode) {
                        $episodeId = $this->stringField($episode, 'id');
                        if ($episodeId === '') {
                            continue;
                        }
                        $this->items->update($episodeId, ['parent_id' => $targetSeasonId]);
                        $moved++;
                    }
                    $this->items->delete($childId);
                    $deleted++;
                } else {
                    // The primary lacks this season — re-parent the whole season
                    // row onto the primary series (its episodes ride along, so
                    // they are not individually re-parented). Register it so a
                    // later duplicate season of the same number folds into it.
                    $this->items->update($childId, ['parent_id' => $primaryId]);
                    $moved++;
                    $primarySeasonByNumber[$seasonNumber] = $childId;
                }
                continue;
            }

            // A STRAY direct episode (parent is the series, not a season) —
            // re-parent it straight onto the primary series.
            $this->items->update($childId, ['parent_id' => $primaryId]);
            $moved++;
        }

        // The duplicate series is now an empty shell — delete it last.
        $this->items->delete($duplicateId);
        $deleted++;

        return ['moved' => $moved, 'deleted' => $deleted];
    }

    /**
     * Fold one duplicate LEAF (movie or any non-series top-level item) into the
     * primary: gap-fill the primary's metadata from the duplicate (ADD-ONLY),
     * then delete the duplicate row.
     *
     * @param array<string, mixed> $primary   The hydrated primary row.
     * @param array<string, mixed> $duplicate The hydrated duplicate row.
     * @return array{moved: int, deleted: int}
     */
    private function mergeLeaf(array $primary, array $duplicate): array
    {
        $duplicateId = $this->stringField($duplicate, 'id');
        $primaryId = $this->stringField($primary, 'id');
        if ($duplicateId === '' || $primaryId === '') {
            return ['moved' => 0, 'deleted' => 0];
        }

        $primaryMeta = $this->metadataOf($primary);
        $duplicateMeta = $this->metadataOf($duplicate);

        $filled = $this->fillGaps($primaryMeta, $duplicateMeta);
        if ($filled !== $primaryMeta) {
            $this->items->update($primaryId, ['metadata_json' => $filled]);
        }

        $this->items->delete($duplicateId);

        return ['moved' => 0, 'deleted' => 1];
    }

    /**
     * Fill keys MISSING (or empty) on the primary metadata from the duplicate.
     * Non-empty primary values are never overwritten (ADD-ONLY). The
     * `canonical_key` is never carried (it belongs to the primary's own identity)
     * and an explicit primary value always wins over a duplicate one.
     *
     * "Empty" = null, '', or [] — so a duplicate's real overview/poster fills a
     * primary that has a blank one, but a primary with real data is left alone.
     *
     * @param array<string, mixed> $primaryMeta
     * @param array<string, mixed> $duplicateMeta
     * @return array<string, mixed> The (possibly) gap-filled primary metadata.
     */
    private function fillGaps(array $primaryMeta, array $duplicateMeta): array
    {
        foreach ($duplicateMeta as $key => $value) {
            if ($key === 'canonical_key') {
                continue;
            }
            if ($this->isEmptyValue($value)) {
                continue;
            }
            if (array_key_exists($key, $primaryMeta) && !$this->isEmptyValue($primaryMeta[$key])) {
                continue;
            }
            $primaryMeta[$key] = $value;
        }

        return $primaryMeta;
    }

    /**
     * A value counts as "empty" (a gap to fill) when it is null, the empty
     * string, or the empty array. Zero / "0" / false are NOT empty (a real
     * runtime of 0 or a real rating would be a deliberate value).
     *
     * @param mixed $value
     */
    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * The season number a season row carries, read from `metadata.season`
     * (an int; defaults to 0 — the "specials" / unnumbered bucket — when absent
     * or non-numeric), so seasons match the SAME way the scanner addresses them.
     *
     * @param array<string, mixed> $season Hydrated season row.
     */
    private function seasonNumberOf(array $season): int
    {
        $metadata = $this->metadataOf($season);
        return isset($metadata['season']) && is_numeric($metadata['season'])
            ? (int) $metadata['season']
            : 0;
    }

    /**
     * Decoded metadata for a hydrated row. {@see ItemRepository} hydrates the
     * blob under `metadata`; fall back to decoding `metadata_json` for a row
     * (e.g. a test double) that only carries the raw column.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function metadataOf(array $row): array
    {
        if (isset($row['metadata']) && is_array($row['metadata'])) {
            return $this->stringKeyed($row['metadata']);
        }

        $raw = $row['metadata_json'] ?? null;
        if (is_array($raw)) {
            return $this->stringKeyed($raw);
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->stringKeyed($decoded);
            }
        }

        return [];
    }

    /**
     * Coerce a decoded blob to a string-keyed associative array (dropping any
     * non-string keys), so the metadata maps stay `array<string, mixed>`.
     *
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Read a string field from a hydrated row, or '' when absent / non-string.
     *
     * @param array<string, mixed> $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
