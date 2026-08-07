<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin\Maintenance;

use Phlix\Admin\Maintenance\MaintenanceTask;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Media\Library\PathDeduper;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Server\Http\Controllers\Admin\MaintenanceController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * The maintenance task vocabulary must stay covered end to end (S77).
 *
 * ## What can drift, and what it costs
 *
 * A task name appears in FOUR places: {@see MaintenanceTask::ALL}, the
 * {@see MaintenanceTask::CATALOGUE} the admin Tasks page renders from, the
 * `match` in {@see MaintenanceTaskRunner::run()}, and a route + handler on
 * {@see MaintenanceController}. `maintenance_jobs.task` is a VARCHAR on
 * purpose — the vocabulary lives in PHP, not in a column ENUM — so the database
 * will not reject a name nobody implemented. Each drift has its own failure:
 *
 *  - in `ALL` but not `CATALOGUE`: the Tasks page cannot render it;
 *  - in `ALL` but not the runner's `match`: a queued job fails at run time,
 *    minutes after the click, with "Unknown maintenance task";
 *  - in `ALL` but with no route: unreachable from the UI entirely.
 *
 * So the list is compared against each consumer here rather than trusted.
 */
final class MaintenanceTaskCoverageTest extends TestCase
{
    /** The number of tasks S77 shipped, asserted under its own name. */
    private const EXPECTED_TASK_COUNT = 5;

    public function test_the_task_list_is_the_five_tasks_s77_shipped(): void
    {
        self::assertCount(self::EXPECTED_TASK_COUNT, MaintenanceTask::ALL);
        self::assertSame(MaintenanceTask::ALL, array_values(array_unique(MaintenanceTask::ALL)));
    }

    /**
     * THE DRIFT GATE for the UI half: the catalogue is exactly the task list,
     * in the same order (the Tasks page renders it in that order).
     */
    public function test_the_catalogue_is_exactly_the_task_list(): void
    {
        self::assertSame(
            MaintenanceTask::ALL,
            array_keys(MaintenanceTask::CATALOGUE),
            'Every task needs a CATALOGUE entry, or the admin Tasks page cannot render it.'
        );
    }

    /**
     * Every catalogue entry declares a mode this code understands, plus the
     * label/description/destructive fields S78 renders.
     */
    public function test_every_catalogue_entry_is_complete_and_declares_a_known_mode(): void
    {
        foreach (MaintenanceTask::ALL as $task) {
            $entry = MaintenanceTask::CATALOGUE[$task];

            self::assertContains(
                $entry['mode'],
                [MaintenanceTask::MODE_SYNC, MaintenanceTask::MODE_QUEUED],
                "Task '{$task}' declares an unknown mode."
            );
            self::assertNotSame('', $entry['label'], "Task '{$task}' has no label.");
            self::assertNotSame('', $entry['description'], "Task '{$task}' has no description.");
        }
    }

    /**
     * THE DRIFT GATE for the execution half: {@see MaintenanceTaskRunner::run()}
     * has an arm for every task.
     *
     * Read off the SOURCE of the `match`, because actually calling `run()` for
     * each task would execute it. The unknown-name arm is proved separately, by
     * execution, in {@see MaintenanceTaskRunnerTest}; here the question is only
     * "is there a case label for each name".
     */
    public function test_the_runner_has_an_arm_for_every_task(): void
    {
        $file = (new ReflectionClass(MaintenanceTaskRunner::class))->getFileName();
        self::assertIsString($file);

        $method = (new ReflectionClass(MaintenanceTaskRunner::class))->getMethod('run');
        $lines = file($file);
        self::assertIsArray($lines);

        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        // Strip comments FIRST. A detector that fires on its own documentation
        // is not a detector: `run()`'s docblock names the constants, so without
        // this the test would pass on a `match` with every arm deleted.
        $stripped = self::stripComments($source);

        self::assertStringContainsString(
            'match (',
            $stripped,
            'ANTI-VACUITY: run() no longer dispatches with a match, so this detector is reading '
            . 'something other than what it thinks.'
        );

        foreach (MaintenanceTask::ALL as $task) {
            $constant = self::constantNameFor($task);

            self::assertStringContainsString(
                'MaintenanceTask::' . $constant . ' =>',
                $stripped,
                "MaintenanceTaskRunner::run() has no arm for MaintenanceTask::{$constant} "
                . "('{$task}'). A queued job for it would fail minutes after the click."
            );
        }
    }

