<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserRepository;
use Phlix\Collections\Collection;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionWithItems;
use Phlix\Server\Http\Controllers\CollectionController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see CollectionController}.
 *
 * Covers the ten handler methods now wired in Application::loadCollectionRoutes():
 *   GET    /api/v1/collections                       - index
 *   POST   /api/v1/collections                       - create
 *   GET    /api/v1/collections/{id}                   - show
 *   PUT    /api/v1/collections/{id}                 - update
 *   DELETE /api/v1/collections/{id}                - delete
 *   POST   /api/v1/collections/{id}/items/{mediaItemId}  - add item
 *   DELETE /api/v1/collections/{id}/items/{mediaItemId}  - remove item
 *   POST   /api/v1/collections/{id}/bulk-add         - bulkAdd
 *   POST   /api/v1/collections/{id}/refresh          - refresh
 *   GET    /api/v1/libraries/{libraryId}/collections  - forLibrary
 *
 * Uses createMock(CollectionManager::class) following the project's existing
 * controller-test conventions (see AuthControllerTest, LibraryControllerTest).
 *
 * Ownership model (migration 112): handlers fail closed to the 401
 * auth.required envelope without $request->userId, list reads are scoped per
 * actor (members: *VisibleTo; active admins: raw sets), and row writes go
 * through the owner-or-admin predicate. This file pins the per-handler
 * identity plumbing and the member/admin list-scope arms; the refusal shapes,
 * laziness, stamping, and route-wiring proofs live in
 * tests/Unit/Server/Core/CollectionsOwnerGateTest.php.
 */
class CollectionControllerTest extends TestCase
{
    /** The acting member every request below carries. */
    private const ACTOR = 'user-1';

    /**
     * Helper to build a minimal Collection for use in mock returns.
     *
     * Owned by ACTOR by default so write handlers pass the ownership
     * predicate; pass $ownedBy to model foreign/legacy rows.
     */
    private function makeCollection(
        string $id = 'c-1',
        string $name = 'My Collection',
        ?string $ownedBy = self::ACTOR,
    ): Collection {
        return new Collection(
            id: $id,
            name: $name,
            libraryId: 'lib-1',
            smartPlaylistId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new \DateTimeImmutable('2024-01-01'),
            updatedAt: new \DateTimeImmutable('2024-01-01'),
            createdBy: $ownedBy,
        );
    }

    /**
     * An authenticated request (userId set), optionally with a JSON body.
     */
    private function request(?array $body = null): Request
    {
        $request = new Request();
        $request->userId = self::ACTOR;
        if ($body !== null) {
            $request->body = $body;
        }

        return $request;
    }

    /**
     * A UserRepository mock answering the ACTOR as an ACTIVE admin.
     */
    private function activeAdminUsers(): UserRepository
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')
            ->with(self::ACTOR)
            ->willReturn(['id' => self::ACTOR, 'is_admin' => 1, 'status' => 'active']);

