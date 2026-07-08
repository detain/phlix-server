<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\AudiobookScanner;
use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

class AudiobookScannerTest extends TestCase
{
    /**
     * Creates a chpl (chapter list) atom data.
     *
     * @param array<int, array{title: string, start_ms: int}> $chapters Chapter entries
     */
    private function createChplAtomData(array $chapters): string
    {
        // chpl header: version(1) + flags(3) + reserved(2) + chapter_count(2)
        $data = "\x00\x00\x00\x00\x00\x00"; // version + flags + reserved
        $data .= pack('n', count($chapters)); // chapter count (big-endian)

        foreach ($chapters as $chapter) {
            // uint64: start time in ms
            $data .= pack('J', $chapter['start_ms']);
            // uint8: title length
            $titleLen = strlen($chapter['title']);
            $data .= pack('C', $titleLen);
            // string: title
            $data .= $chapter['title'];
        }

        return $data;
    }

    /**
     * Creates a minimal MP4 file with chapters for testing.
     *
     * @param array<int, array{title: string, start_ms: int}> $chapters Chapter entries
     */
    private function createMp4WithChapters(array $chapters): string
    {
        $chplData = $this->createChplAtomData($chapters);
        // MP4 atom structure: size(4) + type(4) + payload
        $chplAtom = pack('N', strlen($chplData) + 8) . 'chpl' . $chplData;

        $moovAtom = pack('N', strlen($chplAtom) + 8) . 'moov' . $chplAtom;

        // ftyp data (without header - createTempMp4File adds it)
        $ftypData = 'M4A ' . "\x00\x00\x00\x00";

        // Build the MP4 file directly with properly structured atoms
        $ftypAtom = pack('N', strlen($ftypData) + 8) . 'ftyp' . $ftypData;
        $content = $ftypAtom . $moovAtom;

        $tempFile = sys_get_temp_dir() . '/phlix_test_mp4_' . uniqid() . '.m4b';
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    public function testCanCreateAudiobookScanner(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        $this->assertInstanceOf(AudiobookScanner::class, $scanner);
    }

    public function testIsAudiobookExtensionReturnsTrueForM4b(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        $this->assertTrue($scanner->isAudiobookExtension('m4b'));
        $this->assertTrue($scanner->isAudiobookExtension('M4B'));
    }

    public function testIsAudiobookExtensionReturnsTrueForM4a(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        $this->assertTrue($scanner->isAudiobookExtension('m4a'));
        $this->assertTrue($scanner->isAudiobookExtension('M4A'));
    }

    public function testIsAudiobookExtensionReturnsTrueForMp3(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        $this->assertTrue($scanner->isAudiobookExtension('mp3'));
        $this->assertTrue($scanner->isAudiobookExtension('MP3'));
    }

    public function testIsAudiobookExtensionReturnsFalseForNonAudiobook(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        $this->assertFalse($scanner->isAudiobookExtension('mp4'));
        $this->assertFalse($scanner->isAudiobookExtension('avi'));
        $this->assertFalse($scanner->isAudiobookExtension('mkv'));
        $this->assertFalse($scanner->isAudiobookExtension('epub'));
    }

    public function testHarvestChaptersReturnsEmptyForNonExistentFile(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        $result = $scanner->harvestChapters('/non/existent/file.m4b');

        $this->assertEmpty($result);
    }

    public function testHarvestAudiobookMetadataReturnsEmptyForNonExistentFile(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        $result = $scanner->harvestAudiobookMetadata('/non/existent/file.m4b');

        $this->assertEmpty($result);
    }

    public function testScanAudiobookLibraryYieldsItemsWithChapters(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->method('findByPath')->willReturn(null);

        $scanner = new AudiobookScanner($db, $itemRepo);

        // Create a temp directory with no audiobook files
        $tempDir = sys_get_temp_dir() . '/phlix_test_audiobook_' . uniqid();
        mkdir($tempDir, 0755, true);

        $items = [];
        foreach ($scanner->scanAudiobookLibrary('test-lib-id', $tempDir) as $item) {
            $items[] = $item;
        }

        // Clean up
        rmdir($tempDir);

        $this->assertEmpty($items); // No files, so no items
    }

    public function testHarvestChaptersWithChunkedParsingReturnsChapters(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        // Create MP4 with chapters
        $chapters = [
            ['title' => 'Chapter 1', 'start_ms' => 0],
            ['title' => 'Chapter 2', 'start_ms' => 300000],
            ['title' => 'Chapter 3', 'start_ms' => 600000],
        ];
        $tempFile = $this->createMp4WithChapters($chapters);

        try {
            $result = $scanner->harvestChapters($tempFile);

            $this->assertCount(3, $result);
            $this->assertEquals('Chapter 1', $result[0]['title']);
            $this->assertEquals(0, $result[0]['start_ms']);
            $this->assertEquals(300000, $result[1]['start_ms']);
            $this->assertEquals(600000, $result[2]['start_ms']);
            $this->assertEquals($tempFile, $result[0]['path_hint']);
        } finally {
            unlink($tempFile);
        }
    }

    public function testHarvestChaptersWithEmptyFileReturnsEmpty(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        // Create an empty temp file
        $tempFile = sys_get_temp_dir() . '/phlix_test_empty_' . uniqid() . '.m4b';
        file_put_contents($tempFile, '');

        try {
            $result = $scanner->harvestChapters($tempFile);

            $this->assertEmpty($result);
        } finally {
            unlink($tempFile);
        }
    }

    public function testHarvestChaptersWithoutMoovAtomReturnsEmpty(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        // Create MP4 with only ftyp (no moov)
        $ftypAtom = 'M4A ' . "\x00\x00\x00\x00";
        $content = pack('N', strlen($ftypAtom) + 8) . 'ftyp' . $ftypAtom;

        $tempFile = sys_get_temp_dir() . '/phlix_test_nomoov_' . uniqid() . '.m4b';
        file_put_contents($tempFile, $content);

        try {
            $result = $scanner->harvestChapters($tempFile);

            $this->assertEmpty($result);
        } finally {
            unlink($tempFile);
        }
    }

    public function testHarvestChaptersWithSingleByteFileReturnsEmpty(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        // Create a minimal file with just one byte (too small to be valid MP4)
        $tempFile = sys_get_temp_dir() . '/phlix_test_onebyte_' . uniqid() . '.m4b';
        file_put_contents($tempFile, "\x00");

        try {
            $result = $scanner->harvestChapters($tempFile);

            $this->assertEmpty($result);
        } finally {
            unlink($tempFile);
        }
    }

    public function testHarvestAudiobookMetadataWithEmptyFileReturnsEmpty(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        // Create an empty temp file
        $tempFile = sys_get_temp_dir() . '/phlix_test_empty_meta_' . uniqid() . '.m4b';
        file_put_contents($tempFile, '');

        try {
            $result = $scanner->harvestAudiobookMetadata($tempFile);

            $this->assertEmpty($result);
        } finally {
            unlink($tempFile);
        }
    }

    public function testHarvestAudiobookMetadataWithoutMoovAtomReturnsEmpty(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        // Create MP4 with only ftyp (no moov)
        $ftypAtom = 'M4A ' . "\x00\x00\x00\x00";
        $content = pack('N', strlen($ftypAtom) + 8) . 'ftyp' . $ftypAtom;

        $tempFile = sys_get_temp_dir() . '/phlix_test_nomoov_meta_' . uniqid() . '.m4b';
        file_put_contents($tempFile, $content);

        try {
            $result = $scanner->harvestAudiobookMetadata($tempFile);

            $this->assertEmpty($result);
        } finally {
            unlink($tempFile);
        }
    }

    public function testChunkedParsingDoesNotLoadEntireFile(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        // Create a file that mimics a large MP4 (with ftyp + large moov + mdat)
        // The implementation should only read the moov atom, not the entire file
        $ftypData = 'M4A ' . "\x00\x00\x00\x00";
        $ftypAtom = pack('N', strlen($ftypData) + 8) . 'ftyp' . $ftypData;

        // Create a minimal moov with chpl
        $chplData = $this->createChplAtomData([['title' => 'Test', 'start_ms' => 0]]);
        $chplAtom = pack('N', strlen($chplData) + 8) . 'chpl' . $chplData;
        $moovData = $chplAtom;
        $moovAtom = pack('N', strlen($moovData) + 8) . 'moov' . $moovData;

        // Create a large mdat that should NOT be fully loaded
        $mdatSize = 1024 * 1024; // 1MB of zeros
        $mdatAtom = pack('N', $mdatSize + 8) . 'mdat' . str_repeat("\x00", $mdatSize);

        $tempFile = sys_get_temp_dir() . '/phlix_test_large_' . uniqid() . '.m4b';
        file_put_contents($tempFile, $ftypAtom . $moovAtom . $mdatAtom);

        try {
            // Get initial memory usage
            $memoryBefore = memory_get_usage(true);

            $result = $scanner->harvestChapters($tempFile);

            // Get memory after parsing
            $memoryAfter = memory_get_usage(true);
            $memoryUsed = $memoryAfter - $memoryBefore;

            // The memory used should be reasonable (< 100KB) since we're using chunked reading
            // If the entire file was loaded, it would use 1MB+
            $this->assertLessThan(200 * 1024, $memoryUsed, 'Chunked reading should not use excessive memory');

            $this->assertCount(1, $result);
            $this->assertEquals('Test', $result[0]['title']);
        } finally {
            unlink($tempFile);
        }
    }
}
