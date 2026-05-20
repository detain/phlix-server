<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Collections\Collection;
use Phlix\Collections\CollectionManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * REST API controller for collections.
 *
 * Provides endpoints for managing collections (manual + rule-based)
 * and their items.
 *
 * Routes:
 *   GET    /api/v1/collections                    - list all
 *   POST   /api/v1/collections                    - create
 *   GET    /api/v1/collections/{id}                - get one with items
 *   PUT    /api/v1/collections/{id}                - update
 *   DELETE /api/v1/collections/{id}               - delete
 *   POST   /api/v1/collections/{id}/items/{mediaItemId}  - add item
 *   DELETE /api/v1/collections/{id}/items/{mediaItemId}  - remove item
 *   POST   /api/v1/collections/{id}/bulk-add           - bulk-add from search
 *   POST   /api/v1/collections/{id}/refresh            - re-evaluate smart collection
 *   GET    /api/v1/libraries/{libraryId}/collections   - collections for library
 *
 * @since 0.14.0
 */
final class CollectionController
{
    public function __construct(
        private readonly CollectionManager $manager,
    ) {
    }

    /**
     * List all collections.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters
     * @return Response JSON response with collections list
     *
     * @since 0.14.0
     */
    public function index(Request $request, array $params): Response
    {
        $collections = $this->manager->findAll();

        return (new Response())->json([
            'collections' => array_map(fn(Collection $c) => $c->toArray(), $collections),
        ]);
    }

    /**
     * Create a new collection.
     *
     * @param Request $request Current request with JSON body
     * @param array<string, string> $params Path parameters
     * @return Response JSON response with created collection
     *
     * @since 0.14.0
     */
    public function create(Request $request, array $params): Response
    {
        $body = $request->jsonPayload ?? [];

        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return (new Response())->status(400)->json(['error' => 'name is required']);
        }

        $libraryId = $body['library_id'] ?? null;
        if (!is_string($libraryId) || trim($libraryId) === '') {
            return (new Response())->status(400)->json(['error' => 'library_id is required']);
        }

        $smartPlaylistId = null;
        if (isset($body['smart_playlist_id']) && is_string($body['smart_playlist_id'])) {
            $smartPlaylistId = $body['smart_playlist_id'];
        }

        $parentId = null;
        if (isset($body['parent_id']) && is_string($body['parent_id'])) {
            $parentId = $body['parent_id'];
        }

        $sortOrder = 0;
        if (isset($body['sort_order']) && is_numeric($body['sort_order'])) {
            $sortOrder = (int)$body['sort_order'];
        }

        $now = new \DateTimeImmutable();
        $collection = new Collection(
            id: $this->generateUuid(),
            name: trim($name),
            libraryId: trim($libraryId),
            smartPlaylistId: $smartPlaylistId,
            parentId: $parentId,
            sortOrder: $sortOrder,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->manager->create($collection);

        return (new Response())->status(201)->json(['collection' => $collection->toArray()]);
    }

