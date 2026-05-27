<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Admin\BackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `backup:create [--label=]` — create a new server backup archive.
 *
 * Thin console wrapper around {@see BackupManager::createBackup()}, which
 * returns `array{backup_id: string, file_path: string, size_bytes: int}`.
 * The command prints those fields. The backing {@see BackupManager} is
 * resolved lazily through the injected factory so constructing this command
 * never builds the DI container.
 */
#[AsCommand(name: 'backup:create', description: 'Create a new server backup archive')]
final class BackupCreateCommand extends Command
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
     * Declare the optional `--label` value option.
     */
    protected function configure(): void
    {
        $this->addOption(
            'label',
            null,
            InputOption::VALUE_REQUIRED,
            'Optional human-readable label for the backup'
        );
    }

    /**
     * Create the backup and report the result.
     *
     * @return int {@see Command::SUCCESS} (0) once the backup is created, or
     *         {@see Command::FAILURE} (1) when creation throws.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $labelRaw = $input->getOption('label');
        $label = is_string($labelRaw) ? $labelRaw : null;

        try {
            $manager = ($this->backupManagerFactory)();
            $result = $manager->createBackup($label);
        } catch (Throwable $e) {
            $output->writeln('<error>Backup creation failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('Backup created.');
        $output->writeln('  ID:   ' . $result['backup_id']);
        $output->writeln('  Path: ' . $result['file_path']);
        $output->writeln('  Size: ' . $result['size_bytes'] . ' bytes');

        return Command::SUCCESS;
    }
}