        return $users;
    }

    // ─── index ─────────────────────────────────────────────────────────────────

    public function testIndexAsMemberReturnsVisibleCollectionsList(): void
    {
        $collection = $this->makeCollection();
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findAllVisibleTo')
            ->with(self::ACTOR)
            ->willReturn([$collection]);
        // Members never receive the unscoped set.
        $manager->expects($this->never())->method('findAll');

        // Unwired admin predicate (null) — the controller cannot invent
        // omniscience; the member scope is the fail-closed arm.
        $controller = new CollectionController($manager);
        $response = $controller->index($this->request(), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body['collections']);
        $this->assertCount(1, $body['collections']);
        $this->assertSame('c-1', $body['collections'][0]['id']);
        $this->assertSame(self::ACTOR, $body['collections'][0]['created_by']);
    }

    public function testIndexAsActiveAdminReturnsUnscopedList(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findAll')
            ->willReturn([$this->makeCollection(ownedBy: 'someone-else')]);
        $manager->expects($this->never())->method('findAllVisibleTo');

        $controller = new CollectionController($manager, $this->activeAdminUsers());
        $response = $controller->index($this->request(), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['collections']);
        $this->assertSame('someone-else', $body['collections'][0]['created_by']);
    }

    // ─── create ────────────────────────────────────────────────────────────────

    public function testCreateReturns201OnSuccess(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(Collection::class));

        $controller = new CollectionController($manager);

        $response = $controller->create($this->request([
            'name' => 'New Collection',
            'library_id' => 'lib-1',
        ]), []);

        $this->assertSame(201, $response->statusCode);
        /** @var array{collection: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('New Collection', $body['collection']['name']);
        $this->assertSame('lib-1', $body['collection']['library_id']);
    }

    public function testCreateReturns400WhenNameMissing(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->never())->method('create');

        $controller = new CollectionController($manager);

        $response = $controller->create($this->request(['library_id' => 'lib-1']), []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('name is required', $body['error']);
    }

    public function testCreateReturns400WhenLibraryIdMissing(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->never())->method('create');

        $controller = new CollectionController($manager);

        $response = $controller->create($this->request(['name' => 'No Library']), []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('library_id is required', $body['error']);
    }

    // ─── show ─────────────────────────────────────────────────────────────────

    public function testShowReturns200WithItems(): void
    {
        $collection = $this->makeCollection();
        $withItems = new CollectionWithItems($collection, [], 0);

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('getCollectionWithItems')
            ->with('c-1')
            ->willReturn($withItems);

        $controller = new CollectionController($manager);
        $response = $controller->show($this->request(), ['id' => 'c-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{collection: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('c-1', $body['collection']['id']);
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('getCollectionWithItems')
            ->with('not-found')
            ->willReturn(null);

        $controller = new CollectionController($manager);
        $response = $controller->show($this->request(), ['id' => 'not-found']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Collection not found', $body['error']);
    }

    // ─── update ────────────────────────────────────────────────────────────────

    public function testUpdateReturns200OnSuccess(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->once())
            ->method('update')
            ->with($this->isInstanceOf(Collection::class));

        $controller = new CollectionController($manager);

        $response = $controller->update($this->request(['name' => 'Updated Name']), ['id' => 'c-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{collection: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Updated Name', $body['collection']['name']);
    }

    public function testUpdateReturns404WhenNotFound(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('not-found')
            ->willReturn(null);

        $controller = new CollectionController($manager);
        $response = $controller->update($this->request(), ['id' => 'not-found']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Collection not found', $body['error']);
    }

    // ─── delete ────────────────────────────────────────────────────────────────

    public function testDeleteReturns200OnSuccess(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->once())
            ->method('delete')
            ->with('c-1');

        $controller = new CollectionController($manager);
        $response = $controller->delete($this->request(), ['id' => 'c-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Collection deleted successfully', $body['message']);
    }

    public function testDeleteReturns404WhenNotFound(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('not-found')
            ->willReturn(null);

        $controller = new CollectionController($manager);
        $response = $controller->delete($this->request(), ['id' => 'not-found']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Collection not found', $body['error']);
    }

    // ─── addItem ───────────────────────────────────────────────────────────────

    public function testAddItemReturns200OnSuccess(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->once())
            ->method('addItem')
            ->with('c-1', 'media-1');

        $controller = new CollectionController($manager);
        $response = $controller->addItem($this->request(), ['id' => 'c-1', 'mediaItemId' => 'media-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Item added to collection', $body['message']);
    }

    public function testAddItemReturns404WhenCollectionNotFound(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('not-found')
            ->willReturn(null);

        $controller = new CollectionController($manager);
        $response = $controller->addItem($this->request(), ['id' => 'not-found', 'mediaItemId' => 'media-1']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Collection not found', $body['error']);
    }

    public function testAddItemReturns400WhenCollectionIdMissing(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->never())->method('addItem');

        $controller = new CollectionController($manager);
        $response = $controller->addItem($this->request(), ['mediaItemId' => 'media-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('collection id is required', $body['error']);
    }

    // ─── removeItem ────────────────────────────────────────────────────────────

    public function testRemoveItemReturns200OnSuccess(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->once())
            ->method('removeItem')
            ->with('c-1', 'media-1');

        $controller = new CollectionController($manager);
        $response = $controller->removeItem($this->request(), ['id' => 'c-1', 'mediaItemId' => 'media-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Item removed from collection', $body['message']);
    }

    public function testRemoveItemReturns404WhenCollectionNotFound(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('not-found')
            ->willReturn(null);

        $controller = new CollectionController($manager);
        $response = $controller->removeItem($this->request(), ['id' => 'not-found', 'mediaItemId' => 'media-1']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Collection not found', $body['error']);
    }

    // ─── bulkAdd ───────────────────────────────────────────────────────────────

    public function testBulkAddReturns200OnSuccess(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->once())
            ->method('bulkAddFromSearch')
            ->with('c-1', ['media-1', 'media-2']);

        $controller = new CollectionController($manager);

        $response = $controller->bulkAdd(
            $this->request(['media_item_ids' => ['media-1', 'media-2']]),
            ['id' => 'c-1']
        );

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Items added to collection', $body['message']);
        $this->assertSame(2, $body['added_count']);
    }

    public function testBulkAddReturns400WhenMediaItemIdsMissing(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->never())->method('bulkAddFromSearch');

        $controller = new CollectionController($manager);

        $response = $controller->bulkAdd($this->request([]), ['id' => 'c-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('media_item_ids array is required', $body['error']);
    }

    public function testBulkAddReturns400WhenMediaItemIdsEmpty(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->never())->method('bulkAddFromSearch');

        $controller = new CollectionController($manager);

        $response = $controller->bulkAdd($this->request(['media_item_ids' => []]), ['id' => 'c-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('media_item_ids array is required', $body['error']);
    }

    public function testBulkAddReturns400WhenAllMediaItemIdsInvalid(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->never())->method('bulkAddFromSearch');

        $controller = new CollectionController($manager);

        $response = $controller->bulkAdd($this->request(['media_item_ids' => ['', '  ']]), ['id' => 'c-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('media_item_ids must contain at least one valid id', $body['error']);
    }

    // ─── refresh ────────────────────────────────────────────────────────────────

    public function testRefreshReturns200OnSmartCollection(): void
    {
        $collection = new Collection(
            id: 'c-1',
            name: 'Smart Collection',
            libraryId: 'lib-1',
            smartPlaylistId: 'sp-1', // Smart collection
            parentId: null,
            sortOrder: 0,
            createdAt: new \DateTimeImmutable('2024-01-01'),
            updatedAt: new \DateTimeImmutable('2024-01-01'),
            createdBy: self::ACTOR,
        );

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->once())
            ->method('refreshSmartCollection')
            ->with('c-1');

        $controller = new CollectionController($manager);
        $response = $controller->refresh($this->request(), ['id' => 'c-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Smart collection refreshed', $body['message']);
    }

    public function testRefreshReturns400OnNonSmartCollection(): void
    {
        $collection = $this->makeCollection(); // Not a smart collection (smartPlaylistId = null)

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('c-1')
            ->willReturn($collection);
        $manager->expects($this->never())->method('refreshSmartCollection');

        $controller = new CollectionController($manager);
        $response = $controller->refresh($this->request(), ['id' => 'c-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Collection is not a smart collection', $body['error']);
    }

    public function testRefreshReturns404WhenNotFound(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('findById')
            ->with('not-found')
            ->willReturn(null);

        $controller = new CollectionController($manager);
        $response = $controller->refresh($this->request(), ['id' => 'not-found']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Collection not found', $body['error']);
    }

    // ─── forLibrary ───────────────────────────────────────────────────────────

    public function testForLibraryAsMemberUsesVisibleScope(): void
    {
        $collection = $this->makeCollection();

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('getCollectionsForLibraryVisibleTo')
            ->with('lib-1', self::ACTOR)
            ->willReturn([$collection]);
        $manager->expects($this->never())->method('getCollectionsForLibrary');

        $controller = new CollectionController($manager);
        $response = $controller->forLibrary($this->request(), ['libraryId' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body['collections']);
        $this->assertCount(1, $body['collections']);
        $this->assertSame('c-1', $body['collections'][0]['id']);
    }

    public function testForLibraryAsActiveAdminUsesUnscopedSet(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('getCollectionsForLibrary')
            ->with('lib-1')
            ->willReturn([$this->makeCollection(ownedBy: 'someone-else')]);
        $manager->expects($this->never())->method('getCollectionsForLibraryVisibleTo');

        $controller = new CollectionController($manager, $this->activeAdminUsers());
        $response = $controller->forLibrary($this->request(), ['libraryId' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testForLibraryReturns400WhenLibraryIdMissing(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->never())->method('getCollectionsForLibrary');

        $controller = new CollectionController($manager);
        $response = $controller->forLibrary($this->request(), []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('library_id is required', $body['error']);
    }
}
