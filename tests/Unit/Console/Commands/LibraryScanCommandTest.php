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
        $exitCode = $tester->execute(['libraryId' => 'lib-3'], ['capture_stderr_separately' => true]);

        // Review r2 F7: a lossy scan must be visible to a NON-HUMAN caller too. It used to
        // print to stdout behind exit 0, so a cron/CI wrapper inspecting the exit status or
        // stderr saw an unqualified success while the library was missing files.
        $this->assertSame(
            2,
            $exitCode,
            'a scan that lost files must exit non-zero (2 = completed-with-loss, distinct from 1 = '
            . 'did-not-run), so cron/CI notices'
        );
        $this->assertStringContainsString('scanned: 10', $tester->getDisplay());
        $this->assertStringContainsString('added: 7', $tester->getDisplay());
        $this->assertStringContainsString('failed: 2', $tester->getDisplay());
        $this->assertStringContainsString(
            '2 file(s) could not be indexed',
            $tester->getErrorOutput(),
            'the warning belongs on STDERR — a wrapper that only reads stderr must still see it'
        );
        $this->assertStringNotContainsString(
            'could not be indexed',
            $tester->getDisplay(),
            'and it must not ALSO go to stdout, or piping stdout into a parser picks up prose'
        );
    }

    /**
     * The lossy exit code must not be the same as the did-not-run one, or a wrapper
     * cannot tell "fix your config" from "your library just lost files".
     */
    public function testTheLossyExitCodeIsDistinctFromTheFailureExitCode(): void
    {
        $lossy = new ScanResult();
        $lossy->scanned = 1;
        $lossy->failed = 1;

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willReturn($lossy);
        $lossyCode = $this->tester($manager)->execute(['libraryId' => 'lib-5']);

        $throwing = $this->createMock(LibraryManager::class);
        $throwing->method('scanLibrary')->willThrowException(new InvalidArgumentException('nope'));
        $failureCode = $this->tester($throwing)->execute(['libraryId' => 'lib-6']);

        $this->assertSame(Command::FAILURE, $failureCode);
        $this->assertNotSame($failureCode, $lossyCode);
        $this->assertNotSame(Command::SUCCESS, $lossyCode);
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
