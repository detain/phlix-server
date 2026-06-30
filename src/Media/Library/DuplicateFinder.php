<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * Groups top-level media items that are in fact the SAME show/film but were
 * persisted as separate rows — the "100 episodes + 1 episode" symptom that the
 * prevention guard ({@see MediaScanner::findOrCreateContainer()} +
 * {@see CanonicalKey}) stops for NEW scans but cannot retroactively fix for
 * rows created before it landed.
 *
 * For each library this pages the top-level (parent-less) items in FIXED
 * BATCHES — never loading the whole library into memory, which matters in this
 * long-lived Workerman worker — computes each item's {@see CanonicalKey::forItem()}
 * (preferring the key already stamped into `metadata.canonical_key`, else
 * recomputing it from the same title/year/external-id fields the scanner uses),
 * and buckets items by `(type, canonical_key)`. Any bucket with two or more
 * members is a duplicate GROUP; a singleton is not a duplicate and is excluded.
 *
 * Grouping is always scoped to one library AND one media type, so a movie is
 * never grouped with a series even if their canonical keys happen to collide.
 *
 * Within a group the PRIMARY is the member with the most descendants
 * (seasons + episodes for a series; movies have none, so the first by id order
 * wins a deterministic tiebreak) — i.e. the richest container is kept and the
 * thinner shells become merge candidates. The returned structure is consumed by
 * {@see SeriesMerger} (Step 1.4) and the admin merge API (Step 1.6).
 *
 * Returned shape — a list of groups, each:
 *   array{
 *     canonical_key: string,                  // the shared CanonicalKey value
 *     type: string,                           // 'series' | 'movie' | …
 *     library_id: string,                     // owning library UUID
 *     primary: array<string, mixed>,          // the kept item (hydrated row +
 *                                             //   added 'descendant_count' int)
 *     duplicates: list<array<string, mixed>>, // the merge-candidate items
 *                                             //   (each hydrated + 'descendant_count')
 *   }
 *
 * Every item array is the hydrated `media_items` row (so callers have `id`,
 * `name`, decoded `metadata`, …) with one ADD-ONLY key, `descendant_count`
 * (int), so a UI/CLI can show "100 eps" vs "1 ep" without re-counting.
 *
 * Pure orchestration over {@see ItemRepository}: no DB access of its own beyond
 * the repository, no `exit`/`die`, no blocking sleep, no growing static state.
 */
final class DuplicateFinder
{
    /**
     * Default number of top-level rows fetched per page. Bounds the working set
     * to a fixed batch so a huge library never materialises in one query.
     */
    public const DEFAULT_BATCH_SIZE = 500;

    private ItemRepository $items;

    /** @var int<1, max> Rows fetched per paging batch. */
    private int $batchSize;

    /**
     * @param ItemRepository $items     Data-access for top-level paging + descendant counts.
     * @param int            $batchSize Rows per paging batch (defaults to {@see self::DEFAULT_BATCH_SIZE}).
     *                                  Coerced to at least 1.
     */
    public function __construct(ItemRepository $items, int $batchSize = self::DEFAULT_BATCH_SIZE)
    {
        $this->items = $items;
        $this->batchSize = $batchSize < 1 ? 1 : $batchSize;
    }

    /**
     * Find duplicate groups within a single library, optionally restricted to
     * one media type. Pages the library's top-level items in fixed batches,
     * buckets them by `(type, canonical_key)`, and returns only the buckets
     * with two or more members (singletons excluded), with a designated primary
     * per group.
     *
     * Memory note: the only per-call accumulation is the bucket map, which is
     * bounded by the number of DISTINCT canonical keys (+ their member rows) in
     * the library — necessary for grouping and acceptable. Rows are still read
     * from the DB one fixed-size batch at a time, never the whole table at once.
     *
     * @param string      $libraryId Owning library UUID.
     * @param string|null $type      Restrict to one media type ('series'|'movie'|…);
     *                               null groups every type, still per-type.
     * @return list<array{
     *     canonical_key: string,
     *     type: string,
     *     library_id: string,
     *     primary: array<string, mixed>,
     *     duplicates: list<array<string, mixed>>
     * }> Duplicate groups (size >= 2), each with a designated primary.
     */
    public function findForLibrary(string $libraryId, ?string $type = null): array
    {
        /**
         * Bucket map: "type\x00canonicalKey" => list of hydrated rows.
         *
         * @var array<string, list<array<string, mixed>>> $buckets
         */
        $buckets = [];

        $offset = 0;
        do {
            $batch = $this->items->getTopLevelByLibrary($libraryId, $this->batchSize, $offset);
            foreach ($batch as $row) {
                $rowType = isset($row['type']) && is_string($row['type']) ? $row['type'] : '';
                if ($rowType === '') {
                    continue;
                }
                if ($type !== null && $rowType !== $type) {
                    continue;
                }

                $key = $this->canonicalKeyFor($row);
                // An empty key is not a meaningful grouping signal (a title with
                // nothing alphanumeric, no year, no external id); never bucket
                // such rows together as "duplicates".
                if ($key === '') {
                    continue;
                }

                // NUL separator can never appear inside a type name or a
                // canonical key, so the composite map key is unambiguous.
                $bucketKey = $rowType . "\x00" . $key;
                $buckets[$bucketKey][] = $row;
            }

            $offset += $this->batchSize;
        } while (count($batch) === $this->batchSize);

        $groups = [];
        foreach ($buckets as $bucketKey => $members) {
            if (count($members) < 2) {
                continue;
            }

            [$rowType, $canonicalKey] = explode("\x00", $bucketKey, 2);

            $groups[] = $this->buildGroup($libraryId, $rowType, $canonicalKey, $members);
        }

        return $groups;
    }

