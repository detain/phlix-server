<?php

declare(strict_types=1);

namespace Phlix\Collections;

use Phlix\Media\Library\ItemRepository;
use Phlix\Playlists\RuleNode;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRepository;

/**
 * Orchestrator for collection operations.
 *
 * Handles create/update/delete of collections and their items,
 * including smart collection refresh which re-evaluates the
 * underlying smart playlist rules and syncs items via diff.
 *
 * @since 0.14.0
 */
final class CollectionManager
{
    public function __construct(
        private readonly CollectionRepository $repo,
        private readonly CollectionItemRepository $itemRepo,
        private readonly SmartPlaylistEngine $engine,
        private readonly SmartPlaylistRepository $playlistRepo,
        private readonly ItemRepository $items,
    ) {
    }

    /**
     * Create a new collection.
     *
     * @param Collection $collection Collection to create
     * @return void
     *
     * @since 0.14.0
     */
    public function create(Collection $collection): void
    {
        $this->repo->insert($collection);
    }

    /**
     * Update an existing collection.
     *
     * @param Collection $collection Collection to update
     * @return void
     *
     * @since 0.14.0
     */
    public function update(Collection $collection): void
    {
        $this->repo->update($collection);
    }

    /**
     * Delete a collection and all its items.
     *
     * @param string $id Collection ID to delete
     * @return void
     *
     * @since 0.14.0
     */
    public function delete(string $id): void
    {
        // Delete all items first (FK constraint would prevent this otherwise)
        $this->itemRepo->deleteAllForCollection($id);
        $this->repo->delete($id);
    }

    /**
     * Add a media item to a collection.
     *
     * @param string $collectionId Collection UUID
     * @param string $mediaItemId Media item UUID
     * @return void
     *
     * @since 0.14.0
     */
    public function addItem(string $collectionId, string $mediaItemId): void
    {
        // Get the next sort order position
        $maxSortOrder = $this->itemRepo->getMaxSortOrder($collectionId);
        $this->itemRepo->insert($collectionId, $mediaItemId, $maxSortOrder + 1);
    }

    /**
     * Remove a media item from a collection.
     *
     * @param string $collectionId Collection UUID
     * @param string $mediaItemId Media item UUID
     * @return void
     *
     * @since 0.14.0
     */
    public function removeItem(string $collectionId, string $mediaItemId): void
    {
        $this->itemRepo->delete($collectionId, $mediaItemId);
    }

    /**
     * Bulk add media items from search results.
     *
     * Accepts an array of pre-resolved media item IDs because the
     * search UI already resolved them before calling the API.
     *
     * @param string $collectionId Collection UUID
     * @param array<int, string> $mediaItemIds Array of media item UUIDs to add
     * @return void
     *
     * @since 0.14.0
     */
    public function bulkAddFromSearch(string $collectionId, array $mediaItemIds): void
    {
        $maxSortOrder = $this->itemRepo->getMaxSortOrder($collectionId);
        $sortOrder = $maxSortOrder + 1;

        foreach ($mediaItemIds as $mediaItemId) {
            // Skip if already in collection
            if ($this->itemRepo->existsInCollection($collectionId, $mediaItemId)) {
                continue;
            }
            $this->itemRepo->insert($collectionId, $mediaItemId, $sortOrder);
            $sortOrder++;
        }
    }

    /**
     * Get a collection with its items hydrated.
     *
     * @param string $id Collection UUID
     * @return CollectionWithItems|null Collection with items or null if not found
     *
     * @since 0.14.0
     */
    public function getCollectionWithItems(string $id): ?CollectionWithItems
    {
        $collection = $this->repo->findById($id);
        if ($collection === null) {
            return null;
        }

        $mediaItemIds = $this->itemRepo->findMediaItemIdsForCollection($id);
        $items = [];

        foreach ($mediaItemIds as $mediaItemId) {
            $item = $this->items->findById($mediaItemId);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $total = count($items);

        return new CollectionWithItems(
            collection: $collection,
            items: $items,
            total: $total,
        );
    }

    /**
     * Get all collections for a library.
     *
     * @param string $libraryId Library UUID
     * @return array<int, Collection> Array of collections
     *
     * @since 0.14.0
     */
    public function getCollectionsForLibrary(string $libraryId): array
    {
        return $this->repo->findByLibraryId($libraryId);
    }

    /**
     * Refresh a smart collection by re-evaluating its rules.
     *
     * Loads the collection's smartPlaylistId, fetches the SmartPlaylist,
     * evaluates the rules against the library, then diffs the result
     * against current items. Adds new matches, removes non-matches.
     * Does NOT wipe and rebuild to preserve curator sort orders.
     *
     * @param string $id Collection UUID
     * @return void
     *
     * @since 0.14.0
     */
    public function refreshSmartCollection(string $id): void
    {
        $collection = $this->repo->findById($id);
        if ($collection === null || $collection->smartPlaylistId === null) {
            return;
        }

        $playlist = $this->playlistRepo->findById($collection->smartPlaylistId);
        if ($playlist === null) {
            return;
        }

        // Evaluate the rules against the library
        /** @var array<array<string, mixed>|RuleNode> $rules */
        $rules = $playlist->getRules();
        $matchedItems = $this->engine->evaluateOnScan(
            $rules,
            $playlist->libraryId,
            $playlist->limit,
            $playlist->sortBy,
            $playlist->sortDesc
        );

        // Get current item IDs in the collection
        $currentIds = $this->itemRepo->findMediaItemIdsForCollection($id);
        $newIds = array_column($matchedItems, 'id');

        // Compute diff: items to add (in new but not in current)
        $newIdsStrings = [];
        foreach ($newIds as $idValue) {
            if (is_string($idValue)) {
                $newIdsStrings[] = $idValue;
            } elseif (is_int($idValue) || is_float($idValue)) {
                $newIdsStrings[] = (string) $idValue;
            }
        }
        $currentIdsStrings = [];
        foreach ($currentIds as $idValue) {
            $currentIdsStrings[] = (string) $idValue;
        }
        $toAdd = array_diff($newIdsStrings, $currentIdsStrings);

        // Compute diff: items to remove (in current but not in new)
        $toRemove = array_diff($currentIdsStrings, $newIdsStrings);

        // Add new items with sort order at the end
        $maxSortOrder = $this->itemRepo->getMaxSortOrder($id);
        $sortOrder = $maxSortOrder + 1;
        foreach ($toAdd as $mediaItemId) {
            $this->itemRepo->insert($id, (string)$mediaItemId, $sortOrder);
            $sortOrder++;
        }

        // Remove items that no longer match
        foreach ($toRemove as $mediaItemId) {
            $this->itemRepo->delete($id, $mediaItemId);
        }
    }

    /**
     * Find a collection by ID.
     *
     * @param string $id Collection UUID
     * @return Collection|null Found collection or null
     *
     * @since 0.14.0
     */
    public function findById(string $id): ?Collection
    {
        return $this->repo->findById($id);
    }

    /**
     * Get all collections.
     *
     * @return array<int, Collection> Array of all collections
     *
     * @since 0.14.0
     */
    public function findAll(): array
    {
        return $this->repo->findAll();
    }
}
