<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlix\Dlna\LibraryBridge;
use Phlix\Media\Library\ItemRepository;
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
}
