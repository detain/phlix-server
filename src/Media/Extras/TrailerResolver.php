<?php

declare(strict_types=1);

namespace Phlex\Media\Extras;

use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Metadata\TmdbProvider;

/**
 * TrailerResolver merges local trailers with TMDB trailers.
 *
 * Local trailers take priority over TMDB trailers with the same title.
 * Results are cached in media_extras with a 24-hour TTL.
 *
 * @since 0.14.0
 */
class TrailerResolver
{
    /** @var ItemRepository Repository for media items */
    private ItemRepository $items;

    /** @var TmdbProvider TMDB provider for trailer data */
    private TmdbProvider $tmdb;

    /** @var ExtrasRepository Repository for extras storage */
    private ExtrasRepository $extras;

    /** @var TrailerFinder Finder for local trailer files */
    private TrailerFinder $trailerFinder;

    /** @var int Cache TTL in seconds (24 hours) */
    private int $cacheTtl;

    /**
     * @param ItemRepository $items Repository for media items
     * @param TmdbProvider $tmdb TMDB provider
     * @param ExtrasRepository $extras Repository for extras storage
     * @param TrailerFinder $trailerFinder Finder for local trailers
     * @param int $cacheTtl Cache TTL in seconds (default 24 hours)
     */
    public function __construct(
        ItemRepository $items,
        TmdbProvider $tmdb,
        ExtrasRepository $extras,
        TrailerFinder $trailerFinder,
        int $cacheTtl = 86400
    ) {
        $this->items = $items;
        $this->tmdb = $tmdb;
        $this->extras = $extras;
        $this->trailerFinder = $trailerFinder;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Get trailers for a media item.
     *
     * Merges local trailers with TMDB trailers, with local taking priority.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return Trailer[] Array of trailers
     */
    public function getTrailers(string $mediaItemId): array
    {
        // Check cache first
        if ($this->extras->isCacheValid($mediaItemId, $this->cacheTtl)) {
            return $this->buildTrailersFromDb($mediaItemId);
        }

        // Refresh from sources
        $this->refreshTrailers($mediaItemId);

        return $this->buildTrailersFromDb($mediaItemId);
    }

    /**
     * Get non-trailer extras for a media item.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return Extra[] Array of extras
     */
    public function getExtras(string $mediaItemId): array
    {
        // Check cache first
        if ($this->extras->isCacheValid($mediaItemId, $this->cacheTtl)) {
            return $this->buildExtrasFromDb($mediaItemId);
        }

        // Refresh from sources
        $this->refreshTrailers($mediaItemId);

        return $this->buildExtrasFromDb($mediaItemId);
    }

    /**
     * Get all extras (trailers + non-trailer) for a media item.
     *
     * Returns a merged, type-priority sorted list.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return array<int, Trailer|Extra> Merged array of trailers and extras
     */
    public function getAllExtras(string $mediaItemId): array
    {
        $trailers = $this->getTrailers($mediaItemId);
        $extras = $this->getExtras($mediaItemId);

        // Merge and sort by type priority
        $all = array_merge($trailers, $extras);

        usort($all, function ($a, $b) {
            $priorityA = $this->getTypePriority($a);
            $priorityB = $this->getTypePriority($b);

            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            // Same priority: sort by title
            return strcmp($a->title, $b->title);
        });

        return $all;
    }

    /**
     * Refresh trailers and extras from all sources.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return void
     */
    public function refreshTrailers(string $mediaItemId): void
    {
        $item = $this->items->findById($mediaItemId);
        if ($item === null) {
            return;
        }

        // Clear existing extras for this item
        $this->extras->deleteByMediaItemId($mediaItemId);

        $extras = [];

        // Get local trailers from filesystem (item['path'] could be mixed)
        $mediaPath = is_string($item['path'] ?? null) ? $item['path'] : '';
        if ($mediaPath !== '') {
            $localTrailers = $this->scanLocalTrailers($mediaItemId, $mediaPath);
            foreach ($localTrailers as $trailer) {
                $extras[] = $trailer;
            }
        }

        // Get TMDB trailers
        $tmdbTrailers = $this->fetchTmdbTrailers($mediaItemId, $item);
        foreach ($tmdbTrailers as $trailer) {
            $extras[] = $trailer;
        }

        // Batch insert all extras
        if (!empty($extras)) {
            $this->extras->batchInsert($extras);
        }
    }

    /**
     * Scan local filesystem for trailers.
     *
     * @param string $mediaItemId The media item ID
     * @param string $mediaPath The main media file path
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanLocalTrailers(string $mediaItemId, string $mediaPath): array
    {
        $mediaDir = dirname($mediaPath);
        $mediaFilename = basename($mediaPath);

        $localTrailers = $this->trailerFinder->findLocalTrailers($mediaDir, $mediaFilename);

        $extras = [];
        foreach ($localTrailers as $trailer) {
            $extras[] = [
                'id' => $this->generateUuid(),
                'media_item_id' => $mediaItemId,
                'title' => $trailer['title'],
                'extra_type' => Extra::TYPE_TRAILER,
                'source' => 'local',
                'url' => 'file://' . $trailer['path'],
                'file_path' => $trailer['path'],
                'duration' => $trailer['duration'],
                'quality' => $trailer['quality'],
                'cached_at' => date('Y-m-d H:i:s'),
            ];
        }

        return $extras;
    }

    /**
     * Fetch trailers from TMDB.
     *
     * @param string $mediaItemId The media item ID
     * @param array<string, mixed> $item The media item
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTmdbTrailers(string $mediaItemId, array $item): array
    {
        $extras = [];

        // Get TMDB ID from metadata
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $tmdbId = $metadata['tmdb_id'] ?? null;

        if (!is_string($tmdbId) && !is_numeric($tmdbId)) {
            return $extras;
        }

        // Get trailers from TMDB
        $tmdbTrailers = $this->tmdb->getTrailers((string) $tmdbId);

        foreach ($tmdbTrailers as $tmdbTrailer) {
            $extras[] = [
                'id' => $this->generateUuid(),
                'media_item_id' => $mediaItemId,
                'title' => is_string($tmdbTrailer['title'] ?? null) ? $tmdbTrailer['title'] : 'Trailer',
                'extra_type' => Extra::TYPE_TRAILER,
                'source' => 'tmdb',
                'url' => is_string($tmdbTrailer['url'] ?? null) ? $tmdbTrailer['url'] : '',
                'file_path' => '',
                'duration' => is_numeric($tmdbTrailer['duration'] ?? null) ? (int) $tmdbTrailer['duration'] : 0,
                'quality' => is_numeric($tmdbTrailer['quality'] ?? null) ? (int) $tmdbTrailer['quality'] : 0,
                'cached_at' => date('Y-m-d H:i:s'),
            ];
        }

        return $extras;
    }

    /**
     * Build Trailer objects from database records.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return Trailer[]
     */
    private function buildTrailersFromDb(string $mediaItemId): array
    {
        $rows = $this->extras->findTrailersByMediaItemId($mediaItemId);

        return array_map(function ($row) {
            $source = is_string($row['source'] ?? null) ? $row['source'] : '';
            return new Trailer(
                id: is_string($row['id'] ?? null) ? $row['id'] : '',
                mediaItemId: is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '',
                title: is_string($row['title'] ?? null) ? $row['title'] : '',
                source: $source,
                url: is_string($row['url'] ?? null) ? $row['url'] : '',
                duration: is_numeric($row['duration'] ?? null) ? (int) $row['duration'] : 0,
                quality: is_numeric($row['quality'] ?? null) ? (int) $row['quality'] : 0,
                isLocal: $source === 'local',
                filePath: is_string($row['file_path'] ?? null) ? $row['file_path'] : ''
            );
        }, $rows);
    }

    /**
     * Build Extra objects from database records.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return Extra[]
     */
    private function buildExtrasFromDb(string $mediaItemId): array
    {
        $rows = $this->extras->findNonTrailerExtrasByMediaItemId($mediaItemId);

        return array_map(function ($row) {
            $source = is_string($row['source'] ?? null) ? $row['source'] : '';
            $type = is_string($row['extra_type'] ?? null) ? $row['extra_type'] : 'clip';
            return new Extra(
                id: is_string($row['id'] ?? null) ? $row['id'] : '',
                mediaItemId: is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '',
                title: is_string($row['title'] ?? null) ? $row['title'] : '',
                type: $type,
                source: $source,
                url: is_string($row['url'] ?? null) ? $row['url'] : '',
                duration: is_numeric($row['duration'] ?? null) ? (int) $row['duration'] : 0,
                quality: is_numeric($row['quality'] ?? null) ? (int) $row['quality'] : 0,
                isLocal: $source === 'local',
                filePath: is_string($row['file_path'] ?? null) ? $row['file_path'] : ''
            );
        }, $rows);
    }

    /**
     * Get type priority for sorting.
     *
     * Lower values = higher priority.
     *
     * @param Trailer|Extra $item
     *
     * @return int Priority value
     */
    private function getTypePriority(Trailer|Extra $item): int
    {
        if ($item instanceof Trailer) {
            return 1; // Trailers have highest priority
        }

        return match ($item->type) {
            Extra::TYPE_TRAILER => 1,
            Extra::TYPE_FEATURETTE => 2,
            Extra::TYPE_BEHIND_THE_SCENES => 3,
            Extra::TYPE_INTERVIEW => 4,
            Extra::TYPE_CLIP => 5,
            Extra::TYPE_DELETED_SCENE => 6,
            default => 10,
        };
    }

    /**
     * Generate a UUID.
     *
     * @return string UUID
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
