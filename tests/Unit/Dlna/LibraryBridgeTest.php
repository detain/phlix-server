<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlix\Dlna\LibraryBridge;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;

/**
 * Tests for LibraryBridge class.
 *
 * @since 0.12.0
 */
class LibraryBridgeTest extends TestCase
{
    private LibraryBridge $bridge;
    private MockObject $itemRepositoryMock;
    private MockObject $hlsStreamerMock;

    protected function setUp(): void
    {
        $this->itemRepositoryMock = $this->createMock(ItemRepository::class);
        $this->hlsStreamerMock = $this->createMock(HlsStreamer::class);

        $this->bridge = new LibraryBridge(
            $this->itemRepositoryMock,
            $this->hlsStreamerMock
        );
    }

    /**
     * Stub per-type counts; anything unlisted counts zero.
     *
     * @param array<string, int> $counts media_items.type => row count
     */
    private function stubCounts(array $counts): void
    {
        $this->itemRepositoryMock->method('countAllByType')
            ->willReturnCallback(static fn (string $type): int => $counts[$type] ?? 0);
    }

    /**
     * CONSEQUENCE: root containers reflect what is actually in the library.
     *
     * Counts used to be hardcoded to 0 and the category->type mapping did not
     * match the media_items ENUM, so every container was permanently empty.
     *
     * @since 0.12.0
     */
    public function testGetRootContainersReturnsCategoriesThatHaveContent(): void
    {
        // Production-shaped: video only.
        $this->stubCounts(['movie' => 10719, 'series' => 434]);

        $containers = $this->bridge->getRootContainers();

        $this->assertArrayHasKey(0, $containers);

        foreach ($containers as $container) {
            foreach (['id', 'parent_id', 'name', 'type', 'class', 'child_count'] as $key) {
                $this->assertArrayHasKey($key, $container);
            }
            $this->assertEquals('0', $container['parent_id']);
            $this->assertEquals('container', $container['type']);
            $this->assertEquals('object.container', $container['class']);
        }

        $byId = array_column($containers, 'child_count', 'id');

        // Video spans SEVERAL enum members. Mapping 'video' to just 'movie',
        // as the old code did, would report 10719 here and hide the series.
        $this->assertSame(10719 + 434, $byId['library-video'] ?? null);
    }

    /**
     * CONSEQUENCE: photos are counted as `photo`, never `image`.
     *
     * `image` is a SCANNER label; the media_items ENUM says `photo`. The old
     * mapping used 'image', so the Images container counted zero on every
     * install no matter how many photos were present.
     *
     * Mutation-verified: changing the photos mapping back to 'image' fails this.
     */
    public function testPhotosAreCountedUnderTheRealEnumMember(): void
    {
        $this->stubCounts(['photo' => 7]);

        $byId = array_column($this->bridge->getRootContainers(), 'child_count', 'id');

        $this->assertSame(7, $byId['library-photos'] ?? null);
    }

    /**
     * CONSEQUENCE: empty categories are not advertised.
     *
     * A TV showing containers that are always empty is worse than showing only
     * what exists.
     */
    public function testEmptyCategoriesAreOmitted(): void
    {
        $this->stubCounts(['movie' => 3]);

        $ids = array_column($this->bridge->getRootContainers(), 'id');

        $this->assertSame(['library-video'], $ids);
    }

    /**
     * CONSEQUENCE: a completely empty library advertises nothing at all.
     */
    public function testAnEmptyLibraryYieldsNoContainers(): void
    {
        $this->stubCounts([]);

        $this->assertSame([], $this->bridge->getRootContainers());
    }