    /**
     * Build one group from its bucket members: attach a descendant count to
     * each, pick the primary (most descendants), and split the rest as
     * duplicates.
     *
     * @param list<array<string, mixed>> $members Bucket members (>= 2).
     * @return array{
     *     canonical_key: string,
     *     type: string,
     *     library_id: string,
     *     primary: array<string, mixed>,
     *     duplicates: list<array<string, mixed>>
     * }
     */
    private function buildGroup(string $libraryId, string $type, string $canonicalKey, array $members): array
    {
        // Attach a descendant count to each member once. One count query per
        // member here (bounded by the group size, itself bounded by how many
        // rows share a key) — acceptable, and far cheaper than recounting while
        // choosing the primary AND again when the UI renders the group.
        $counted = [];
        foreach ($members as $member) {
            $id = isset($member['id']) && is_string($member['id']) ? $member['id'] : null;
            $member['descendant_count'] = $id !== null ? $this->items->countDescendants($id) : 0;
            $counted[] = $member;
        }

        // Primary = the member with the MOST descendants (richest container).
        // Tiebreak deterministically on the item id so repeated runs are stable.
        $primaryIndex = 0;
        foreach ($counted as $i => $member) {
            if ($i === 0) {
                continue;
            }
            $current = $member['descendant_count'];
            $best = $counted[$primaryIndex]['descendant_count'];
            if ($current > $best) {
                $primaryIndex = $i;
                continue;
            }
            if ($current === $best && $this->idOf($member) < $this->idOf($counted[$primaryIndex])) {
                $primaryIndex = $i;
            }
        }

        $primary = $counted[$primaryIndex];
        $duplicates = [];
        foreach ($counted as $i => $member) {
            if ($i !== $primaryIndex) {
                $duplicates[] = $member;
            }
        }

        return [
            'canonical_key' => $canonicalKey,
            'type' => $type,
            'library_id' => $libraryId,
            'primary' => $primary,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Resolve the canonical dedup key for a top-level row.
     *
     * Prefers the key the scanner already stamped into `metadata.canonical_key`
     * (Step 1.2) so grouping agrees exactly with what prevention persisted; for
     * rows that predate that stamping it recomputes the key from the SAME fields
     * the scanner uses — the row `name` as title, `metadata.year` as year, and
     * any `metadata.external_ids` (imdb/tmdb) — via {@see CanonicalKey::forItem()}.
     *
     * @param array<string, mixed> $row Hydrated media item row (has 'metadata').
     * @return string Canonical key, or '' when none can be derived.
     */
    private function canonicalKeyFor(array $row): string
    {
        $metadata = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [];

        $stored = $metadata['canonical_key'] ?? null;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $title = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
        $year = isset($metadata['year']) && is_numeric($metadata['year']) ? (int) $metadata['year'] : null;

        return CanonicalKey::forItem($title, $year, $this->externalIdsOf($metadata));
    }

    /**
     * Extract the imdb/tmdb external ids from a row's metadata, mirroring the
     * shape {@see CanonicalKey::forItem()} expects. Missing/blank ids are simply
     * absent (the key falls through to title(+year)).
     *
     * @param array<string, mixed> $metadata Decoded metadata blob.
     * @return array<string, string|int|null>
     */
    private function externalIdsOf(array $metadata): array
    {
        $external = isset($metadata['external_ids']) && is_array($metadata['external_ids'])
            ? $metadata['external_ids']
            : [];

        $out = [];
        foreach (['imdb', 'tmdb'] as $provider) {
            $value = $external[$provider] ?? ($metadata[$provider . '_id'] ?? null);
            if (is_string($value) || is_int($value)) {
                $out[$provider] = $value;
            }
        }

        return $out;
    }

    /**
     * Stable string id for a row, for the primary tiebreak. Rows always carry a
     * CHAR(36) id; falls back to '' so the comparison is total.
     *
     * @param array<string, mixed> $row
     */
    private function idOf(array $row): string
    {
        return isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
    }
}
