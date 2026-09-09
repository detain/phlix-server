<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserDisableCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UserDisableCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserDisableCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:disable'));
    }

    public function testDisablesNotLastAdminWithoutJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'alice', 'is_admin' => 0]);
        $repository->method('isLastAdmin')->willReturn(false);
        $repository->expects($this->once())->method('setStatus')->with('user-1', 'disabled');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Disabled user "alice"', $tester->getDisplay());
    }

    public function testDisablesWithJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'alice', 'is_admin' => 1]);
        $repository->method('isLastAdmin')->willReturn(false);
        $repository->method('setStatus');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('disabled', $decoded['data'][0]['status']);
    }

    /**
     * AC: disabling the sole admin is rejected and no status write occurs.
     */
    public function testRefusesToDisableLastAdmin(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'root', 'username' => 'root', 'is_admin' => 1]);
        $repository->method('isLastAdmin')->willReturn(true);
        $repository->expects($this->never())->method('setStatus');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'root']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Cannot disable the last admin', $tester->getDisplay());
    }

    public function testNotFoundExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(null);
        $repository->method('findByEmail')->willReturn(null);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'ghost']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('User not found: ghost', $tester->getDisplay());
    }
}
