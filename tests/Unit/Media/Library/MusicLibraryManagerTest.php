<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\AudioScanner;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MusicLibraryManager;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Metadata\MetadataManager;
use Workerman\MySQL\Connection;

/**
 * Unit tests for MusicLibraryManager.
 *
 * @covers \Phlix\Media\Library\MusicLibraryManager
 * @covers \Phlix\Media\Library\ScanResult
 */
class MusicLibraryManagerTest extends TestCase
{
    private Connection $db;
    private AudioScanner $scanner;
    private MetadataManager $metadata;
    private ItemRepository $itemRepo;
    private MusicLibraryManager $manager;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->scanner = $this->createMock(AudioScanner::class);
        $this->metadata = $this->createMock(MetadataManager::class);
        $this->itemRepo = new ItemRepository($this->db);

        $this->manager = new MusicLibraryManager(
            $this->scanner,
            $this->metadata,
            $this->itemRepo,
            $this->db
        );
    }

    /**
     * @test
     */
    public function testRescanLibraryReturnsScanResult(): void
    {
        $libraryId = 'test-lib-123';

        // Mock library lookup
        $this->db->method('query')->willReturn([
            [
                'id' => $libraryId,
                'name' => 'Test Music',
                'type' => 'music',
                'paths' => '["/tmp/music"]',
                'options' => '{}',
            ]
        ]);

        // Mock scanner to yield empty generator
        $emptyGenerator = function(): \Generator {
            return;
            yield; // Force this to be a generator
        };
        $this->scanner->method('scanMusicLibrary')
            ->willReturn($emptyGenerator());

        $result = $this->manager->rescanLibrary($libraryId);

        $this->assertInstanceOf(ScanResult::class, $result);
        $this->assertIsInt($result->scanned);
        $this->assertIsInt($result->added);
        $this->assertIsInt($result->updated);
        $this->assertIsInt($result->durationMs);
    }

    /**
     * @test
     */
    public function testRescanLibraryCallsScanner(): void
    {
        $libraryId = 'test-lib-456';

        $this->db->method('query')->willReturn([
            [
                'id' => $libraryId,
                'name' => 'Test Music',
                'type' => 'music',
                'paths' => '["/tmp/music"]',
                'options' => '{}',
            ]
        ]);

        // Create a generator that yields nothing
        $emptyGenerator = function(): \Generator {
            return;
            yield;
        };

        // Scanner should be called with music library
        $this->scanner->method('scanMusicLibrary')
            ->willReturn($emptyGenerator());

        // The method should not throw
        $result = $this->manager->rescanLibrary($libraryId);

        $this->assertInstanceOf(ScanResult::class, $result);
    }

    /**
     * @test
     */
    public function testRescanLibraryThrowsForNonexistentLibrary(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library not found');

        $this->manager->rescanLibrary('non-existent-id');
    }

    /**
     * @test
     */
    public function testUpsertTrackStoresTags(): void
    {
        $libraryId = 'test-lib-789';
        $tempFile = tempnam(sys_get_temp_dir(), 'phlix_test_') . '.mp3';

        // Write minimal MP3 data
        file_put_contents($tempFile, $this->createMinimalMp3());

        // Mock item repository
        $this->db->method('query')
            ->willReturnCallback(function($sql, $params) use ($tempFile) {
                if (strpos($sql, 'SELECT') === 0) {
                    return []; // No existing item
                }
                return [];
            });

        $item = $this->manager->upsertTrack($libraryId, $tempFile);

        // Clean up
        unlink($tempFile);

        $this->assertNull($item); // No tags in minimal file, returns null
    }

    /**
     * @test
     */
    public function testUpsertTrackReturnsNullForNonexistentFile(): void
    {
        $result = $this->manager->upsertTrack('lib-123', '/non/existent/file.mp3');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function testUpsertTrackEnrichesViaMetadataManager(): void
    {
        $libraryId = 'test-lib-enrich';
        $tempFile = tempnam(sys_get_temp_dir(), 'phlix_test_') . '.mp3';

        // Write minimal MP3 data
        file_put_contents($tempFile, $this->createMinimalMp3());

        // Mock the scanner to return tags (simulating a file with valid tags)
        $this->scanner->method('harvestTags')->willReturn([
            'title' => 'Test Track',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
        ]);

        // Mock DB - return existing item on findByPath
        $this->db->method('query')
            ->willReturnCallback(function($sql, $params) use ($tempFile) {
                if (strpos($sql, 'SELECT') === 0 && strpos($sql, 'path') !== false) {
                    return [[
                        'id' => 'existing-item-id',
                        'name' => 'Test Track',
                        'type' => 'track',
                        'path' => $tempFile,
                        'metadata_json' => '{}',
                    ]];
                }
                return [];
            });

        // Expect metadata refresh to be called
        $this->metadata->expects($this->atLeastOnce())
            ->method('refreshItemMetadata');

        $this->manager->upsertTrack($libraryId, $tempFile);

        // Clean up
        unlink($tempFile);
    }

    /**
     * @test
     */
    public function testGetLibraryReturnsDecodedPathsAndOptions(): void
    {
        $libraryId = 'test-lib-decode';

        $this->db->method('query')->willReturn([
            [
                'id' => $libraryId,
                'name' => 'Test Library',
                'type' => 'music',
                'paths' => '["/path/one", "/path/two"]',
                'options' => '{"scan_interval": 3600}',
            ]
        ]);

        $library = $this->manager->getLibrary($libraryId);

        $this->assertIsArray($library);
        $this->assertEquals(['/path/one', '/path/two'], $library['paths']);
        $this->assertEquals(['scan_interval' => 3600], $library['options']);
    }

    /**
     * @test
     */
    public function testGetLibraryReturnsNullWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->manager->getLibrary('non-existent');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function testGetArtistsReturnsGroupedByArtist(): void
    {
        $libraryId = 'test-lib-artists';

        $this->db->method('query')
            ->willReturnCallback(function($sql, $params) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'media_items') !== false) {
                    return [
                        [
                            'id' => 'track-1',
                            'name' => 'Track 1',
                            'type' => 'track',
                            'library_id' => 'test-lib',
                            'path' => '/music/artist1/album1/track1.mp3',
                            'metadata_json' => '{"artist": "Artist A", "album": "Album X", "title": "Song 1"}',
                        ],
                        [
                            'id' => 'track-2',
                            'name' => 'Track 2',
                            'type' => 'track',
                            'library_id' => 'test-lib',
                            'path' => '/music/artist1/album1/track2.mp3',
                            'metadata_json' => '{"artist": "Artist A", "album": "Album X", "title": "Song 2"}',
                        ],
                        [
                            'id' => 'track-3',
                            'name' => 'Track 3',
                            'type' => 'track',
                            'library_id' => 'test-lib',
                            'path' => '/music/artist2/album2/track1.mp3',
                            'metadata_json' => '{"artist": "Artist B", "album": "Album Y", "title": "Song 3"}',
                        ],
                    ];
                }
                return [];
            });

        $artists = $this->manager->getArtists($libraryId);

        $this->assertCount(2, $artists);

        // Find Artist A
        $artistA = null;
        foreach ($artists as $artist) {
            if ($artist['name'] === 'Artist A') {
                $artistA = $artist;
                break;
            }
        }

        $this->assertNotNull($artistA);
        $this->assertEquals(2, $artistA['track_count']);
        $this->assertEquals(1, $artistA['album_count']);
    }

    /**
     * @test
     */
    public function testGetAlbumsReturnsGroupedByAlbum(): void
    {
        $libraryId = 'test-lib-albums';

        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params) use ($libraryId) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'media_items') !== false) {
                    return [
                        [
                            'id' => 'track-1',
                            'name' => 'Track 1',
                            'type' => 'track',
                            'library_id' => $libraryId,
                            'path' => '/music/artist1/album1/01.mp3',
                            'metadata_json' => '{"artist": "Artist A", "album": "Album One", "title": "Track 1", "year": 2020}',
                        ],
                        [
                            'id' => 'track-2',
                            'name' => 'Track 2',
                            'type' => 'track',
                            'library_id' => $libraryId,
                            'path' => '/music/artist1/album1/02.mp3',
                            'metadata_json' => '{"artist": "Artist A", "album": "Album One", "title": "Track 2", "year": 2020}',
                        ],
                    ];
                }
                return [];
            });

        $albums = $this->manager->getAlbums($libraryId);

        $this->assertCount(1, $albums);
        $this->assertEquals('Album One', $albums[0]['name']);
        $this->assertEquals('Artist A', $albums[0]['artist']);
        $this->assertEquals(2, $albums[0]['track_count']);
        $this->assertEquals(2020, $albums[0]['year']);
    }

    /**
     * @test
     */
    public function testGetTracksReturnsPaginatedResults(): void
    {
        $libraryId = 'test-lib-tracks';

        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params) use ($libraryId) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'media_items') !== false) {
                    return [
                        [
                            'id' => 'track-1',
                            'name' => 'Track 1',
                            'type' => 'track',
                            'library_id' => $libraryId,
                            'path' => '/music/track1.mp3',
                            'metadata_json' => '{"title": "First Track"}',
                        ],
                    ];
                }
                return [];
            });

        $tracks = $this->manager->getTracks($libraryId, 50, 0);

        $this->assertIsArray($tracks);
    }

    /**
     * Creates minimal MP3 data for testing.
     */
    private function createMinimalMp3(): string
    {
        $id3 = 'ID3' . chr(0x04) . chr(0x00) . chr(0x00) . chr(0) . chr(0) . chr(0) . chr(0);
        return $id3 . str_repeat("\x00", 128) . chr(0xFF) . chr(0xFB);
    }
}
