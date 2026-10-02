<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\UserRepository;
use Phlix\Collections\Collection;
use Phlix\Collections\CollectionManager;
use Phlix\Common\Uuid;
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
 * OWNERSHIP MODEL (migration 112, Option A — supersedes the interim
 * admin-only gate of c53b1490; mirrors the syncplay room-visibility
 * precedent of 1ef503b7):
 *   * every handler first resolves the actor from $request->userId; a missing
 *     or empty identity fails closed with the same 401 {error:Unauthorized,
 *     code:auth.required} envelope AuthMiddleware itself emits (belt and
 *     suspenders: the routes are already [AuthMiddleware]-gated).
 *   * LIST reads are SQL-scoped: members get findAllVisibleTo/
 *     getCollectionsForLibraryVisibleTo (own rows + NULL-owner legacy rows);
 *     ACTIVE admins get the raw findAll/getCollectionsForLibrary sets.
 *   * SINGLE reads are visible when the row is the actor's own, when it is
 *     legacy-NULL-owned (shared-legacy policy), or when the actor is an
 *     active admin.
 *   * WRITES (create-stamp, update, delete, addItem, removeItem, bulkAdd,
 *     refresh) require the actor to be the row's owner — or an active admin
 *     for foreign and legacy-NULL rows. create() stamps created_by = actor.
 *   * Every ownership miss returns the byte-identical
 *     404 {error:"Collection not found"} a genuinely absent id produces: the
 *     refusal must be indistinguishable from absence (no 403 oracle).
 *   * The admin predicate is UserRepository::findAdminById — is_admin = 1
 *     AND status = 'active', NEVER the soft is_admin flag — and is consulted
 *     lazily: the owner-hit fast path never queries the users table at all,
 *     and an unwired $adminUsers repository fails closed (non-admin).
 *
 * @since 0.14.0
 */
final class CollectionController
{
    /**
     * @param CollectionManager $manager Collection operations orchestrator
     * @param UserRepository|null $adminUsers Active-admin predicate source;
     *                                        null (unwired) fails closed to non-admin
     */
    public function __construct(
        private readonly CollectionManager $manager,
        private readonly ?UserRepository $adminUsers = null,
    ) {
    }

    /**
     * List collections visible to the actor.
     *
     * Members receive their own rows plus the legacy NULL-owner rows (the
     * predicate lives in SQL — findAllVisibleTo); active admins receive the
     * unscoped set.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters
     * @return Response JSON response with collections list
     *
     * @since 0.14.0
     */
    public function index(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

        $collections = $this->isAdminUser($actor)
            ? $this->manager->findAll()
            : $this->manager->findAllVisibleTo($actor);

        return (new Response())->json([
            'collections' => array_map(fn(Collection $c) => $c->toArray(), $collections),
        ]);
    }

    /**
     * Create a new collection owned by the actor.
     *
     * @param Request $request Current request with JSON body
     * @param array<string, string> $params Path parameters
     * @return Response JSON response with created collection
     *
     * @since 0.14.0
     */
    public function create(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

        $body = $request->body;

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
            createdBy: $actor,
        );

        $this->manager->create($collection);

