<?php

declare(strict_types=1);

namespace Phlex\Dlna;

use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Streaming\HlsStreamer;

/**
 * LibraryBridge connects the media library (ItemRepository) to the DLNA ContentDirectory service.
 *
 * This bridge transforms media items from the database into CDS (Content Directory Service)
 * compatible objects with proper DIDL-Lite metadata and HLS stream URLs.
 *
 * @since 0.12.0
 */
class LibraryBridge
{
    /** @var ItemRepository Media item repository */
    private ItemRepository $itemRepository;

    /** @var HlsStreamer HLS streaming service */
    private HlsStreamer $hlsStreamer;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger;

    /**
     * @param ItemRepository $itemRepository Repository for accessing media items
     * @param HlsStreamer $hlsStreamer Service for generating HLS stream URLs
     * @param StructuredLogger|null $logger Optional logger for diagnostics
     *
     * @since 0.12.0
     */
    public function __construct(
        ItemRepository $itemRepository,
        HlsStreamer $hlsStreamer,
        ?StructuredLogger $logger = null
    ) {
        $this->itemRepository = $itemRepository;
        $this->hlsStreamer = $hlsStreamer;
        $this->logger = $logger;
    }

    /**
     * Get root containers representing the media library categories.
     *
     * Returns video, audio, and image libraries with accurate child counts from the database.
     *
     * @return array<int, array{id: string, parent_id: string, name: string,
     *           type: string, class: string, child_count: int}>
     *
     * @since 0.12.0
     */
    public function getRootContainers(): array
    {
        $this->logger?->debug('LibraryBridge: Getting root containers');

        return [
            [
                'id' => 'library-video',
                'parent_id' => '0',
                'name' => 'Video',
                'type' => 'container',
                'class' => 'object.container',
                'child_count' => $this->getLibraryChildCount('video'),
            ],
            [
                'id' => 'library-audio',
                'parent_id' => '0',
                'name' => 'Audio',
                'type' => 'container',
                'class' => 'object.container',
                'child_count' => $this->getLibraryChildCount('audio'),
            ],
            [
                'id' => 'library-images',
                'parent_id' => '0',
                'name' => 'Images',
                'type' => 'container',
                'class' => 'object.container',
                'child_count' => $this->getLibraryChildCount('image'),
            ],
        ];
    }

    /**
     * Get child count for a library type.
     *
     * @param string $libraryType The library type (video, audio, images)
     * @return int Number of items in the library
     *
     * @since 0.12.0
     */
    private function getLibraryChildCount(string $libraryType): int
    {
        $type = match ($libraryType) {
            'video' => 'movie',
            'audio' => 'audio',
            'images' => 'image',
            default => null,
        };

        if ($type === null) {
            return 0;
        }

        return $this->itemRepository->countAllByType($type);
    }

    /**
     * Get children of a container (library, folder, playlist).
     *
     * Uses ItemRepository::findByParent() to get actual media items.
     *
     * @param string $objectId The object ID of the container
     * @return array<int, array<string, mixed>> Array of child items
     *
     * @since 0.12.0
     */
    public function getContainerChildren(string $objectId): array
    {
        $this->logger?->debug('LibraryBridge: Getting container children', ['object_id' => $objectId]);

        // Handle library containers
        if (strpos($objectId, 'library-') === 0) {
            $libraryType = substr($objectId, 8);
            return $this->getLibraryItems($libraryType);
        }

        // Handle regular parent-based children
        $items = $this->itemRepository->findByParent($objectId);

        return array_map(fn($item) => $this->itemToCdsObject($item), $items);
    }

    /**
     * Get media items from a specific library type.
     *
     * @param string $libraryType The library type (video, audio, images)
     * @return array<int, array<string, mixed>> Array of media items converted to CDS objects
     *
     * @since 0.12.0
     */
    private function getLibraryItems(string $libraryType): array
    {
        $type = match ($libraryType) {
            'video' => 'movie',
            'audio' => 'audio',
            'images' => 'image',
            default => null,
        };

        if ($type === null) {
            return [];
        }

        $items = $this->itemRepository->getAllByType($type);

        return array_map(fn($item) => $this->itemToCdsObject($item), $items);
    }

    /**
     * Get a media item as a CDS object by its ID.
     *
     * @param string $objectId The object ID to look up
     * @return array<string, mixed>|null The CDS object or null if not found
     *
     * @since 0.12.0
     */
    public function getMediaObject(string $objectId): ?array
    {
        $this->logger?->debug('LibraryBridge: Getting media object', ['object_id' => $objectId]);

        // Handle library container IDs
        if (strpos($objectId, 'library-') === 0) {
            $libraryType = substr($objectId, 8);
            return [
                'id' => $objectId,
                'parent_id' => '0',
                'name' => ucfirst($libraryType),
                'type' => 'container',
                'class' => 'object.container',
            ];
        }

        // Try to find in database
        $item = $this->itemRepository->findById($objectId);

        if ($item === null) {
            return null;
        }

        return $this->itemToCdsObject($item);
    }

    /**
     * Convert an ItemRepository media item to a CDS object format.
     *
     * Maps database fields to CDS (Content Directory Service) object format with
     * all necessary metadata for DIDL-Lite generation.
     *
     * @param array<string, mixed> $item Raw media item from ItemRepository
     * @return array<string, mixed> CDS-compatible object array
     *
      * @since 0.12.0
     */
    public function itemToCdsObject(array $item): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        // Handle genre field - could be string or array
        $genreValue = $metadata['genre'] ?? '';
        if (is_string($genreValue)) {
            $genre = $genreValue;
        } elseif (is_array($metadata['genres'] ?? null)) {
            /** @var array<mixed> $genres */
            $genres = $metadata['genres'];
            $genreStrings = [];
            foreach ($genres as $g) {
                if (is_scalar($g)) {
                    $genreStrings[] = (string) $g;
                } elseif ($g === null) {
                    $genreStrings[] = '';
                }
            }
            $genre = implode(', ', $genreStrings);
        } else {
            $genre = '';
        }

