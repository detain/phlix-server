<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\UserApproveCommand;
use Phlix\Console\Commands\UserCreateCommand;
use Phlix\Console\Commands\UserDeleteCommand;
use Phlix\Console\Commands\UserDisableCommand;
use Phlix\Console\Commands\UserListCommand;
use Phlix\Console\Commands\UserPromoteCommand;
use Phlix\Console\Commands\UserRejectCommand;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A-06 characterization net for the seven server `user:*` commands whose
 * hand-rolled failure renders (six private `fail()` clones plus two inline
 * arms in `user:list`) were folded into the JsonOutput trait.
 *
 * Every case pins the exit code AND the complete display bytes — exact
 * equality, never `assertStringContainsString` — captured from the
 * pre-adoption implementation (SRV-CONSOLE lane, fixtures
 * SRVCONSOLE-02-matrix-PREbaseline.log). The fold ran green against this
 * file's literals before it happened and again after, unchanged: that
 * same-literals green on both sides of the fold IS the byte-identity proof.
 * Any drift in wording, envelope key order, escaping, trailing newline, or
 * exit code (FAILURE (1) vs INVALID (2) on `user:create` and the `user:list`
 * status refusal) reddens this file.
 *
 * The last-admin precedence law (`user:delete`: the guard fires BEFORE the
 * confirmation refusal and survives `--force`, `--json` included — estate law
 * pinned hub-side by the HUB-CONSOLE twin at phlix-hub commit 47715678) gets
 * its byte-level expression in the `delete.*last_admin*` cases here.
 *
 * Repository state is a spec array; the mock is built per case so no
 * interaction expectations compete with the byte assertions. The stream legs
 * exercise the direct `run()` + `BufferedOutput` shape with an explicit
 * `data://` handle so the `--password-file=-` seam never touches the test
 * runner's real stdin (hermetic under any invocation).
 *
 * @package Phlix\Tests\Unit\Console\Commands
 */
class UserCommandOutputByteMatrixTest extends TestCase
{
    /** @var array<string, mixed> */
    private const ALICE = ['id' => 'u1', 'username' => 'alice', 'is_admin' => 0];
    /** @var array<string, mixed> */
    private const ROOT = ['id' => 'u2', 'username' => 'root', 'is_admin' => 1];
    /** @var array<string, mixed> */
    private const PENDING = ['id' => 'u3', 'username' => 'carol', 'is_admin' => 0, 'status' => 'pending'];
    /** @var array<string, mixed> */
    private const MISSING_ID = ['username' => 'zoe', 'is_admin' => 0];

    /** Direct `run()` over an ArrayInput carrying a data:// stream — the scripted `--password-file=-` leg. */
    private const MODE_STREAM = 'stream';

    private const TABLE_HEADER = '%-38s %-20s %-40s %-10s %-6s';

    #[DataProvider('promoteMatrix')]
    public function testPromoteByteMatrix(array $spec, array $inputs, int $exit, string $bytes): void
    {
        $tester = new CommandTester($this->command(UserPromoteCommand::class, 'user:promote', $this->repo($spec)));

        $this->assertSame($exit, $tester->execute($inputs));
        $this->assertSame($bytes, $tester->getDisplay());
    }

    #[DataProvider('disableMatrix')]
    public function testDisableByteMatrix(array $spec, array $inputs, int $exit, string $bytes): void
    {
        $tester = new CommandTester($this->command(UserDisableCommand::class, 'user:disable', $this->repo($spec)));

        $this->assertSame($exit, $tester->execute($inputs));
        $this->assertSame($bytes, $tester->getDisplay());
    }

    #[DataProvider('approveMatrix')]
    public function testApproveByteMatrix(array $spec, array $inputs, int $exit, string $bytes): void
    {
        $tester = new CommandTester($this->command(UserApproveCommand::class, 'user:approve', $this->repo($spec)));

        $this->assertSame($exit, $tester->execute($inputs));
        $this->assertSame($bytes, $tester->getDisplay());
    }

    #[DataProvider('rejectMatrix')]
    public function testRejectByteMatrix(array $spec, array $inputs, int $exit, string $bytes): void
    {
        $tester = new CommandTester($this->command(UserRejectCommand::class, 'user:reject', $this->repo($spec)));

        $this->assertSame($exit, $tester->execute($inputs));
        $this->assertSame($bytes, $tester->getDisplay());
    }

