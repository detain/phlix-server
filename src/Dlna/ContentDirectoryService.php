<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

/**
 * ContentDirectoryService provides the UPnP ContentDirectory browse interface.
 *
 * Implements the Browse action for DLNA/UPnP Media Servers, returning
 * DIDL-Lite XML for media items and containers based on object ID.
 *
 * Object ID hierarchy:
 * - "0"      = root (containers: music library)
 * - "music"  = music container
 * - "music/artists"     = artists container
 * - "music/albums/{id}"  = specific album
 * - "music/tracks/{id}"  = specific track
 *
 * @since 0.12.0
 * @see UPnP ContentDirectory:1 Service Specification
 */
class ContentDirectoryService
{
    /** Root object ID */
    public const OBJECT_ID_ROOT = '0';

    /** Music container object ID */
    public const OBJECT_ID_MUSIC = 'music';

    /** Artists container object ID */
    public const OBJECT_ID_ARTISTS = 'music/artists';

    /** Albums container object ID prefix */
    public const OBJECT_ID_ALBUMS_PREFIX = 'music/albums/';

    /** Tracks container object ID prefix */
    public const OBJECT_ID_TRACKS_PREFIX = 'music/tracks/';

    /**
     * Handle Browse request.
     *
     * @param string $objectId The object ID to browse
     * @param string $browseFlag Browse flag (BrowseDirectChildren or BrowseMetadata)
     * @param int $startingIndex Starting index for results
     * @param int $requestedCount Number of results to return (0 = all)
     * @return array{Result: string, NumberReturned: int, TotalMatches: int} Browse result
     *
     * @since 0.12.0
     */
    public function browse(
        string $objectId,
        string $browseFlag,
        int $startingIndex,
        int $requestedCount
    ): array {
        // Normalize browse flag
        $browseMetadata = ($browseFlag === 'BrowseMetadata');

        // Handle root object
        if ($objectId === self::OBJECT_ID_ROOT || $objectId === '') {
            return $this->browseRoot($browseMetadata, $startingIndex, $requestedCount);
        }

        // Handle music container
        if ($objectId === self::OBJECT_ID_MUSIC) {
            return $browseMetadata
                ? $this->browseMetadata($objectId, $this->getMusicContainer())
                : $this->browseChildren($objectId, $startingIndex, $requestedCount, [
                    $this->getArtistsContainer(),
                    $this->getAlbumsContainer(),
                    $this->getTracksContainer(),
                ]);
        }

        // Handle artists container
        if ($objectId === self::OBJECT_ID_ARTISTS) {
            return $browseMetadata
                ? $this->browseMetadata($objectId, $this->getArtistsContainer())
                : $this->browseChildren($objectId, $startingIndex, $requestedCount, []);
        }

        // Handle albums container
        if ($objectId === 'music/albums') {
            return $browseMetadata
                ? $this->browseMetadata($objectId, $this->getAlbumsContainer())
                : $this->browseChildren($objectId, $startingIndex, $requestedCount, []);
        }

        // Handle tracks container
        if ($objectId === 'music/tracks') {
            return $browseMetadata
                ? $this->browseMetadata($objectId, $this->getTracksContainer())
                : $this->browseChildren($objectId, $startingIndex, $requestedCount, []);
        }

        // Handle specific album
        if (str_starts_with($objectId, self::OBJECT_ID_ALBUMS_PREFIX)) {
            $albumId = substr($objectId, strlen(self::OBJECT_ID_ALBUMS_PREFIX));
            return $browseMetadata
                ? $this->browseMetadata($objectId, $this->getAlbum($albumId))
                : $this->browseChildren($objectId, $startingIndex, $requestedCount, []);
        }

        // Handle specific track
        if (str_starts_with($objectId, self::OBJECT_ID_TRACKS_PREFIX)) {
            $trackId = substr($objectId, strlen(self::OBJECT_ID_TRACKS_PREFIX));
            return $browseMetadata
                ? $this->browseMetadata($objectId, $this->getTrack($trackId))
                : $this->browseChildren($objectId, $startingIndex, $requestedCount, []);
        }

        // Unknown object ID
        return [
            'Result' => '',
            'NumberReturned' => 0,
            'TotalMatches' => 0,
        ];
    }

