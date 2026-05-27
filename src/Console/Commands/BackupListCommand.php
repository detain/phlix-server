<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Admin\BackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `backup:list` — list every stored backup, newest first.
 *
 * Thin console wrapper around {@see BackupManager::listBackups()}: it prints
 * each backup's id, label, size, location, and creation timestamp as a table.
 * The backing {@see BackupManager} is resolved lazily through the injected
 * factory so constructing this command never builds the DI container.
 */
#[AsCommand(name: 'backup:list', description: 'List stored server backups')]
final class BackupListCommand extends Command
{
    /** @var callable(): BackupManager Lazy factory for the backing manager. */
    private $backupManagerFactory;

    /**
     * @param callable(): BackupManager $backupManagerFactory Lazy factory
     *        returning the backing {@see BackupManager}. Invoked only inside
     *        {@see execute()}, never at registration time.
     */
    public function __construct(callable $backupManagerFactory)
    {
        $this->backupManagerFactory = $backupManagerFactory;
        parent::__construct();
    }

    /**
     * Render the backup list.
     *
     * @return int {@see Command::SUCCESS} (0) once the list is rendered, or
     *         {@see Command::FAILURE} (1) when the manager throws.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $manager = ($this->backupManagerFactory)();
            $backups = $manager->listBackups();
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to list backups: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($backups === []) {
            $output->writeln('No backups found.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Label', 'Size (bytes)', 'Location', 'Created']);

        foreach ($backups as $backup) {
            $table->addRow([
                $backup['id'],
                $backup['label'],
                (string) $backup['size_bytes'],
                $backup['is_s3'] ? 'S3' : 'local',
                $backup['created_at'],
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
