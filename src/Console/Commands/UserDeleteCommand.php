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
 * `user:delete {user} [--force] [--json]` — permanently remove an account.
 *
 * Two distinct gates, deliberately ordered:
 *
 *  1. The repository-level last-admin invariant
 *     ({@see UserRepository::isLastAdmin()}) is checked FIRST and is absolute:
 *     the final administrator is never deletable, `--force` included. Deleting
 *     an account is irreversible, so this runs before any convenience.
 *  2. `--force` is only a CONFIRMATION bypass. A deletion without it is refused
 *     with a message telling the operator to re-run — the human-facing "are you
 *     sure?" made scriptable — and never overrides gate 1.
 *
 * With `--json` the deletion is reported under the shared success envelope;
 * every refusal emits the shared `{"ok":false,"error":"..."}` shape. The backing
 * {@see UserRepository} is resolved lazily through the injected factory.
 */
#[AsCommand(name: 'user:delete', description: 'Delete a user account (last admin is protected)')]
final class UserDeleteCommand extends Command
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
            ->addArgument('user', InputArgument::REQUIRED, 'The username or email of the account to delete')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Skip the confirmation prompt (does NOT bypass the last-admin guard)',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable {"ok":true,"data":[...]} JSON');
    }

    /**
     * @return int {@see Command::FAILURE} (1) when the user is missing, is the
     *         last admin, was not confirmed with --force, or the delete throws;
     *         else {@see Command::SUCCESS} (0).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = self::stringArgument($input, 'user');
        $force = $input->getOption('force') === true;

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

            // Gate 1 — absolute, before --force.
            if ($repository->isLastAdmin($user)) {
                return $this->fail($input, $output, 'Cannot delete the last admin.');
            }

            // Gate 2 — --force is a confirmation bypass, nothing more.
            if (!$force) {
                return $this->fail(
                    $input,
                    $output,
                    'Refusing to delete user "' . $identifier . '"; re-run with --force to confirm.',
                );
            }

            $repository->delete($id);
        } catch (Throwable $e) {
            return $this->fail($input, $output, 'User deletion failed: ' . $e->getMessage());
        }

        if ($this->isJsonMode($input)) {
            $this->emitJsonSuccess($output, [[
                'id' => $id,
                'username' => self::stringOrNull($user['username'] ?? null) ?? $identifier,
                'deleted' => true,
            ]]);

            return Command::SUCCESS;
        }

        $output->writeln('Deleted user "' . $identifier . '" (' . $id . ').');

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
