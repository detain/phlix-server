# Step 2.3: Item Repository

**Phase:** 2 - Media Library & Metadata System  
**Plan File:** step-2.3-item-repository.md  
**Objective:** Implement ItemRepository with full CRUD operations, queries, and batch operations

---

## Overview

This step implements the ItemRepository class for CRUD operations on media items, optimized queries, and search functionality.

**Prerequisites:** Step 2.2 must be completed first.

---

## Tasks

### 2.3.1 Create ItemRepository Class

Create `src/Media/Library/ItemRepository.php`:
```php
<?php

namespace Phlex\Media\Library;

use Phlex\Common\Database\Connection;

class ItemRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findById(string $id): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM media_items WHERE id = ?",
            [$id]
        );
        
        if (empty($result)) {
            return null;
        }
        
        return $this->hydrateItem($result[0]);
    }

    public function findByPath(string $path): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM media_items WHERE path = ?",
            [$path]
        );
        
        if (empty($result)) {
            return null;
        }
        
        return $this->hydrateItem($result[0]);
    }

    public function findByParent(string $parentId): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE parent_id = ? ORDER BY name",
            [$parentId]
        );
        
        return array_map(fn($r) => $this->hydrateItem($r), $results);
    }

    public function getByType(string $libraryId, string $type, int $limit = 100, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? AND type = ? ORDER BY name LIMIT ? OFFSET ?",
            [$libraryId, $type, $limit, $offset]
        );
        
        return array_map(fn($r) => $this->hydrateItem($r), $results);
    }

    public function getByLibrary(string $libraryId, int $limit = 100, int $offset = 0): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? ORDER BY name LIMIT ? OFFSET ?",
            [$libraryId, $limit, $offset]
        );
        
        return array_map(fn($r) => $this->hydrateItem($r), $results);
    }

    public function search(string $query, int $limit = 50): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE MATCH(name) AGAINST(? IN BOOLEAN MODE) LIMIT ?",
            [$query, $limit]
        );
        
        return array_map(fn($r) => $this->hydrateItem($r), $results);
    }

    public function searchFuzzy(string $query, int $limit = 50): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE name LIKE ? LIMIT ?",
            ['%' . $this->db->escape($query) . '%', $limit]
        );
        
        return array_map(fn($r) => $this->hydrateItem($r), $results);
    }

    public function create(array $data): string
    {
        $id = $data['id'] ?? $this->generateUuid();
        $metadataJson = isset($data['metadata_json']) 
            ? (is_array($data['metadata_json']) ? json_encode($data['metadata_json']) : $data['metadata_json'])
            : '{}';

        $this->db->query(
            "INSERT INTO media_items (id, library_id, parent_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $data['library_id'],
                $data['parent_id'] ?? null,
                $data['name'],
                $data['type'],
                $data['path'],
                $metadataJson,
            ]
        );
        
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            if ($key === 'metadata_json' && is_array($value)) {
                $value = json_encode($value);
            }
            $values[] = $value;
        }
        
        if (empty($sets)) {
            return;
        }
        
        $values[] = $id;
        
        $this->db->query(
            "UPDATE media_items SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
    }

    public function delete(string $id): void
    {
        $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);
    }

    public function deleteByLibrary(string $libraryId): void
    {
        $this->db->query("DELETE FROM media_items WHERE library_id = ?", [$libraryId]);
    }

    public function countByType(string $libraryId, string $type): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM media_items WHERE library_id = ? AND type = ?",
            [$libraryId, $type]
        );
        
        return (int)($result[0]['count'] ?? 0);
    }

    public function getRecentlyAdded(string $libraryId, int $limit = 20): array
    {
        $results = $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? ORDER BY created_at DESC LIMIT ?",
            [$libraryId, $limit]
        );
        
        return array_map(fn($r) => $this->hydrateItem($r), $results);
    }

    public function getItemStreams(string $itemId): array
    {
        return $this->db->query(
            "SELECT * FROM media_streams WHERE media_item_id = ? ORDER BY stream_index",
            [$itemId]
        );
    }

    public function addStream(string $itemId, array $streamData): string
    {
        $id = $streamData['id'] ?? $this->generateUuid();
        
        $this->db->query(
            "INSERT INTO media_streams (id, media_item_id, stream_index, stream_type, codec, language, bitrate, width, height)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $itemId,
                $streamData['stream_index'],
                $streamData['stream_type'],
                $streamData['codec'] ?? null,
                $streamData['language'] ?? null,
                $streamData['bitrate'] ?? null,
                $streamData['width'] ?? null,
                $streamData['height'] ?? null,
            ]
        );
        
        return $id;
    }

    public function batchCreate(array $items): array
    {
        $ids = [];
        
        foreach ($items as $item) {
            $ids[] = $this->create($item);
        }
        
        return $ids;
    }

    private function hydrateItem(array $row): array
    {
        $row['metadata_json'] = $row['metadata_json'] ?? '{}';
        if (is_string($row['metadata_json'])) {
            $row['metadata'] = json_decode($row['metadata_json'], true) ?? [];
        } else {
            $row['metadata'] = $row['metadata_json'];
        }
        return $row;
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
```

