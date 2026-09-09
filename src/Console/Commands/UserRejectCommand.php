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
 * `user:reject {user} [--json]` — reject (delete) a still-pending signup.
 *
 * Server-only (the hub has no account-status column). Mirrors
 * {@see \Phlix\Server\Http\Controllers\Admin\AdminUserController::reject()}:
 * only a `pending` account may be rejected — an already-`active` account must
 * be disabled instead — and rejection is a hard delete. Because it deletes, the
 * absolute last-admin invariant still applies (a pending row that is somehow the
 * sole administrator is refused before removal), so the invariant holds for
 * EVERY destructive path the CLI exposes, not just `user:delete`.
 *
 * With `--json` the rejection is reported under the shared success envelope. The
 * backing {@see UserRepository} is resolved lazily through the injected factory.
 */
#[AsCommand(name: 'user:reject', description: 'Reject a pending user by deleting the account')]
final class UserRejectCommand extends Command
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
            ->addArgument('user', InputArgument::REQUIRED, 'The username or email of the pending account to reject')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable {"ok":true,"data":[...]} JSON');
    }

    /**
     * @return int {@see Command::FAILURE} (1) when the user is missing, is not
     *         pending, is the last admin, or the delete throws; else
     *         {@see Command::SUCCESS} (0).
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

            $status = is_string($user['status'] ?? null) ? $user['status'] : 'active';
            if ($status !== 'pending') {
                return $this->fail(
                    $input,
                    $output,
                    'Only pending users can be rejected; disable an active account instead.',
                );
            }

            if ($repository->isLastAdmin($user)) {
                return $this->fail($input, $output, 'Cannot delete the last admin.');
            }

            $repository->delete($id);
        } catch (Throwable $e) {
            return $this->fail($input, $output, 'Reject failed: ' . $e->getMessage());
        }

        if ($this->isJsonMode($input)) {
            $this->emitJsonSuccess($output, [[
                'id' => $id,
                'username' => self::stringOrNull($user['username'] ?? null) ?? $identifier,
                'rejected' => true,
            ]]);

            return Command::SUCCESS;
        }

        $output->writeln('Rejected (deleted) user "' . $identifier . '" (' . $id . ').');

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
