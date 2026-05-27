<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Media\Library\LibraryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `library:scan {libraryId}` — scan (or rescan) a single media library.
 *
 * Thin console wrapper around {@see LibraryManager::scanLibrary()} (and
 * {@see LibraryManager::rescanLibrary()} when `--rescan` is given). The
 * backing {@see LibraryManager} is resolved lazily through the injected
 * factory so command construction never builds the DI container or opens a
 * database connection.
 */
#[AsCommand(name: 'library:scan', description: 'Scan (or rescan) a media library for new content')]
final class LibraryScanCommand extends Command
{
    /** @var callable(): LibraryManager Lazy factory for the backing manager. */
    private $libraryManagerFactory;

    /**
     * @param callable(): LibraryManager $libraryManagerFactory Lazy factory
     *        returning the backing {@see LibraryManager}. Invoked only inside
     *        {@see execute()}, never at registration time.
     */
    public function __construct(callable $libraryManagerFactory)
    {
        $this->libraryManagerFactory = $libraryManagerFactory;
        parent::__construct();
    }

    /**
     * Declare the `libraryId` argument and the `--rescan` flag.
     */
    protected function configure(): void
    {
        $this->addArgument('libraryId', InputArgument::REQUIRED, 'The library identifier to scan');
        $this->addOption(
            'rescan',
            null,
            InputOption::VALUE_NONE,
            'Clear existing items and rescan from the filesystem'
        );
    }

    /**
     * Run the scan / rescan and report completion.
     *
     * @return int {@see Command::SUCCESS} (0) on a completed scan, or
     *         {@see Command::FAILURE} (1) when the library is missing or the
     *         manager throws (e.g. unknown library id).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $libraryIdRaw = $input->getArgument('libraryId');
        $libraryId = is_string($libraryIdRaw) ? $libraryIdRaw : '';
        $rescan = (bool) $input->getOption('rescan');

        try {
            $manager = ($this->libraryManagerFactory)();
            if ($rescan) {
                $manager->rescanLibrary($libraryId);
            } else {
                $manager->scanLibrary($libraryId);
            }
        } catch (Throwable $e) {
            $output->writeln('<error>Scan failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '%s of library "%s" complete.',
            $rescan ? 'Rescan' : 'Scan',
            $libraryId
        ));

        return Command::SUCCESS;
    }
}
