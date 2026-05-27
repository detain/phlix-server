<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use InvalidArgumentException;
use Phlix\Console\Commands\LibraryScanCommand;
use Phlix\Media\Library\LibraryManager;
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
        $manager->expects($this->once())->method('scanLibrary')->with('lib-1');
        $manager->expects($this->never())->method('rescanLibrary');

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'lib-1']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Scan of library "lib-1" complete.', $tester->getDisplay());
    }

    public function testRescanFlagCallsRescanLibrary(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->once())->method('rescanLibrary')->with('lib-2');
        $manager->expects($this->never())->method('scanLibrary');

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'lib-2', '--rescan' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Rescan of library "lib-2" complete.', $tester->getDisplay());
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