    /**
     * CONSEQUENCE: ContentDirectory's root browse USES the bridge.
     *
     * This is the zero-caller bug. `ContentDirectory::browseRoot()` ignored the
     * LibraryBridge completely and always returned a hardcoded
     * Music/Artists/Albums/Tracks list with child_count 0, so
     * `LibraryBridge::getRootContainers()` had no callers at all and a TV saw
     * four empty music folders while 10 719 movies sat invisible.
     *
     * Asserts through the PUBLIC browse path, not by calling the bridge
     * directly — calling the bridge would have passed even while nothing used it.
     *
     * Mutation-verified: removing the bridge branch from browseRoot() fails this.
     */
    public function testContentDirectoryRootBrowseUsesTheBridge(): void
    {
        $this->stubCounts(['movie' => 10719, 'series' => 434]);

        $cd = new \Phlix\Dlna\ContentDirectory($this->itemRepositoryMock);
        $cd->setLibraryBridge($this->bridge);

        $result = $cd->browse('0', 'BrowseDirectChildren', '*', 0, 10, '');
        $didl = is_string($result['Result'] ?? null) ? $result['Result'] : '';

        $this->assertStringContainsString('Video', $didl, 'Root browse must list the real library categories.');
        $this->assertStringNotContainsString(
            'Artists',
            $didl,
            'Root browse must not fall back to the hardcoded music-only list when a bridge is set.'
        );
    }

    /**
     * @since 0.12.0
     */
    public function testGetContainerChildrenUsesItemRepository(): void
    {
        $parentId = 'parent-folder-123';
        $expectedItems = [
            [
                'id' => 'item-1',
                'parent_id' => $parentId,
                'name' => 'Test Movie',
                'type' => 'movie',
                'path' => '/media/movies/test.mp4',
            ],
            [
                'id' => 'item-2',
                'parent_id' => $parentId,
                'name' => 'Test Movie 2',
                'type' => 'movie',
                'path' => '/media/movies/test2.mp4',
            ],
        ];

        $this->itemRepositoryMock
            ->expects($this->once())
            ->method('findByParent')
            ->with($parentId)
            ->willReturn($expectedItems);

        $children = $this->bridge->getContainerChildren($parentId);

        $this->assertArrayHasKey(0, $children);
        $this->assertCount(2, $children);

        // Verify items are converted to CDS objects
        $this->assertEquals('item-1', $children[0]['id']);
        $this->assertEquals('Test Movie', $children[0]['name']);
        $this->assertEquals('object.item.videoItem.movie', $children[0]['class']);
    }

    /**
     * @since 0.12.0
     */
    public function testGetContainerChildrenForLibraryContainers(): void
    {
        // For library-* containers, the bridge returns empty by default
        // since we don't have library_id in this context
        $children = $this->bridge->getContainerChildren('library-video');

        $this->assertCount(0, $children);
        // Without a real library_id, this returns empty
        $this->assertEmpty($children);
    }

    /**
     * @since 0.12.0
     */
    public function testItemToCdsObjectMapsAllFields(): void
    {
        $item = [
            'id' => 'media-123',
            'parent_id' => 'library-video',
            'name' => 'Test Movie',
            'type' => 'movie',
            'path' => '/media/movies/test.mp4',
            'metadata' => [
                'artist' => 'Test Director',
                'album' => 'Test Album',
                'genre' => 'Action',
                'duration' => 7200,
                'release_date' => '2023-01-15',
                'width' => 1920,
                'height' => 1080,
                'thumbnail' => '/thumbnails/test.jpg',
            ],
        ];

        $cdsObject = $this->bridge->itemToCdsObject($item);

        $this->assertEquals('media-123', $cdsObject['id']);
        $this->assertEquals('library-video', $cdsObject['parent_id']);
        $this->assertEquals('Test Movie', $cdsObject['name']);
        $this->assertEquals('movie', $cdsObject['type']);
        $this->assertEquals('/media/movies/test.mp4', $cdsObject['path']);
        $this->assertEquals('Test Director', $cdsObject['artist']);
        $this->assertEquals('Test Album', $cdsObject['album']);
        $this->assertEquals('Action', $cdsObject['genre']);
        $this->assertEquals(7200, $cdsObject['duration']);
        $this->assertEquals('2023-01-15', $cdsObject['date']);
        $this->assertEquals(1920, $cdsObject['width']);
        $this->assertEquals(1080, $cdsObject['height']);
        $this->assertEquals('/thumbnails/test.jpg', $cdsObject['thumbnail']);
        $this->assertEquals('object.item.videoItem.movie', $cdsObject['class']);
    }

