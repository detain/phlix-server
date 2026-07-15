<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Common\Logger\LoggerFactory;
use Workerman\MySQL\Connection;

class LibraryManagerTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    public function testCanCreateLibraryManager(): void
    {
        $db = $this->createMock(Connection::class);
        $scanner = $this->createMock(MediaScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        $musicScanner = $this->createMock(MusicLibraryScanner::class);
        $musicLibraryService = $this->createMock(MusicLibraryService::class);

        $manager = new LibraryManager($db, $scanner, $watcher, $musicLibraryService);

        $this->assertInstanceOf(LibraryManager::class, $manager);
    }
}