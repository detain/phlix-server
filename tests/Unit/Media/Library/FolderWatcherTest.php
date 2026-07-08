<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Common\Logger\LoggerFactory;

class FolderWatcherTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    public function testCanCreateFolderWatcher(): void
    {
        $watcher = new FolderWatcher();

        $this->assertInstanceOf(FolderWatcher::class, $watcher);
    }

    public function testWatchedPathsStartsEmpty(): void
    {
        $watcher = new FolderWatcher();

        $this->assertEmpty($watcher->getWatchedPaths());
    }

    public function testCanSetCheckInterval(): void
    {
        $watcher = new FolderWatcher();
        $watcher->setCheckInterval(60);

        // The interval setter persists the configured value.
        $this->assertSame(60, $watcher->getCheckInterval());
    }
}