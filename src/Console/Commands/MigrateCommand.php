<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Common\Database\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `migrate` — apply the database migrations under `migrations/*.sql`.
 *
 * Thin console wrapper around {@see MigrationRunner}: it runs the same
 * apply-all loop that `scripts/run-migrations.php` uses, renders a human
 * summary, and maps the result to a process exit code. The runner is injected
 * (and obtains its connection lazily) so constructing this command — e.g. for
 * `bin/phlix list` — never opens a database connection.
 */
#[AsCommand(name: 'migrate', description: 'Apply database migrations (migrations/*.sql)')]
final class MigrateCommand extends Command
{
    public function __construct(private MigrationRunner $runner)
    {
        parent::__construct();
    }

    /**
     * Run the migrations and render a summary.
     *
     * Returns {@see Command::SUCCESS} (0) when every statement applied (or was
     * an idempotent no-op) and {@see Command::FAILURE} (1) when the runner
     * reported one or more genuine, non-idempotent errors.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->runner->run();

        foreach ($result['applied'] as $file) {
            $output->writeln('Running migration: ' . $file);
        }

        foreach ($result['notes'] as $note) {
            $output->writeln('  note: ' . $note);
        }

        foreach ($result['errors'] as $error) {
            $output->writeln('  Warning: ' . $error);
        }

        $output->writeln(sprintf(
            'Migrations complete. (%d file(s), %d note(s), %d error(s))',
            count($result['applied']),
            count($result['notes']),
            count($result['errors'])
        ));

        return $result['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
