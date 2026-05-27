<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Auth\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `user:reset-password {user} [--password=]` — reset a user's password.
 *
 * Resolves the user via {@see UserRepository::findByUsername()}, falling back
 * to {@see UserRepository::findByEmail()}. When `--password` is supplied it is
 * used verbatim; otherwise a strong random password is generated and printed.
 * The password is then stored via {@see UserRepository::update()}, which
 * Argon2ID-hashes it. The backing {@see UserRepository} is resolved lazily
 * through the injected factory so constructing this command never builds the
 * DI container.
 */
#[AsCommand(name: 'user:reset-password', description: "Reset a user's password by username or email")]
final class UserResetPasswordCommand extends Command
{
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

    /**
     * Declare the required `user` argument and optional `--password` option.
     */
    protected function configure(): void
    {
        $this->addArgument('user', InputArgument::REQUIRED, 'The username or email of the user to reset');
        $this->addOption(
            'password',
            null,
            InputOption::VALUE_REQUIRED,
            'The new password (a strong random one is generated and printed when omitted)'
        );
    }

    /**
     * Reset the resolved user's password.
     *
     * @return int {@see Command::SUCCESS} (0) on a successful reset, or
     *         {@see Command::FAILURE} (1) when the user cannot be found, the
     *         row lacks a usable id, or the update throws.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userArg = $input->getArgument('user');
        $identifier = is_string($userArg) ? $userArg : '';

        try {
            $repository = ($this->userRepositoryFactory)();

            $user = $repository->findByUsername($identifier);
            if ($user === null) {
                $user = $repository->findByEmail($identifier);
            }

            if ($user === null) {
                $output->writeln('<error>User not found: ' . $identifier . '</error>');

                return Command::FAILURE;
            }

            $id = $user['id'] ?? null;
            if (!is_string($id) || $id === '') {
                $output->writeln('<error>User record is missing a valid id.</error>');

                return Command::FAILURE;
            }

            $passwordOption = $input->getOption('password');
            if (is_string($passwordOption) && $passwordOption !== '') {
                $password = $passwordOption;
                $generated = false;
            } else {
                $password = bin2hex(random_bytes(12));
                $generated = true;
            }

            $repository->update($id, ['password' => $password]);
        } catch (Throwable $e) {
            $output->writeln('<error>Password reset failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('Password reset for user "' . $identifier . '".');
        if ($generated) {
            $output->writeln('Generated password: ' . $password);
        }

        return Command::SUCCESS;
    }
}
