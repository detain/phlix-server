<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Console\Commands\LibraryListCommand;
use Phlix\Media\Library\LibraryManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\LibraryListCommand
 */
class LibraryListCommandTest extends TestCase
{
    private function tester(LibraryManager $manager): CommandTester
    {
        $application = new Application();
        $application->add(new LibraryListCommand(fn(): LibraryManager => $manager));

        return new CommandTester($application->find('library:list'));
    }

    public function testListsLibrariesAsTable(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->once())
            ->method('getAllLibraries')
            ->willReturn([
                ['id' => 'lib-1', 'name' => 'Movies', 'type' => 'video', 'paths' => ['/mnt/movies']],
                ['id' => 'lib-2', 'name' => 'Shows', 'type' => 'video', 'paths' => ['/mnt/shows', '/mnt/extra']],
            ]);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('lib-1', $display);
        $this->assertStringContainsString('Movies', $display);
        $this->assertStringContainsString('/mnt/movies', $display);
        $this->assertStringContainsString('lib-2', $display);
        $this->assertStringContainsString('/mnt/shows', $display);
    }

    public function testHandlesMissingPathsAndNonScalarFields(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->method('getAllLibraries')->willReturn([
            // No 'paths' key (→ formatPaths sees null), nested array under a
            // 'paths' entry (non-string entries skipped), missing name/type.
            ['id' => 'lib-x'],
            ['id' => 'lib-y', 'name' => 'Mixed', 'paths' => ['/ok', ['nested'], 42]],
        ]);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('lib-x', $display);
        $this->assertStringContainsString('/ok', $display);
    }

    public function testEmptyListPrintsMessage(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->method('getAllLibraries')->willReturn([]);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No libraries configured.', $tester->getDisplay());
    }

    public function testManagerThrowsExitsOne(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->method('getAllLibraries')->willThrowException(new RuntimeException('db down'));

        $tester = $this->tester($manager);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Failed to list libraries: db down', $tester->getDisplay());
    }
}
