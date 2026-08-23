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
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Library\ScanProgressSink;
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
 *
 * ## S150 — a CLI scan is now VISIBLE in the admin UI
 *
 * Before S150 this command wrote **nothing** to `library_scan_jobs`
 * (`grep -rn "ScanJobRepository|scanJobs" bin/phlix src/Console/` → zero hits): its
 * entire side-effect surface was the `LibraryManager` call plus `writeln()`. The
 * admin Libraries page polls that table, so during a live CLI scan it kept showing
 * whatever the LAST web-enqueued job had left behind. Measured on production during
 * the S145 healing rescan: a healthy scan had been running for ~45 minutes and
 * demonstrably repairing rows, while the page showed a **red `failed` badge** from a
 * job that had ended at 06:27 that morning with
 * `error = 'Interrupted by server restart'`.
 *
 * ⚠ **A stale `failed` badge is worse than no badge at all.** An absent status reads
 * as "idle"; a stale failure reads as "the last thing that happened, broke" — and it
 * reads that way for the entire multi-hour run, which is exactly when an operator
 * looks. That is why this is a bug and not a nice-to-have.
 *
 * So the command now: creates its own `running` job row up front
 * ({@see ScanJobRepository::startRunning()}), streams `items_found` /
 * `items_updated` / `current_path` through the SAME throttled sink the worker uses
 * ({@see ScanProgressSink}), and stamps `markCompleted()` /
 * `markFailed()` on the success AND throw paths — plus on SIGTERM/SIGINT/SIGHUP and
 * on a fatal-error shutdown, so a killed run lands as `failed` with a truthful
 * reason instead of stranding a permanently-`running` row.
 *
 * ⚠ **`items_updated` is the PROCESSED-FILE numerator, not a count of rows written.**
 * The badge percentage is `items_updated / items_found`. See
 * {@see ScanProgressSink}.
 *
 * ## Concurrency policy (decided in S150, and it is a REFUSAL)
 *
 * Before S150 nothing stopped a CLI scan and a worker scan running concurrently over
 * the same library. Two scanners interleaving find-or-create over one library race on
 * every per-file existence lookup, and the two of them share ONE job row's worth of
 * UI, so the badge would report one scan's progress under the other's counters. This
 * command therefore REFUSES to start when the library's newest job is `queued` or
 * `running`, and exits {@see Command::FAILURE}. `--force` overrides it, for the one
 * case the check cannot distinguish: a row left `running` by a `kill -9` / power loss,
 * which no in-process handler can ever clean up. (A `phlix-server` restart clears such
 * rows by itself — {@see \Phlix\Media\Library\LibraryScanWorker::start()} reaps every
 * `running` row at boot.)
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

    /** Signals that must land the job row as `failed` rather than strand it. */
    private const TRAPPED_SIGNALS = [SIGTERM, SIGINT, SIGHUP];

    /**
     * The one refusal message, emitted from both the pre-check and the lost-race path
     * so an operator cannot tell them apart (there is nothing useful to tell apart —
     * in both cases a scan of this library is already in flight).
     */
    private const REFUSAL_MESSAGE = '<error>A scan job for this library is already queued or running. '
        . 'Refusing to start a second one — two scanners over one library race on every per-file lookup, '
        . 'and the admin badge can only report one of them. Re-run with --force if you know the existing '
        . 'row is stranded (a kill -9 leaves one behind; a phlix-server restart clears it).</error>';

    /** @var callable(): LibraryManager Lazy factory for the backing manager. */
    private $libraryManagerFactory;

    /**
     * @var (callable(): ScanJobRepository)|null Lazy factory for the job store.
     *      NULL means "run without job tracking", which is what every existing
     *      unit test constructs and what a caller with no database wants.
     */
    private $scanJobsFactory;

    /**
     * The `library_scan_jobs` row this run owns, or `''` when there is none.
     *
     * ⚠ **Instance state on a Symfony console command, deliberately.** The signal
     * and shutdown handlers need to know which row to fail, and they cannot be
     * handed it any other way. This is a one-shot CLI process, NOT a Workerman
     * worker: the object is constructed once per `php bin/phlix` invocation and dies
     * with it, so this is not the resident-memory hazard that a `static` would be.
     */
    private string $jobId = '';

    /** @var ScanJobRepository|null Resolved store for the current run, if any. */
    private ?ScanJobRepository $jobs = null;

    /** True once the row has reached a terminal state, so handlers do not re-stamp. */
    private bool $jobFinished = false;

    /**
     * @param callable(): LibraryManager $libraryManagerFactory Lazy factory
     *        returning the backing {@see LibraryManager}. Invoked only inside
     *        {@see execute()}, never at registration time.
     * @param (callable(): ScanJobRepository)|null $scanJobsFactory Lazy factory for
     *        the `library_scan_jobs` store (S150). Optional and defaulted to NULL so
     *        that a caller without a database — including every pre-S150 test — keeps
     *        working: the scan then runs exactly as before, just invisibly to the UI.
     */
    public function __construct(callable $libraryManagerFactory, ?callable $scanJobsFactory = null)
    {
        $this->libraryManagerFactory = $libraryManagerFactory;
        $this->scanJobsFactory = $scanJobsFactory;
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
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Start even when the library already has a queued/running scan job (S150). Use this only to '
            . 'get past a row stranded `running` by a kill -9 or a power loss — starting a second real '
            . 'scan over the same library makes two scanners race on every per-file lookup and makes the '
            . 'admin badge report one scan\'s progress under the other\'s counters.'
        );
        // S347 — the exit-code contract used to live ONLY in the docblocks above, which an
        // operator or wrapper script never reads. `--help` is where they look; state each
        // code and its meaning here, and keep the README table in lock-step with it.
        $this->setHelp(
            "Exit codes:\n"
            . "  0  scan (or rescan) completed; every file was indexed\n"
            . "  1  scan did not run — unknown library id, the manager threw, or an already-queued/running "
            . "job refused the scan\n"
            . "  3  scan completed but N file(s) could not be indexed (the lossy warning is on stderr; each "
            . "file is named in .logs/error.log)\n"
            . "  2  Symfony's INVALID (invalid input/usage) — deliberately NOT used, so \"you typed the "
            . "arguments wrong\" and \"your library lost files\" never share a number\n"
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
        $force = (bool) $input->getOption('force');
        $type = $rescan ? 'rescan' : 'scan';

        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // S150 — open the job row BEFORE the scan, so the admin Libraries page shows a
        // live `running` badge from the first poll rather than the last web-enqueued
        // job's stale outcome. A store that is absent or unreachable degrades to the
        // pre-S150 behaviour (scan runs, UI cannot see it) instead of blocking a scan
        // an operator asked for at the console.
        $refusal = $this->openJobRow($libraryId, $type, $force, $errOutput);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            $manager = ($this->libraryManagerFactory)();
            $sink = $this->progressSink();
            $result = $rescan
                ? $manager->rescanLibrary($libraryId, [], $sink)
                : $manager->scanLibrary($libraryId, $sink);
        } catch (Throwable $e) {
            $this->failJob($e->getMessage());
            $output->writeln('<error>Scan failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        // Authoritative final counters, mirroring LibraryScanWorker::runOnce(). NOTE
        // the deliberate absence of `items_updated`: that column doubles as the
        // progress NUMERATOR (processed files), so writing ScanResult::$updated into
        // it would collapse the UI percentage at the instant the job completes.
        $this->completeJob([
            'items_added' => $result->added,
            'items_removed' => $result->removed,
            'items_failed' => $result->failed,
        ]);

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
            //
            // ⚠ The job row stays `completed`, not `failed`: the SCAN completed. The
            // lost files are reported through `items_failed`, which is exactly what the
            // worker does for the same shape (S96(f)). Flipping the row to `failed`
            // here would make the badge say the scan did not run.
            $errOutput->writeln(sprintf(
                '<comment>%d file(s) could not be indexed — see .logs/error.log for each one.</comment>',
                $result->failed
            ));

            return self::EXIT_FILES_LOST;
        }

        return Command::SUCCESS;
    }

    /**
     * Open the `library_scan_jobs` row this run owns, and install the handlers that
     * guarantee it reaches a terminal state.
     *
     * Degrades on purpose: with no factory, an unresolvable store, or a failing
     * INSERT, the scan still runs — just invisibly, exactly as it did before S150. An
     * observability feature must never be able to refuse an operator's scan.
     *
     * ⚠ **The handlers are installed BEFORE the row is created, and the id is minted
     * before either.** That ordering is the fix for review finding 1 and is explained
     * in full at the call site below — read it before reordering anything here.
     *
     * @param string          $libraryId Library being scanned.
     * @param string          $type      `scan` or `rescan`.
     * @param bool            $force     Skip the already-active refusal.
     * @param OutputInterface $errOutput Stream for warnings/refusals (stderr).
     *
     * @return int|null An exit code when the run must be REFUSED, else null.
     */
    private function openJobRow(string $libraryId, string $type, bool $force, OutputInterface $errOutput): ?int
    {
        if ($this->scanJobsFactory === null || $libraryId === '') {
            return null;
        }

        try {
            $jobs = ($this->scanJobsFactory)();
        } catch (Throwable $e) {
            $errOutput->writeln(
                '<comment>Scan-job tracking unavailable (' . $e->getMessage() . '); the scan will run but '
                . 'will not appear in the admin UI.</comment>'
            );

            return null;
        }

        try {
            if (!$force && $jobs->hasActiveJobForLibrary($libraryId)) {
                $errOutput->writeln(self::REFUSAL_MESSAGE);

                return Command::FAILURE;
            }

            // ⚠ **ORDERING IS LOAD-BEARING — DO NOT MOVE THE INSERT ABOVE THIS LINE.**
            // (S151, review finding 1.) The handlers below are what guarantee the row
            // reaches a terminal state, and they can only fail a row whose id they
            // already hold. Creating the row first left a window in which the row was
            // COMMITTED and externally visible while `$this->jobId` was still `''` and
            // no handler existed at all: a SIGTERM landing there killed the process
            // with the default disposition and stranded the row `running` forever —
            // the exact state S150 exists to abolish. It was not theoretical; the
            // integration test reproduced it in 3 runs out of 10, and a standalone
            // harness in 12 out of 15 with the server under extra latency.
            //
            // Minting the id up front closes the window COMPLETELY, not merely
            // narrowly, and the reason is PHP's signal dispatch, not luck:
            // `pcntl_async_signals(true)` sets `EG(vm_interrupt)` from the C handler
            // and the userland callback runs at the next VM interrupt check, i.e. at
            // an opcode boundary. It therefore CANNOT run in the middle of the
            // `PDOStatement::execute()` that performs the INSERT. So a signal arriving
            // in the INSERT's round-trip is dispatched only after the INSERT has
            // returned — by which point `markFailed($this->jobId)` names the row that
            // was just committed. A signal arriving BEFORE the INSERT stamps a row id
            // that does not exist yet (an UPDATE affecting 0 rows, harmless) and then
            // re-raises, so the INSERT never runs and there is no row to strand.
            $this->jobs = $jobs;
            $this->jobId = $jobs->newJobId();
            $this->installTerminationHandlers();

            // `startRunningIfIdle()` re-asserts the refusal INSIDE the INSERT, closing
            // the check-then-insert race the pre-check above cannot (review finding 5):
            // two invocations started together both read "idle", and only the guarded
            // statement can stop both of them inserting. The pre-check is kept because
            // it is the cheap, unambiguous operator message; the guarded insert is the
            // guarantee.
            $started = $force
                ? $jobs->startRunning($libraryId, $type, $this->jobId)
                : $jobs->startRunningIfIdle($libraryId, $type, $this->jobId);
        } catch (Throwable $e) {
            $this->abandonJobRow();
            $errOutput->writeln(
                '<comment>Could not create the scan-job row (' . $e->getMessage() . '); the scan will run '
                . 'but will not appear in the admin UI.</comment>'
            );

            return null;
        }

        if ($started === null) {
            // Lost the race against a concurrent starter, between the pre-check and
            // the INSERT. No row was written, so nothing needs unwinding.
            $this->abandonJobRow();
            $errOutput->writeln(self::REFUSAL_MESSAGE);

            return Command::FAILURE;
        }

        // The repository is the authority on the id of the row it wrote. It echoes the
        // id we minted, but re-reading it here means a future implementation that mints
        // its own cannot leave the handlers pointing at a row that does not exist.
        $this->jobId = $started;

        return null;
    }

    /**
     * Forget the row this run was going to own, so the already-installed termination
     * handlers become no-ops.
     *
     * Called when the INSERT was refused or failed. The handlers are installed BEFORE
     * the INSERT on purpose (see {@see self::openJobRow()}), so "no row exists" has to
     * be expressed by clearing the state they read rather than by not installing them.
     */
    private function abandonJobRow(): void
    {
        $this->jobs = null;
        $this->jobId = '';
    }

    /**
     * The progress sink handed to the scanner, or NULL when there is no job row.
     *
     * Shares ONE implementation with {@see \Phlix\Media\Library\LibraryScanWorker}
     * ({@see ScanProgressSink}) so the CLI and the worker cannot drift into reporting
     * progress differently.
     *
     * @return callable(int, int, string, array<string, int>): void|null
     */
    private function progressSink(): ?callable
    {
        if ($this->jobs === null || $this->jobId === '') {
            return null;
        }

        return ScanProgressSink::for($this->jobs, $this->jobId);
    }

    /**
     * Trap the signals a deploy / an impatient operator actually sends, plus a fatal
     * shutdown, so the row lands `failed` with a truthful reason.
     *
     * ⚠ **`SIGKILL` cannot be trapped, by anyone.** A `kill -9`, an OOM kill or a
     * power loss still leaves the row `running`. The backstop for that is
     * {@see \Phlix\Media\Library\LibraryScanWorker::start()}, which reaps every
     * `running` row when `phlix-server` next boots; `--force` is the manual override
     * in the meantime. This is stated rather than glossed because "never strands a
     * running row" is not a promise any in-process handler can keep.
     *
     * ⚠ The handler does NOT call `exit()`. It stamps the row, restores the default
     * disposition and re-raises, so the process dies with the correct
     * `128 + signo` status and the shell reports the signal it actually got.
     *
     * ## Is it safe for this handler to WRITE TO THE DATABASE? (review finding 7)
     *
     * Yes, and the reason is a property of PHP's signal machinery, not luck. It was
     * MEASURED rather than assumed:
     *
     *   * `pcntl_async_signals(true)` does not run PHP code from the C signal handler.
     *     The C handler sets `EG(vm_interrupt)`; the userland callback is invoked from
     *     the executor's interrupt check, i.e. at an OPCODE BOUNDARY. It therefore
     *     cannot be entered part-way through the C call that is executing a query.
     *     Probe: a `SELECT SLEEP(3)` on a PDO connection, SIGTERMed 500 ms in — the
     *     handler was entered at **3,003 ms**, not at 500 ms, in every run.
     *   * The connection this repository uses ({@see \Phlix\Common\Database\PhlixMySQLConnection})
     *     sets `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = true`, so a statement's whole
     *     result set is client-side before `execute()` returns. A query issued from the
     *     handler therefore starts on an idle connection; there is no half-read result
     *     stream to desync. The same probe issued `SELECT 42` from inside the handler
     *     while the outer statement was still on the PHP stack: the nested query
     *     returned normally, the outer statement then returned its rows correctly, and
     *     the connection was still healthy afterwards.
     *   * The stamp is a single autocommitted `UPDATE … WHERE id = ?` and
     *     {@see self::failJob()} is guarded by `$this->jobFinished`, so it can neither
     *     nest a transaction nor run twice.
     *
     * `CliScanJobVisibilityTest::testASigtermDeliveredMidQueryStillLandsTheRowFailed()`
     * pins this end to end against real MySQL, by signalling the child while it is
     * inside a genuinely slow query rather than at a quiescent point.
     *
     * ⚠ The residual is `SIGKILL`, as above — nothing else.
     */
    private function installTerminationHandlers(): void
    {
        // A fatal error (or any other early return path) still closes the row.
        register_shutdown_function(function (): void {
            $this->failJob('CLI scan process exited before the scan completed');
        });

        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach (self::TRAPPED_SIGNALS as $signal) {
            pcntl_signal($signal, function (int $signo): void {
                $this->failJob(sprintf('CLI scan interrupted by signal %d', $signo));

                // Re-raise with the default disposition rather than exit(): the caller
                // then observes the real signal, and no handler has to decide an exit
                // code on the process's behalf.
                pcntl_signal($signo, SIG_DFL);
                if (function_exists('posix_kill') && function_exists('posix_getpid')) {
                    posix_kill(posix_getpid(), $signo);
                }
            });
        }
    }

    /**
     * Stamp the row `completed` with its final counters. Idempotent.
     *
     * @param array<string, int> $finalCounts
     */
    private function completeJob(array $finalCounts): void
    {
        if ($this->jobs === null || $this->jobId === '' || $this->jobFinished) {
            return;
        }

        $this->jobFinished = true;

        try {
            $this->jobs->markCompleted($this->jobId, $finalCounts);
        } catch (Throwable) {
            // The scan itself succeeded; a bookkeeping failure must not change that.
            $this->jobs = null;
        }
    }

    /**
     * Stamp the row `failed` with a reason. Idempotent — the shutdown function runs
     * on EVERY exit path, including the ones that already stamped a terminal state.
     */
    private function failJob(string $reason): void
    {
        if ($this->jobs === null || $this->jobId === '' || $this->jobFinished) {
            return;
        }

        $this->jobFinished = true;

        try {
            $this->jobs->markFailed($this->jobId, $reason);
        } catch (Throwable) {
            $this->jobs = null;
        }
    }
}