    /**
     * @since 0.12.0
     */
    public function testItemToCdsObjectHandlesAudioType(): void
    {
        $item = [
            'id' => 'audio-123',
            'parent_id' => 'library-audio',
            'name' => 'Test Song',
            'type' => 'audio',
            'path' => '/media/music/test.mp3',
        ];

        $cdsObject = $this->bridge->itemToCdsObject($item);

        $this->assertEquals('audio-123', $cdsObject['id']);
        $this->assertEquals('audio', $cdsObject['type']);
        $this->assertEquals('object.item.audioItem.musicTrack', $cdsObject['class']);
    }

    /**
     * @since 0.12.0
     */
    public function testItemToCdsObjectHandlesPhotoType(): void
    {
        $item = [
            'id' => 'image-123',
            'parent_id' => 'library-images',
            'name' => 'Test Photo',
            'type' => 'photo',
            'path' => '/media/images/test.jpg',
        ];

        $cdsObject = $this->bridge->itemToCdsObject($item);

        $this->assertEquals('image-123', $cdsObject['id']);
        $this->assertEquals('photo', $cdsObject['type']);
        $this->assertEquals('object.item.imageItem.photo', $cdsObject['class']);
    }

    /**
     * Types whose class used to come from the `default => 'object.item.' . $type`
     * arm, i.e. an invented class no renderer is required to accept.
     *
     * @return array<string, array{string, string}>
     */
    public static function previouslyMalformedTypeProvider(): array
    {
        return [
            'book' => ['book', 'object.item.textItem'],
            'audiobook' => ['audiobook', 'object.item.audioItem.audioBook'],
            'track' => ['track', 'object.item.audioItem.musicTrack'],
            'album' => ['album', 'object.container.album.musicAlbum'],
            'artist' => ['artist', 'object.container.person.musicArtist'],
            'season' => ['season', 'object.item.videoItem.videoBroadcast'],
            'episode' => ['episode', 'object.item.videoItem.videoBroadcast'],
        ];
    }

    /**
     * @dataProvider previouslyMalformedTypeProvider
     *
     * @since 0.12.0
     */
    public function testItemToCdsObjectEmitsSpecClassForPreviouslyMalformedTypes(
        string $type,
        string $expectedClass
    ): void {
        $cdsObject = $this->bridge->itemToCdsObject([
            'id' => 'item-1',
            'parent_id' => 'parent-1',
            'name' => 'Test Item',
            'type' => $type,
            'path' => '/media/test',
        ]);

        $this->assertEquals($expectedClass, $cdsObject['class']);
        $this->assertStringNotContainsString(
            'object.item.' . $type,
            $cdsObject['class'],
            'The class must not be built by concatenating the raw type.'
        );
    }

    /**
     * @since 0.12.0
     */
    public function testItemToCdsObjectFallsBackToGenericItemForUnknownType(): void
    {
        $cdsObject = $this->bridge->itemToCdsObject([
            'id' => 'item-1',
            'parent_id' => 'parent-1',
            'name' => 'Test Item',
            'type' => 'not-a-real-type',
            'path' => '/media/test',
        ]);

        $this->assertEquals('object.item', $cdsObject['class']);
    }

    /**
     * @since 0.12.0
     */
    public function testGetStreamUrlUsesHlsStreamer(): void
    {
        $item = [
            'id' => 'media-stream-123',
            'name' => 'Test Stream',
            'type' => 'movie',
        ];
        $expectedUrl = 'http://localhost:8096/hls/media-stream-123/playlist.m3u8';

        $this->hlsStreamerMock
            ->expects($this->once())
            ->method('getStreamUrl')
            ->with($item)
            ->willReturn($expectedUrl);

        $streamUrl = $this->bridge->getStreamUrl($item);

        $this->assertEquals($expectedUrl, $streamUrl);
    }

