<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserApproveCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UserApproveCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserApproveCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:approve'));
    }

    public function testApprovesWithoutJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn([
            'id' => 'user-1',
            'username' => 'alice',
            'status' => 'pending',
        ]);
        $repository->expects($this->once())->method('setStatus')->with('user-1', 'active');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Approved user "alice"', $tester->getDisplay());
    }

    public function testApprovesWithJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(['id' => 'user-1', 'username' => 'alice']);
        $repository->method('setStatus');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'alice', '--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('active', $decoded['data'][0]['status']);
    }

    public function testNotFoundExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn(null);
        $repository->method('findByEmail')->willReturn(null);
        $repository->expects($this->never())->method('setStatus');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['user' => 'ghost']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('User not found: ghost', $tester->getDisplay());
    }
}
