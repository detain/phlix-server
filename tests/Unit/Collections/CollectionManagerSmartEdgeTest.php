<?php

/**
 * Phlix media server test: Collections.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Collections;

use Phlix\Collections\Collection;
use Phlix\Collections\CollectionItemRepository;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SRV-M19 (review server__pm): the smart-collection edge must stop lying.
 *
 * Manager legs pinned here:
 * - `smartPlaylistExists()` is the single existence oracle the HTTP layer
 *   validates writes against;
 * - `refreshSmartCollection()` returns the applied diff counts on a real
 *   refresh and null when the referenced playlist row does not exist — so
 *   the controller can never answer 200 "Smart collection refreshed" over
 *   a silent no-op.
 *
 * `SmartPlaylistRepository` is final, so every leg drives the REAL repo
 * over a mocked Connection (the existing CollectionManagerTest idiom).
 */
class CollectionManagerSmartEdgeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function playlistRow(string $id): array
    {
        return [
            'id' => $id,
            'name' => 'Recently added',
            'library_id' => 'lib-1',
            'rules_json' => '{}',
            'limit' => 0,
            'sort_by' => 'addedAt',
            'sort_desc' => true,
        ];
    }

    private function manager(
        CollectionRepository $collectionRepo,
        CollectionItemRepository $itemRepo,
        SmartPlaylistEngine $engine,
        Connection $db,
    ): CollectionManager {
        return new CollectionManager(
            $collectionRepo,
            $itemRepo,
            $engine,
            new SmartPlaylistRepository($db),
            $this->createMock(ItemRepository::class),
            $db
        );
    }

    private function smartCollection(string $playlistId): Collection
    {
        return new Collection(
            id: 'col-1',
            name: 'Smart Collection',
            libraryId: 'lib-1',
            smartPlaylistId: $playlistId,
            parentId: null,
        );
    }

    public function testSmartPlaylistExistsFollowsThePlaylistRowLookup(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $bind = []): array {
                if (str_contains($sql, 'FROM smart_playlists')) {
                    return $bind === ['sp-real'] ? [$this->playlistRow('sp-real')] : [];
                }
                return [];
            });

        $manager = $this->manager(
            $this->createMock(CollectionRepository::class),
            $this->createMock(CollectionItemRepository::class),
            $this->createMock(SmartPlaylistEngine::class),
            $db
        );

        $this->assertTrue($manager->smartPlaylistExists('sp-real'));
        $this->assertFalse($manager->smartPlaylistExists('sp-ghost'));
        $this->assertFalse($manager->smartPlaylistExists(''));
    }

    public function testRefreshReturnsNullAndAppliesNoDiffWhenThePlaylistRowIsMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findById')->with('col-1')
            ->willReturn($this->smartCollection('sp-ghost'));

        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepo->expects($this->never())->method('applyMemberDiff');

        $manager = $this->manager(
            $collectionRepo,
            $itemRepo,
            $this->createMock(SmartPlaylistEngine::class),
            $db
        );

        $this->assertNull($manager->refreshSmartCollection('col-1'));
    }

    public function testRefreshReturnsNullForANonSmartCollection(): void
    {
        $collection = new Collection(
            id: 'col-1',
            name: 'Manual Collection',
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
        );
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findById')->with('col-1')->willReturn($collection);

        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepo->expects($this->never())->method('applyMemberDiff');

        $manager = $this->manager(
            $collectionRepo,
            $itemRepo,
            $this->createMock(SmartPlaylistEngine::class),
            $this->createMock(Connection::class)
        );

        $this->assertNull($manager->refreshSmartCollection('col-1'));
    }

    public function testRefreshReturnsTheAppliedDiffCounts(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([$this->playlistRow('sp-1')]);

        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findById')->with('col-1')
            ->willReturn($this->smartCollection('sp-1'));

        $engine = $this->createMock(SmartPlaylistEngine::class);
        $engine->method('evaluateOnScan')->willReturn([
            ['id' => 'media-2'],
            ['id' => 'media-1'],
        ]);

        $itemRepo = $this->createMock(CollectionItemRepository::class);
        $itemRepo->method('findMediaItemIdsForCollection')->with('col-1')
            ->willReturn(['media-1', 'media-old']);
        $itemRepo->method('getMaxSortOrder')->with('col-1')->willReturn(5);
        $itemRepo->expects($this->once())->method('applyMemberDiff')
            ->with('col-1', ['media-2'], ['media-old'], 6);

        $manager = $this->manager(
            $collectionRepo,
            $itemRepo,
            $engine,
            $db
        );

        $result = $manager->refreshSmartCollection('col-1');

        $this->assertIsArray($result);
        $this->assertSame(1, $result['items_added']);
        $this->assertSame(1, $result['items_removed']);
    }
}
