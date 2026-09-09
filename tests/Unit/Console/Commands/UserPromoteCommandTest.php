<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserPromoteCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UserPromoteCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserPromoteCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:promote'));
    }

    public function testPromotesWithoutJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')
            ->with('alice')
            ->willReturn(['id' => 'user-1', 'username' => 'alice', 'is_admin' => 0]);
        $repository->expects($this->never())->method('findByEmail');
        $repository->expects($this->never())->method('isLastAdmin');
        $repository->expects($this->once())
            ->method('setAdmin')
            ->with('user-1', true);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('promoted to admin', $tester->getDisplay());
    }

    public function testPromotesWithJsonEmitsFlag(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'alice']);
        $repository->method('setAdmin');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertTrue($decoded['ok']);
        $this->assertTrue($decoded['data'][0]['is_admin']);
    }

    public function testResolvesByEmailWhenUsernameMisses(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(null);
        $repository->method('findByEmail')
            ->with('alice@example.com')
            ->willReturn(['id' => 'user-2', 'username' => 'alice']);
        $repository->expects($this->once())->method('setAdmin')->with('user-2', true);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice@example.com']);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function testRevokeDemotesWhenNotLastAdmin(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'alice', 'is_admin' => 1]);
        $repository->method('isLastAdmin')->willReturn(false);
        $repository->expects($this->once())->method('setAdmin')->with('user-1', false);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--revoke' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('demoted from admin', $tester->getDisplay());
    }

    /**
     * AC: the last-admin guard rejects demoting the sole admin (human mode).
     */
    public function testRevokeRefusesLastAdminWithoutJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'root', 'is_admin' => 1]);
        $repository->method('isLastAdmin')->willReturn(true);
        $repository->expects($this->never())->method('setAdmin');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'root', '--revoke' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Cannot demote the last admin', $tester->getDisplay());
    }

    /**
     * AC: the same rejection in JSON mode.
     */
    public function testRevokeRefusesLastAdminWithJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'root', 'is_admin' => 1]);
        $repository->method('isLastAdmin')->willReturn(true);
        $repository->expects($this->never())->method('setAdmin');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'root', '--revoke' => true, '--json' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertFalse($decoded['ok']);
        $this->assertStringContainsString('last admin', $decoded['error']);
    }

    public function testNotFoundExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(null);
        $repository->method('findByEmail')->willReturn(null);
        $repository->expects($this->never())->method('setAdmin');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'ghost']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('User not found: ghost', $tester->getDisplay());
    }
}