    /**
     * Browse the root container.
     *
     * @param bool $browseMetadata Whether to return metadata only
     * @param int $startingIndex Starting index
     * @param int $requestedCount Requested count
     * @return array{Result: string, NumberReturned: int, TotalMatches: int}
     */
    private function browseRoot(bool $browseMetadata, int $startingIndex, int $requestedCount): array
    {
        $items = [
            $this->getMusicContainer(),
        ];

        $total = count($items);
        $resultItems = $this->sliceItems($items, $startingIndex, $requestedCount);

        return [
            'Result' => $this->generateDidl($resultItems),
            'NumberReturned' => count($resultItems),
            'TotalMatches' => $total,
        ];
    }

    /**
     * Browse children of a container.
     *
     * @param string $objectId The container object ID
     * @param int $startingIndex Starting index
     * @param int $requestedCount Requested count
     * @param array<array<string, mixed>> $children Child items
     * @return array{Result: string, NumberReturned: int, TotalMatches: int}
     */
    private function browseChildren(string $objectId, int $startingIndex, int $requestedCount, array $children): array
    {
        $total = count($children);
        $resultItems = $this->sliceItems($children, $startingIndex, $requestedCount);

        return [
            'Result' => $this->generateDidl($resultItems),
            'NumberReturned' => count($resultItems),
            'TotalMatches' => $total,
        ];
    }

    /**
     * Browse metadata for a specific object.
     *
     * @param string $objectId The object ID
     * @param array<string, mixed> $item The item metadata
     * @return array{Result: string, NumberReturned: int, TotalMatches: int}
     */
    private function browseMetadata(string $objectId, array $item): array
    {
        if (empty($item)) {
            return [
                'Result' => '',
                'NumberReturned' => 0,
                'TotalMatches' => 0,
            ];
        }

        return [
            'Result' => $this->generateDidl([$item]),
            'NumberReturned' => 1,
            'TotalMatches' => 1,
        ];
    }

    /**
     * Get the music container definition.
     *
     * @return array<string, mixed> Container item
     */
    private function getMusicContainer(): array
    {
        return [
            'id' => self::OBJECT_ID_MUSIC,
            'parent_id' => self::OBJECT_ID_ROOT,
            'title' => 'Music',
            'type' => 'container',
            'upnp_class' => 'object.container',
            'child_count' => 3,
        ];
    }

    /**
     * Get the artists container definition.
     *
     * @return array<string, mixed> Container item
     */
    private function getArtistsContainer(): array
    {
        return [
            'id' => self::OBJECT_ID_ARTISTS,
            'parent_id' => self::OBJECT_ID_MUSIC,
            'title' => 'Artists',
            'type' => 'container',
            'upnp_class' => 'object.container',
            'child_count' => 0,
        ];
    }

    /**
     * Get the albums container definition.
     *
     * @return array<string, mixed> Container item
     */
    private function getAlbumsContainer(): array
    {
        return [
            'id' => 'music/albums',
            'parent_id' => self::OBJECT_ID_MUSIC,
            'title' => 'Albums',
            'type' => 'container',
            'upnp_class' => 'object.container',
            'child_count' => 0,
        ];
    }

    /**
     * Get the tracks container definition.
     *
     * @return array<string, mixed> Container item
     */
    private function getTracksContainer(): array
    {
        return [
            'id' => 'music/tracks',
            'parent_id' => self::OBJECT_ID_MUSIC,
            'title' => 'Tracks',
            'type' => 'container',
            'upnp_class' => 'object.container',
            'child_count' => 0,
        ];
    }

    /**
     * Get album by ID.
     *
     * @param string $albumId Album ID
     * @return array<string, mixed> Album item (empty if not found)
     */
    private function getAlbum(string $albumId): array
    {
        // Placeholder - in a real implementation this would query the library
        if (empty($albumId)) {
            return [];
        }

        return [
            'id' => self::OBJECT_ID_ALBUMS_PREFIX . $albumId,
            'parent_id' => 'music/albums',
            'title' => 'Album ' . $albumId,
            'type' => 'container',
            'upnp_class' => 'object.container.album',
            'child_count' => 0,
            'creator' => 'Unknown Artist',
        ];
    }

    /**
     * Get track by ID.
     *
     * @param string $trackId Track ID
     * @return array<string, mixed> Track item (empty if not found)
     */
    private function getTrack(string $trackId): array
    {
        // Placeholder - in a real implementation this would query the library
        if (empty($trackId)) {
            return [];
        }

        return [
            'id' => self::OBJECT_ID_TRACKS_PREFIX . $trackId,
            'parent_id' => 'music/tracks',
            'title' => 'Track ' . $trackId,
            'type' => 'item',
            'upnp_class' => 'object.item.audioItem.musicTrack',
            'creator' => 'Unknown Artist',
            'album' => 'Unknown Album',
            'albumArtURI' => null,
            'res' => null,
        ];
    }

