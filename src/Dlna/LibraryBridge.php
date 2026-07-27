<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Streaming\HlsStreamer;

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
     * The `music_*` read path (S97) — the ONE authoritative music hierarchy.
     *
     * Null degrades the Audio category to artists-only with no drill-down, which
     * is still correct (an empty container), never wrong (a flood of unbrowsable
     * album/track rows).
     *
     * @var MusicLibraryService|null
     */
    private ?MusicLibraryService $musicLibrary;

    /**
     * @param ItemRepository $itemRepository Repository for accessing media items
     * @param HlsStreamer $hlsStreamer Service for generating HLS stream URLs
     * @param StructuredLogger|null $logger Optional logger for diagnostics
     * @param MusicLibraryService|null $musicLibrary Music hierarchy reader (S97)
     *
     * @since 0.12.0
     */
    public function __construct(
        ItemRepository $itemRepository,
        HlsStreamer $hlsStreamer,
        ?StructuredLogger $logger = null,
        ?MusicLibraryService $musicLibrary = null
    ) {
        $this->itemRepository = $itemRepository;
        $this->hlsStreamer = $hlsStreamer;
        $this->logger = $logger;
        $this->musicLibrary = $musicLibrary;
    }

    /**
     * Which `media_items.type` ENUM members belong to each browse category.
     *
     * ONE definition, used for both the child COUNT and the child LISTING —
     * they previously had separate `match` blocks that disagreed with each
     * other and with the database.
     *
     * Every value here is a real member of the migration-034 ENUM:
     * `movie, series, season, episode, track, music, album, artist, video,
     * audio, book, photo, audiobook`. Note **`photo`, not `image`** — the
     * long-standing landmine in this codebase, and the reason the Images
     * container counted zero on every install.
     *
     * `season`/`episode` are deliberately excluded from the top level: they
     * hang off their series and would otherwise flood the root (production has
     * 26 389 episodes against 434 series).
     *
     * ⚠ **`album` / `track` / `music` are excluded for the SAME reason, and S97
     * is why the exclusion had to be a type narrowing rather than a parent
     * filter.** Audio used to list `['music','audio','album','artist','track',
     * 'audiobook']`, which put artists, their albums AND their individual tracks
     * side by side as SIBLINGS of the Audio root, with no way to descend from one
     * to the next. Two concrete consequences on production's music library
     * (4,656 artists / 10,966 albums / 61,105 tracks, measured read-only
     * 2026-07-27):
     *
     *  - {@see getLibraryChildCount()} sums {@see ItemRepository::countAllByType()}
     *    over all six types and advertised **76,727+** children, while
     *    {@see getLibraryItems()} returned {@see ItemRepository::getAllByType()}'s
     *    default page — **at most 100 per type**. The `<upnp:childCount>` a
     *    renderer was promised and the list it received disagreed by three orders
     *    of magnitude. (S147 closed the disagreement itself — the listing is now
     *    paged, see {@see getLibraryItems()} — but the flattening below is a
     *    separate wrong, and this narrowing is still what fixes it.)
     *  - The 100 albums and 100 tracks it did return were an arbitrary
     *    title-ordered slice with no relation to the 100 artists beside them.
     *
     * That could not be fixed by nesting them: {@see getLibraryItems()} calls
     * {@see ItemRepository::getAllByType()}, which has **no parent filter at all**,
     * and S97 settled that `media_items.parent_id` is never written for music —
     * the hierarchy lives only in the `music_*` tables. So the root lists only the
     * true top level (artists, plus the standalone audio types) and the levels
     * below it are produced by {@see getMusicChildren()} from `music_*`.
     * `music` is dropped outright: it is a container type with zero rows.
     *
     * @var array<string, list<string>>
     */
    private const CATEGORY_TYPES = [
        'video'  => ['movie', 'series', 'video'],
        'audio'  => ['artist', 'audio', 'audiobook'],
        'photos' => ['photo'],
        'books'  => ['book'],
    ];

    /**
     * `media_items.type` members whose children come from `music_*`, not from
     * `media_items.parent_id` (S97).
     *
     * @var list<string>
     */
    private const MUSIC_CONTAINER_TYPES = ['artist', 'album'];

    /**
     * How many `media_items` ids are resolved per `findByIds()` statement.
     *
     * {@see getLibraryItems()} can hand {@see ItemRepository::findByIds()} up to
     * {@see MusicLibraryService::MAX_EMBEDDED_ROWS} artist ids, and that method
     * builds one `IN (…)` with a placeholder per id — a 2,000-placeholder
     * statement whose whole result set is buffered inside a resident Workerman
     * worker. Chunking bounds the single largest statement the DLNA path issues
     * without changing what is returned: `findByIds()` re-orders its rows to
     * match the ids it was given, so concatenating the chunks in id order
     * reproduces the un-chunked list exactly.
     *
     * 500 is the batch ceiling already used elsewhere in this codebase for the
     * same reason ({@see \Phlix\Media\Library\DuplicateFinder::DEFAULT_BATCH_SIZE}).
     */
    private const FIND_BY_IDS_CHUNK = 500;

    /**
     * The most CDS objects one `Browse` response may carry.
     *
     * This is a PAGE size, not a ceiling on the container. Every browsable
     * container in this bridge now answers `StartingIndex` from SQL
     * ({@see getLibraryItems()}, {@see getMusicChildren()},
     * {@see ItemRepository::findByParentPage()}), and the count it advertises is
     * the true unbounded total, so a renderer reaches row 26,388 of the Video
     * root by asking for it — which is precisely what it could NOT do while the
     * bound lived in the listing.
     *
     * UPnP says `RequestedCount = 0` means "as many as you can". This is that
     * answer: `NumberReturned` comes back smaller than `TotalMatches` and a
     * renderer continues from where it left off, which is the ordinary paging
     * contract every CDS client already implements.
     *
     * 2,000 is deliberately {@see MusicLibraryService::MAX_EMBEDDED_ROWS}'s
     * value: `getArtistMediaItemIds()` clamps its own `$limit` to that constant,
     * so a larger page here would be silently truncated by it and the arithmetic
     * in {@see getLibraryItems()} would start skipping rows.
     */
    public const MAX_PAGE_ROWS = MusicLibraryService::MAX_EMBEDDED_ROWS;

    /**
     * Root containers representing the media library categories, with accurate
     * child counts read from the database.
     *
     * Empty categories are omitted.
     *
     * @return array<int, array{id: string, parent_id: string, name: string,
     *           type: string, class: string, child_count: int}>
     *
     * @since 0.12.0
     */
    public function getRootContainers(): array
    {
        $this->logger?->debug('LibraryBridge: Getting root containers');

        $containers = [];
        foreach (
            [
                'video'  => 'Video',
                'audio'  => 'Audio',
                'photos' => 'Photos',
                'books'  => 'Books',
            ] as $category => $label
        ) {
            $count = $this->getLibraryChildCount($category);

            // Hide empty categories. A TV showing four containers of which
            // three are always empty is worse than showing only what exists —
            // and before this, ALL of them were empty regardless of content.
            if ($count === 0) {
                continue;
            }

            $containers[] = [
                'id' => 'library-' . $category,
                'parent_id' => '0',
                'name' => $label,
                'type' => 'container',
                'class' => 'object.container',
                'child_count' => $count,
            ];
        }

        return $containers;
    }

    /**
     * How many rows each `media_items.type` in a category contributes, in the
     * order {@see getLibraryItems()} concatenates them.
     *
     * ONE source for both the advertised `childCount` and the offset arithmetic
     * of the listing. That is the whole mechanism by which "advertised count and
     * deliverable list agree at every StartingIndex" holds: the listing walks
     * this very map to decide which type a global offset lands in, so the two can
     * only disagree if a row is written between the two statements.
     *
     * @param string $libraryType One of {@see CATEGORY_TYPES}'s keys.
     * @return array<string, int> `media_items.type` => row count, insertion-ordered.
     */
    private function categoryTypeCounts(string $libraryType): array
    {
        $types = self::CATEGORY_TYPES[$libraryType] ?? null;
        if ($types === null) {
            return [];
        }

        $counts = [];
        foreach ($types as $type) {
            // The artist count must come from the SAME source as the artist
            // listing (S97), or the advertised childCount over-counts by every
            // `media_items[artist]` row that no `music_artists` row points at —
            // the orphan shape MusicLibraryScanner's adoption path exists to
            // reclaim.
            $counts[$type] = $type === 'artist' && $this->musicLibrary !== null
                ? $this->musicLibrary->getArtistsWithMediaItemCount()
                : $this->itemRepository->countAllByType($type);
        }

        return $counts;
    }

    /**
     * Get child count for a library type.
     *
     * ⚠ **This number is UNBOUNDED, and that is now correct** — read this before
     * re-introducing a clamp. S97 clamped the artist term to
     * {@see MusicLibraryService::MAX_EMBEDDED_ROWS} because the listing stopped
     * dead there: `getLibraryItems()` took no offset and
     * `ContentDirectory::browseChildren()` applied `StartingIndex` in PHP to a
     * list this bridge had already truncated, so on production's 4,656 artists a
     * renderer was promised 4,656, handed 2,000, and could not page to the rest
     * by any navigation. The clamp made the promise honest; it did not make the
     * artists reachable, and it left the far larger lie on the OTHER roots
     * untouched (movies advertised 10,718 against a `LIMIT 100` listing — 107x;
     * episodes 26,389 — 264x; all measured read-only 2026-07-27).
     *
     * S147 removed the bound instead of the honesty: {@see getLibraryItems()}
     * takes an offset and resolves it in SQL, {@see MAX_PAGE_ROWS} is a page
     * size, and the true total is what a renderer needs in order to know there
     * is a second page to ask for. 🛑 Clamping this to the page size again would
     * re-create the S97 defect from the other side — the renderer would stop
     * after one page believing it had seen everything.
     *
     * @param string $libraryType The library type (video, audio, images)
     * @return int Number of items in the library
     *
     * @since 0.12.0
     */
    private function getLibraryChildCount(string $libraryType): int
    {
        return array_sum($this->categoryTypeCounts($libraryType));
    }

    /**
     * Get children of a container (library, folder, playlist).
     *
     * Uses ItemRepository::findByParent() to get actual media items — EXCEPT for
     * music containers, whose children come from `music_*` (S97): an `artist` or
     * `album` `media_items` row never carries children under `parent_id`, so
     * `findByParent()` on one returns an empty container and the browse dead-ends.
     *
     * ⚠ **Every branch resolves `$offset`/`$limit` in SQL.** Nothing above this
     * method may re-slice the result — {@see ContentDirectory::browseChildren()}
     * used to, against a list that was already truncated, which is what made
     * `StartingIndex` useless past the first page (S147).
     *
     * @param string $objectId The object ID of the container
     * @param string|null $objectType The container's `media_items.type`, when the
     *        caller has already resolved the row. {@see ContentDirectory::browse()}
     *        always has — it resolves and caches the object before dispatching to
     *        `browseChildren()` — so passing it here saves this class a second
     *        `findById()` on EVERY drill-down, music or not. NULL means "not
     *        known", and the type is looked up as before.
     * @param int $offset DLNA `StartingIndex` — rows to skip within the container.
     * @param int $limit DLNA `RequestedCount`, clamped to
     *        `[1, self::MAX_PAGE_ROWS]`. Callers map `RequestedCount = 0`
     *        ("as many as you can") onto {@see MAX_PAGE_ROWS}.
     * @return array<int, array<string, mixed>> Array of child items
     *
     * @since 0.12.0
     */
    public function getContainerChildren(
        string $objectId,
        ?string $objectType = null,
        int $offset = 0,
        int $limit = self::MAX_PAGE_ROWS
    ): array {
        $this->logger?->debug('LibraryBridge: Getting container children', [
            'object_id' => $objectId,
            'offset' => $offset,
            'limit' => $limit,
        ]);

        $offset = max(0, $offset);
        $limit = min(max(1, $limit), self::MAX_PAGE_ROWS);

        // Handle library containers
        if (strpos($objectId, 'library-') === 0) {
            $libraryType = substr($objectId, 8);
            return $this->getLibraryItems($libraryType, $offset, $limit);
        }

        // Music drill-down: artist → albums → tracks, read from `music_*`.
        $musicChildren = $this->getMusicChildren($objectId, $objectType, $offset, $limit);
        if ($musicChildren !== null) {
            return $musicChildren;
        }

        // Handle regular parent-based children
        $items = $this->itemRepository->findByParentPage($objectId, $limit, $offset);

        return array_map(fn($item) => $this->itemToCdsObject($item), $items);
    }

    /**
     * How many children a container has IN TOTAL — the `TotalMatches` a `Browse`
     * response must carry.
     *
     * Deliberately NOT `count(getContainerChildren(...))`: that is the size of
     * one page, and a renderer that is told the page size is the total stops
     * after one page. Each branch here shares its listing counterpart's
     * predicate exactly, so the promise and the deliverable agree at every
     * `StartingIndex`.
     *
     * @param string $objectId The object ID of the container.
     * @param string|null $objectType The container's already-resolved
     *        `media_items.type`; NULL triggers a lookup, exactly as in
     *        {@see getContainerChildren()}.
     * @return int Total number of children.
     */
    public function getContainerChildCount(string $objectId, ?string $objectType = null): int
    {
        if (strpos($objectId, 'library-') === 0) {
            return $this->getLibraryChildCount(substr($objectId, 8));
        }

        if ($this->musicLibrary !== null && $objectId !== '') {
            $resolvedType = $this->resolveContainerType($objectId, $objectType);

            if ($resolvedType === 'artist') {
                return $this->musicLibrary->countAlbumMediaItemsForArtist($objectId);
            }

            if ($resolvedType === 'album') {
                return $this->musicLibrary->countTrackMediaItemsForAlbum($objectId);
            }
        }

        return $this->itemRepository->countByParent($objectId);
    }

    /**
     * The container's `media_items.type`, using the caller's value when it has
     * one and only then paying for a `findById()`.
     *
     * @param string $objectId A `media_items` UUID.
     * @param string|null $objectType The caller's already-resolved type, if any.
     */
    private function resolveContainerType(string $objectId, ?string $objectType): string
    {
        if ($objectType !== null) {
            return $objectType;
        }

        $item = $this->itemRepository->findById($objectId);
        $type = $item['type'] ?? null;

        return is_string($type) ? $type : '';
    }

    /**
     * Resolves the children of a music container (`artist` → albums, `album` →
     * tracks) through the authoritative `music_*` tables.
     *
     * @param string $objectId A `media_items` UUID.
     * @param string|null $objectType The caller's already-resolved
     *        `media_items.type` for `$objectId`. When supplied, NO lookup is
     *        issued here at all — which is the point: the previous version ran a
     *        `findById()` on every drill-down, including `series`/`season` ones
     *        that then fell straight through to `findByParent()`.
     * @return array<int, array<string, mixed>>|null CDS objects, or NULL when
     *         `$objectId` is not a music container — the caller then falls through
     *         to the ordinary `parent_id` drill-down. NULL and `[]` are deliberately
     *         distinct: `[]` means "this artist really has no browsable albums".
     * @param int $offset Rows to skip, resolved by SQL `OFFSET` — both listings
     *        end their `ORDER BY` on a PRIMARY KEY, so the order is TOTAL and a
     *        paged walk can neither drop nor repeat an album/track.
     * @param int $limit Page size, already clamped by the caller.
     */
    private function getMusicChildren(
        string $objectId,
        ?string $objectType = null,
        int $offset = 0,
        int $limit = self::MAX_PAGE_ROWS
    ): ?array {
        if ($this->musicLibrary === null || $objectId === '') {
            return null;
        }

        $objectType = $this->resolveContainerType($objectId, $objectType);

        if (!in_array($objectType, self::MUSIC_CONTAINER_TYPES, true)) {
            return null;
        }

        $childIds = $objectType === 'artist'
            ? $this->musicLibrary->getAlbumMediaItemIdsForArtist($objectId, $limit, $offset)
            : $this->musicLibrary->getTrackMediaItemIdsForAlbum($objectId, $limit, $offset);

        return array_map(
            fn(array $child): array => $this->itemToCdsObject($child),
            $this->findByIdsChunked($childIds)
        );
    }

    /**
     * Resolves `media_items` rows for a list of ids in bounded batches.
     *
     * Chunking bounds the statement size, but the batches are still ONE logical
     * page, so the profile tag filter runs ONCE over the concatenated rows
     * instead of once per batch — `ItemRepository::findByIds()` ends in
     * `filterItemsByTags()`, which costs two `profile_tags` queries per call
     * whenever a profile is set, so a 2,000-id page was paying 8 of those
     * queries where 2 do the same work. The rows are unaffected: the filter is
     * a per-item predicate that preserves relative order, so applying it to the
     * whole list drops exactly the rows each per-batch call would have dropped
     * and leaves the rest in the same order.
     *
     * @param list<string> $ids Ids in the order the rows should be returned.
     * @return array<int, array<string, mixed>> Rows in `$ids` order.
     *
     * @see self::FIND_BY_IDS_CHUNK for why this is not one statement.
     */
    private function findByIdsChunked(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        if (count($ids) <= self::FIND_BY_IDS_CHUNK) {
            return $this->itemRepository->findByIds($ids);
        }

        $rows = [];
        foreach (array_chunk($ids, self::FIND_BY_IDS_CHUNK) as $chunk) {
            foreach ($this->itemRepository->findByIds($chunk, false) as $row) {
                $rows[] = $row;
            }
        }

        return $this->itemRepository->filterItemsByTags($rows);
    }

    /**
     * One page of a root category's children.
     *
     * A category is the CONCATENATION of its {@see CATEGORY_TYPES} members in a
     * fixed order (`video` = movies, then series, then loose videos), so
     * `StartingIndex` is a global index into that concatenation, not into any one
     * type. The walk below spends the offset against
     * {@see categoryTypeCounts()} — the same counts the container advertises —
     * skipping whole types until it lands inside one, then hands the remainder to
     * that type's own SQL `OFFSET`. Once a type is entered, `$skip` is zeroed and
     * later types start from their first row, which is what makes a page that
     * straddles a type boundary contiguous.
     *
     * ⚠ **Why this had to be SQL and not `array_slice()`.** Before S147 this
     * method took no offset at all: it returned `getAllByType()`'s default
     * `LIMIT 100` per type, and `ContentDirectory::browseChildren()` then applied
     * `StartingIndex` in PHP to that already-truncated list. On production's
     * 26,389 episodes a renderer was promised 26,389 and could reach 100 of them
     * — asking for `StartingIndex = 5000` returned nothing at all rather than the
     * 5,001st episode.
     *
     * **Order is preserved across page boundaries** because every per-type
     * listing sorts on a TOTAL order: `getAllByType()` ends its `ORDER BY` on the
     * `id` PRIMARY KEY, and `getArtistMediaItemIds()` on `music_artists.id`.
     * Without that, two rows sharing a sort key have no defined relative order
     * and MySQL may return them one way for one `OFFSET` and the other way for
     * the next — the classic paged-walk row loss.
     *
     * `$remaining` is decremented by what was REQUESTED, not by what came back,
     * so the offset arithmetic stays aligned with the counts even if the profile
     * tag filter drops rows from a music page. A short page is then honest
     * (`NumberReturned` < requested) instead of silently shifting every
     * subsequent page.
     *
     * @param string $libraryType The library type (video, audio, images)
     * @param int $offset DLNA `StartingIndex` into the whole category.
     * @param int $limit Page size, clamped by the caller to
     *        `[1, self::MAX_PAGE_ROWS]`.
     * @return array<int, array<string, mixed>> Array of media items converted to CDS objects
     *
     * @since 0.12.0
     */
    private function getLibraryItems(string $libraryType, int $offset = 0, int $limit = self::MAX_PAGE_ROWS): array
    {
        $skip = max(0, $offset);
        $remaining = min(max(1, $limit), self::MAX_PAGE_ROWS);

        $objects = [];
        foreach ($this->categoryTypeCounts($libraryType) as $type => $available) {
            if ($remaining <= 0) {
                break;
            }

            if ($skip >= $available) {
                $skip -= $available;
                continue;
            }

            $take = min($remaining, $available - $skip);

            $items = $type === 'artist' && $this->musicLibrary !== null
                // Enumerated from `music_artists` (S97), so the root shows exactly
                // the artists that can actually be drilled into — an orphaned
                // `media_items[artist]` row is not one of them.
                //
                // The id resolution is chunked so no single `IN (…)` carries
                // MAX_PAGE_ROWS placeholders. See self::FIND_BY_IDS_CHUNK.
                ? $this->findByIdsChunked($this->musicLibrary->getArtistMediaItemIds($take, $skip))
                : $this->itemRepository->getAllByType($type, $take, $skip);

            foreach ($items as $item) {
                $objects[] = $this->itemToCdsObject($item);
            }

            $remaining -= $take;
            $skip = 0;
        }

        return $objects;
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
     * Delegates to {@see UpnpClassMap}, which covers the whole
     * `media_items.type` ENUM. This previously carried its own `match` whose
     * `default` built `'object.item.' . $type` — an invented, non-spec class
     * for `book`, `audiobook`, `track`, `album`, `artist`, `season` and
     * `episode`.
     *
     * @param array<string, mixed> $item Media item from repository
     * @return string UPnP class string
     *
     * @since 0.12.0
     */
    private function determineUpnpClass(array $item): string
    {
        $type = is_string($item['type'] ?? null) ? $item['type'] : '';

        return UpnpClassMap::forType($type);
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
     * Delegates to {@see DlnaMimeTypes}, which is the ONE table the whole DLNA
     * surface reads. This method used to own a private copy of it, and the
     * {@see ContentDirectory} owned a second — so the MIME a renderer was told to
     * expect in `<res protocolInfo>` and the `Content-Type` the bytes arrive with
     * could silently diverge, which makes a renderer refuse the item.
     *
     * NOT a pure refactor: the resolution ORDER is unchanged, but
     * {@see DlnaMimeTypes::EXTENSION_MAP} is a SUPERSET of the table this method
     * used to hold, so the `mime_type` this returns changed for roughly twenty
     * containers (`.mov` → `video/quicktime` instead of `video/mp4`, `.m4a` →
     * `audio/mp4` instead of `audio/mpeg`, and so on). See that class's docblock
     * for the full list and the reasoning.
     *
     * @param array<string, mixed> $item Media item
     * @return string MIME type
     *
     * @since 0.12.0
     */
    private function determineMimeType(array $item): string
    {
        return DlnaMimeTypes::forItem($item);
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
