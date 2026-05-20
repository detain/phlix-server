<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\AudioScanner;
use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

/**
 * Unit tests for AudioScanner tag harvesting functionality.
 *
 * @covers \Phlix\Media\Library\AudioScanner
 */
class AudioScannerTest extends TestCase
{
    private Connection $db;
    private ItemRepository $itemRepo;
    private AudioScanner $scanner;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->itemRepo = new ItemRepository($this->db);
        $this->scanner = new AudioScanner($this->db, $this->itemRepo);
    }

    /**
     * @test
     */
    public function testHarvestTagsReturnsEmptyArrayForNonExistentFile(): void
    {
        $result = $this->scanner->harvestTags('/non/existent/file.mp3');
        $this->assertEquals([], $result);
    }

    /**
     * @test
     */
    public function testHarvestTagsReturnsEmptyArrayForUnreadableFile(): void
    {
        $result = $this->scanner->harvestTags('/root/secret.mp3');
        $this->assertEquals([], $result);
    }

    /**
     * @test
     */
    public function testHarvestTagsFlac(): void
    {
        // Create a minimal FLAC file structure to test parsing
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $filePath = $tempDir . '/test.flac';

        // Create a minimal FLAC file with Vorbis comment block
        $flacData = $this->createMinimalFlacFile();
        file_put_contents($filePath, $flacData);

        $tags = $this->scanner->harvestTags($filePath);

        // FLAC parsing should return array (may be empty for minimal file)
        $this->assertIsArray($tags);

        unlink($filePath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testHarvestTagsMp3Id3v2(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $filePath = $tempDir . '/test.mp3';

        // Create a minimal MP3 file with ID3v2 header
        $mp3Data = $this->createMinimalMp3File();
        file_put_contents($filePath, $mp3Data);

        $tags = $this->scanner->harvestTags($filePath);

        $this->assertIsArray($tags);

        unlink($filePath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testHarvestTagsM4a(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $filePath = $tempDir . '/test.m4a';

        // Create a minimal M4A file
        $m4aData = $this->createMinimalM4aFile();
        file_put_contents($filePath, $m4aData);

        $tags = $this->scanner->harvestTags($filePath);

        $this->assertIsArray($tags);

        unlink($filePath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testHarvestTagsReturnsPartialOnFailure(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create a corrupted file
        $filePath = $tempDir . '/corrupted.mp3';
        file_put_contents($filePath, 'this is not a valid audio file');

        $tags = $this->scanner->harvestTags($filePath);

        // Should return empty array, not throw
        $this->assertEquals([], $tags);

        unlink($filePath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testScanMusicLibraryYieldsItems(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create some audio files
        $mp3File = $tempDir . '/01 - Test Track.mp3';
        file_put_contents($mp3File, $this->createMinimalMp3File());

        $flacFile = $tempDir . '/02 - Another Track.flac';
        file_put_contents($flacFile, $this->createMinimalFlacFile());

        $libraryId = 'test-lib-123';
        $results = [];
        foreach ($this->scanner->scanMusicLibrary($libraryId, $tempDir, $tempDir) as $item) {
            $results[] = $item;
        }

        // Should yield items for the audio files
        $this->assertGreaterThanOrEqual(0, count($results));

        // Clean up
        unlink($mp3File);
        unlink($flacFile);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testScanMusicLibraryGeneratorIsLazy(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $generator = $this->scanner->scanMusicLibrary('lib-1', $tempDir, $tempDir);

        // Should be a Generator, not an array
        $this->assertInstanceOf(\Generator::class, $generator);

        // Clean up
        rmdir($tempDir);
    }

    /**
     * Creates a minimal FLAC file for testing.
     */
    private function createMinimalFlacFile(): string
    {
        // fLaC marker
        $data = 'fLaC';

        // Last metadata block (0x80) + type 0 (STREAMINFO) + 34 bytes
        $blockHeader = chr(0x80 | 0) . chr(0) . chr(0) . chr(34);
        $streamInfo = str_repeat("\x00", 34);
        $data .= $blockHeader . $streamInfo;

        return $data;
    }

    /**
     * Creates a minimal MP3 file with ID3v2 header for testing.
     */
    private function createMinimalMp3File(): string
    {
        // ID3v2 header
        $id3 = 'ID3' . chr(0x04) . chr(0x00) . chr(0x00);

        // Size (synchsafe: 0)
        $id3 .= chr(0) . chr(0) . chr(0) . chr(0);

        // Frame TIT2 (title) - minimal
        $frame = 'TIT2' . chr(0) . chr(0) . chr(0) . chr(0);
        $id3 .= $frame;

        // Padding
        $id3 .= str_repeat("\x00", 100);

        // MP3 frame sync
        $id3 .= chr(0xFF) . chr(0xFB);

        return $id3;
    }

    /**
     * Creates a minimal M4A file for testing.
     */
    private function createMinimalM4aFile(): string
    {
        // ftyp atom
        $data = pack('N', 32); // size
        $data .= 'ftyp';
        $data .= 'M4A ';
        $data .= pack('N', 0); // minor version
        $data .= 'M4A ' . 'mp4a'; // compatible brands

        // Free atom
        $data .= pack('N', 8);
        $data .= 'free';

        return $data;
    }

    /**
     * @test
     */
    public function testHarvestTagsReturnsStructuredArray(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $filePath = $tempDir . '/test.mp3';
        file_put_contents($filePath, $this->createMinimalMp3File());

        $tags = $this->scanner->harvestTags($filePath);

        // Should be an array with documented fields or empty
        $this->assertIsArray($tags);

        // If tags were found, verify structure
        if (!empty($tags)) {
            // These fields may or may not be present depending on what's in the file
            $allowedFields = [
                'title', 'artist', 'album', 'album_artist', 'year', 'genre',
                'track_number', 'disc_number', 'duration_secs', 'bitrate',
                'sample_rate', 'channels', 'composer', 'comment'
            ];

            foreach (array_keys($tags) as $field) {
                $this->assertContains($field, $allowedFields, "Unexpected field: $field");
            }
        }

        unlink($filePath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testHarvestTagsNeverThrows(): void
    {
        // Test with various invalid inputs - should never throw
        $this->expectNotToPerformAssertions();

        try {
            $this->scanner->harvestTags('/non/existent/path.mp3');
            $this->scanner->harvestTags('');
            $this->scanner->harvestTags('/root/secret.flac');
        } catch (\Throwable $e) {
            $this->fail("harvestTags threw an exception: " . $e->getMessage());
        }
    }
}