        return (new Response())->status(201)->json(['collection' => $collection->toArray()]);
    }

    /**
     * Get a visible collection with its items.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with collection and items
     *
     * @since 0.14.0
     */
    public function show(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

        $id = $params['id'] ?? null;
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'id is required']);
        }

        $collectionWithItems = $this->manager->getCollectionWithItems($id);
        if ($collectionWithItems === null) {
            return $this->collectionNotFound();
        }

        if (!$this->actorSees($collectionWithItems->collection, $actor)) {
            return $this->collectionNotFound();
        }

        return (new Response())->json($collectionWithItems->toArray());
    }

    /**
     * Update a collection the actor manages.
     *
     * @param Request $request Current request with JSON body
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with updated collection
     *
     * @since 0.14.0
     */
    public function update(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

        $id = $params['id'] ?? null;
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'id is required']);
        }

        $existing = $this->manager->findById($id);
        if ($existing === null) {
            return $this->collectionNotFound();
        }

        if (!$this->actorManages($existing, $actor)) {
            return $this->collectionNotFound();
        }

        $body = $request->body;

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
            // Ownership is immutable: a rename never re-stamps the anchor.
            createdBy: $existing->createdBy,
        );

        $this->manager->update($updated);

        return (new Response())->json(['collection' => $updated->toArray()]);
    }

    /**
     * Delete a collection the actor manages.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function delete(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

        $id = $params['id'] ?? null;
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'id is required']);
        }

        $existing = $this->manager->findById($id);
        if ($existing === null) {
            return $this->collectionNotFound();
        }

        if (!$this->actorManages($existing, $actor)) {
            return $this->collectionNotFound();
        }

        $this->manager->delete($id);

        return (new Response())->json(['message' => 'Collection deleted successfully']);
    }

    /**
     * Add an item to a collection the actor manages.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id' and 'mediaItemId'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function addItem(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

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
            return $this->collectionNotFound();
        }

        if (!$this->actorManages($collection, $actor)) {
            return $this->collectionNotFound();
        }

        $this->manager->addItem($collectionId, $mediaItemId);

        return (new Response())->json(['message' => 'Item added to collection']);
    }

    /**
     * Remove an item from a collection the actor manages.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id' and 'mediaItemId'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function removeItem(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

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
            return $this->collectionNotFound();
        }

        if (!$this->actorManages($collection, $actor)) {
            return $this->collectionNotFound();
        }

        $this->manager->removeItem($collectionId, $mediaItemId);

        return (new Response())->json(['message' => 'Item removed from collection']);
    }

    /**
     * Bulk add items from search to a collection the actor manages.
     *
     * @param Request $request Current request with JSON body containing 'media_item_ids'
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response on success
     *
     * @since 0.14.0
     */
    public function bulkAdd(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

        $collectionId = $params['id'] ?? null;
        if ($collectionId === null) {
            return (new Response())->status(400)->json(['error' => 'collection id is required']);
        }

        $collection = $this->manager->findById($collectionId);
        if ($collection === null) {
            return $this->collectionNotFound();
        }

        if (!$this->actorManages($collection, $actor)) {
            return $this->collectionNotFound();
        }

        $body = $request->body;
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
     * Refresh a smart collection the actor manages.
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
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

        $id = $params['id'] ?? null;
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'collection id is required']);
        }

        $collection = $this->manager->findById($id);
        if ($collection === null) {
            return $this->collectionNotFound();
        }

        if (!$this->actorManages($collection, $actor)) {
            return $this->collectionNotFound();
        }

        if (!$collection->isSmart()) {
            return (new Response())->status(400)->json(['error' => 'Collection is not a smart collection']);
        }

        $this->manager->refreshSmartCollection($id);

        return (new Response())->json(['message' => 'Smart collection refreshed']);
    }

    /**
     * Get collections visible to the actor within one library.
     *
     * Members receive their own rows plus the legacy NULL-owner rows (the
     * predicate lives in SQL — findVisibleByLibraryId); active admins
     * receive the unscoped set.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'libraryId'
     * @return Response JSON response with collections list
     *
     * @since 0.14.0
     */
    public function forLibrary(Request $request, array $params): Response
    {
        $actor = $this->actorUserId($request);
        if ($actor === null) {
            return $this->unauthorized();
        }

        $libraryId = $params['libraryId'] ?? null;
        if ($libraryId === null) {
            return (new Response())->status(400)->json(['error' => 'library_id is required']);
        }

        $collections = $this->isAdminUser($actor)
            ? $this->manager->getCollectionsForLibrary($libraryId)
            : $this->manager->getCollectionsForLibraryVisibleTo($libraryId, $actor);

        return (new Response())->json([
            'collections' => array_map(fn(Collection $c) => $c->toArray(), $collections),
        ]);
    }

    /**
     * Resolve the acting user id, failing closed on absent/empty identity.
     *
     * The routes already run under AuthMiddleware; this is the in-handler
     * second lock so the controller can never be re-wired onto an
     * unauthenticated group without silently losing its authz predicate.
     */
    private function actorUserId(Request $request): ?string
    {
        $userId = $request->userId;

        return $userId === null || $userId === '' ? null : $userId;
    }

    /**
     * Active-admin predicate (is_admin = 1 AND status = 'active' via
     * UserRepository::findAdminById — never the soft flag). An unwired
     * repository fails closed to non-admin, mirroring
     * SyncPlayController::isAdminUser.
     */
    private function isAdminUser(string $userId): bool
    {
        if ($userId === '' || $this->adminUsers === null) {
            return false;
        }

        return $this->adminUsers->findAdminById($userId) !== null;
    }

    /**
     * Read predicate: own rows, legacy NULL-owner rows, and (for active
     * admins) everything. The owner/legacy hit is an early return so the
     * common member path never queries the users table.
     */
    private function actorSees(Collection $collection, string $actor): bool
    {
        if ($collection->createdBy === $actor || $collection->createdBy === null) {
            return true;
        }

        return $this->isAdminUser($actor);
    }

    /**
     * Write predicate: the owner manages their row; foreign and legacy-NULL
     * rows are admin-only (the interim-gate policy survives exactly for the
     * degenerate unowned case). The owner hit short-circuits before the
     * admin lookup — laziness is pinned in CollectionsOwnerGateTest.
     */
    private function actorManages(Collection $collection, string $actor): bool
    {
        if ($collection->createdBy === $actor) {
            return true;
        }

        return $this->isAdminUser($actor);
    }

    /**
     * The refusal shape every miss shares with genuine absence (no 403 oracle).
     */
    private function collectionNotFound(): Response
    {
        return (new Response())->status(404)->json(['error' => 'Collection not found']);
    }

    /**
     * Same 401 envelope AuthMiddleware emits ({error:Unauthorized,
     * code:auth.required}) — the in-handler fail-closed lock.
     */
    private function unauthorized(): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'Unauthorized',
            'code' => 'auth.required',
        ]);
    }

    /**
     * Generate a v4 UUID.
     *
     * @return string A formatted UUID string
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
