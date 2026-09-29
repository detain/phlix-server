<?php

namespace Phlix\Tests\Unit\Collections;

use PHPUnit\Framework\TestCase;
use Phlix\Collections\CollectionItemRepository;
use Workerman\MySQL\Connection;

class CollectionItemRepositoryTest extends TestCase
{
    public function testCanCreateCollectionItemRepository(): void
    {
        $db = $this->createMock(Connection::class);
        $repo = new CollectionItemRepository($db);

        $this->assertInstanceOf(CollectionItemRepository::class, $repo);
    }

    public function testInsertThenFindMediaIdsReturnsSame(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO collection_items'),
                $this->callback(function ($params) {
                    return count($params) === 4
                        && $params[0] === 'col-1'
                        && $params[1] === 'media-1'
                        && $params[2] === 0;
                })
            );

        $db->method('query')->willReturn([]);

        $repo = new CollectionItemRepository($db);
        $repo->insert('col-1', 'media-1', 0);
    }

    public function testDeleteRemovesRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM collection_items WHERE collection_id'),
                $this->callback(function ($params) {
                    return count($params) === 2
                        && $params[0] === 'col-1'
                        && $params[1] === 'media-1';
                })
            );

        $db->method('query')->willReturn([]);

        $repo = new CollectionItemRepository($db);
        $repo->delete('col-1', 'media-1');
    }

    public function testDeleteAllForCollection(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM collection_items WHERE collection_id'),
                $this->callback(function ($params) {
                    return count($params) === 1 && $params[0] === 'col-1';
                })
            );

        $db->method('query')->willReturn([]);

        $repo = new CollectionItemRepository($db);
        $repo->deleteAllForCollection('col-1');
    }

    public function testCountForCollection(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['cnt' => 5],
        ]);

        $repo = new CollectionItemRepository($db);
        $result = $repo->countForCollection('col-1');

        $this->assertEquals(5, $result);
    }

    public function testCountForCollectionReturnsZeroWhenEmpty(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new CollectionItemRepository($db);
        $result = $repo->countForCollection('col-1');

        $this->assertEquals(0, $result);
    }

    public function testFindMediaItemIdsForCollection(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['media_item_id' => 'media-1'],
            ['media_item_id' => 'media-2'],
            ['media_item_id' => 'media-3'],
        ]);

        $repo = new CollectionItemRepository($db);
        $result = $repo->findMediaItemIdsForCollection('col-1');

        $this->assertCount(3, $result);
        $this->assertEquals('media-1', $result[0]);
        $this->assertEquals('media-2', $result[1]);
        $this->assertEquals('media-3', $result[2]);
    }

    public function testFindMediaItemIdsForCollectionReturnsEmptyWhenNoItems(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new CollectionItemRepository($db);
        $result = $repo->findMediaItemIdsForCollection('col-1');

        $this->assertCount(0, $result);
    }

    public function testExistsInCollectionReturnsTrue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['1' => 1]]);

        $repo = new CollectionItemRepository($db);
        $result = $repo->existsInCollection('col-1', 'media-1');

        $this->assertTrue($result);
    }

    public function testExistsInCollectionReturnsFalse(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new CollectionItemRepository($db);
        $result = $repo->existsInCollection('col-1', 'media-1');

        $this->assertFalse($result);
    }

    public function testGetMaxSortOrder(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['max_order' => 10],
        ]);

        $repo = new CollectionItemRepository($db);
        $result = $repo->getMaxSortOrder('col-1');

        $this->assertEquals(10, $result);
    }

    // ---- L5: applyMemberDiff transactional semantics ------------------------

    public function testApplyMemberDiffWrapsAddsAndRemovesInOneTransaction(): void
    {
        $log = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param list<mixed> $params */
            function (string $sql, array $params) use (&$log): array {
                $log[] = [str_starts_with(trim($sql), 'INSERT') ? 'INSERT' : 'DELETE', $params];
                return [];
            }
        );
        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('commitTrans');
        $db->expects($this->never())->method('rollBackTrans');

        $repo = new CollectionItemRepository($db);
        $repo->applyMemberDiff('col-1', ['media-2', 'media-3'], ['media-1'], 5);

        $this->assertCount(3, $log);
        $this->assertSame('INSERT', $log[0][0]);
        $this->assertSame('INSERT', $log[1][0]);
        $this->assertSame('DELETE', $log[2][0]);
        // sort_order offsets from startSortOrder in add order
        $this->assertSame(5, $log[0][1][2]);
        $this->assertSame(6, $log[1][1][2]);
        $this->assertSame('media-1', $log[2][1][1]);
    }

    public function testApplyMemberDiffRollsBackAndRethrowsOnStatementFailure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params): array {
                // The item id lives in the bound params, not the SQL text.
                if (($params[1] ?? null) === 'media-bad') {
                    throw new \RuntimeException('simulated statement failure');
                }
                return [];
            }
        );
        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->never())->method('commitTrans');
        $db->expects($this->once())->method('rollBackTrans');

        $repo = new CollectionItemRepository($db);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated statement failure');

        $repo->applyMemberDiff('col-1', ['media-bad'], ['media-1'], 1);
    }

    public function testApplyMemberDiffNoOpOnEmptyDiffOpensNoTransaction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $db->expects($this->never())->method('beginTrans');

        $repo = new CollectionItemRepository($db);
        $repo->applyMemberDiff('col-1', [], [], 1);
    }
}