    #[DataProvider('deleteMatrix')]
    public function testDeleteByteMatrix(array $spec, array $inputs, int $exit, string $bytes): void
    {
        $tester = new CommandTester($this->command(UserDeleteCommand::class, 'user:delete', $this->repo($spec)));

        $this->assertSame($exit, $tester->execute($inputs));
        $this->assertSame($bytes, $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $inputs
     */
    #[DataProvider('createMatrix')]
    public function testCreateByteMatrix(
        array $spec,
        array $inputs,
        int $exit,
        string $mode,
        string $stream,
        string $bytes,
    ): void {
        $captured = null;
        $repository = $this->repo($spec, $captured);
        $command = $this->command(UserCreateCommand::class, 'user:create', $repository);

        if ($mode === self::MODE_STREAM) {
            $input = new ArrayInput($inputs, $command->getDefinition());
            $input->setInteractive(false);
            $handle = fopen('data://text/plain,' . $stream, 'r');
            $this->assertIsResource($handle);
            $input->setStream($handle);
            $output = new BufferedOutput();

            $this->assertSame($exit, $command->run($input, $output));
            $this->assertSame($bytes, $output->fetch());
            $this->assertSame($spec['createPassword'] ?? null, $captured);

            return;
        }

        $tester = new CommandTester($command);

        $this->assertSame($exit, $tester->execute($inputs));
        $this->assertSame($bytes, $tester->getDisplay());
        $this->assertSame($spec['createPassword'] ?? null, $captured);
    }

    /**
     * The file-seam leg keeps the fixture hermetic: the password arrives
     * through a real temp file, one trailing newline trimmed by the command.
     */
    public function testCreateReadsPasswordFileAndTrimsOneTrailingNewline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'srvconsole-matrix-');
        $this->assertIsString($path);
        file_put_contents($path, "file-secret\n");

        try {
            $captured = null;
            $tester = new CommandTester($this->command(
                UserCreateCommand::class,
                'user:create',
                $this->repo(['createPassword' => 'file-secret'], $captured),
            ));

            $this->assertSame(0, $tester->execute([
                'username' => 'bob',
                '--email' => 'bob@example.com',
                '--password-file' => $path,
            ]));
            $this->assertSame("Created user \"bob\" (new-id).\n", $tester->getDisplay());
            $this->assertSame('file-secret', $captured);
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('listMatrix')]
    public function testListByteMatrix(array $spec, array $inputs, int $exit, string $bytes): void
    {
        $tester = new CommandTester($this->command(UserListCommand::class, 'user:list', $this->repo($spec)));

        $this->assertSame($exit, $tester->execute($inputs));
        $this->assertSame($bytes, $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $spec
     * @param string|null $capturedPassword by-ref sink: the password the command
     *        actually handed to the repository (secret-hygiene teeth for the
     *        file/stdin seams — argv never carried it).
     */
    private function repo(array $spec, ?string &$capturedPassword = null): UserRepository
    {
        $capturedPassword = null;
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn($spec['byUsername'] ?? null);
        $repository->method('findByEmail')->willReturn($spec['byEmail'] ?? null);
        $repository->method('isLastAdmin')->willReturn($spec['lastAdmin'] ?? false);
        $repository->method('usernameExists')->willReturn($spec['usernameTaken'] ?? false);
        $repository->method('emailExists')->willReturn($spec['emailTaken'] ?? false);
        $repository->method('findAll')->willReturn($spec['rows'] ?? []);
        $repository->method('listByStatus')->willReturn($spec['rows'] ?? []);

        if (($spec['createPassword'] ?? null) !== null) {
            $repository->method('create')->willReturnCallback(
                /** @param array<string, mixed> $data */
                static function (array $data) use (&$capturedPassword): string {
                    $raw = $data['password'] ?? null;
                    $capturedPassword = is_string($raw) ? $raw : null;

                    return 'new-id';
                },
            );
        } else {
            $repository->method('create')->willReturn('new-id');
        }

        $throwOn = $spec['throwOn'] ?? '';
        $thrower = static fn (): never => throw new RuntimeException('db down');
        foreach (['setAdmin', 'setStatus', 'delete', 'create', 'findAll', 'listByStatus'] as $method) {
            if ($throwOn === $method) {
                if ($method === 'create') {
                    $repository->method('create')->willThrowException(new RuntimeException('db down'));
                } elseif ($method === 'findAll') {
                    $repository->method('findAll')->willThrowException(new RuntimeException('db down'));
                } elseif ($method === 'listByStatus') {
                    $repository->method('listByStatus')->willThrowException(new RuntimeException('db down'));
                } else {
                    $repository->method($method)->willReturnCallback($thrower);
                }
            }
        }

        return $repository;
    }

    /**
     * @param class-string<Command> $class
     */
    private function command(string $class, string $name, UserRepository $repository): Command
    {
        $factory = static fn (): UserRepository => $repository;
        $application = new Application();
        $application->add(match ($class) {
            UserPromoteCommand::class => new UserPromoteCommand($factory),
            UserDisableCommand::class => new UserDisableCommand($factory),
            UserApproveCommand::class => new UserApproveCommand($factory),
            UserRejectCommand::class => new UserRejectCommand($factory),
            UserCreateCommand::class => new UserCreateCommand($factory),
            UserDeleteCommand::class => new UserDeleteCommand($factory),
            UserListCommand::class => new UserListCommand($factory),
            default => throw new InvalidArgumentException('unpinned command: ' . $class),
        });

        return $application->find($name);
    }

    private static function tableRow(string $id, string $username, string $email, string $status, string $admin): string
    {
        return sprintf(self::TABLE_HEADER, $id, $username, $email, $status, $admin) . "\n";
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function promoteMatrix(): array
    {
        return [
            'promote.ok_plain' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice'],
                0,
                "User \"alice\" promoted to admin.\n",
            ],
            'promote.ok_json' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u1\",\"username\":\"alice\",\"is_admin\":true}]}\n",
            ],
            'promote.ok_email_resolution' => [
                ['byEmail' => self::ALICE],
                ['user' => 'alice@example.com'],
                0,
                "User \"alice@example.com\" promoted to admin.\n",
            ],
            'promote.revoke_ok_plain' => [
                ['byUsername' => self::ROOT],
                ['user' => 'root', '--revoke' => true],
                0,
                "User \"root\" demoted from admin.\n",
            ],
            'promote.notfound_plain' => [
                [],
                ['user' => 'ghost'],
                1,
                "User not found: ghost\n",
            ],
            'promote.notfound_json' => [
                [],
                ['user' => 'ghost', '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"User not found: ghost\"}\n",
            ],
            'promote.revoke_last_json' => [
                ['byUsername' => self::ROOT, 'lastAdmin' => true],
                ['user' => 'root', '--revoke' => true, '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Cannot demote the last admin.\"}\n",
            ],
            'promote.missing_id' => [
                ['byUsername' => self::MISSING_ID],
                ['user' => 'zoe'],
                1,
                "User record is missing a valid id.\n",
            ],
            'promote.write_throws' => [
                ['byUsername' => self::ALICE, 'throwOn' => 'setAdmin'],
                ['user' => 'alice'],
                1,
                "Admin change failed: db down\n",
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function disableMatrix(): array
    {
        return [
            'disable.ok_plain' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice'],
                0,
                "Disabled user \"alice\" (status=disabled).\n",
            ],
            'disable.ok_json' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u1\",\"username\":\"alice\",\"status\":\"disabled\"}]}\n",
            ],
            'disable.notfound_plain' => [
                [],
                ['user' => 'ghost'],
                1,
                "User not found: ghost\n",
            ],
            'disable.last_admin_json' => [
                ['byUsername' => self::ROOT, 'lastAdmin' => true],
                ['user' => 'root', '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Cannot disable the last admin.\"}\n",
            ],
            'disable.missing_id' => [
                ['byUsername' => self::MISSING_ID],
                ['user' => 'zoe'],
                1,
                "User record is missing a valid id.\n",
            ],
            'disable.write_throws' => [
                ['byUsername' => self::ALICE, 'throwOn' => 'setStatus'],
                ['user' => 'alice'],
                1,
                "Disable failed: db down\n",
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function approveMatrix(): array
    {
        return [
            'approve.ok_plain' => [
                ['byUsername' => self::PENDING],
                ['user' => 'carol'],
                0,
                "Approved user \"carol\" (status=active).\n",
            ],
            'approve.ok_json' => [
                ['byUsername' => self::PENDING],
                ['user' => 'carol', '--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u3\",\"username\":\"carol\",\"status\":\"active\"}]}\n",
            ],
            'approve.notfound_plain' => [
                [],
                ['user' => 'ghost'],
                1,
                "User not found: ghost\n",
            ],
            'approve.missing_id' => [
                ['byUsername' => self::MISSING_ID],
                ['user' => 'zoe'],
                1,
                "User record is missing a valid id.\n",
            ],
            'approve.write_throws_json' => [
                ['byUsername' => self::PENDING, 'throwOn' => 'setStatus'],
                ['user' => 'carol', '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Approve failed: db down\"}\n",
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function rejectMatrix(): array
    {
        return [
            'reject.ok_plain' => [
                ['byUsername' => self::PENDING],
                ['user' => 'carol'],
                0,
                "Rejected (deleted) user \"carol\" (u3).\n",
            ],
            'reject.ok_json' => [
                ['byUsername' => self::PENDING],
                ['user' => 'carol', '--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u3\",\"username\":\"carol\",\"rejected\":true}]}\n",
            ],
            'reject.notfound_plain' => [
                [],
                ['user' => 'ghost'],
                1,
                "User not found: ghost\n",
            ],
            'reject.not_pending_plain' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice'],
                1,
                "Only pending users can be rejected; disable an active account instead.\n",
            ],
            'reject.last_admin_json' => [
                ['byUsername' => self::PENDING, 'lastAdmin' => true],
                ['user' => 'carol', '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Cannot delete the last admin.\"}\n",
            ],
            'reject.missing_id' => [
                ['byUsername' => self::MISSING_ID],
                ['user' => 'zoe'],
                1,
                "User record is missing a valid id.\n",
            ],
            'reject.delete_throws' => [
                ['byUsername' => self::PENDING, 'throwOn' => 'delete'],
                ['user' => 'carol'],
                1,
                "Reject failed: db down\n",
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function deleteMatrix(): array
    {
        return [
            'delete.confirm_refused_plain' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice'],
                1,
                "Refusing to delete user \"alice\"; re-run with --force to confirm.\n",
            ],
            'delete.confirm_refused_json' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Refusing to delete user \\\"alice\\\"; re-run with --force to confirm.\"}\n",
            ],
            'delete.force_ok_plain' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--force' => true],
                0,
                "Deleted user \"alice\" (u1).\n",
            ],
            'delete.force_ok_json' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--force' => true, '--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u1\",\"username\":\"alice\",\"deleted\":true}]}\n",
            ],
            'delete.notfound_plain' => [
                [],
                ['user' => 'ghost', '--force' => true],
                1,
                "User not found: ghost\n",
            ],
            'delete.notfound_json' => [
                [],
                ['user' => 'ghost', '--force' => true, '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"User not found: ghost\"}\n",
            ],
            // H-04 byte pins: the guard fires BEFORE the confirmation refusal
            // and survives --force (and --json): the refused-last-admin bytes
            // are the guard message, never the confirm message.
            'delete.last_admin_with_force_json' => [
                ['byUsername' => self::ROOT, 'lastAdmin' => true],
                ['user' => 'root', '--force' => true, '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Cannot delete the last admin.\"}\n",
            ],
            'delete.last_admin_with_force_plain' => [
                ['byUsername' => self::ROOT, 'lastAdmin' => true],
                ['user' => 'root', '--force' => true],
                1,
                "Cannot delete the last admin.\n",
            ],
            'delete.last_admin_no_force_plain' => [
                ['byUsername' => self::ROOT, 'lastAdmin' => true],
                ['user' => 'root'],
                1,
                "Cannot delete the last admin.\n",
            ],
            'delete.force_admin_not_last_ok' => [
                ['byUsername' => self::ROOT],
                ['user' => 'root', '--force' => true],
                0,
                "Deleted user \"root\" (u2).\n",
            ],
            'delete.repo_throws' => [
                ['byUsername' => self::ALICE, 'throwOn' => 'delete'],
                ['user' => 'alice', '--force' => true],
                1,
                "User deletion failed: db down\n",
            ],
            'delete.missing_id' => [
                ['byUsername' => self::MISSING_ID],
                ['user' => 'zoe', '--force' => true],
                1,
                "User record is missing a valid id.\n",
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     0: array<string, mixed>,
     *     1: array<string, mixed>,
     *     2: int,
     *     3: string,
     *     4: string,
     *     5: string,
     * }>
     */
    public static function createMatrix(): array
    {
        return [
            'create.flags_plain' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123'],
                0,
                'tester',
                '',
                "Created user \"bob\" (new-id).\n",
            ],
            'create.flags_json' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123', '--json' => true],
                0,
                'tester',
                '',
                "{\"ok\":true,\"data\":[{\"id\":\"new-id\",\"username\":\"bob\","
                . "\"email\":\"bob@example.com\",\"display_name\":\"bob\"}]}\n",
            ],
            'create.displayname_json' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123',
                    '--display-name' => 'Bobby', '--json' => true],
                0,
                'tester',
                '',
                "{\"ok\":true,\"data\":[{\"id\":\"new-id\",\"username\":\"bob\","
                . "\"email\":\"bob@example.com\",\"display_name\":\"Bobby\"}]}\n",
            ],
            'create.explicit_password_wins_over_unreadable_file' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123',
                    '--password-file' => '/nope/missing'],
                0,
                'tester',
                '',
                "Created user \"bob\" (new-id).\n",
            ],
            'create.stdin_via_password_file_dash' => [
                ['createPassword' => 'piped-secret'],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password-file' => '-'],
                0,
                self::MODE_STREAM,
                "piped-secret\n",
                "Created user \"bob\" (new-id).\n",
            ],
            'create.short_username' => [
                [],
                ['username' => 'ab', '--email' => 'a@b.com', '--password' => 'x'],
                2,
                'tester',
                '',
                "Username must be 3-50 characters.\n",
            ],
            'create.bad_username_chars' => [
                [],
                ['username' => 'b@d', '--email' => 'a@b.com', '--password' => 'x'],
                2,
                'tester',
                '',
                "Username must be alphanumeric with underscores only.\n",
            ],
            'create.bad_email' => [
                [],
                ['username' => 'bob', '--email' => 'not-an-email', '--password' => 'x'],
                2,
                'tester',
                '',
                "Invalid email format.\n",
            ],
            'create.empty_password' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => ''],
                2,
                'tester',
                '',
                "A password is required (--password= or --password-file=).\n",
            ],
            'create.password_file_missing_json' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com',
                    '--password-file' => '/nope/missing', '--json' => true],
                2,
                'tester',
                '',
                "{\"ok\":false,\"error\":\"Cannot read --password-file: /nope/missing\"}\n",
            ],
            'create.dup_username' => [
                ['usernameTaken' => true],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123'],
                2,
                'tester',
                '',
                "Username already exists: bob\n",
            ],
            'create.dup_email_json' => [
                ['emailTaken' => true],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123', '--json' => true],
                2,
                'tester',
                '',
                "{\"ok\":false,\"error\":\"That email address is already in use: bob@example.com\"}\n",
            ],
            'create.repo_throws_json' => [
                ['throwOn' => 'create'],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123', '--json' => true],
                1,
                'tester',
                '',
                "{\"ok\":false,\"error\":\"User creation failed: db down\"}\n",
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function listMatrix(): array
    {
        $aliceRow = [
            'id' => 'u1', 'username' => 'alice', 'email' => 'alice@example.com',
            'status' => 'active', 'is_admin' => 0, 'password_hash' => 'never-on-the-wire',
        ];
        $rootRow = [
            'id' => 'u2', 'username' => 'root', 'email' => 'root@example.com',
            'status' => 'active', 'is_admin' => 1,
        ];

        return [
            'list.human_table_with_redaction' => [
                ['rows' => [$aliceRow, $rootRow]],
                [],
                0,
                sprintf(self::TABLE_HEADER, 'ID', 'USERNAME', 'EMAIL', 'STATUS', 'ADMIN') . "\n"
                . self::tableRow('u1', 'alice', 'alice@example.com', 'active', 'no')
                . self::tableRow('u2', 'root', 'root@example.com', 'active', 'yes'),
            ],
            'list.json_redacts_secret_columns' => [
                ['rows' => [$aliceRow]],
                ['--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u1\",\"username\":\"alice\",\"email\":\"alice@example.com\","
                . "\"status\":\"active\",\"is_admin\":0}]}\n",
            ],
            'list.empty_plain' => [
                ['rows' => []],
                [],
                0,
                "No users found.\n",
            ],
            'list.empty_json' => [
                ['rows' => []],
                ['--json' => true],
                0,
                "{\"ok\":true,\"data\":[]}\n",
            ],
            'list.status_filter_json' => [
                ['rows' => [self::PENDING]],
                ['--status' => 'pending', '--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u3\",\"username\":\"carol\","
                . "\"is_admin\":0,\"status\":\"pending\"}]}\n",
            ],
            'list.bad_status_plain' => [
                [],
                ['--status' => 'bogus'],
                2,
                "Invalid status \"bogus\"; must be one of pending, active, disabled.\n",
            ],
            'list.bad_status_json' => [
                [],
                ['--status' => 'bogus', '--json' => true],
                2,
                "{\"ok\":false,\"error\":\"Invalid status \\\"bogus\\\"; must be one of "
                . "pending, active, disabled.\"}\n",
            ],
            'list.repo_throws_plain' => [
                ['throwOn' => 'findAll'],
                [],
                1,
                "Failed to list users: db down\n",
            ],
            'list.status_repo_throws_json' => [
                ['throwOn' => 'listByStatus'],
                ['--status' => 'active', '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Failed to list users: db down\"}\n",
            ],
        ];
    }
}
