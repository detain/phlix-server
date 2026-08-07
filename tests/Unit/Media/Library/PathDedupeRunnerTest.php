<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\PathDedupeRunner;
use Phlix\Media\Library\PathDeduper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * {@see PathDedupeRunner} — the keeper-selection and merge rules, extracted in
 * S77 so the CLI command and the `dedupe-paths` maintenance task share ONE copy.
 */
final class PathDedupeRunnerTest extends TestCase
{
    /**
     * @param array<string, int> $scores
     */
    private function deduper(array $scores = []): PathDeduper
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('scoreItem')->willReturnCallback(
            static fn (string $id): int => $scores[$id] ?? 0
        );

        return $deduper;
    }

    /**
     * @return array{id: string, name: string, type: string, created_at: string}
     */
    private function item(string $id): array
    {
        return ['id' => $id, 'name' => 'Film', 'type' => 'movie', 'created_at' => '2026-01-01'];
    }

    /**
     * @param list<string> $ids
     *
     * @return array{path: string, library_id: string, library_name: string,
     *               items: list<array{id: string, name: string, type: string, created_at: string}>}
     */
    private function group(array $ids): array
    {
        return [
            'path' => '/library/Film.mp4',
            'library_id' => 'lib-1',
            'library_name' => 'Movies',
            'items' => array_map(fn (string $id): array => $this->item($id), $ids),
        ];
    }

    // -----------------------------------------------------------------
    // rank()
    // -----------------------------------------------------------------

    public function test_the_highest_score_is_kept(): void
    {
        $ranked = PathDedupeRunner::rank(
            $this->deduper(['a' => 1, 'b' => 9, 'c' => 5]),
            [$this->item('a'), $this->item('b'), $this->item('c')]
        );

        self::assertSame('b', $ranked['keeper']['item']['id']);
        self::assertSame(9, $ranked['keeper']['score']);
        // Losers stay in the same score-descending order, so a caller that
        // reports them lists the most valuable discard first.
        self::assertSame(['c', 'a'], array_column(array_column($ranked['losers'], 'item'), 'id'));
    }

    /**
     * A TIE breaks on the lowest id, so the ordering is deterministic for a
     * given database state.
     *
     * That determinism is the reason a dry-run preview is trustworthy: the
     * apply that follows must keep the same row the preview named.
     */
    public function test_a_tie_breaks_on_the_lowest_id_deterministically(): void
    {
        $items = [$this->item('zzz'), $this->item('aaa'), $this->item('mmm')];

        $first = PathDedupeRunner::rank($this->deduper(), $items);
        $second = PathDedupeRunner::rank($this->deduper(), array_reverse($items));

        self::assertSame('aaa', $first['keeper']['item']['id']);
        self::assertSame(
            'aaa',
            $second['keeper']['item']['id'],
            'The keeper must not depend on the order the rows arrived in.'
        );
    }

    public function test_a_single_item_group_has_no_losers(): void
    {
        $ranked = PathDedupeRunner::rank($this->deduper(), [$this->item('only')]);

        self::assertSame('only', $ranked['keeper']['item']['id']);
        self::assertSame([], $ranked['losers']);
    }

    // -----------------------------------------------------------------
    // mergeGroup()
    // -----------------------------------------------------------------

    /**
     * 🚨 REPOINT BEFORE DELETE, inside ONE transaction.
     *
     * The order is the whole correctness of the merge: once the loser row is
     * gone the repoint has nothing to find, and every watch-history,
     * playback-state and user-item-data reference to it is lost with it. The
     * call ORDER is asserted, not just that both happened.
     */
    public function test_the_merge_repoints_before_deleting_inside_one_transaction(): void
    {
        $calls = [];
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('beginTrans')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'begin';
        });
        $deduper->method('repointReferencingTables')->willReturnCallback(
            static function (string $from, string $to) use (&$calls): int {
                $calls[] = "repoint:{$from}->{$to}";

                return 1;
            }
        );
        $deduper->method('deleteItem')->willReturnCallback(
            static function (string $id) use (&$calls): bool {
                $calls[] = "delete:{$id}";

                return true;
            }
        );
        $deduper->method('commit')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'commit';
        });

        $outcome = PathDedupeRunner::mergeGroup($deduper, 'keeper', ['loser-1', 'loser-2']);

        self::assertSame(['merged' => 2, 'error' => null], $outcome);
        self::assertSame(
            [
                'begin',
                'repoint:loser-1->keeper',
                'delete:loser-1',
                'repoint:loser-2->keeper',
                'delete:loser-2',
                'commit',
            ],
            $calls
        );
    }

    /**
     * A throw rolls back and reports, rather than leaving a half-merged group
     * behind an open transaction.
     */
    public function test_a_failing_merge_rolls_back_and_reports_the_error(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->expects(self::once())->method('beginTrans');
        $deduper->method('repointReferencingTables')->willThrowException(new RuntimeException('deadlock'));
        $deduper->expects(self::once())->method('rollback');
        $deduper->expects(self::never())->method('commit');

        $outcome = PathDedupeRunner::mergeGroup($deduper, 'keeper', ['loser-1']);

        self::assertSame(0, $outcome['merged']);
        self::assertSame('deadlock', $outcome['error']);
    }

    /**
     * An empty loser list opens NO transaction — a group of one is not a merge.
     */
    public function test_an_empty_loser_list_opens_no_transaction(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->expects(self::never())->method('beginTrans');

        self::assertSame(['merged' => 0, 'error' => null], PathDedupeRunner::mergeGroup($deduper, 'k', []));
    }

    // -----------------------------------------------------------------
    // run()
    // -----------------------------------------------------------------

    public function test_a_dry_run_reports_what_would_be_merged_and_writes_nothing(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([
            $this->group(['a', 'b']),
            $this->group(['c', 'd', 'e']),
        ]);
        $deduper->method('scoreItem')->willReturn(0);
        $deduper->expects(self::never())->method('beginTrans');
        $deduper->expects(self::never())->method('deleteItem');

        $result = PathDedupeRunner::run($deduper, false, 500);

        self::assertFalse($result['applied']);
        self::assertSame(2, $result['groups_found']);
        self::assertSame(2, $result['groups_processed']);
        self::assertSame(2, $result['rows_kept']);
        self::assertSame(3, $result['rows_merged'], '1 loser + 2 losers');
        self::assertFalse($result['truncated']);
    }

    /**
     * CONTROL: with `$apply = true` the same input really does merge, so the
     * dry-run assertion is discriminating.
     */
    public function test_an_applied_run_merges(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([$this->group(['a', 'b'])]);
        $deduper->method('scoreItem')->willReturnCallback(static fn (string $id): int => $id === 'a' ? 5 : 1);
        $deduper->expects(self::once())->method('deleteItem')->with('b');
        $deduper->method('repointReferencingTables')->willReturn(1);

        $result = PathDedupeRunner::run($deduper, true, 500);

        self::assertTrue($result['applied']);
        self::assertSame(1, $result['rows_merged']);
        self::assertSame(0, $result['groups_failed']);
    }

    /**
     * The batch size BOUNDS the run and the remainder is reported as truncated,
     * so a huge library does not turn one queued job into an unbounded one.
     */
    public function test_the_batch_size_bounds_the_run_and_reports_the_remainder(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([
            $this->group(['a', 'b']),
            $this->group(['c', 'd']),
            $this->group(['e', 'f']),
        ]);
        $deduper->method('scoreItem')->willReturn(0);

        $result = PathDedupeRunner::run($deduper, false, 2);

        self::assertSame(3, $result['groups_found']);
        self::assertSame(2, $result['groups_processed']);
        self::assertTrue($result['truncated']);
    }

    /**
     * A group whose merge fails is COUNTED and the run continues.
     *
     * The transaction boundary is per group on purpose: a failure on group 400
     * must not roll back the 399 that succeeded, because the scan that found
     * them is the expensive part.
     */
    public function test_a_failing_group_is_counted_and_the_run_continues(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([
            $this->group(['a', 'b']),
            $this->group(['c', 'd']),
        ]);
        $deduper->method('scoreItem')->willReturn(0);
        $deduper->method('repointReferencingTables')->willReturnCallback(
            static function (string $from): int {
                if ($from === 'b') {
                    throw new RuntimeException('deadlock');
                }

                return 1;
            }
        );

        $result = PathDedupeRunner::run($deduper, true, 500);

        self::assertSame(2, $result['groups_processed']);
        self::assertSame(1, $result['groups_failed']);
        self::assertSame(1, $result['rows_merged'], 'The healthy group still merged.');
    }

    public function test_no_duplicates_is_a_clean_zero(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([]);

        $result = PathDedupeRunner::run($deduper, true, 500);

        self::assertSame(0, $result['groups_found']);
        self::assertSame(0, $result['rows_merged']);
        self::assertFalse($result['truncated']);
    }

    public function test_loser_ids_extracts_the_ids_in_rank_order(): void
    {
        $ranked = PathDedupeRunner::rank(
            $this->deduper(['keep' => 10]),
            [$this->item('keep'), $this->item('x'), $this->item('y')]
        );

        self::assertSame(['x', 'y'], PathDedupeRunner::loserIds($ranked['losers']));
    }
}
