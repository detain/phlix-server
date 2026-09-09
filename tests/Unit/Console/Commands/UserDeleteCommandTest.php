<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserDeleteCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UserDeleteCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserDeleteCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:delete'));
    }

    public function testWithoutForceIsRefusedAsConfirmation(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'alice', 'is_admin' => 0]);
        $repository->method('isLastAdmin')->willReturn(false);
        $repository->expects($this->never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('re-run with --force to confirm', $tester->getDisplay());
    }

    public function testDeleteWithForceSucceedsPlain(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'alice', 'is_admin' => 0]);
        $repository->method('isLastAdmin')->willReturn(false);
        $repository->expects($this->once())->method('delete')->with('user-1');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Deleted user "alice"', $tester->getDisplay());
    }

    public function testDeleteWithForceSucceedsJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'alice', 'is_admin' => 0]);
        $repository->method('isLastAdmin')->willReturn(false);
        $repository->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--force' => true, '--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertTrue($decoded['ok']);
        $this->assertTrue($decoded['data'][0]['deleted']);
    }

    /**
     * AC + R5: deleting the SOLE admin is rejected.
     */
    public function testRefusesToDeleteLastAdminWithoutForce(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'root', 'username' => 'root', 'is_admin' => 1]);
        $repository->method('isLastAdmin')->willReturn(true);
        $repository->expects($this->never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'root']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Cannot delete the last admin', $tester->getDisplay());
    }

    /**
     * R5: --force is a confirmation bypass, NEVER a guard bypass — the last
     * admin is still refused with --force present.
     */
    public function testForceDoesNotBypassLastAdminGuard(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'root', 'username' => 'root', 'is_admin' => 1]);
        $repository->method('isLastAdmin')->willReturn(true);
        $repository->expects($this->never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'root', '--force' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Cannot delete the last admin', $tester->getDisplay());
    }

    /**
     * R5: same absolute rejection, and it takes precedence over the confirmation
     * message even in JSON mode.
     */
    public function testForceWithJsonStillRefusesLastAdmin(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'root', 'username' => 'root', 'is_admin' => 1]);
        $repository->method('isLastAdmin')->willReturn(true);
        $repository->expects($this->never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'root', '--force' => true, '--json' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertFalse($decoded['ok']);
        $this->assertStringContainsString('Cannot delete the last admin', $decoded['error']);
    }

    public function testNotFoundExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(null);
        $repository->method('findByEmail')->willReturn(null);
        $repository->expects($this->never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'ghost', '--force' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('User not found: ghost', $tester->getDisplay());
    }
}
