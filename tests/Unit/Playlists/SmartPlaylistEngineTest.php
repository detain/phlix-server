<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Playlists;

use Phlix\Media\Library\ItemRepository;
use Phlix\Playlists\RuleNode;
use Phlix\Playlists\SmartPlaylistEngine;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for {@see SmartPlaylistEngine}.
 *
 * Tests the generator-based memory-efficient evaluation, heap-based top-K
 * selection, and reservoir sampling for random ordering.
 */
class SmartPlaylistEngineTest extends TestCase
{
    private const LIBRARY_ID = 'library-1';

    private ItemRepository&MockObject $itemRepository;
    private SmartPlaylistEngine $engine;

    protected function setUp(): void
    {
        $this->itemRepository = $this->createMock(ItemRepository::class);
        $this->engine = new SmartPlaylistEngine($this->itemRepository);
    }

    /**
     * Creates a media item with the given metadata value for sorting.
     *
     * @param int|float|string|null $value The sort field value
     * @param int|null $id Optional item ID
     * @return array<string, mixed>
     */
    private function createItem(int|float|string|null $value, ?int $id = null): array
    {
        return [
            'id' => $id ?? (is_numeric($value) ? (int) $value : ($value !== null ? ord($value[0]) : 0)),
            'metadata' => ['sortField' => $value],
        ];
    }

    /**
     * Reads the nested metadata.sortField value from an evaluated result item,
     * narrowing the mixed nested structure for static analysis.
     *
     * @param array<int, mixed> $result
     * @return mixed
     */
    private function metadataSortField(array $result, int $index): mixed
    {
        $item = $result[$index];
        self::assertIsArray($item);
        self::assertIsArray($item['metadata']);

        return $item['metadata']['sortField'];
    }

    public function testEvaluateOnScanWithNoLimitReturnsAllItems(): void
    {
        $items = [
            $this->createItem(1),
            $this->createItem(2),
            $this->createItem(3),
        ];

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturn($items);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 0, 'addedAt', true);

