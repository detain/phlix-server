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
        $this->assertSame(20, $result[0]['metadata']['sortField']);
        $this->assertSame(15, $result[1]['metadata']['sortField']);
        $this->assertSame(10, $result[2]['metadata']['sortField']);
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
        $this->assertSame(5, $result[0]['metadata']['sortField']);
        $this->assertSame(10, $result[1]['metadata']['sortField']);
        $this->assertSame(15, $result[2]['metadata']['sortField']);
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
        $this->assertSame(20, $result[0]['metadata']['sortField']);
        $this->assertSame(15, $result[1]['metadata']['sortField']);
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
        $this->assertSame(10, $result[0]['metadata']['sortField']);
        $this->assertSame(5, $result[1]['metadata']['sortField']);
        $this->assertNull($result[2]['metadata']['sortField']);
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
        $this->assertSame(20, $result[0]['metadata']['sortField']); // Largest
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
        $this->assertSame(5, $result[0]['metadata']['sortField']); // Smallest
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
        $this->assertSame('and', $decoded['logic']);
        $this->assertCount(1, $decoded['rules']);
        $this->assertSame('genre', $decoded['rules'][0]['field']);
    }
}