    /**
     * Get a collection with its items.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with collection and items
     *
     * @since 0.14.0
     */
    public function show(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'id is required']);
        }

        $collectionWithItems = $this->manager->getCollectionWithItems($id);
        if ($collectionWithItems === null) {
            return (new Response())->status(404)->json(['error' => 'Collection not found']);
        }

        return (new Response())->json($collectionWithItems->toArray());
    }

    /**
     * Update a collection.
     *
     * @param Request $request Current request with JSON body
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with updated collection
     *
     * @since 0.14.0
     */
    public function update(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'id is required']);
        }

        $existing = $this->manager->findById($id);
        if ($existing === null) {
            return (new Response())->status(404)->json(['error' => 'Collection not found']);
        }

        $body = $request->jsonPayload ?? [];

        $name = $existing->name;
        if (isset($body['name']) && is_string($body['name']) && trim($body['name']) !== '') {
            $name = trim($body['name']);
        }

        $libraryId = $existing->libraryId;
        if (isset($body['library_id']) && is_string($body['library_id']) && trim($body['library_id']) !== '') {
            $libraryId = trim($body['library_id']);
        }

        $smartPlaylistId = $existing->smartPlaylistId;
        if (array_key_exists('smart_playlist_id', $body)) {
            $smartPlaylistId = is_string($body['smart_playlist_id']) ? $body['smart_playlist_id'] : null;
        }

        $parentId = $existing->parentId;
        if (array_key_exists('parent_id', $body)) {
            $parentId = is_string($body['parent_id']) ? $body['parent_id'] : null;
        }

        $sortOrder = $existing->sortOrder;
        if (isset($body['sort_order']) && is_numeric($body['sort_order'])) {
            $sortOrder = (int)$body['sort_order'];
        }

        $updated = new Collection(
            id: $existing->id,
            name: $name,
            libraryId: $libraryId,
            smartPlaylistId: $smartPlaylistId,
            parentId: $parentId,
            sortOrder: $sortOrder,
            createdAt: $existing->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );

        $this->manager->update($updated);

        return (new Response())->json(['collection' => $updated->toArray()]);
    }

    /**
     * Delete a collection.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function delete(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'id is required']);
        }

        $existing = $this->manager->findById($id);
        if ($existing === null) {
            return (new Response())->status(404)->json(['error' => 'Collection not found']);
        }

        $this->manager->delete($id);

        return (new Response())->json(['message' => 'Collection deleted successfully']);
    }

    /**
     * Add an item to a collection.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id' and 'mediaItemId'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function addItem(Request $request, array $params): Response
    {
        $collectionId = $params['id'] ?? null;
        $mediaItemId = $params['mediaItemId'] ?? null;

        if ($collectionId === null) {
            return (new Response())->status(400)->json(['error' => 'collection id is required']);
        }
        if ($mediaItemId === null) {
            return (new Response())->status(400)->json(['error' => 'media item id is required']);
        }

        $collection = $this->manager->findById($collectionId);
        if ($collection === null) {
            return (new Response())->status(404)->json(['error' => 'Collection not found']);
        }

        $this->manager->addItem($collectionId, $mediaItemId);

        return (new Response())->json(['message' => 'Item added to collection']);
    }

    /**
     * Remove an item from a collection.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id' and 'mediaItemId'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function removeItem(Request $request, array $params): Response
    {
        $collectionId = $params['id'] ?? null;
        $mediaItemId = $params['mediaItemId'] ?? null;

        if ($collectionId === null) {
            return (new Response())->status(400)->json(['error' => 'collection id is required']);
        }
        if ($mediaItemId === null) {
            return (new Response())->status(400)->json(['error' => 'media item id is required']);
        }

        $collection = $this->manager->findById($collectionId);
        if ($collection === null) {
            return (new Response())->status(404)->json(['error' => 'Collection not found']);
        }

        $this->manager->removeItem($collectionId, $mediaItemId);

        return (new Response())->json(['message' => 'Item removed from collection']);
    }

    /**
     * Bulk add items from search.
     *
     * @param Request $request Current request with JSON body containing 'media_item_ids'
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function bulkAdd(Request $request, array $params): Response
    {
        $collectionId = $params['id'] ?? null;
        if ($collectionId === null) {
            return (new Response())->status(400)->json(['error' => 'collection id is required']);
        }

        $collection = $this->manager->findById($collectionId);
        if ($collection === null) {
            return (new Response())->status(404)->json(['error' => 'Collection not found']);
        }

        $body = $request->jsonPayload ?? [];
        $mediaItemIds = $body['media_item_ids'] ?? null;

        if (!is_array($mediaItemIds) || empty($mediaItemIds)) {
            return (new Response())->status(400)->json(['error' => 'media_item_ids array is required']);
        }

        $validIds = [];
        foreach ($mediaItemIds as $id) {
            if (is_string($id) && trim($id) !== '') {
                $validIds[] = trim($id);
            }
        }

        if (empty($validIds)) {
            return (new Response())->status(400)->json([
                'error' => 'media_item_ids must contain at least one valid id',
            ]);
        }

        $this->manager->bulkAddFromSearch($collectionId, $validIds);

        return (new Response())->json([
            'message' => 'Items added to collection',
            'added_count' => count($validIds),
        ]);
    }

    /**
     * Refresh a smart collection.
     *
     * Re-evaluates the underlying smart playlist rules and syncs items.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function refresh(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'collection id is required']);
        }

        $collection = $this->manager->findById($id);
        if ($collection === null) {
            return (new Response())->status(404)->json(['error' => 'Collection not found']);
        }

        if (!$collection->isSmart()) {
            return (new Response())->status(400)->json(['error' => 'Collection is not a smart collection']);
        }

        $this->manager->refreshSmartCollection($id);

        return (new Response())->json(['message' => 'Smart collection refreshed']);
    }

    /**
     * Get collections for a library.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'libraryId'
     * @return Response JSON response with collections list
     *
     * @since 0.14.0
     */
    public function forLibrary(Request $request, array $params): Response
    {
        $libraryId = $params['libraryId'] ?? null;
        if ($libraryId === null) {
            return (new Response())->status(400)->json(['error' => 'library_id is required']);
        }

        $collections = $this->manager->getCollectionsForLibrary($libraryId);

        return (new Response())->json([
            'collections' => array_map(fn(Collection $c) => $c->toArray(), $collections),
        ]);
    }

    /**
     * Generate a v4 UUID.
     *
     * @return string A formatted UUID string
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
