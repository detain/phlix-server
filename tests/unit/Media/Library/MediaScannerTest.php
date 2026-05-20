<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ItemRepository;
use Phlix\Common\Logger\LoggerFactory;
use Workerman\MySQL\Connection;

class MediaScannerTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    public function testCanCreateMediaScanner(): void
    {
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $this->createMock(ItemRepository::class)
        );

        $this->assertInstanceOf(MediaScanner::class, $scanner);
    }
}