    /**
     * @since 0.12.0
     */
    public function testGetMediaObjectReturnsNullForNonExistent(): void
    {
        $this->itemRepositoryMock
            ->expects($this->once())
            ->method('findById')
            ->with('non-existent-id')
            ->willReturn(null);

        $result = $this->bridge->getMediaObject('non-existent-id');

        $this->assertNull($result);
    }

    /**
     * @since 0.12.0
     */
    public function testGetMediaObjectReturnsLibraryContainer(): void
    {
        $result = $this->bridge->getMediaObject('library-video');

        $this->assertIsArray($result);
        $this->assertEquals('library-video', $result['id']);
        $this->assertEquals('0', $result['parent_id']);
        $this->assertEquals('Video', $result['name']);
        $this->assertEquals('container', $result['type']);
    }

    /**
     * @since 0.12.0
     */
    public function testItemToCdsObjectHandlesDurationFormats(): void
    {
        // Test integer duration
        $item1 = [
            'id' => 'item1',
            'name' => 'Test 1',
            'type' => 'movie',
            'metadata' => ['duration' => 3661],
        ];
        $this->assertEquals(3661, $this->bridge->itemToCdsObject($item1)['duration']);

        // Test HH:MM:SS format
        $item2 = [
            'id' => 'item2',
            'name' => 'Test 2',
            'type' => 'movie',
            'metadata' => ['duration' => '01:01:01'],
        ];
        $this->assertEquals(3661, $this->bridge->itemToCdsObject($item2)['duration']);

        // Test MM:SS format
        $item3 = [
            'id' => 'item3',
            'name' => 'Test 3',
            'type' => 'music',
            'metadata' => ['duration' => '05:30'],
        ];
        $this->assertEquals(330, $this->bridge->itemToCdsObject($item3)['duration']);
    }

    /**
     * @since 0.12.0
     */
    public function testItemToCdsObjectHandlesMimeTypes(): void
    {
        // Test mp4
        $item1 = [
            'id' => 'item1',
            'name' => 'Test 1',
            'type' => 'video',
            'path' => '/test/video.mp4',
        ];
        $this->assertEquals('video/mp4', $this->bridge->itemToCdsObject($item1)['mime_type']);

        // Test mkv
        $item2 = [
            'id' => 'item2',
            'name' => 'Test 2',
            'type' => 'video',
            'path' => '/test/video.mkv',
        ];
        $this->assertEquals('video/x-matroska', $this->bridge->itemToCdsObject($item2)['mime_type']);

        // Test mp3
        $item3 = [
            'id' => 'item3',
            'name' => 'Test 3',
            'type' => 'audio',
            'path' => '/test/audio.mp3',
        ];
        $this->assertEquals('audio/mpeg', $this->bridge->itemToCdsObject($item3)['mime_type']);
    }

    /**
     * @since 0.12.0
     */
    public function testGetItemRepositoryReturnsRepository(): void
    {
        $repo = $this->bridge->getItemRepository();
        $this->assertSame($this->itemRepositoryMock, $repo);
    }

    /**
     * @since 0.12.0
     */
    public function testGetHlsStreamerReturnsStreamer(): void
    {
        $streamer = $this->bridge->getHlsStreamer();
        $this->assertSame($this->hlsStreamerMock, $streamer);
    }

    // -----------------------------------------------------------------------
    // S97 — the music hierarchy comes from `music_*`, never from `parent_id`.
    // -----------------------------------------------------------------------

    /**
     * The Audio category must advertise ARTISTS (the top of the music
     * hierarchy) and the standalone audio types — not artists AND albums AND
     * tracks flattened into one sibling list.
     *
     * Before S97 `CATEGORY_TYPES['audio']` was
     * `['music','audio','album','artist','track','audiobook']`, so on a
     * production-shaped library this container advertised 76,727+ children
     * while `getLibraryItems()` could only ever return
     * `ItemRepository::getAllByType()`'s default page of 100 PER TYPE. Nesting
     * could not fix it: `getAllByType()` has no parent filter, and music rows
     * carry no `parent_id` to filter on.
     */
    public function testAudioRootCountsArtistsButNotAlbumsOrTracks(): void
    {
        $this->stubCounts([
            'artist' => 4656,
            'album' => 10966,
            'track' => 61105,
            'music' => 0,
            'audio' => 12,
            'audiobook' => 3,
        ]);

        $byId = array_column($this->bridge->getRootContainers(), 'child_count', 'id');

        $this->assertSame(4656 + 12 + 3, $byId['library-audio'] ?? null);
    }

