<?php

/**
 * Phlix media server component: Commands.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Auth\UserRepository;
use Phlix\Console\Commands\Concerns\JsonOutput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_map;
use function implode;
use function in_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function sprintf;

/**
 * `user:list [--status=] [--json]` — list server accounts.
 *
 * Without `--status` every account is listed; with it, only the matching
 * cohort (`pending`, `active` or `disabled` — the three values
 * {@see UserRepository::listByStatus()} understands). Whatever the source, the
 * rows come from a repository that does a `SELECT *`, so this command is
 * responsible for dropping the secret-bearing columns
 * ({@see self::SENSITIVE_COLUMNS}) before anything reaches the terminal or a
 * JSON document — a CLI operator must not be able to exfiltrate password hashes
 * this way any more than the admin list endpoint leaks them.
 *
 * With `--json` the (redacted) rows are emitted under the shared
 * `{"ok":true,"data":[...]}` envelope; otherwise a fixed-width human table is
 * printed. The backing {@see UserRepository} is resolved lazily through the
 * injected factory so constructing this command never builds the DI container.
 */
#[AsCommand(name: 'user:list', description: 'List users, optionally filtered by account status')]
final class UserListCommand extends Command
{
    use JsonOutput;

    /**
     * Columns stripped from every row before output. `SELECT *` returns them;
     * neither a terminal nor a JSON stream should ever echo them.
     */
    private const SENSITIVE_COLUMNS = ['password_hash', 'password_reset_token', 'provider_data'];

    /** The three account statuses {@see UserRepository::listByStatus()} accepts. */
    private const STATUSES = ['pending', 'active', 'disabled'];

    /** @var callable(): UserRepository Lazy factory for the backing repository. */
    private $userRepositoryFactory;

    /**
     * @param callable(): UserRepository $userRepositoryFactory Lazy factory
     *        returning the backing {@see UserRepository}. Invoked only inside
     *        {@see execute()}, never at registration time.
     */
    public function __construct(callable $userRepositoryFactory)
    {
        $this->userRepositoryFactory = $userRepositoryFactory;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status: pending, active or disabled')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable {"ok":true,"data":[...]} JSON');
    }

    /**
     * Resolve the requested cohort and render it.
     *
     * @return int {@see Command::INVALID} (2) for an unknown `--status`;
     *         {@see Command::FAILURE} (1) when the repository throws; else
     *         {@see Command::SUCCESS} (0).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statusArg = $input->getOption('status');
        $status = is_string($statusArg) ? $statusArg : null;

        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            $error = 'Invalid status "' . $status . '"; must be one of ' . implode(', ', self::STATUSES) . '.';
            if ($this->isJsonMode($input)) {
                $this->emitJsonError($output, $error);

                return Command::INVALID;
            }
            $output->writeln('<error>' . $error . '</error>');

            return Command::INVALID;
        }

        try {
            $repository = ($this->userRepositoryFactory)();
            $rows = $status !== null ? $repository->listByStatus($status) : $repository->findAll();
        } catch (Throwable $e) {
            $error = 'Failed to list users: ' . $e->getMessage();
            if ($this->isJsonMode($input)) {
                $this->emitJsonError($output, $error);

                return Command::FAILURE;
            }
            $output->writeln('<error>' . $error . '</error>');

            return Command::FAILURE;
        }

        $rows = array_map(static fn(array $row): array => self::publicRow($row), $rows);

        if ($this->isJsonMode($input)) {
            $this->emitJsonSuccess($output, $rows);

            return Command::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln('No users found.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '%-38s %-20s %-40s %-10s %-6s',
            'ID',
            'USERNAME',
            'EMAIL',
            'STATUS',
            'ADMIN',
        ));
        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%-38s %-20s %-40s %-10s %-6s',
                self::str($row['id'] ?? null),
                self::str($row['username'] ?? null),
                self::str($row['email'] ?? null),
                self::str($row['status'] ?? null),
                self::isTruth(self::scalar($row['is_admin'] ?? null)) ? 'yes' : 'no',
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Drop the secret-bearing columns from a raw user row.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function publicRow(array $row): array
    {
        foreach (self::SENSITIVE_COLUMNS as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    /**
     * @return mixed the raw column value, typed loosely for the casters below.
     */
    private static function scalar(mixed $value): mixed
    {
        return $value;
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null || is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function isTruth(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        return false;
    }
}
