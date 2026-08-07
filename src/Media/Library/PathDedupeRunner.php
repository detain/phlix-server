<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Throwable;

/**
 * The keeper-selection and merge rules for duplicate media paths — ONE copy.
 *
 * ## Why this exists (S77)
 *
 * The rules lived inside
 * {@see \Phlix\Console\Commands\MediaDedupePathsCommand::displayDuplicateGroup()},
 * interleaved with `$output->writeln()` calls. S77 added a second caller — the
 * `dedupe-paths` maintenance task, which has no console to write to — and
 * re-typing "sort by score desc, then id asc; repoint every referencing table
 * from each loser to the keeper; delete the loser; all inside one transaction"
 * is exactly the duplicated-logic drift this repo keeps paying for. So the
 * rules moved here and BOTH callers read them; the console command keeps its
 * presentation and nothing else.
 *
 * ## What is deliberately NOT here
 *
 * The transaction boundary is per GROUP, not per run. That is the pre-existing
 * behaviour and it is the right one: a merge that fails on group 400 must not
 * roll back the 399 groups that succeeded, because the scan that found them is
 * the expensive part and re-running it is not free.
 *
 * @package Phlix\Media\Library
 * @since 1.9
 */
final class PathDedupeRunner
{
    /**
     * Rank one duplicate group into the row to keep and the rows to merge away.
     *
     * Highest {@see PathDeduper::scoreItem()} wins; ties break on the lowest
     * id, so the choice is deterministic for a given database state and a
     * dry-run preview matches the apply that follows it.
     *
     * @param PathDeduper $deduper Scoring source.
     * @param list<array{id: string, name: string, type: string, created_at: string}> $items
     *        The group's rows, as {@see PathDeduper::findDuplicateGroups()} returns them.
     *
     * @return array{
     *     keeper: array{item: array{id: string, name: string, type: string, created_at: string}, score: int},
     *     losers: list<array{item: array{id: string, name: string, type: string, created_at: string}, score: int}>
     * }
     */
    public static function rank(PathDeduper $deduper, array $items): array
    {
        $scored = [];
        foreach ($items as $item) {
            $scored[] = [
                'item' => $item,
                'score' => $deduper->scoreItem($item['id']),
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp($a['item']['id'], $b['item']['id']);
        });

        return [
            'keeper' => $scored[0],
            'losers' => array_values(array_slice($scored, 1)),
        ];
    }

    /**
     * Merge one group's losers onto its keeper, in a single transaction.
     *
     * @param PathDeduper $deduper  The transactional worker.
     * @param string      $keeperId The row to keep.
     * @param list<string> $loserIds The rows to repoint and delete.
     *
     * @return array{merged: int, error: string|null} How many rows were
     *         deleted, or the failure message after a rollback.
     */
    public static function mergeGroup(PathDeduper $deduper, string $keeperId, array $loserIds): array
    {
        if ($loserIds === []) {
            return ['merged' => 0, 'error' => null];
        }

        try {
            $deduper->beginTrans();

            foreach ($loserIds as $loserId) {
                // Order matters: every referencing row must point at the keeper
                // BEFORE the loser disappears, or the repoint has nothing to
                // find and the references are lost with the row.
                $deduper->repointReferencingTables($loserId, $keeperId);
                $deduper->deleteItem($loserId);
            }

            $deduper->commit();

            return ['merged' => count($loserIds), 'error' => null];
        } catch (Throwable $e) {
            $deduper->rollback();

            return ['merged' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract the loser ids from a {@see self::rank()} result.
     *
     * @param list<array{item: array{id: string, name: string, type: string, created_at: string}, score: int}> $losers
     *
     * @return list<string>
     */
    public static function loserIds(array $losers): array
    {
        $ids = [];
        foreach ($losers as $loser) {
            $ids[] = $loser['item']['id'];
        }

        return $ids;
    }

    /**
     * Headless whole-run driver — what the `dedupe-paths` maintenance task uses.
     *
     * ⚠ NOT for an HTTP handler. {@see PathDeduper::findDuplicateGroups()} is an
     * unbounded `media_items` scan and each group costs its own transaction, so
     * this must only ever run inside a background worker.
     *
     * @param PathDeduper $deduper   Scan + merge source.
     * @param bool        $apply     False (the default) previews without writing.
     * @param int         $batchSize Maximum groups to process in one run.
     *
     * @return array{groups_found: int, groups_processed: int, rows_kept: int,
     *               rows_merged: int, groups_failed: int, applied: bool,
     *               batch_size: int, truncated: bool}
     */
    public static function run(PathDeduper $deduper, bool $apply, int $batchSize): array
    {
        $batchSize = max(1, $batchSize);
        $groups = $deduper->findDuplicateGroups();

        $processed = 0;
        $kept = 0;
        $merged = 0;
        $failed = 0;

        foreach ($groups as $group) {
            if ($processed >= $batchSize) {
                break;
            }
            $processed++;

            $ranked = self::rank($deduper, $group['items']);
            $loserIds = self::loserIds($ranked['losers']);
            $kept++;

            if (!$apply) {
                // A preview reports what WOULD be merged. Counting it as
                // `rows_merged` keeps the dry-run and apply summaries directly
                // comparable, which is the point of previewing.
                $merged += count($loserIds);
                continue;
            }

            $outcome = self::mergeGroup($deduper, $ranked['keeper']['item']['id'], $loserIds);
            if ($outcome['error'] !== null) {
                $failed++;
                continue;
            }
            $merged += $outcome['merged'];
        }

        return [
            'groups_found' => count($groups),
            'groups_processed' => $processed,
            'rows_kept' => $kept,
            'rows_merged' => $merged,
            'groups_failed' => $failed,
            'applied' => $apply,
            'batch_size' => $batchSize,
            'truncated' => count($groups) > $processed,
        ];
    }

    /**
     * Prevent instantiation — this class is a static rule holder only.
     */
    private function __construct()
    {
    }
}