    /**
     * With the `music_*` reader wired, the advertised artist count comes from
     * `music_artists` — the same source the LISTING is enumerated from — and is
     * then clamped to the ceiling that listing actually stops at,
     * {@see MusicLibraryService::MAX_EMBEDDED_ROWS}.
     *
     * On the production shape (4,656 artists with a `media_items` row) the
     * unclamped count advertises 4,656 while `getLibraryItems()` can only hand
     * over 2,000, because it calls `getArtistMediaItemIds()` whose default limit
     * IS that constant. The renderer is then promised 2.3x what it receives.
     *
     * ⚠ This test pins the ADVERTISED number only. It does not claim the other
     * 2,656 artists are reachable — they are not, and
     * {@see LibraryBridge::getLibraryChildCount()} says so: there is no offset
     * path into the audio root, so a renderer cannot page past the cap. Real
     * DLNA paging is a separate step.
     */
    public function testAudioRootClampsTheAdvertisedArtistCountToWhatItCanDeliver(): void
    {
        $this->stubCounts(['artist' => 4658, 'audio' => 0, 'audiobook' => 0]);
        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getArtistsWithMediaItemCount')->willReturn(4656);

        $bridge = new LibraryBridge(
            $this->itemRepositoryMock,
            $this->hlsStreamerMock,
            null,
            $music
        );

        $byId = array_column($bridge->getRootContainers(), 'child_count', 'id');

        $this->assertSame(
            MusicLibraryService::MAX_EMBEDDED_ROWS,
            $byId['library-audio'] ?? null,
            'the advertised childCount must not exceed what getLibraryItems() can return'
        );
    }

    /**
     * Below the cap, the advertised count is the `music_artists` count exactly —
     * a `media_items[artist]` row that no `music_artists` row points at is
     * adoption residue, not a browsable artist, and must not be advertised.
     *
     * Deliberately uses numbers under {@see MusicLibraryService::MAX_EMBEDDED_ROWS}
     * so the clamp cannot mask the orphan exclusion: at production scale both
     * 4,658 and 4,656 clamp to the same value and this distinction would be
     * invisible.
     */
    public function testAudioRootCountsArtistsFromMusicTablesWhenWired(): void
    {
        $this->stubCounts(['artist' => 12, 'audio' => 0, 'audiobook' => 0]);
        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getArtistsWithMediaItemCount')->willReturn(10);

        $bridge = new LibraryBridge(
            $this->itemRepositoryMock,
            $this->hlsStreamerMock,
            null,
            $music
        );

        $byId = array_column($bridge->getRootContainers(), 'child_count', 'id');

        $this->assertSame(10, $byId['library-audio'] ?? null, 'the 2 orphaned artist rows must not be advertised');
    }

    /**
     * Drilling into an ARTIST returns its albums, read from `music_albums`.
     *
     * `findByParent()` cannot answer this: S97 settled that
     * `media_items.parent_id` is never written for music, so the generic
     * drill-down returns an empty container and the browse dead-ends at the
     * artist.
     */
    public function testDrillingIntoAnArtistReturnsItsAlbumsFromTheMusicTables(): void
    {
        $this->itemRepositoryMock->method('findById')->willReturn([
            'id' => 'artist-1', 'name' => 'An Artist', 'type' => 'artist', 'path' => '',
        ]);
        $this->itemRepositoryMock->expects($this->never())->method('findByParent');
        $this->itemRepositoryMock->method('findByIds')->with(['album-1', 'album-2'])->willReturn([
            ['id' => 'album-1', 'name' => 'First', 'type' => 'album', 'path' => ''],
            ['id' => 'album-2', 'name' => 'Second', 'type' => 'album', 'path' => ''],
        ]);

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getAlbumMediaItemIdsForArtist')->willReturn(['album-1', 'album-2']);

        $bridge = new LibraryBridge($this->itemRepositoryMock, $this->hlsStreamerMock, null, $music);

        $children = $bridge->getContainerChildren('artist-1');

        $this->assertSame(['album-1', 'album-2'], array_column($children, 'id'));
        $this->assertSame(
            ['object.container.album.musicAlbum', 'object.container.album.musicAlbum'],
            array_column($children, 'class')
        );
    }

