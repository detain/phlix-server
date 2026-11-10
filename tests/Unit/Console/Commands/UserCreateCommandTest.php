<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserCreateCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

class UserCreateCommandTest extends TestCase
{
    private function tester(UserRepository $repository): CommandTester
    {
        $application = new Application();
        $application->add(new UserCreateCommand(static fn(): UserRepository => $repository));

        return new CommandTester($application->find('user:create'));
    }

    public function testCreatesWithoutJson(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->willReturn(false);
        $repository->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn(array $d): bool => $d['username'] === 'bob'
                && $d['email'] === 'bob@example.com'
                && $d['password'] === 'Passw0rd!'))
            ->willReturn('new-id-1');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'bob',
            '--email' => 'bob@example.com',
            '--password' => 'Passw0rd!',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Created user "bob"', $display);
        $this->assertStringContainsString('new-id-1', $display);
    }

    public function testCreatesWithJsonEmitsEnvelope(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->willReturn(false);
        $repository->method('create')->willReturn('new-id-2');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'carol',
            '--email' => 'carol@example.com',
            '--password' => 'Passw0rd!',
            '--display-name' => 'Carol C',
            '--json' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('new-id-2', $decoded['data'][0]['id']);
        $this->assertSame('Carol C', $decoded['data'][0]['display_name']);
        // No password/hash material is ever echoed.
        $this->assertStringNotContainsString('Passw0rd!', $tester->getDisplay());
    }

    public function testShortUsernameIsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'ab',
            '--email' => 'x@example.com',
            '--password' => 'Passw0rd!',
        ]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('3-50 characters', $tester->getDisplay());
    }

    public function testInvalidEmailIsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'validname',
            '--email' => 'not-an-email',
            '--password' => 'Passw0rd!',
        ]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Invalid email format', $tester->getDisplay());
    }

    public function testEmptyPasswordIsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'validname',
            '--email' => 'ok@example.com',
            '--password' => '',
        ]);

        $this->assertSame(Command::INVALID, $exitCode);
        // SH-032 era: a password may arrive via --password= or --password-file=;
        // supplying neither (or an empty one) names both affordances in the copy.
        $this->assertStringContainsString('A password is required', $tester->getDisplay());
    }

    public function testDuplicateUsernameIsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->with('taken')->willReturn(true);
        $repository->expects($this->never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'taken',
            '--email' => 'ok@example.com',
            '--password' => 'Passw0rd!',
            '--json' => true,
        ]);

        $this->assertSame(Command::INVALID, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertFalse($decoded['ok']);
        $this->assertStringContainsString('Username already exists', $decoded['error']);
    }

    public function testDuplicateEmailIsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->with('dupe@example.com')->willReturn(true);
        $repository->expects($this->never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'freshname',
            '--email' => 'dupe@example.com',
            '--password' => 'Passw0rd!',
        ]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('That email address is already in use', $tester->getDisplay());
    }

    public function testRepositoryExceptionExitsOne(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->willReturn(false);
        $repository->method('create')->willThrowException(new RuntimeException('insert blew up'));

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'validname',
            '--email' => 'ok@example.com',
            '--password' => 'Passw0rd!',
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertFalse($decoded['ok']);
        $this->assertStringContainsString('insert blew up', $decoded['error']);
    }

    // -----------------------------------------------------------------
    // SH-032: --password-file keeps the secret off argv (shell history
    // and /proc/<pid>/cmdline are world-readable on shared hosts).
    // -----------------------------------------------------------------

    public function testPasswordFileIsReadAndTrimmedOfTrailingNewlines(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phlix-pwfile-');
        self::assertIsString($path);
        file_put_contents($path, "s3cret-from-file\r\n");

        try {
            $repository = $this->createMock(UserRepository::class);
            $repository->method('usernameExists')->willReturn(false);
            $repository->method('emailExists')->willReturn(false);
            $repository->expects($this->once())
                ->method('create')
                ->with($this->callback(static fn(array $d): bool => $d['password'] === 's3cret-from-file'))
                ->willReturn('new-id-pf');

            $tester = $this->tester($repository);
            $exitCode = $tester->execute([
                'username' => 'dave',
                '--email' => 'dave@example.com',
                '--password-file' => $path,
            ]);

            $this->assertSame(Command::SUCCESS, $exitCode);
            $this->assertStringContainsString('Created user "dave"', $tester->getDisplay());
            // The secret never echoes back.
            $this->assertStringNotContainsString('s3cret-from-file', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    public function testPasswordFileDashReadsStdinStream(): void
    {
        // CommandTester builds its own ArrayInput internally and cannot
        // pre-seed the input stream, so this leg drives the command directly
        // with a StreamableInputInterface (the real bin/phlix posture: stdin).
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "stream-s3cret\n");
        rewind($stream);

        $repository = $this->createMock(UserRepository::class);
        $repository->method('usernameExists')->willReturn(false);
        $repository->method('emailExists')->willReturn(false);
        $repository->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn(array $d): bool => $d['password'] === 'stream-s3cret'))
            ->willReturn('new-id-stdin');

        $application = new Application();
        $application->add(new UserCreateCommand(static fn(): UserRepository => $repository));
        $input = new ArrayInput([
            'username' => 'erin',
            '--email' => 'erin@example.com',
            '--password-file' => '-',
        ]);
        $input->setStream($stream);

        $exitCode = $application->find('user:create')->run($input, new BufferedOutput());

        $this->assertSame(Command::SUCCESS, $exitCode);
        fclose($stream);
    }

    public function testExplicitPasswordWinsOverPasswordFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phlix-pwfile-');
        self::assertIsString($path);
        file_put_contents($path, "ignored-from-file\n");

        try {
            $repository = $this->createMock(UserRepository::class);
            $repository->method('usernameExists')->willReturn(false);
            $repository->method('emailExists')->willReturn(false);
            $repository->expects($this->once())
                ->method('create')
                ->with($this->callback(static fn(array $d): bool => $d['password'] === 'Passw0rd!'))
                ->willReturn('new-id-explicit');

            $tester = $this->tester($repository);
            $exitCode = $tester->execute([
                'username' => 'frank',
                '--email' => 'frank@example.com',
                '--password' => 'Passw0rd!',
                '--password-file' => $path,
            ]);

            $this->assertSame(Command::SUCCESS, $exitCode);
        } finally {
            @unlink($path);
        }
    }

    public function testUnreadablePasswordFileIsInvalid(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->never())->method('create');

        $tester = $this->tester($repository);
        $exitCode = $tester->execute([
            'username' => 'grace',
            '--email' => 'grace@example.com',
            '--password-file' => '/definitely/not/a/real/phlix-password-file',
        ]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Cannot read --password-file', $tester->getDisplay());
    }
}
