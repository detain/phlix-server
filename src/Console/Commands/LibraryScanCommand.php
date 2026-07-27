<?php

/**
 * Phlix media server component: Commands.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Media\Library\LibraryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
    /**
     * Exit code for a scan that COMPLETED but could not index every file it read.
     *
     * Distinct from {@see Command::FAILURE} (1), which means the scan did not run at all
     * (unknown library, manager threw). A wrapper needs to tell those apart: 1 says "try
     * again / fix the config", 3 says "the library is now missing N files".
     *
     * ⚠ **WHY 3 AND NOT 2 (review r3 finding 10).** Symfony reserves the low codes and
     * defines all three of them on the class this command extends:
     * `Command::SUCCESS = 0`, `Command::FAILURE = 1`, `Command::INVALID = 2`
     * (`vendor/symfony/console/Command/Command.php:38-40`), where `INVALID` means
     * **invalid input / usage** — i.e. precisely the "the scan did not run, fix your
     * arguments" meaning this constant exists to be DISTINGUISHABLE from. Returning 2
     * here put both meanings on one number inside a file that imports `Command` and uses
     * two of its constants, so a wrapper switching on 2 could not tell "you typed the
     * library id wrong" from "5 tracks were silently lost". 3 is the first value outside
     * Symfony's reserved set. `LibraryScanCommandTest` pins both the value and the
     * non-collision, so a future renumber cannot silently walk back onto 2.
     *
     * ⚠ Review r2 F7 asked for callers to be checked before changing this from 0. There
     * are NONE: `grep -rn "library:scan"` across the repo finds no systemd unit (
     * `scripts/install.sh` installs only `phlix-server.service`, which runs `start.php`),
     * no cron entry, nothing in `docker/docker-entrypoint.sh` or `docker/supervisord.conf`,
     * and nothing in `.github/workflows/*` — only `CHANGELOG.md`/docs prose. So no caller
     * depended on exit 0 for a lossy scan, and silently returning success to a cron job
     * that just lost files is the worse default. That same absence of consumers is why
     * renumbering to 3 now costs nothing.
     */
    private const EXIT_FILES_LOST = 3;

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
        // ⚠ The old text — "Clear existing items and rescan from the filesystem" — was
        // wrong in both halves: the rescan has been NON-DESTRUCTIVE since the
        // DELETE-then-rescan data-loss fix, and for music it used to run the same
        // incremental scan `scan` runs. S145 makes the second half true instead of
        // deleting the promise: the flag now reads every file.
        $this->addOption(
            'rescan',
            null,
            InputOption::VALUE_NONE,
            'Full rescan: re-read EVERY file rather than skipping unchanged ones, then prune items whose '
            . 'file is gone. Non-destructive (user data is preserved). For a music library this reads every '
            . 'track\'s tags and can take hours — it is what repairs tracks filed under the wrong '
            . 'album/artist. Use a plain scan for an incremental refresh.'
        );
    }

    /**
     * Run the scan / rescan and report completion, INCLUDING the counters.
     *
     * The counter line exists because of review r1 INFO-10: `items_failed` /
     * {@see \Phlix\Media\Library\ScanResult::$failed} reaches the scan-status JSON and
     * the app log, but the admin SPA's `ScanJob` interface does not list it, so nothing
     * RENDERS it — leaving `curl`/`grep` as the only way to see that a scan lost files.
     * This command already had the whole `ScanResult` in hand and was throwing it away,
     * so it is the cheapest honest operator surface, and `failed` is called out
     * explicitly rather than buried in a tuple.
     *
     * **Machine-readable too (review r2 F7).** The counters go to stdout, the lossy-scan
     * warning goes to **stderr**, and a lossy scan exits {@see self::EXIT_FILES_LOST}, so
     * a cron/CI wrapper that inspects only the exit status or only stderr still learns
     * that files were lost. Previously both signals were on stdout behind exit 0, i.e.
     * invisible to every non-human caller.
     *
     * @return int {@see Command::SUCCESS} (0) on a clean scan,
     *         {@see self::EXIT_FILES_LOST} (3) when the scan completed but could not index
     *         every file it read, or {@see Command::FAILURE} (1) when the scan did not run
     *         (unknown library id, or the manager threw). 2 is NOT used — it is Symfony's
     *         {@see Command::INVALID} (see {@see self::EXIT_FILES_LOST}).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $libraryIdRaw = $input->getArgument('libraryId');
        $libraryId = is_string($libraryIdRaw) ? $libraryIdRaw : '';
        $rescan = (bool) $input->getOption('rescan');

        try {
            $manager = ($this->libraryManagerFactory)();
            $result = $rescan
                ? $manager->rescanLibrary($libraryId)
                : $manager->scanLibrary($libraryId);
        } catch (Throwable $e) {
            $output->writeln('<error>Scan failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '%s of library "%s" complete.',
            $rescan ? 'Rescan' : 'Scan',
            $libraryId
        ));
        $output->writeln(sprintf(
            '  scanned: %d   added: %d   updated: %d   removed: %d   failed: %d   (%d ms)',
            $result->scanned,
            $result->added,
            $result->updated,
            $result->removed,
            $result->failed,
            $result->durationMs
        ));

        if ($result->failed > 0) {
            // Files the scan READ and could not index. Not a policy skip, and recoverable
            // (the next clean scan re-adds them), but it is data missing from the library
            // right now — so it goes to STDERR and the command exits non-zero (r2 F7).
            $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errOutput->writeln(sprintf(
                '<comment>%d file(s) could not be indexed — see .logs/error.log for each one.</comment>',
                $result->failed
            ));

            return self::EXIT_FILES_LOST;
        }

        return Command::SUCCESS;
    }
}