    /**
     * Drilling into an ALBUM returns its tracks, read from `music_tracks`.
     */
    public function testDrillingIntoAnAlbumReturnsItsTracksFromTheMusicTables(): void
    {
        $this->itemRepositoryMock->method('findById')->willReturn([
            'id' => 'album-1', 'name' => 'First', 'type' => 'album', 'path' => '',
        ]);
        $this->itemRepositoryMock->expects($this->never())->method('findByParent');
        $this->itemRepositoryMock->method('findByIds')->with(['track-1'])->willReturn([
            ['id' => 'track-1', 'name' => 'A Song', 'type' => 'track', 'path' => '/music/a.mp3'],
        ]);

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getTrackMediaItemIdsForAlbum')->willReturn(['track-1']);

        $bridge = new LibraryBridge($this->itemRepositoryMock, $this->hlsStreamerMock, null, $music);

        $children = $bridge->getContainerChildren('album-1');

        $this->assertSame(['track-1'], array_column($children, 'id'));
        $this->assertSame('object.item.audioItem.musicTrack', $children[0]['class']);
    }

    /**
     * NON-music containers are untouched: a series still drills down through
     * `media_items.parent_id`, which is where ITS hierarchy really lives.
     */
    public function testDrillingIntoASeriesStillUsesTheParentIdHierarchy(): void
    {
        $this->itemRepositoryMock->method('findById')->willReturn([
            'id' => 'series-1', 'name' => 'A Show', 'type' => 'series', 'path' => '',
        ]);
        $this->itemRepositoryMock->expects($this->once())->method('findByParent')->with('series-1')->willReturn([
            ['id' => 'season-1', 'name' => 'S1', 'type' => 'season', 'path' => ''],
        ]);

        $music = $this->createMock(MusicLibraryService::class);
        $music->expects($this->never())->method('getAlbumMediaItemIdsForArtist');

        $bridge = new LibraryBridge($this->itemRepositoryMock, $this->hlsStreamerMock, null, $music);

        $this->assertSame(['season-1'], array_column($bridge->getContainerChildren('series-1'), 'id'));
    }

    /**
     * A caller that already knows the container's type must not make the bridge
     * look it up again.
     *
     * `ContentDirectory::browse()` resolves (and caches) the object before it
     * dispatches to `browseChildren()`, so the `findById()` this class used to
     * issue was a second read of a row the request already had — one wasted
     * query on EVERY drill-down, including the `series`/`season` ones that end up
     * using `parent_id` anyway.
     */
    public function testAKnownContainerTypeSpareTheBridgeASecondLookup(): void
    {
        $this->itemRepositoryMock->expects($this->never())->method('findById');
        $this->itemRepositoryMock->expects($this->once())->method('findByParent')->with('series-1')->willReturn([
            ['id' => 'season-1', 'name' => 'S1', 'type' => 'season', 'path' => ''],
        ]);

        $music = $this->createMock(MusicLibraryService::class);
        $bridge = new LibraryBridge($this->itemRepositoryMock, $this->hlsStreamerMock, null, $music);

        $this->assertSame(
            ['season-1'],
            array_column($bridge->getContainerChildren('series-1', 'series'), 'id')
        );
    }