    /**
     * THE DRIFT GATE for the routing half: every task has a POST route and a
     * handler method on the controller.
     */
    public function test_every_task_has_a_controller_handler_and_a_route(): void
    {
        $routesFile = file_get_contents(
            dirname(__DIR__, 4) . '/src/Server/Http/Routes/AdminRoutes.php'
        );
        self::assertIsString($routesFile);
        $routes = self::stripComments($routesFile);

        $controller = new ReflectionClass(MaintenanceController::class);

        foreach (MaintenanceTask::ALL as $task) {
            $path = "'/maintenance/" . $task . "'";
            self::assertStringContainsString(
                $path,
                $routes,
                "AdminRoutes registers no POST {$path} for task '{$task}', so it is unreachable."
            );

            $handler = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $task))));
            self::assertTrue(
                $controller->hasMethod($handler),
                "MaintenanceController has no `{$handler}()` handler for task '{$task}'."
            );
        }
    }

    /**
     * The sync/queued split is not arbitrary — the two tasks that shell out or
     * scan a whole table MUST be queued, or they stall the event loop.
     *
     * Pinned by name rather than "some are queued", because the whole point of
     * the split is WHICH ones.
     */
    public function test_the_expensive_tasks_are_queued_and_the_bounded_ones_are_not(): void
    {
        self::assertSame(MaintenanceTask::MODE_QUEUED, MaintenanceTask::mode(MaintenanceTask::STORAGE_SNAPSHOT));
        self::assertSame(MaintenanceTask::MODE_QUEUED, MaintenanceTask::mode(MaintenanceTask::DEDUPE_PATHS));

        self::assertSame(MaintenanceTask::MODE_SYNC, MaintenanceTask::mode(MaintenanceTask::REAP_SCAN_JOBS));
        self::assertSame(MaintenanceTask::MODE_SYNC, MaintenanceTask::mode(MaintenanceTask::REAP_TRANSCODE_JOBS));
        self::assertSame(
            MaintenanceTask::MODE_SYNC,
            MaintenanceTask::mode(MaintenanceTask::CLEANUP_ORPHANED_STATS)
        );

        self::assertFalse(MaintenanceTask::isSynchronous(MaintenanceTask::STORAGE_SNAPSHOT));
        self::assertTrue(MaintenanceTask::isSynchronous(MaintenanceTask::REAP_SCAN_JOBS));
    }

    /**
     * An UNKNOWN task defaults to queued, never to sync.
     *
     * The safe direction: a name nobody recognises must not be handed to the
     * request path, where a blocking implementation would stall the worker.
     */
    public function test_an_unknown_task_is_never_treated_as_synchronous(): void
    {
        self::assertFalse(MaintenanceTask::isValid('not-a-task'));
        self::assertFalse(MaintenanceTask::isSynchronous('not-a-task'));
        self::assertSame(MaintenanceTask::MODE_QUEUED, MaintenanceTask::mode('not-a-task'));
    }

    /**
     * The catalogue the API serves carries the fields S78 needs, per task.
     */
    public function test_the_served_catalogue_shape(): void
    {
        $catalogue = MaintenanceTask::catalogue();

        self::assertCount(self::EXPECTED_TASK_COUNT, $catalogue);
        self::assertSame(MaintenanceTask::ALL, array_column($catalogue, 'task'));

        foreach ($catalogue as $entry) {
            self::assertSame(
                ['task', 'mode', 'label', 'description', 'destructive'],
                array_keys($entry)
            );
        }

        // The two tasks that delete or merge rows are flagged, so the UI can
        // confirm before firing them. Asserted with its negative half, or
        // "everything is destructive" would pass.
        $destructive = array_column($catalogue, 'destructive', 'task');
        self::assertTrue($destructive[MaintenanceTask::CLEANUP_ORPHANED_STATS]);
        self::assertTrue($destructive[MaintenanceTask::DEDUPE_PATHS]);
        self::assertFalse($destructive[MaintenanceTask::STORAGE_SNAPSHOT]);
        self::assertFalse($destructive[MaintenanceTask::REAP_SCAN_JOBS]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The constant NAME for a task value, resolved from the class rather than
     * derived by string mangling, so a rename cannot silently pass.
     */
    private static function constantNameFor(string $task): string
    {
        /** @var array<string, mixed> $constants */
        $constants = (new ReflectionClass(MaintenanceTask::class))->getConstants();

        foreach ($constants as $name => $value) {
            if ($value === $task) {
                return $name;
            }
        }

        self::fail("No MaintenanceTask constant holds the value '{$task}'.");
    }

    /**
     * Remove `//`, `#` and `/* … *\/` comments so a source-scanning assertion
     * cannot be satisfied by prose that merely NAMES the thing it is looking
     * for. This repo has shipped five detectors that fired on their own docs.
     */
    private static function stripComments(string $source): string
    {
        // token_get_all() only tokenises PHP once it has seen an open tag, so a
        // method-body FRAGMENT needs one prepended or every comment survives and
        // the strip silently does nothing.
        if (!str_starts_with(ltrim($source), '<?php')) {
            $source = "<?php\n" . $source;
        }

        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
