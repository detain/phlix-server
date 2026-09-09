<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserListCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UserListCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserListCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:list'));
    }

    public function testListsAllUsersWithoutJsonAndRedactsSecrets(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findAll')
            ->willReturn([[
                'id' => 'user-1',
                'username' => 'alice',
                'email' => 'alice@example.com',
                'status' => 'active',
                'is_admin' => 1,
                'password_hash' => 'super-secret-hash',
                'password_reset_token' => 'reset-secret',
            ]]);
        $repository->expects($this->never())->method('listByStatus');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('alice', $display);
        $this->assertStringContainsString('alice@example.com', $display);
        // SELECT * rows must never leak their hashed secrets to the terminal.
        $this->assertStringNotContainsString('super-secret-hash', $display);
        $this->assertStringNotContainsString('reset-secret', $display);
    }

    public function testJsonSuccessEmitsEnvelopeWithoutSecrets(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willReturn([[
            'id' => 'user-1',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password_hash' => 'super-secret-hash',
        ]]);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('alice', $decoded['data'][0]['username']);
        // The redaction happens before encoding, so the key is simply absent.
        $this->assertArrayNotHasKey('password_hash', $decoded['data'][0]);
    }

    public function testEmptyResultWithoutJsonPrintsNotice(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willReturn([]);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No users found.', $tester->getDisplay());
    }

    public function testEmptyResultWithJsonEmitsEmptyDataArray(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willReturn([]);

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('"data":[]', trim($tester->getDisplay()));
    }

    public function testValidStatusFilterQueriesListByStatus(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('listByStatus')
            ->with('pending')
            ->willReturn([['id' => 'user-9', 'username' => 'bob']]);
        $repository->expects($this->never())->method('findAll');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['--status' => 'pending']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('bob', $tester->getDisplay());
    }

    public function testInvalidStatusExitsInvalidBothModes(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->never())->method('findAll');
        $repository->expects($this->never())->method('listByStatus');

        $plain = $this->tester($repository);
        $this->assertSame(Command::INVALID, $plain->execute(['--status' => 'bogus']));
        $this->assertStringContainsString('Invalid status "bogus"', $plain->getDisplay());

        $json = $this->tester($repository);
        $this->assertSame(Command::INVALID, $json->execute(['--status' => 'bogus', '--json' => true]));
        $decoded = json_decode(trim($json->getDisplay()), true);
        $this->assertFalse($decoded['ok']);
        $this->assertStringContainsString('Invalid status', $decoded['error']);
    }

    public function testRepositoryFailureExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willThrowException(new RuntimeException('db down'));

        $tester = $this->tester($repository);
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertFalse($decoded['ok']);
        $this->assertStringContainsString('db down', $decoded['error']);
    }
}
