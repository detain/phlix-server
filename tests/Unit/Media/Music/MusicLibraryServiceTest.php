<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Library\ScanResult;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MusicLibraryService}, focused on the scan/progress
 * forwarding added for the music-scan-hang fix.
 *
 * @covers \Phlix\Media\Music\MusicLibraryService
 */
final class MusicLibraryServiceTest extends TestCase
{
    public function testScanDirectoryForwardsPathAndProgressSinkToScanner(): void
    {
        $sink = static function (int $p, int $t, string $path): void {
            unset($p, $t, $path);
        };
        $expected = new ScanResult();

        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('scanDirectory')
            ->with('/music/rock', $this->identicalTo($sink))
            ->willReturn($expected);

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);

        $this->assertSame($expected, $service->scanDirectory('/music/rock', $sink));
    }

    public function testScanDirectoryForwardsNullSinkByDefault(): void
    {
        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('scanDirectory')
            ->with('/music/jazz', null)
            ->willReturn(new ScanResult());

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);
        $service->scanDirectory('/music/jazz');
    }

    public function testCountFilesDelegatesToScanner(): void
    {
        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('countAudioFiles')
            ->with('/music/rock')
            ->willReturn(42);

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);
        $this->assertSame(42, $service->countFiles('/music/rock'));
    }
}
