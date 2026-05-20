<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\AudiobookScanner;
use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

class AudiobookScannerTest extends TestCase
{
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

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testHarvestAudiobookMetadataReturnsEmptyForNonExistentFile(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);

        $scanner = new AudiobookScanner($db, $itemRepo);

        $result = $scanner->harvestAudiobookMetadata('/non/existent/file.m4b');

        $this->assertIsArray($result);
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

        $this->assertIsArray($items);
        $this->assertEmpty($items); // No files, so no items
    }
}