        $this->assertCount(3, $result);
    }

    public function testEvaluateOnScanWithLimitReturnsCorrectCount(): void
    {
        $items = array_map(
            fn(int $i) => $this->createItem($i, $i),
            range(1, 100)
        );

        // Simulate batched loading: return items once, then empty to signal end
        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnCallback(fn(string $libId, int $limit, int $offset) => $offset === 0 ? $items : []);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 10, 'addedAt', true);

        $this->assertCount(10, $result);
    }

    public function testEvaluateOnScanDescendingSortReturnsLargestFirst(): void
    {
        $items = [
            $this->createItem(10),
            $this->createItem(5),
            $this->createItem(20),
            $this->createItem(15),
        ];

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnCallback(fn(string $libId, int $limit, int $offset) => $offset === 0 ? $items : []);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 3, 'sortField', true);

        $this->assertCount(3, $result);
        // Should be sorted descending: 20, 15, 10
        $this->assertSame(20, $this->metadataSortField($result, 0));
        $this->assertSame(15, $this->metadataSortField($result, 1));
        $this->assertSame(10, $this->metadataSortField($result, 2));
    }

    public function testEvaluateOnScanAscendingSortReturnsSmallestFirst(): void
    {
        $items = [
            $this->createItem(10),
            $this->createItem(5),
            $this->createItem(20),
            $this->createItem(15),
        ];

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnCallback(fn(string $libId, int $limit, int $offset) => $offset === 0 ? $items : []);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 3, 'sortField', false);

        $this->assertCount(3, $result);
        // Should be sorted ascending: 5, 10, 15
        $this->assertSame(5, $this->metadataSortField($result, 0));
        $this->assertSame(10, $this->metadataSortField($result, 1));
        $this->assertSame(15, $this->metadataSortField($result, 2));
    }

    public function testEvaluateOnScanWithRulesFiltersItems(): void
    {
        $items = [
            $this->createItem(10),
            $this->createItem(5),
            $this->createItem(20),
            $this->createItem(15),
        ];

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnCallback(fn(string $libId, int $limit, int $offset) => $offset === 0 ? $items : []);

        $rules = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'sortField', 'op' => 'gt', 'value' => 10],
            ],
        ];

        $result = $this->engine->evaluateOnScan($rules, self::LIBRARY_ID, 10, 'sortField', true);

        // Should only return items with sortField > 10: 20, 15
        $this->assertCount(2, $result);
        $this->assertSame(20, $this->metadataSortField($result, 0));
        $this->assertSame(15, $this->metadataSortField($result, 1));
    }

    public function testEvaluateOnScanRandomWithLimitUsesReservoirSampling(): void
    {
        // Create 1000 items
        $items = array_map(
            fn(int $i) => $this->createItem($i, $i),
            range(1, 1000)
        );

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnCallback(fn(string $libId, int $limit, int $offset) => $offset === 0 ? $items : []);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 10, 'random', true);

        $this->assertCount(10, $result);
        // All returned items should be from the original set
        foreach ($result as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertGreaterThanOrEqual(1, $item['id']);
            $this->assertLessThanOrEqual(1000, $item['id']);
        }
    }

    public function testEvaluateOnScanBatchedLoadingHandlesMultipleBatches(): void
    {
        // Simulate 3 batches of 500 items each
        $batch1 = array_map(fn(int $i) => $this->createItem($i, $i), range(1, 500));
        $batch2 = array_map(fn(int $i) => $this->createItem($i, $i), range(501, 1000));
        $batch3 = array_map(fn(int $i) => $this->createItem($i, $i), range(1001, 1500));

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnOnConsecutiveCalls($batch1, $batch2, $batch3, []);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 0, 'addedAt', true);

        $this->assertCount(1500, $result);
    }

    public function testEvaluateOnScanWithEmptyLibraryReturnsEmptyArray(): void
    {
        $this->itemRepository
            ->method('getByLibrary')
            ->willReturn([]);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 10, 'sortField', true);

        $this->assertCount(0, $result);
    }

    public function testEvaluateOnScanWithNullValuesHandlesCorrectly(): void
    {
        $items = [
            $this->createItem(null),
            $this->createItem(10),
            $this->createItem(5),
        ];

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnCallback(fn(string $libId, int $limit, int $offset) => $offset === 0 ? $items : []);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 3, 'sortField', true);

        $this->assertCount(3, $result);
        // Null values should be at the end for descending
        $this->assertSame(10, $this->metadataSortField($result, 0));
        $this->assertSame(5, $this->metadataSortField($result, 1));
        $this->assertNull($this->metadataSortField($result, 2));
    }

    public function testEvaluateOnScanWithLimitOneReturnsSingleTopItem(): void
    {
        $items = [
            $this->createItem(10),
            $this->createItem(5),
            $this->createItem(20),
        ];

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnCallback(fn(string $libId, int $limit, int $offset) => $offset === 0 ? $items : []);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 1, 'sortField', true);

        $this->assertCount(1, $result);
        $this->assertSame(20, $this->metadataSortField($result, 0)); // Largest
    }

    public function testEvaluateOnScanDescendingAscendingReturnsSmallest(): void
    {
        $items = [
            $this->createItem(10),
            $this->createItem(5),
            $this->createItem(20),
        ];

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnCallback(fn(string $libId, int $limit, int $offset) => $offset === 0 ? $items : []);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 1, 'sortField', false);

        $this->assertCount(1, $result);
        $this->assertSame(5, $this->metadataSortField($result, 0)); // Smallest
    }

    public function testEvaluateReturnsItemsMatchingRules(): void
    {
        $items = [
            ['id' => '1', 'metadata' => ['genre' => 'Drama', 'year' => 2020]],
            ['id' => '2', 'metadata' => ['genre' => 'Comedy', 'year' => 2015]],
            ['id' => '3', 'metadata' => ['genre' => 'Drama', 'year' => 2018]],
        ];

        $rules = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'genre', 'op' => 'equals', 'value' => 'Drama'],
            ],
        ];

        $result = $this->engine->evaluate($rules, $items, 0, 'addedAt', true);

        $this->assertCount(2, $result);
    }

    public function testBuildFromDslParsesSimpleRule(): void
    {
        $dsl = [
            'logic' => 'and',
            'rules' => [
                ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
            ],
        ];

        $node = $this->engine->buildFromDsl($dsl);

        $this->assertSame(RuleNode::TYPE_AND, $node->type);
        $this->assertCount(1, $node->children);
    }

    public function testBuildFromDslParsesNestedGroups(): void
    {
        $dsl = [
            'logic' => 'or',
            'rules' => [
                [
                    'logic' => 'and',
                    'rules' => [
                        ['field' => 'genre', 'op' => 'equals', 'value' => 'Drama'],
                        ['field' => 'year', 'op' => 'gt', 'value' => 2010],
                    ],
                ],
                [
                    'field' => 'genre',
                    'op' => 'equals',
                    'value' => 'Comedy',
                ],
            ],
        ];

        $node = $this->engine->buildFromDsl($dsl);

        $this->assertSame(RuleNode::TYPE_OR, $node->type);
        $this->assertCount(2, $node->children);
    }

    public function testToJsonSerializesRuleNode(): void
    {
        $node = new RuleNode(
            type: RuleNode::TYPE_AND,
            children: [
                new RuleNode(
                    type: RuleNode::TYPE_RULE,
                    field: 'genre',
                    operator: 'equals',
                    value: 'Drama',
                ),
            ],
        );

        $json = $this->engine->toJson($node);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('and', $decoded['logic']);
        $this->assertIsArray($decoded['rules']);
        $this->assertCount(1, $decoded['rules']);
        $this->assertIsArray($decoded['rules'][0]);
        $this->assertSame('genre', $decoded['rules'][0]['field']);
    }

    public function testEvaluateOnScanWithLargeDataset10500Items(): void
    {
        // Simulate 21 batches of 500 items each = 10,500 items total
        // This tests the memory-safe batched reading mechanism
        $batches = [];
        for ($batchNum = 0; $batchNum < 21; $batchNum++) {
            $startId = ($batchNum * 500) + 1;
            $endId = ($batchNum + 1) * 500;
            $batches[] = array_map(
                fn(int $i) => $this->createItem($i, $i),
                range($startId, $endId)
            );
        }
        $batches[] = []; // End signal

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnOnConsecutiveCalls(...$batches);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 0, 'addedAt', true);

        $this->assertCount(10500, $result);
    }

    public function testEvaluateOnScanWithLargeDatasetAndLimitReturnsCorrectCount(): void
    {
        // Test that top-K selection works correctly with large dataset
        // 22 batches of 500 = 11,000 items, return only top 100
        $batches = [];
        for ($batchNum = 0; $batchNum < 22; $batchNum++) {
            $startId = ($batchNum * 500) + 1;
            $endId = ($batchNum + 1) * 500;
            $batches[] = array_map(
                fn(int $i) => $this->createItem($i, $i),
                range($startId, $endId)
            );
        }
        $batches[] = []; // End signal

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnOnConsecutiveCalls(...$batches);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 100, 'sortField', true);

        $this->assertCount(100, $result);
        // Should return the top 100 highest values (10900-11000)
        $this->assertSame(11000, $this->metadataSortField($result, 0));
        $this->assertSame(10901, $this->metadataSortField($result, 99));
    }

    public function testEvaluateOnScanWithLargeDatasetAndRandomSortUsesReservoirSampling(): void
    {
        // Test reservoir sampling with 10,500 items, selecting 50 random
        $batches = [];
        for ($batchNum = 0; $batchNum < 21; $batchNum++) {
            $startId = ($batchNum * 500) + 1;
            $endId = ($batchNum + 1) * 500;
            $batches[] = array_map(
                fn(int $i) => $this->createItem($i, $i),
                range($startId, $endId)
            );
        }
        $batches[] = []; // End signal

        $this->itemRepository
            ->method('getByLibrary')
            ->willReturnOnConsecutiveCalls(...$batches);

        $result = $this->engine->evaluateOnScan([], self::LIBRARY_ID, 50, 'random', true);

        $this->assertCount(50, $result);
        // All returned items should have IDs between 1 and 10500
        foreach ($result as $item) {
            $this->assertGreaterThanOrEqual(1, $item['id']);
            $this->assertLessThanOrEqual(10500, $item['id']);
        }
    }

    // ---- M2: rules against REAL hydrated list<string> metadata -------------

    /**
     * The real hydrated shape from FieldMappers: the key is the PLURAL
     * `genres` and the value is a `list<string>`, e.g. ['Drama','Crime'].
     *
     * @param list<string> $genres
     * @return array<string, mixed>
     */
    private function itemWithGenres(string $id, array $genres): array
    {
        return ['id' => $id, 'metadata' => ['genres' => $genres, 'title' => 'T-' . $id]];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $rule
     * @return list<string> ids of the matching items, in input order
     */
    private function idsMatching(array $items, array $rule): array
    {
        $result = $this->engine->evaluate(
            ['logic' => 'and', 'rules' => [$rule]],
            $items,
            0,
            'title',
            false
        );

        return array_values(array_map(static fn(array $item): string => (string)$item['id'], $result));
    }

    public function testContainsAgainstGenresListMatchesAnyElement(): void
    {
        $items = [
            $this->itemWithGenres('1', ['Drama', 'Crime']),
            $this->itemWithGenres('2', ['Comedy']),
        ];

        $this->assertSame(
            ['1'],
            $this->idsMatching($items, ['field' => 'genres', 'op' => 'contains', 'value' => 'Drama']),
        );
    }

    public function testContainsAgainstGenresListIsSubstringPerElement(): void
    {
        $items = [
            $this->itemWithGenres('1', ['Drama', 'Crime']),
            $this->itemWithGenres('2', ['Comedy']),
        ];

        $this->assertSame(
            ['1'],
            $this->idsMatching($items, ['field' => 'genres', 'op' => 'contains', 'value' => 'rim']),
        );
    }

    public function testNotContainsAgainstGenresListNoLongerMatchesEverything(): void
    {
        $items = [
            $this->itemWithGenres('1', ['Drama', 'Crime']),
            $this->itemWithGenres('2', ['Comedy']),
        ];

        // Pre-M2: the array cast to '' so notContains matched EVERY item.
        $this->assertSame(
            ['2'],
            $this->idsMatching($items, ['field' => 'genres', 'op' => 'notContains', 'value' => 'Drama']),
        );
    }

    public function testEqualsAgainstGenresListUsesAnyOf(): void
    {
        $items = [
            $this->itemWithGenres('1', ['Drama', 'Crime']),
            $this->itemWithGenres('2', ['Comedy']),
        ];

        $this->assertSame(
            ['1'],
            $this->idsMatching($items, ['field' => 'genres', 'op' => 'equals', 'value' => 'Crime']),
        );
        $this->assertSame(
            [],
            $this->idsMatching($items, ['field' => 'genres', 'op' => 'equals', 'value' => 'Dram']),
        );
    }

    public function testNotEqualsAgainstGenresListUsesNoneOf(): void
    {
        $items = [
            $this->itemWithGenres('1', ['Drama', 'Crime']),
            $this->itemWithGenres('2', ['Comedy']),
        ];

        $this->assertSame(
            ['2'],
            $this->idsMatching($items, ['field' => 'genres', 'op' => 'notEquals', 'value' => 'Drama']),
        );
    }

    public function testScalarFieldSemanticsUnchangedByListSupport(): void
    {
        $items = [
            ['id' => '1', 'metadata' => ['title' => 'Dune']],
            ['id' => '2', 'metadata' => ['title' => 'Dunkirk']],
        ];

        $this->assertSame(
            ['1', '2'],
            $this->idsMatching($items, ['field' => 'title', 'op' => 'contains', 'value' => 'Du']),
        );
    }

    public function testObjectListElementsNeverMatchViaEmptyCast(): void
    {
        // 'cast' holds list<array{...}> — non-scalar elements must be skipped,
        // not stringified to '' (which would match an empty needle only, but
        // proves no accidental containment via casts).
        $items = [
            ['id' => '1', 'metadata' => ['cast' => [['name' => 'Timothée']]]],
        ];

        $this->assertSame(
            [],
            $this->idsMatching($items, ['field' => 'cast', 'op' => 'contains', 'value' => 'Timothée']),
        );
    }

    public function testGteBoundaryHasNoEpsilonFalsePositive(): void
    {
        $items = [
            ['id' => '1', 'metadata' => ['sortField' => 2010.9995]],
            ['id' => '2', 'metadata' => ['sortField' => 2011]],
        ];

        // Pre-L4, the -0.001 epsilon let 2010.9995 satisfy `gte 2011`.
        $this->assertSame(
            ['2'],
            $this->idsMatching($items, ['field' => 'sortField', 'op' => 'gte', 'value' => 2011]),
        );
        // Exact-boundary equality still matches.
        $this->assertSame(
            ['1', '2'],
            $this->idsMatching($items, ['field' => 'sortField', 'op' => 'gte', 'value' => 2010.9995]),
        );
    }

    // ---- M4: bounded top-K must equal the naive full-sort + slice -----------

    public function testTopKBoundedSelectionMatchesNaiveFullSortOrdering(): void
    {
        // Deterministic pseudo-random fixture of 137 items (> K = 10) mixing
        // numeric values, ties, and nulls (nulls sort last per comparator).
        $values = [];
        mt_srand(20260929);
        for ($i = 1; $i <= 137; $i++) {
            $roll = $i % 7;
            $values[] = match (true) {
                $roll === 0 => null,
                $roll < 3 => (int)($i / 10),   // lots of ties
                default => mt_rand(1, 200),
            };
        }

        $items = [];
        foreach ($values as $index => $value) {
            $items[] = ['id' => 'item-' . $index, 'metadata' => ['sortField' => $value]];
        }

        // Naive reference: full evaluation, full sort, then slice — computed
        // in-test from the fixture with the same semantics the class promises
        // (numeric desc, nulls last, stable for ties).
        $reference = $items;
        usort($reference, static function (array $a, array $b): int {
            $va = $a['metadata']['sortField'];
            $vb = $b['metadata']['sortField'];
            if ($va === null && $vb === null) {
                return 0;
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }
            return -((int)$va <=> (int)$vb);
        });
        $expectedIds = array_map(
            static fn(array $item): string => (string)$item['id'],
            array_slice($reference, 0, 10)
        );

        // Re-run stream fixture in the SAME order for a fair comparison.
        $this->assertSame($expectedIds, $this->runTopKWithFixture($items, 10, true));
    }

    public function testTopKBoundedSelectionMatchesNaiveFullSortAscending(): void
    {
        $items = [];
        for ($i = 1; $i <= 53; $i++) {
            $items[] = ['id' => 'x' . $i, 'metadata' => ['sortField' => ($i * 37) % 53]];
        }

        $reference = $items;
        usort($reference, fn(array $a, array $b): int => $a['metadata']['sortField'] <=> $b['metadata']['sortField']);
        $expectedIds = array_map(
            static fn(array $item): string => (string)$item['id'],
            array_slice($reference, 0, 7)
        );

        $this->assertSame($expectedIds, $this->runTopKWithFixture($items, 7, false));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private function runTopKWithFixture(array $items, int $limit, bool $desc): array
    {
        $repository = $this->createMock(ItemRepository::class);
        $repository
            ->method('getByLibrary')
            ->willReturnCallback(
                function (string $libId, int $batch, int $offset) use ($items) {
                    return array_slice($items, $offset, $batch);
                }
            );

        $engine = new SmartPlaylistEngine($repository);
        $result = $engine->evaluateOnScan([], self::LIBRARY_ID, $limit, 'sortField', $desc);

        return array_map(static fn(array $item): string => (string)$item['id'], $result);
    }
}
