<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use InvalidArgumentException;
use Phlix\Console\Commands\LibraryScanCommand;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\LibraryScanCommand
 */
class LibraryScanCommandTest extends TestCase
{
    private function tester(LibraryManager $manager): CommandTester
    {
        $application = new Application();
        $application->add(new LibraryScanCommand(fn(): LibraryManager => $manager));

        return new CommandTester($application->find('library:scan'));
    }

    public function testScanCallsScanLibrary(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        // scanLibrary() returns a ScanResult since S96(b); ScanResult is final, so
        // PHPUnit cannot auto-generate a return value and the stub must be explicit.
        $manager->expects($this->once())->method('scanLibrary')->with('lib-1')
            ->willReturn(new ScanResult());
        $manager->expects($this->never())->method('rescanLibrary');

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'lib-1']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Scan of library "lib-1" complete.', $tester->getDisplay());
    }

    public function testRescanFlagCallsRescanLibrary(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->once())->method('rescanLibrary')->with('lib-2')
            ->willReturn(new ScanResult());
        $manager->expects($this->never())->method('scanLibrary');

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'lib-2', '--rescan' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Rescan of library "lib-2" complete.', $tester->getDisplay());
    }

    /**
     * Review r1 INFO-10: `failed` must be RENDERED somewhere, not merely reachable.
     *
     * S96(f) put the counter in `ScanResult::toArray()`, in `library_scan_jobs
     * .items_failed` and in the app log — but the admin SPA's `ScanJob` interface does
     * not list it, so the only operator-visible surface was `curl`/`grep`. This command
     * already had the whole `ScanResult` and discarded it.
     */
    public function testScanRendersTheCountersIncludingFailed(): void
    {
        $result = new ScanResult();
        $result->scanned = 10;
        $result->added = 7;
        $result->updated = 1;
        $result->removed = 0;
        $result->failed = 2;

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willReturn($result);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'lib-3']);
        $display = $tester->getDisplay();

        // A lossy scan is still a completed scan: the exit code must stay 0 (the next
        // clean scan re-adds the file), but it must not read as an unqualified success.
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('scanned: 10', $display);
        $this->assertStringContainsString('added: 7', $display);
        $this->assertStringContainsString('failed: 2', $display);
        $this->assertStringContainsString('2 file(s) could not be indexed', $display);
    }

    public function testCleanScanDoesNotWarnAboutFailures(): void
    {
        $result = new ScanResult();
        $result->scanned = 4;
        $result->added = 4;

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willReturn($result);

        $tester = $this->tester($manager);
        $tester->execute(['libraryId' => 'lib-4']);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('failed: 0', $display);
        $this->assertStringNotContainsString('could not be indexed', $display);
    }

    public function testUnknownLibraryExitsOne(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')
            ->willThrowException(new InvalidArgumentException('Library not found: missing'));

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'missing']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Scan failed: Library not found: missing', $tester->getDisplay());
    }
}