    /**
     * Slice items array with pagination.
     *
     * @param array<array<string, mixed>> $items Items to slice
     * @param int $startingIndex Starting index
     * @param int $requestedCount Requested count (0 = all)
     * @return array<array<string, mixed>> Sliced items
     */
    private function sliceItems(array $items, int $startingIndex, int $requestedCount): array
    {
        if ($startingIndex >= count($items)) {
            return [];
        }

        if ($requestedCount === 0) {
            return array_slice($items, $startingIndex);
        }

        return array_slice($items, $startingIndex, $requestedCount);
    }

    /**
     * Generate DIDL-Lite XML for items.
     *
     * @param array<array<string, mixed>> $items Items to include in DIDL
     * @return string DIDL-Lite XML string
     */
    private function generateDidl(array $items): string
    {
        $didl = '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" ' .
                'xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
                'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">';

        foreach ($items as $item) {
            $didl .= $this->itemToDidl($item);
        }

        $didl .= '</DIDL-Lite>';

        return $didl;
    }

    /**
     * Convert item to DIDL-Lite XML element.
     *
     * @param array<string, mixed> $item Item data
     * @return string DIDL-Lite XML element
     */
    private function itemToDidl(array $item): string
    {
        $idRaw = $item['id'] ?? null;
        $id = htmlspecialchars(is_string($idRaw) ? $idRaw : '', ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $parentIdRaw = $item['parent_id'] ?? null;
        $parentId = htmlspecialchars(is_string($parentIdRaw) ? $parentIdRaw : '0', ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $titleRaw = $item['title'] ?? null;
        $title = htmlspecialchars(is_string($titleRaw) ? $titleRaw : 'Unknown', ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $upnpClassRaw = $item['upnp_class'] ?? null;
        $upnpClass = htmlspecialchars(is_string($upnpClassRaw) ? $upnpClassRaw : 'object.item', ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $type = is_string($item['type'] ?? null) ? $item['type'] : 'item';

        $creatorRaw = $item['creator'] ?? null;
        $creator = htmlspecialchars(is_string($creatorRaw) ? $creatorRaw : '', ENT_XML1 | ENT_QUOTES, 'UTF-8');

        if ($type === 'container') {
            $childCountRaw = $item['child_count'] ?? null;
            $childCount = is_numeric($childCountRaw) ? (int) $childCountRaw : 0;
            return sprintf(
                '<container id="%s" parentID="%s" restricted="true">' .
                '<dc:title>%s</dc:title>' .
                '<upnp:class>%s</upnp:class>' .
                '<upnp:childCount>%d</upnp:childCount>' .
                '<dc:creator>%s</dc:creator>' .
                '</container>',
                $id,
                $parentId,
                $title,
                $upnpClass,
                $childCount,
                $creator
            );
        }

        // Item element
        $xml = sprintf(
            '<item id="%s" parentID="%s" restricted="true">',
            $id,
            $parentId
        );

        $xml .= sprintf('<dc:title>%s</dc:title>', $title);
        $xml .= sprintf('<upnp:class>%s</upnp:class>', $upnpClass);

        if ($creator !== '') {
            $xml .= sprintf('<dc:creator>%s</dc:creator>', $creator);
        }

        // Album
        if (!empty($item['album']) && is_string($item['album'])) {
            $album = htmlspecialchars($item['album'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= sprintf('<upnp:album>%s</upnp:album>', $album);
        }

        // Album art URI
        if (!empty($item['albumArtURI']) && is_string($item['albumArtURI'])) {
            $albumArtUri = htmlspecialchars($item['albumArtURI'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= sprintf('<upnp:albumArtURI xmlns:dlna="urn:schemas-dlna-org:metadata-1-0">%s</upnp:albumArtURI>', $albumArtUri);
        }

        // Resource
        if (!empty($item['res']) && is_string($item['res'])) {
            $res = htmlspecialchars($item['res'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $protocolInfo = is_string($item['protocolInfo'] ?? null) ? $item['protocolInfo'] : 'http-get:*:audio/mpeg:*';
            $xml .= sprintf('<upnp:res protocolInfo="%s">%s</upnp:res>', $protocolInfo, $res);
        }

        $xml .= '</item>';

        return $xml;
    }
}