    /**
     * The same shortcut on the MUSIC side: a known `artist` type goes straight to
     * `music_albums` with no `media_items` type probe.
     */
    public function testAKnownArtistTypeGoesStraightToTheMusicTables(): void
    {
        $this->itemRepositoryMock->expects($this->never())->method('findById');
        $this->itemRepositoryMock->expects($this->never())->method('findByParent');
        $this->itemRepositoryMock->method('findByIds')->with(['album-1'])->willReturn([
            ['id' => 'album-1', 'name' => 'First', 'type' => 'album', 'path' => ''],
        ]);

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getAlbumMediaItemIdsForArtist')->willReturn(['album-1']);

        $bridge = new LibraryBridge($this->itemRepositoryMock, $this->hlsStreamerMock, null, $music);

        $this->assertSame(
            ['album-1'],
            array_column($bridge->getContainerChildren('artist-1', 'artist'), 'id')
        );
    }

    /**
     * `ContentDirectory` really does hand the resolved type over — asserted
     * through the PUBLIC browse path, because a bridge-level test would pass
     * even if nothing used the new parameter.
     *
     * One `findById()` for the whole Browse: the one `browse()` itself makes to
     * decide BrowseMetadata vs BrowseDirectChildren.
     */
    public function testContentDirectoryHandsTheResolvedTypeToTheBridge(): void
    {
        $this->itemRepositoryMock->expects($this->once())->method('findById')->with('artist-1')->willReturn([
            'id' => 'artist-1', 'name' => 'An Artist', 'type' => 'artist', 'path' => '',
        ]);
        $this->itemRepositoryMock->expects($this->never())->method('findByParent');
        $this->itemRepositoryMock->method('findByIds')->with(['album-1'])->willReturn([
            ['id' => 'album-1', 'name' => 'First', 'type' => 'album', 'path' => ''],
        ]);

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getAlbumMediaItemIdsForArtist')->willReturn(['album-1']);

        $bridge = new LibraryBridge($this->itemRepositoryMock, $this->hlsStreamerMock, null, $music);
        $cd = new \Phlix\Dlna\ContentDirectory($this->itemRepositoryMock);
        $cd->setLibraryBridge($bridge);

        $result = $cd->browse('artist-1', 'BrowseDirectChildren', '*', 0, 10, '');

        $this->assertSame(1, $result['TotalMatches'] ?? null);
        $this->assertStringContainsString('album-1', is_string($result['Result'] ?? null) ? $result['Result'] : '');
    }

    /**
     * The audio root resolves its artist ids in BOUNDED batches.
     *
     * `getArtistMediaItemIds()` returns up to
     * {@see MusicLibraryService::MAX_EMBEDDED_ROWS} ids and
     * `ItemRepository::findByIds()` builds one `IN (…)` placeholder per id, so
     * un-chunked this was a single 2,000-placeholder statement buffered whole
     * inside a resident worker — the largest the DLNA path issues. Chunking must
     * not change the RESULT: `findByIds()` re-orders its rows to match the ids it
     * was handed, so the concatenated chunks are the un-chunked list.
     */
    public function testTheAudioRootResolvesArtistIdsInBoundedBatches(): void
    {
        $ids = [];
        for ($i = 0; $i < 1200; $i++) {
            $ids[] = sprintf('artist-%04d', $i);
        }

        $this->stubCounts(['artist' => 1200, 'audio' => 0, 'audiobook' => 0]);

        $batchSizes = [];
        $this->itemRepositoryMock->method('findByIds')
            ->willReturnCallback(function (array $batch) use (&$batchSizes): array {
                $batchSizes[] = count($batch);

                return array_map(
                    static fn (string $id): array => ['id' => $id, 'name' => $id, 'type' => 'artist', 'path' => ''],
                    $batch
                );
            });

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getArtistsWithMediaItemCount')->willReturn(1200);
        $music->method('getArtistMediaItemIds')->willReturn($ids);

        $bridge = new LibraryBridge($this->itemRepositoryMock, $this->hlsStreamerMock, null, $music);

        $children = $bridge->getContainerChildren('library-audio');

        $this->assertSame([500, 500, 200], $batchSizes, 'no single IN (…) may carry the whole page');
        $this->assertSame($ids, array_column($children, 'id'), 'chunking must not reorder or drop rows');
    }
}
