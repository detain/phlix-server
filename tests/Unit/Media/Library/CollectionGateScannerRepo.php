<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

/**
 * Minimal self-contained scanner repo double for the S33 auto-collections gate
 * test ({@see MediaScannerAutoCollectionsTest}).
 *
 * Extends the real {@see ItemRepository} so {@see ItemRepository::upsertByPath()}
 * find-or-create logic is genuine, but overrides the handful of read/write
 * methods the movie-leaf scan path touches so no query ever reaches the mock
 * Connection:
 *   - findByPath()/findPathsMap()/findTopLevelByCanonical() → "brand new file";
 *   - create() → store the row and return its id;
 *   - findById() → return the created row WITH a `tmdb_id` injected, which is the
 *     precondition the scanner's per-item collection-sync block checks before it
 *     would ever call {@see \Phlix\Media\CollectionService::syncCollectionForMovie()}.
 *
 * In its OWN PSR-4 file (rather than inline in the test) so it is autoloadable
 * independently and each file holds a single class (PSR-12).
 */
final class CollectionGateScannerRepo extends ItemRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $store = [];

    private int $seq = 0;

    private int $tmdbId;

    public function __construct(Connection $db, int $tmdbId)
    {
        parent::__construct($db);
        $this->tmdbId = $tmdbId;
    }

    public function findByPath(string $path, ?string $libraryId = null): ?array
    {
        return null;
    }

    /**
     * @param array<int, string> $paths
     * @return array<string, array<string, mixed>>
     */
    public function findPathsMap(array $paths, ?string $libraryId = null): array
    {
        return [];
    }

    public function findTopLevelByCanonical(string $libraryId, string $type, string $canonicalKey): ?array
    {
        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $id = 'id-' . (++$this->seq);
        $this->store[] = [
            'id' => $id,
            'library_id' => $data['library_id'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? null,
            'path' => $data['path'] ?? null,
            'metadata_json' => $data['metadata_json'] ?? [],
        ];
        return $id;
    }

    public function findById(string $id): ?array
    {
        foreach ($this->store as $item) {
            if (($item['id'] ?? null) === $id) {
                $meta = is_array($item['metadata_json'] ?? null) ? $item['metadata_json'] : [];
                // Inject the tmdb_id the scanner's collection-sync gate requires.
                $meta['tmdb_id'] = $this->tmdbId;
                $item['metadata_json'] = $meta;
                return $item;
            }
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function items(): array
    {
        return $this->store;
    }
}
