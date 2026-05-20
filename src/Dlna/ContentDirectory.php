<?php

namespace Phlix\Dlna;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;

/**
 * Content Directory Service for DLNA/UPnP Media Servers.
 *
 * Implements the UPnP ContentDirectory:1 service specification for browsing
 * and searching media content. Handles Browse and Search actions, generates
 * DIDL-Lite XML responses, and manages object hierarchy (containers and items).
 *
 * The Content Directory service exposes media items organized in a tree structure
 * rooted at object ID "0". Items can be containers (albums, folders) or individual
 * media items (songs, videos, images). All content is returned in DIDL-Lite format.
 *
 * @since 0.12.0 Replaced stub library data with LibraryBridge integration
 * @see UPnP ContentDirectory:1 Service Specification
 * @see DIDL-Lite Format For metadata XML structure
 */
class ContentDirectory
{
    /** Root object ID for the content directory hierarchy */
    public const OBJECT_ID_ROOT = '0';

    /** Result format constant for XML output */
    public const RESULT_FORMAT_XML = 'xml';

    /** Result format constant for object/array output */
    public const RESULT_FORMAT_OBJECT = 'object';

    /** Sort criteria for alphabetical sorting */
    public const SORT_CRITIERIA_ALPHABETICAL = '*';

    /** Sort criteria for date-based sorting */
    public const SORT_CRITIERIA_DATE = 'dc:date';

    /** Sort criteria for title sorting */
    public const SORT_CRITIERIA_TITLE = 'dc:title';

    /** @var LibraryBridge Bridge to media library for real data */
    private ?LibraryBridge $libraryBridge = null;

    /** @var ItemRepository Item repository for media item data access (fallback) */
    private ItemRepository $itemRepository;

    /** @var StructuredLogger Logger instance for debugging and diagnostics */
    private StructuredLogger $logger;

    /** @var array<string, array<string, mixed>> Cache of resolved object IDs to items */
    private array $objectCache = [];

    /** @var int Current browse flag (0=BrowseMetadata, 1=BrowseDirectChildren) */
    private int $browseFlag = 1;

    /** @var int Total number of matching items for current browse/search */
    private int $totalMatches = 0;

    /** @var int System update ID - increments when content changes */
    private int $systemUpdateId = 1;