        return [
            'id' => $item['id'],
            'parent_id' => $item['parent_id'] ?? '0',
            'name' => $item['name'],
            'type' => $item['type'],
            'class' => $this->determineUpnpClass($item),
            'path' => $item['path'] ?? '',
            // Metadata fields
            'artist' => $metadata['artist'] ?? $item['artist'] ?? '',
            'album' => $metadata['album'] ?? $item['album'] ?? '',
            'genre' => $genre,
            'duration' => $this->parseDuration($metadata['duration'] ?? null),
            'date' => $metadata['release_date'] ?? $item['created_at'] ?? '',
            'width' => $metadata['width'] ?? $item['width'] ?? 0,
            'height' => $metadata['height'] ?? $item['height'] ?? 0,
            'thumbnail' => $this->buildThumbnailUrl($item),
            'creator' => $metadata['creator'] ?? '',
            'mime_type' => $this->determineMimeType($item),
        ];
    }

    /**
     * Determine the UPnP class based on media item type.
     *
     * @param array<string, mixed> $item Media item from repository
     * @return string UPnP class string
     *
     * @since 0.12.0
     */
    private function determineUpnpClass(array $item): string
    {
        /** @var string $type */
        $type = $item['type'] ?? 'unknown';

        return match ($type) {
            'movie', 'video' => 'object.item.videoItem.movie',
            'series', 'tvshow' => 'object.item.videoItem.videoBroadcast',
            'audio', 'music' => 'object.item.audioItem.musicTrack',
            'image', 'photo' => 'object.item.imageItem.photo',
            default => 'object.item.' . $type,
        };
    }

    /**
     * Parse duration from various formats to seconds.
     *
     * @param mixed $duration Duration value (could be seconds, HH:MM:SS, etc.)
     * @return int Duration in seconds
     *
     * @since 0.12.0
     */
    private function parseDuration($duration): int
    {
        if ($duration === null || $duration === '') {
            return 0;
        }

        if (is_int($duration) || is_numeric($duration)) {
            return (int)$duration;
        }

        // Try to parse HH:MM:SS or MM:SS format
        if (is_string($duration) && preg_match('/^(\d+):(\d+):(\d+)$/', $duration, $matches)) {
            return (int)$matches[1] * 3600 + (int)$matches[2] * 60 + (int)$matches[3];
        }

        if (is_string($duration) && preg_match('/^(\d+):(\d+)$/', $duration, $matches)) {
            return (int)$matches[1] * 60 + (int)$matches[2];
        }

        return 0;
    }

    /**
     * Build a thumbnail URL for a media item.
     *
     * @param array<string, mixed> $item Media item
     * @return string Thumbnail URL or empty string
     *
     * @since 0.12.0
     */
    private function buildThumbnailUrl(array $item): string
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        $thumbnail = $metadata['thumbnail'] ?? null;
        if (is_string($thumbnail) && $thumbnail !== '') {
            return $thumbnail;
        }

        $poster = $metadata['poster'] ?? null;
        if (is_string($poster) && $poster !== '') {
            return $poster;
        }

        $itemThumb = $item['thumbnail'] ?? null;
        if (is_string($itemThumb) && $itemThumb !== '') {
            return $itemThumb;
        }

        return '';
    }

    /**
     * Determine MIME type based on item type and path.
     *
     * @param array<string, mixed> $item Media item
     * @return string MIME type
     *
     * @since 0.12.0
     */
    private function determineMimeType(array $item): string
    {
        // First check if we have an explicit mime type
        $mimeType = $item['mime_type'] ?? null;
        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        // Fall back to extension-based detection
        $path = $item['path'] ?? '';
        $extension = is_string($path) ? pathinfo($path, PATHINFO_EXTENSION) : '';
        $type = is_string($item['type'] ?? null) ? $item['type'] : '';

        return match (strtolower($extension)) {
            'mp4', 'm4v' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => match ($type) {
                'video', 'movie' => 'video/mp4',
                'audio', 'music' => 'audio/mpeg',
                'image', 'photo' => 'image/jpeg',
                default => 'application/octet-stream',
            },
        };
    }

    /**
     * Get the HLS stream URL for a media item.
     *
     * Uses HlsStreamer to generate a proper HLS streaming URL for the media item.
     *
     * @param array<string, mixed> $item The media item array
     * @return string HLS stream URL
     *
     * @since 0.12.0
     */
    public function getStreamUrl(array $item): string
    {
        $itemId = $item['id'] ?? '';
        $this->logger?->debug('LibraryBridge: Getting stream URL', ['item_id' => $itemId]);

        return $this->hlsStreamer->getStreamUrl($item);
    }

    /**
     * Get the ItemRepository instance.
     *
     * @return ItemRepository
     *
     * @since 0.12.0
     */
    public function getItemRepository(): ItemRepository
    {
        return $this->itemRepository;
    }

    /**
     * Get the HlsStreamer instance.
     *
     * @return HlsStreamer
     *
     * @since 0.12.0
     */
    public function getHlsStreamer(): HlsStreamer
    {
        return $this->hlsStreamer;
    }
}
