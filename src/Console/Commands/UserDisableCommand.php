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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function is_string;

/**
 * `user:disable {user} [--json]` — move an account to `disabled`.
 *
 * Server-only (the hub has no account-status column). Guards against disabling
 * the LAST remaining administrator, mirroring
 * {@see \Phlix\Server\Http\Controllers\Admin\AdminUserController::disable()} —
 * a disabled admin can no longer pass `AdminMiddleware` (its lookup requires
 * `status = 'active'`), so leaving zero admins to re-enable them would lock the
 * operator out. Uses the repository-level {@see UserRepository::isLastAdmin()}
 * so the CLI and HTTP agree on the predicate.
 *
 * With `--json` the new status is emitted under the shared success envelope. The
 * backing {@see UserRepository} is resolved lazily through the injected factory.
 */
#[AsCommand(name: 'user:disable', description: 'Disable a user (set status to disabled; last admin is protected)')]
final class UserDisableCommand extends Command
{
    use JsonOutput;

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
            ->addArgument('user', InputArgument::REQUIRED, 'The username or email of the account to disable')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable {"ok":true,"data":[...]} JSON');
    }

    /**
     * @return int {@see Command::FAILURE} (1) when the user is missing, is the
     *         last admin, or the write throws; else {@see Command::SUCCESS} (0).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = self::stringArgument($input, 'user');

        try {
            $repository = ($this->userRepositoryFactory)();

            $user = $this->resolve($repository, $identifier);
            if ($user === null) {
                return $this->fail($input, $output, 'User not found: ' . $identifier);
            }

            $id = $user['id'] ?? null;
            if (!is_string($id) || $id === '') {
                return $this->fail($input, $output, 'User record is missing a valid id.');
            }

            if ($repository->isLastAdmin($user)) {
                return $this->fail($input, $output, 'Cannot disable the last admin.');
            }

            $repository->setStatus($id, 'disabled');
        } catch (Throwable $e) {
            return $this->fail($input, $output, 'Disable failed: ' . $e->getMessage());
        }

        if ($this->isJsonMode($input)) {
            $this->emitJsonSuccess($output, [[
                'id' => $id,
                'username' => self::stringOrNull($user['username'] ?? null) ?? $identifier,
                'status' => 'disabled',
            ]]);

            return Command::SUCCESS;
        }

        $output->writeln('Disabled user "' . $identifier . '" (status=disabled).');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(UserRepository $repository, string $identifier): ?array
    {
        $user = $repository->findByUsername($identifier);
        if ($user === null) {
            $user = $repository->findByEmail($identifier);
        }

        return $user;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $error): int
    {
        if ($this->isJsonMode($input)) {
            $this->emitJsonError($output, $error);
        } else {
            $output->writeln('<error>' . $error . '</error>');
        }

        return Command::FAILURE;
    }

    private static function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
