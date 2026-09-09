<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserRejectCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UserRejectCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserRejectCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:reject'));
    }

    public function testRejectsPendingUserWithoutJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'user-1',
            'username' => 'bob',
            'status' => 'pending',
        ]);
        $repository->method('isLastAdmin')->willReturn(false);
        $repository->expects($this->once())->method('delete')->with('user-1');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'bob']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Rejected (deleted) user "bob"', $tester->getDisplay());
    }

    public function testRejectsPendingUserWithJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'user-1',
            'username' => 'bob',
            'status' => 'pending',
        ]);
        $repository->method('isLastAdmin')->willReturn(false);
        $repository->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'bob', '--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertTrue($decoded['ok']);
        $this->assertTrue($decoded['data'][0]['rejected']);
    }

    public function testRefusesNonPendingUser(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'user-1',
            'username' => 'bob',
            'status' => 'active',
        ]);
        $repository->expects($this->never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'bob']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Only pending users can be rejected', $tester->getDisplay());
    }

    /**
     * A pending row that is nonetheless the sole admin is still protected by the
     * absolute guard (delete path).
     */
    public function testRefusesToDeleteLastAdminEvenWhenPending(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'root',
            'username' => 'root',
            'status' => 'pending',
            'is_admin' => 1,
        ]);
        $repository->method('isLastAdmin')->willReturn(true);
        $repository->expects($this->never())->method('delete');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'root']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Cannot delete the last admin', $tester->getDisplay());
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