    /**
     * @param ItemRepository $itemRepository Item repository for fallback access
     * @param StructuredLogger|null $logger Optional logger
     *
     * @since 0.12.0 Item repository is now secondary to LibraryBridge
     */
    public function __construct(ItemRepository $itemRepository, ?StructuredLogger $logger = null)
    {
        $this->itemRepository = $itemRepository;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Set the LibraryBridge for real library data access.
     *
     * When a LibraryBridge is set, the ContentDirectory will use it to fetch
     * real media items instead of the stub data.
     *
     * @param LibraryBridge $bridge The library bridge instance
     * @return void
     *
     * @since 0.12.0
     */
    public function setLibraryBridge(LibraryBridge $bridge): void
    {
        $this->libraryBridge = $bridge;
    }

    /**
     * Check if LibraryBridge is available.
     *
     * @return bool True if LibraryBridge is set
     *
     * @since 0.12.0
     */
    public function hasLibraryBridge(): bool
    {
        return $this->libraryBridge !== null;
    }

    /**
     * Create a default logger for standalone/test operation.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlix_dlna_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/dlna.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::DLNA, $config);
    }

    /**
     * Handle Browse request.
     *
     * @param string $objectId The object ID to browse
     * @param string $browseFlag BrowseMetadata or BrowseDirectChildren
     * @param string $filter Comma-separated list of properties to return
     * @param int $startingIndex Starting index for results
     * @param int $requestedCount Number of results to return (0 = all)
     * @param string $sortCriteria Sort order
     * @return array<string, mixed> Result containing Result, NumberReturned, TotalMatches, UpdateID
     */
    public function browse(
        string $objectId,
        string $browseFlag = 'BrowseDirectChildren',
        string $filter = '*',
        int $startingIndex = 0,
        int $requestedCount = 0,
        string $sortCriteria = ''
    ): array {
        $this->logger->debug('Browse request', [
            'object_id' => $objectId,
            'browse_flag' => $browseFlag,
            'starting_index' => $startingIndex,
            'requested_count' => $requestedCount,
        ]);

        $this->browseFlag = $browseFlag === 'BrowseMetadata' ? 0 : 1;

        if ($objectId === self::OBJECT_ID_ROOT || $objectId === '') {
            $objectId = self::OBJECT_ID_ROOT;
            return $this->browseRoot($filter, $startingIndex, $requestedCount, $sortCriteria);
        }

        // Check if it's a container or item
        $item = $this->resolveObjectId($objectId);

        if ($item === null) {
            return $this->createErrorResult(701, 'No such object');
        }

        if ($this->browseFlag === 0) {
            // Browse Metadata
            return $this->browseMetadata($objectId, $item, $filter);
        }

        // Browse Direct Children
        return $this->browseChildren($objectId, $filter, $startingIndex, $requestedCount, $sortCriteria);
    }

    /**
     * Handle Search request.
     *
     * @param string $containerId The container to search in (0 for root)
     * @param string $searchCriteria The search criteria (UPnP Search syntax)
     * @param string $filter Properties to return
     * @param int $startingIndex Starting index
     * @param int $requestedCount Number of results
     * @param string $sortCriteria Sort order
     * @return array<string, mixed> Search result
     */
    public function search(
        string $containerId,
        string $searchCriteria,
        string $filter = '*',
        int $startingIndex = 0,
        int $requestedCount = 0,
        string $sortCriteria = ''
    ): array {
        $this->logger->debug('Search request', [
            'container_id' => $containerId,
            'search_criteria' => $searchCriteria,
            'starting_index' => $startingIndex,
        ]);

        // UPnP Search capabilities - simplified implementation
        // Real implementation would parse UPnP Search Expression syntax

        if (empty($searchCriteria) || $searchCriteria === '*') {
            // Return all items
            return $this->browse($containerId, 'BrowseDirectChildren', $filter, $startingIndex, $requestedCount, $sortCriteria);
        }

        // Parse simple search criteria
        $parsedSearch = $this->parseSearchCriteria($searchCriteria);

        if ($parsedSearch === null) {
            return $this->createErrorResult(800, 'Unsupported or invalid search criteria');
        }

        // Perform search based on parsed criteria
        $items = $this->performSearch($containerId, $parsedSearch);

        $this->totalMatches = count($items);

        // Apply pagination
        $resultItems = array_slice($items, $startingIndex, $requestedCount > 0 ? $requestedCount : null);

        // Generate DIDL
        $didl = $this->generateDidl($resultItems);

        return [
            'Result' => $didl,
            'NumberReturned' => count($resultItems),
            'TotalMatches' => $this->totalMatches,
            'UpdateID' => $this->systemUpdateId,
        ];
    }

    /**
     * Browse the root container.
     *
     * @return array<string, mixed>
     */
    private function browseRoot(string $filter, int $startingIndex, int $requestedCount, string $sortCriteria): array
    {
        // Root contains library containers
        $libraries = $this->getLibraryContainers();

        $this->totalMatches = count($libraries);

        // Apply pagination
        $resultItems = array_slice($libraries, $startingIndex, $requestedCount > 0 ? $requestedCount : null);

        $didl = $this->generateDidl($resultItems);

        return [
            'Result' => $didl,
            'NumberReturned' => count($resultItems),
            'TotalMatches' => $this->totalMatches,
            'UpdateID' => $this->systemUpdateId,
        ];
    }

    /**
     * Browse metadata for a specific object.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function browseMetadata(string $objectId, array $item, string $filter): array
    {
        $didl = $this->generateDidl([$item], true);

        return [
            'Result' => $didl,
            'NumberReturned' => 1,
            'TotalMatches' => 1,
            'UpdateID' => $this->systemUpdateId,
        ];
    }

    /**
     * Browse direct children of a container.
     *
     * @return array<string, mixed>
     */
    private function browseChildren(
        string $objectId,
        string $filter,
        int $startingIndex,
        int $requestedCount,
        string $sortCriteria
    ): array {
        // Get children based on object ID type
        $children = $this->getChildren($objectId);

        // Sort if needed
        if (!empty($sortCriteria)) {
            $children = $this->sortItems($children, $sortCriteria);
        }

        $this->totalMatches = count($children);

        // Apply pagination
        $resultItems = array_slice($children, $startingIndex, $requestedCount > 0 ? $requestedCount : null);

        $didl = $this->generateDidl($resultItems);

        return [
            'Result' => $didl,
            'NumberReturned' => count($resultItems),
            'TotalMatches' => $this->totalMatches,
            'UpdateID' => $this->systemUpdateId,
        ];
    }

    /**
     * Get library containers for root.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getLibraryContainers(): array
    {
        // Use LibraryBridge if available for real data
        if ($this->libraryBridge !== null) {
            return $this->libraryBridge->getRootContainers();
        }

        // Fallback to predefined library containers
        return [
            [
                'id' => 'library-video',
                'parent_id' => '0',
                'name' => 'Video',
                'type' => 'container',
                'class' => 'object.container',
                'child_count' => 0,
            ],
            [
                'id' => 'library-audio',
                'parent_id' => '0',
                'name' => 'Audio',
                'type' => 'container',
                'class' => 'object.container',
                'child_count' => 0,
            ],
            [
                'id' => 'library-images',
                'parent_id' => '0',
                'name' => 'Images',
                'type' => 'container',
                'class' => 'object.container',
                'child_count' => 0,
            ],
        ];
    }

    /**
     * Get children of a container.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getChildren(string $objectId): array
    {
        // Use LibraryBridge if available for real data
        if ($this->libraryBridge !== null) {
            return $this->libraryBridge->getContainerChildren($objectId);
        }

        // Handle library containers with fallback
        if (strpos($objectId, 'library-') === 0) {
            $libraryType = substr($objectId, 8); // Remove 'library-' prefix
            return $this->getLibraryItems($libraryType);
        }

        // Handle item-based containers
        $item = $this->resolveObjectId($objectId);
        if ($item !== null && ($item['type'] ?? '') === 'container') {
            $parentId = $item['id'] ?? '';
            return $this->itemRepository->findByParent(is_string($parentId) ? $parentId : '');
        }

        return [];
    }

    /**
     * Get items from a specific library.
     *
     * @return array<int, array<string, mixed>>
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

        // This would typically come from a library manager
        // For now, return empty array as libraries need to be set up
        return [];
    }

    /**
     * Resolve object ID to an item.
     *
     * @return array<string, mixed>|null
     */
    private function resolveObjectId(string $objectId): ?array
    {
        // Check cache first
        if (isset($this->objectCache[$objectId])) {
            return $this->objectCache[$objectId];
        }

        // Use LibraryBridge if available
        if ($this->libraryBridge !== null) {
            $item = $this->libraryBridge->getMediaObject($objectId);
            if ($item !== null) {
                $this->objectCache[$objectId] = $item;
            }
            return $item;
        }

        // Handle special container IDs
        if (strpos($objectId, 'library-') === 0) {
            return [
                'id' => $objectId,
                'parent_id' => '0',
                'name' => ucfirst(substr($objectId, 8)),
                'type' => 'container',
                'class' => 'object.container',
            ];
        }

        // Try to find in database
        $item = $this->itemRepository->findById($objectId);

        if ($item !== null) {
            $this->objectCache[$objectId] = $item;
        }

        return $item;
    }

    /**
     * Parse UPnP Search criteria.
     * Simplified implementation for common cases.
     *
     * @return array{property: string, op: string, value: mixed}|null
     */
    private function parseSearchCriteria(string $criteria): ?array
    {
        // Handle property comparisons
        // Format: property op "value" or property op value

        // Check for common patterns
        if (preg_match('/^(dc:title|dc:creator|upnp:artist|upnp:album)\s+contains\s+["\'](.+)["\']$/i', $criteria, $matches)) {
            return [
                'property' => strtolower($matches[1]),
                'op' => 'contains',
                'value' => $matches[2],
            ];
        }

        if (preg_match('/^(dc:title|dc:creator|upnp:artist|upnp:album)\s+exists\s+["\'](.+)["\']$/i', $criteria, $matches)) {
            return [
                'property' => strtolower($matches[1]),
                'op' => 'exists',
                'value' => $matches[2] === 'true',
            ];
        }

        // Unsupported or complex criteria
        return null;
    }

    /**
     * Perform search based on parsed criteria.
     *
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    private function performSearch(string $containerId, array $criteria): array
    {
        $items = $this->getChildren($containerId);
        $filtered = [];

        foreach ($items as $item) {
            if ($this->itemMatchesCriteria($item, $criteria)) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    /**
     * Check if an item matches search criteria.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $criteria
     */
    private function itemMatchesCriteria(array $item, array $criteria): bool
    {
        $property = is_string($criteria['property'] ?? null) ? $criteria['property'] : '';
        $op = is_string($criteria['op'] ?? null) ? $criteria['op'] : '';
        $value = $criteria['value'] ?? '';

        $rawValue = match ($property) {
            'dc:title' => $item['name'] ?? '',
            'dc:creator' => $item['creator'] ?? '',
            'upnp:artist' => $item['artist'] ?? '',
            'upnp:album' => $item['album'] ?? '',
            default => '',
        };
        $itemValue = is_scalar($rawValue) ? (string) $rawValue : '';
        $valueStr = is_scalar($value) ? (string) $value : '';

        return match ($op) {
            'contains' => stripos($itemValue, $valueStr) !== false,
            'exists' => !empty($itemValue) === (bool) $value,
            '=' => strcasecmp($itemValue, $valueStr) === 0,
            '!=' => strcasecmp($itemValue, $valueStr) !== 0,
            default => true,
        };
    }

    /**
     * Sort items by sort criteria.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function sortItems(array $items, string $sortCriteria): array
    {
        $sortField = match (trim($sortCriteria, '+- ')) {
            'dc:title', 'title' => 'name',
            'dc:date', 'date' => 'created_at',
            'dc:creator', 'creator' => 'creator',
            default => 'name',
        };

        $descending = strpos($sortCriteria, '-') === 0;

        usort($items, function (array $a, array $b) use ($sortField, $descending): int {
            $aRaw = $a[$sortField] ?? '';
            $bRaw = $b[$sortField] ?? '';
            $aVal = is_scalar($aRaw) ? $aRaw : '';
            $bVal = is_scalar($bRaw) ? $bRaw : '';

            $result = is_numeric($aVal) && is_numeric($bVal)
                ? $aVal <=> $bVal
                : strcasecmp((string) $aVal, (string) $bVal);

            return $descending ? -$result : $result;
        });

        return $items;
    }

    /**
     * Generate DIDL-Lite XML for items.
     *
     * @param array<int, array<string, mixed>> $items Items to include in DIDL
     * @param bool $includeMeta Whether to include full metadata
     * @return string DIDL-Lite XML string
     */
    public function generateDidl(array $items, bool $includeMeta = false): string
    {
        $didl = '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" ' .
                'xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
                'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">';

        foreach ($items as $item) {
            $didl .= $this->itemToDidl($item, $includeMeta);
        }

        $didl .= '</DIDL-Lite>';

        return $didl;
    }

    /**
     * Convert a single item to DIDL-Lite XML.
     *
     * @param array<string, mixed> $item
     */
    private function itemToDidl(array $item, bool $includeMeta): string
    {
        $idRaw = $item['id'] ?? $this->generateObjectId();
        $id = htmlspecialchars(is_scalar($idRaw) ? (string) $idRaw : $this->generateObjectId());
        $parentIdRaw = $item['parent_id'] ?? '0';
        $parentId = htmlspecialchars(is_scalar($parentIdRaw) ? (string) $parentIdRaw : '0');
        $nameRaw = $item['name'] ?? 'Unknown';
        $name = htmlspecialchars(is_scalar($nameRaw) ? (string) $nameRaw : 'Unknown');
        $type = is_string($item['type'] ?? null) ? $item['type'] : 'item';

        // Determine UPnP class
        $upnpClass = $this->getUpnpClass($item);

        $didl = sprintf(
            '<item id="%s" parentID="%s" restricted="true">',
            $id,
            $parentId
        );

        $didl .= sprintf('<dc:title>%s</dc:title>', $name);
        $didl .= sprintf('<upnp:class>%s</upnp:class>', $upnpClass);

        if ($type === 'container') {
            $didl = str_replace('<item ', '<container ', $didl);
            $childCountRaw = $item['child_count'] ?? 0;
            $childCount = is_numeric($childCountRaw) ? (int) $childCountRaw : 0;
            $didl .= sprintf('<upnp:childCount>%d</upnp:childCount>', $childCount);
        }

        // Add common metadata
        if ($includeMeta || $type === 'item') {
            $didl .= $this->addItemMetadata($item);
        }

        if ($type === 'container') {
            $didl .= '</container>';
        } else {
            $didl .= '</item>';
        }

        return $didl;
    }

    /**
     * Get the UPnP class for an item.
     *
     * @param array<string, mixed> $item
     */
    private function getUpnpClass(array $item): string
    {
        $type = is_string($item['type'] ?? null) ? $item['type'] : 'unknown';
        $mediaTypeRaw = $item['media_type'] ?? '';
        $mediaType = is_string($mediaTypeRaw) ? $mediaTypeRaw : '';
        $classRaw = $item['class'] ?? '';
        $classStr = is_string($classRaw) ? $classRaw : '';

        return match ($type) {
            'container', 'folder' => 'object.container',
            'movie', 'video' => 'object.item.videoItem.' . ($classStr !== '' ? $classStr : 'movie'),
            'audio', 'music' => 'object.item.audioItem.' . ($classStr !== '' ? $classStr : 'musicTrack'),
            'image', 'photo' => 'object.item.imageItem.photo',
            'series', 'tvshow' => 'object.item.videoItem.videoBroadcast',
            default => 'object.item.' . ($mediaType !== '' ? $mediaType : 'unknown'),
        };
    }

    /**
     * Add item-specific metadata to DIDL.
     *
     * @param array<string, mixed> $item
     */
    private function addItemMetadata(array $item): string
    {
        $metadata = '';

        // Artist
        if (!empty($item['artist']) && is_scalar($item['artist'])) {
            $metadata .= sprintf('<upnp:artist>%s</upnp:artist>', htmlspecialchars((string) $item['artist']));
        }

        // Album
        if (!empty($item['album']) && is_scalar($item['album'])) {
            $metadata .= sprintf('<upnp:album>%s</upnp:album>', htmlspecialchars((string) $item['album']));
        }

        // Genre
        if (!empty($item['genre']) && is_scalar($item['genre'])) {
            $metadata .= sprintf('<upnp:genre>%s</upnp:genre>', htmlspecialchars((string) $item['genre']));
        }

        // Duration (in seconds, but DIDL uses mm:ss or hh:mm:ss)
        if (!empty($item['duration']) && is_numeric($item['duration'])) {
            $duration = $this->formatDuration((int) $item['duration']);
            $metadata .= sprintf('<upnp:duration>%s</upnp:duration>', $duration);
        }

        // Date
        if (!empty($item['date']) && is_scalar($item['date'])) {
            $metadata .= sprintf('<dc:date>%s</dc:date>', htmlspecialchars((string) $item['date']));
        }

        // Resolution
        if (
            !empty($item['width']) && !empty($item['height'])
            && is_numeric($item['width']) && is_numeric($item['height'])
        ) {
            $metadata .= sprintf(
                '<upnp:resolution>%dx%d</upnp:resolution>',
                (int) $item['width'],
                (int) $item['height']
            );
        }

        // Thumbnail/icon
        if (!empty($item['thumbnail']) && is_scalar($item['thumbnail'])) {
            $metadata .= sprintf(
                '<upnp:albumArtURI xmlns:dlna="urn:schemas-dlna-org:metadata-1-0">%s</upnp:albumArtURI>',
                htmlspecialchars((string) $item['thumbnail'])
            );
        }

        // Resource URL for streaming (HLS URL via LibraryBridge)
        if ($this->libraryBridge !== null && !empty($item['id'])) {
            $streamUrl = $this->libraryBridge->getStreamUrl($item);
            $protocolInfo = $this->getProtocolInfo($item);
            $metadata .= sprintf(
                '<upnp:res protocolInfo="%s">%s</upnp:res>',
                htmlspecialchars($protocolInfo),
                htmlspecialchars($streamUrl)
            );
        } elseif (!empty($item['path']) && is_scalar($item['path'])) {
            // Fallback to file path if no LibraryBridge
            $protocolInfo = $this->getProtocolInfo($item);
            $metadata .= sprintf(
                '<upnp:res protocolInfo="%s">%s</upnp:res>',
                htmlspecialchars($protocolInfo),
                htmlspecialchars((string) $item['path'])
            );
        }

        return $metadata;
    }

    /**
     * Format duration in seconds to DIDL duration format (HH:MM:SS).
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Get DLNA protocol info for an item.
     *
     * @param array<string, mixed> $item
     */
    private function getProtocolInfo(array $item): string
    {
        $mimeTypeRaw = $item['mime_type'] ?? $this->getMimeType($item);
        $mimeType = is_string($mimeTypeRaw) ? $mimeTypeRaw : $this->getMimeType($item);
        $type = is_string($item['type'] ?? null) ? $item['type'] : 'video';

        $dlnaProfile = match ($type) {
            'video', 'movie' => 'DLNA.ORG_PN=AVC_MP4_MP_HD',
            'audio', 'music' => 'DLNA.ORG_PN=AAC_ADTS',
            'image', 'photo' => 'DLNA.ORG_PN=JPEG_LRG',
            default => '',
        };

        return sprintf(
            'http-get:*:%s:%s',
            $mimeType,
            $dlnaProfile !== '' ? $dlnaProfile : '*'
        );
    }

    /**
     * Get MIME type based on item type.
     *
     * @param array<string, mixed> $item
     */
    private function getMimeType(array $item): string
    {
        $pathRaw = $item['path'] ?? '';
        $path = is_string($pathRaw) ? $pathRaw : '';
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (!is_string($extension)) {
            $extension = '';
        }

        return match (strtolower($extension)) {
            'mp4', 'm4v' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    /**
     * Generate a unique object ID.
     */
    private function generateObjectId(): string
    {
        return sprintf(
            'obj-%04x%04x-%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Create an error result.
     *
     * @return array<string, mixed>
     */
    private function createErrorResult(int $code, string $description): array
    {
        $this->logger->warning('ContentDirectory error', [
            'code' => $code,
            'description' => $description,
        ]);

        return [
            'Result' => '',
            'NumberReturned' => 0,
            'TotalMatches' => 0,
            'UpdateID' => $this->systemUpdateId,
            'Error' => ['code' => $code, 'description' => $description],
        ];
    }

    /**
     * Get the system update ID.
     */
    public function getSystemUpdateId(): int
    {
        return $this->systemUpdateId;
    }

    /**
     * Increment the system update ID (call when content changes).
     */
    public function incrementSystemUpdateId(): void
    {
        $this->systemUpdateId++;
    }

    /**
     * Get the SCPD (Service Description) for ContentDirectory service.
     */
    public function getScpdXml(): string
    {
        return '<?xml version="1.0"?>
<scpd xmlns="urn:schemas-upnp-org:service-1-0">
    <specVersion>
        <major>1</major>
        <minor>0</minor>
    </specVersion>
    <actionList>
        <action>
            <name>Browse</name>
            <argumentList>
                <argument>
                    <name>ObjectID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_ObjectID</relatedStateVariable>
                </argument>
                <argument>
                    <name>BrowseFlag</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_BrowseFlag</relatedStateVariable>
                </argument>
                <argument>
                    <name>Filter</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_Filter</relatedStateVariable>
                </argument>
                <argument>
                    <name>StartingIndex</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_Index</relatedStateVariable>
                </argument>
                <argument>
                    <name>RequestedCount</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_Count</relatedStateVariable>
                </argument>
                <argument>
                    <name>SortCriteria</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_SortCriteria</relatedStateVariable>
                </argument>
                <argument>
                    <name>Result</name>
                    <direction>out</direction>
                    <relatedStateVariable>A_ARG_TYPE_Result</relatedStateVariable>
                </argument>
                <argument>
                    <name>NumberReturned</name>
                    <direction>out</direction>
                    <relatedStateVariable>A_ARG_TYPE_Count</relatedStateVariable>
                </argument>
                <argument>
                    <name>TotalMatches</name>
                    <direction>out</direction>
                    <relatedStateVariable>A_ARG_TYPE_Count</relatedStateVariable>
                </argument>
                <argument>
                    <name>UpdateID</name>
                    <direction>out</direction>
                    <relatedStateVariable>A_ARG_TYPE_UpdateID</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>Search</name>
            <argumentList>
                <argument>
                    <name>ContainerID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_ObjectID</relatedStateVariable>
                </argument>
                <argument>
                    <name>SearchCriteria</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_SearchCriteria</relatedStateVariable>
                </argument>
                <argument>
                    <name>Filter</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_Filter</relatedStateVariable>
                </argument>
                <argument>
                    <name>StartingIndex</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_Index</relatedStateVariable>
                </argument>
                <argument>
                    <name>RequestedCount</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_Count</relatedStateVariable>
                </argument>
                <argument>
                    <name>SortCriteria</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_SortCriteria</relatedStateVariable>
                </argument>
                <argument>
                    <name>Result</name>
                    <direction>out</direction>
                    <relatedStateVariable>A_ARG_TYPE_Result</relatedStateVariable>
                </argument>
                <argument>
                    <name>NumberReturned</name>
                    <direction>out</direction>
                    <relatedStateVariable>A_ARG_TYPE_Count</relatedStateVariable>
                </argument>
                <argument>
                    <name>TotalMatches</name>
                    <direction>out</direction>
                    <relatedStateVariable>A_ARG_TYPE_Count</relatedStateVariable>
                </argument>
                <argument>
                    <name>UpdateID</name>
                    <direction>out</direction>
                    <relatedStateVariable>A_ARG_TYPE_UpdateID</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetSearchCapabilities</name>
            <argumentList>
                <argument>
                    <name>SearchCaps</name>
                    <direction>out</direction>
                    <relatedStateVariable>SearchCaps</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetSortCapabilities</name>
            <argumentList>
                <argument>
                    <name>SortCaps</name>
                    <direction>out</direction>
                    <relatedStateVariable>SortCaps</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetSystemUpdateID</name>
            <argumentList>
                <argument>
                    <name>Id</name>
                    <direction>out</direction>
                    <relatedStateVariable>SystemUpdateID</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
    </actionList>
    <serviceStateTable>
        <stateVariable sendEvents="yes">
            <name>TransferIDs</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_ObjectID</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_Result</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_BrowseFlag</name>
            <dataType>string</dataType>
            <allowedValueList>
                <allowedValue>BrowseMetadata</allowedValue>
                <allowedValue>BrowseDirectChildren</allowedValue>
            </allowedValueList>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_Filter</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_SortCriteria</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_Index</name>
            <dataType>ui4</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_Count</name>
            <dataType>ui4</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_UpdateID</name>
            <dataType>ui4</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_SearchCriteria</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>SearchCaps</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>SortCaps</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>SystemUpdateID</name>
            <dataType>ui4</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>ContainerUpdateIDs</name>
            <dataType>string</dataType>
        </stateVariable>
    </serviceStateTable>
</scpd>';
    }
}
