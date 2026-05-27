<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserResetPasswordCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\UserResetPasswordCommand
 */
class UserResetPasswordCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserResetPasswordCommand(fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:reset-password'));
    }

    public function testFoundByUsernameWithExplicitPassword(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findByUsername')
            ->with('alice')
            ->willReturn(['id' => 'user-1', 'username' => 'alice']);
        $repository->expects($this->never())->method('findByEmail');
        $repository->expects($this->once())
            ->method('update')
            ->with('user-1', ['password' => 'Sup3rSecret!']);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--password' => 'Sup3rSecret!']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Password reset for user "alice".', $display);
        // Explicit password must NOT be echoed back.
        $this->assertStringNotContainsString('Generated password:', $display);
    }

    public function testFoundByUsernameGeneratesPassword(): void
    {
        $captured = null;

        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1']);
        $repository->expects($this->once())
            ->method('update')
            ->with(
                'user-1',
                $this->callback(static function (array $data) use (&$captured): bool {
                    $captured = $data['password'] ?? null;

                    return is_string($captured) && $captured !== '';
                })
            );

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Generated password: ', $display);
        // The generated password printed must be the same one passed to update().
        $this->assertIsString($captured);
        $this->assertStringContainsString('Generated password: ' . $captured, $display);
    }

    public function testFoundByEmailFallback(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findByUsername')
            ->with('alice@example.com')
            ->willReturn(null);
        $repository->expects($this->once())
            ->method('findByEmail')
            ->with('alice@example.com')
            ->willReturn(['id' => 'user-7']);
        $repository->expects($this->once())
            ->method('update')
            ->with('user-7', ['password' => 'hunter2hunter2']);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice@example.com', '--password' => 'hunter2hunter2']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Password reset for user "alice@example.com".', $tester->getDisplay());
    }

    public function testNotFoundExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(null);
        $repository->method('findByEmail')->willReturn(null);
        $repository->expects($this->never())->method('update');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'ghost']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('User not found: ghost', $tester->getDisplay());
    }

    public function testRowMissingIdExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['username' => 'alice']);
        $repository->expects($this->never())->method('update');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('missing a valid id', $tester->getDisplay());
    }

    public function testRepositoryExceptionExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1']);
        $repository->method('update')
            ->willThrowException(new \RuntimeException('database is down'));

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--password' => 'Sup3rSecret!']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Password reset failed: database is down', $tester->getDisplay());
    }
}
