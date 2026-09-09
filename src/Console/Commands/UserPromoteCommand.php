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
 * `user:promote {user} [--revoke] [--json]` — toggle an account's admin flag.
 *
 * Promotes the resolved user (username, falling back to email) to admin, or,
 * with `--revoke`, demotes them. Demotion is gated by the repository-level
 * last-admin invariant ({@see UserRepository::isLastAdmin()}): the final
 * administrator cannot be demoted, mirroring the HTTP admin surface. Promotion
 * needs no guard — it can only ever add an administrator.
 *
 * With `--json` the resulting admin flag is emitted under the shared success
 * envelope. The backing {@see UserRepository} is resolved lazily through the
 * injected factory so constructing this command never builds the DI container.
 */
#[AsCommand(name: 'user:promote', description: 'Promote a user to admin, or demote with --revoke')]
final class UserPromoteCommand extends Command
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
            ->addArgument('user', InputArgument::REQUIRED, 'The username or email of the account to change')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Demote the user instead of promoting them')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable {"ok":true,"data":[...]} JSON');
    }

    /**
     * @return int {@see Command::FAILURE} (1) when the user is missing, is the
     *         last admin being demoted, or the write throws; else
     *         {@see Command::SUCCESS} (0).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = self::stringArgument($input, 'user');
        $revoke = $input->getOption('revoke') === true;

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

            if ($revoke && $repository->isLastAdmin($user)) {
                return $this->fail($input, $output, 'Cannot demote the last admin.');
            }

            $repository->setAdmin($id, !$revoke);
        } catch (Throwable $e) {
            return $this->fail($input, $output, 'Admin change failed: ' . $e->getMessage());
        }

        if ($this->isJsonMode($input)) {
            $this->emitJsonSuccess($output, [[
                'id' => $id,
                'username' => self::stringOrNull($user['username'] ?? null) ?? $identifier,
                'is_admin' => !$revoke,
            ]]);

            return Command::SUCCESS;
        }

        $verb = $revoke ? 'demoted from' : 'promoted to';
        $output->writeln('User "' . $identifier . '" ' . $verb . ' admin.');

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