### 2.3.2 Create Media Items API Controller

Create `src/Server/Http/Controllers/MediaItemController.php`:
```php
<?php

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Media\Library\ItemRepository;

class MediaItemController
{
    private ItemRepository $itemRepository;

    public function __construct(ItemRepository $itemRepository)
    {
        $this->itemRepository = $itemRepository;
    }

    public function index(Request $request, array $params): Response
    {
        $libraryId = $params['library_id'] ?? null;
        $type = $request->query['type'] ?? null;
        $limit = (int)($request->query['limit'] ?? 100);
        $offset = (int)($request->query['offset'] ?? 0);

        if ($libraryId) {
            if ($type) {
                $items = $this->itemRepository->getByType($libraryId, $type, $limit, $offset);
            } else {
                $items = $this->itemRepository->getByLibrary($libraryId, $limit, $offset);
            }
        } else {
            $items = $this->itemRepository->searchFuzzy($request->query['q'] ?? '', $limit);
        }

        return (new Response())->json(['items' => $items]);
    }

    public function show(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);
        
        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Also get streams
        $item['streams'] = $this->itemRepository->getItemStreams($item['id']);

        return (new Response())->json(['item' => $item]);
    }

    public function children(Request $request, array $params): Response
    {
        $children = $this->itemRepository->findByParent($params['id']);
        return (new Response())->json(['items' => $children]);
    }

    public function search(Request $request, array $params): Response
    {
        $query = $request->query['q'] ?? '';
        
        if (empty($query)) {
            return (new Response())->status(400)->json(['error' => 'Query parameter "q" is required']);
        }

        $items = $this->itemRepository->searchFuzzy($query);
        return (new Response())->json(['items' => $items]);
    }

    public function recentlyAdded(Request $request, array $params): Response
    {
        $libraryId = $params['library_id'] ?? null;
        $limit = (int)($request->query['limit'] ?? 20);

        if (!$libraryId) {
            return (new Response())->status(400)->json(['error' => 'library_id is required']);
        }

        $items = $this->itemRepository->getRecentlyAdded($libraryId, $limit);
        return (new Response())->json(['items' => $items]);
    }

    public function delete(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);
        
        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $this->itemRepository->delete($params['id']);

        return (new Response())->json(['message' => 'Item deleted successfully']);
    }
}
```

### 2.3.3 Create Unit Tests

Create `tests/unit/Media/Library/ItemRepositoryTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;

class ItemRepositoryTest extends TestCase
{
    public function testCanCreateItemRepository(): void
    {
        $db = $this->createMock(\Phlex\Common\Database\Connection::class);
        $repo = new ItemRepository($db);
        
        $this->assertInstanceOf(ItemRepository::class, $repo);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(\Phlex\Common\Database\Connection::class);
        $db->method('query')->willReturn([]);
        
        $repo = new ItemRepository($db);
        $result = $repo->findById('non-existent-id');
        
        $this->assertNull($result);
    }

    public function testFindByIdReturnsItemWhenFound(): void
    {
        $db = $this->createMock(\Phlex\Common\Database\Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'test-id',
                'name' => 'Test Movie',
                'type' => 'movie',
                'library_id' => 'lib-1',
                'path' => '/movies/test.mkv',
                'metadata_json' => '{}',
            ]
        ]);
        
        $repo = new ItemRepository($db);
        $result = $repo->findById('test-id');
        
        $this->assertIsArray($result);
        $this->assertEquals('test-id', $result['id']);
        $this->assertEquals('Test Movie', $result['name']);
    }
}
```

---

## Verification

After completing all tasks:

1. Run unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Media/Library/ItemRepositoryTest.php --testdox
```

2. Verify classes exist:
```bash
ls -la /home/sites/phlex/src/Media/Library/
```

---

## Git Workflow

After verification, commit your changes:

```bash
cd /home/sites/phlex
git checkout -b step-2.3-item-repository
git add .
git commit -m "Step 2.3: Implement ItemRepository with CRUD and search"
unset GITHUB_TOKEN
gh pr create --title "Step 2.3: Item Repository" --body "Implements ItemRepository with full CRUD operations, search functionality, and batch operations."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 2.R: Phase 2 Review** (`plans/phase-2/step-2.R-phase-review.md`).
