<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlex\Dlna\LibraryBridge;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Streaming\HlsStreamer;
use Phlex\Media\Streaming\QualitySelector;

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
     * @since 0.12.0
     */
    public function testGetRootContainersReturnsVideoAndAudio(): void
    {
        $containers = $this->bridge->getRootContainers();

        $this->assertIsArray($containers);
        $this->assertGreaterThanOrEqual(3, count($containers));

        // Verify structure of each container
        foreach ($containers as $container) {
            $this->assertArrayHasKey('id', $container);
            $this->assertArrayHasKey('parent_id', $container);
            $this->assertArrayHasKey('name', $container);
            $this->assertArrayHasKey('type', $container);
            $this->assertArrayHasKey('class', $container);
            $this->assertArrayHasKey('child_count', $container);

            $this->assertEquals('0', $container['parent_id']);
            $this->assertEquals('container', $container['type']);
            $this->assertEquals('object.container', $container['class']);
        }

        // Verify specific container IDs
        $containerIds = array_column($containers, 'id');
        $this->assertContains('library-video', $containerIds);
        $this->assertContains('library-audio', $containerIds);
        $this->assertContains('library-images', $containerIds);
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

        $this->assertIsArray($children);
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

        $this->assertIsArray($children);
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
    public function testItemToCdsObjectHandlesImageType(): void
    {
        $item = [
            'id' => 'image-123',
            'parent_id' => 'library-images',
            'name' => 'Test Photo',
            'type' => 'image',
            'path' => '/media/images/test.jpg',
        ];

        $cdsObject = $this->bridge->itemToCdsObject($item);

        $this->assertEquals('image-123', $cdsObject['id']);
        $this->assertEquals('image', $cdsObject['type']);
        $this->assertEquals('object.item.imageItem.photo', $cdsObject['class']);
    }

    /**
     * @since 0.12.0
     */
    public function testGetStreamUrlUsesHlsStreamer(): void
    {
        $itemId = 'media-stream-123';
        $expectedUrl = 'http://localhost:8096/hls/media-stream-123/playlist.m3u8';

        $this->hlsStreamerMock
            ->expects($this->once())
            ->method('getPlaylistUrl')
            ->with($itemId)
            ->willReturn($expectedUrl);

        $streamUrl = $this->bridge->getStreamUrl($itemId);

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
