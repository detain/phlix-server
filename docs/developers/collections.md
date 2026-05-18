# Collections (Step H.2)

**Phase:** H (Smart Features)
**Step:** H.2
**Since:** 0.14.0

## Overview

Collections are named groups of media items that curators can manually assemble (bulk-add from search) or that derive from saved smart playlist rules. Collections appear alongside libraries in the UI, support defined sort order, and can be nested.

## Model

### Entity: `Collection`

```php
final class Collection
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $libraryId,
        public readonly ?string $smartPlaylistId, // null = manual
        public readonly ?string $parentId,          // null = top-level
        public readonly int $sortOrder = 0,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public function isSmart(): bool  // true if smartPlaylistId !== null
}
```

### DTO: `CollectionWithItems`

Hydrated DTO containing the collection entity plus its full list of media items.

```php
final class CollectionWithItems
{
    public function __construct(
        public readonly Collection $collection,
        public readonly array $items, // MediaItem[]
        public readonly int $total,
    ) {}
}
```

## Database Schema

```sql
CREATE TABLE collections (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    library_id CHAR(36) NOT NULL,
    smart_playlist_id CHAR(36) NULL,
    parent_id CHAR(36) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_col_library (library_id),
    INDEX idx_col_smart_pl (smart_playlist_id),
    INDEX idx_col_parent (parent_id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_items (
    collection_id CHAR(36) NOT NULL,
    media_item_id CHAR(36) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    added_at DATETIME NOT NULL,
    PRIMARY KEY (collection_id, media_item_id),
    INDEX idx_ci_media (media_item_id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/collections` | List all collections |
| POST | `/api/v1/collections` | Create collection |
| GET | `/api/v1/collections/{id}` | Get collection with items |
| PUT | `/api/v1/collections/{id}` | Update collection |
| DELETE | `/api/v1/collections/{id}` | Delete collection |
| POST | `/api/v1/collections/{id}/items/{mediaItemId}` | Add item to collection |
| DELETE | `/api/v1/collections/{id}/items/{mediaItemId}` | Remove item from collection |
| POST | `/api/v1/collections/{id}/bulk-add` | Bulk-add from search results |
| POST | `/api/v1/collections/{id}/refresh` | Re-evaluate smart collection |
| GET | `/api/v1/libraries/{libraryId}/collections` | Collections for library |

### Creating a Collection

```json
POST /api/v1/collections
{
    "name": "Oscar Winners 2020-2024",
    "library_id": "lib-uuid-here",
    "parent_id": null,
    "sort_order": 0
}
```

Response (201):
```json
{
    "collection": {
        "id": "col-uuid",
        "name": "Oscar Winners 2020-2024",
        "library_id": "lib-uuid-here",
        "smart_playlist_id": null,
        "parent_id": null,
        "sort_order": 0,
        "is_smart": false,
        "created_at": "2024-01-15 10:30:00",
        "updated_at": "2024-01-15 10:30:00"
    }
}
```

### Bulk-adding Items

```json
POST /api/v1/collections/col-uuid/bulk-add
{
    "media_item_ids": ["media-1", "media-2", "media-3"]
}
```

The client first searches for items, then passes the resolved IDs to bulk-add. The server trusts the client to have already validated those IDs.

## Smart Collection Sync Algorithm

Smart collections auto-sync from saved playlist rules when:

1. A library scan completes (`LibraryUpdated` event)
2. A user manually triggers refresh via `POST /api/v1/collections/{id}/refresh`

**Sync uses diff (not wipe-and-rebuild)**:

```php
public function refreshSmartCollection(string $id): void
{
    // 1. Load collection and its smart playlist
    $playlist = $this->playlistRepo->findById($collection->smartPlaylistId);

    // 2. Evaluate rules against library
    $matchedItems = $this->engine->evaluateOnScan($playlist->getRules(), ...);

    // 3. Diff: items in new results but not in collection → add
    $toAdd = array_diff($newIds, $currentIds);

    // 4. Diff: items in collection but not in new results → remove
    $toRemove = array_diff($currentIds, $newIds);

    // 5. Add new items with sort order at end
    foreach ($toAdd as $mediaItemId) {
        $this->itemRepo->insert($id, $mediaItemId, $sortOrder++);
    }

    // 6. Remove non-matching items (preserves curator order on retained items)
    foreach ($toRemove as $mediaItemId) {
        $this->itemRepo->delete($id, $mediaItemId);
    }
}
```

This approach preserves curator-applied sort orders on retained items while adding new matches and removing items that no longer match.

## Integration with SmartPlaylistRefreshHandler

When `SmartPlaylistRefreshHandler::onLibraryUpdated()` re-evaluates a smart playlist, it also calls `refreshSmartCollection()` for any collection linked to that playlist:

```php
private function refreshCollectionsForPlaylist(string $smartPlaylistId): void
{
    $collections = $this->collectionRepo->findBySmartPlaylistId($smartPlaylistId);
    foreach ($collections as $collection) {
        $this->collectionManager->refreshSmartCollection($collection->id);
    }
}
```

## Architecture

```
CollectionController (REST API)
└── CollectionManager (orchestrator)
    ├── CollectionRepository (CRUD)
    ├── CollectionItemRepository (membership)
    ├── SmartPlaylistEngine (rule evaluation)
    ├── SmartPlaylistRepository (playlist lookup)
    └── ItemRepository (media item lookup)
```

## Extending Collection Types

To add a new collection type (e.g., "Recently Added" auto-collection):

1. Add a `type` column to the `collections` table via migration
2. Add `$type` to the `Collection` entity
3. Add type-specific logic in `CollectionManager` (e.g., query recently added items)
4. Document the new type in this file

## Testing

- **Unit tests**: `tests/unit/Collections/`
- **Integration test**: `tests/integration/Collections/CollectionCrudTest.php`
- Coverage target: `CollectionManager`, `CollectionRepository`, `CollectionItemRepository` ≥ 85%
