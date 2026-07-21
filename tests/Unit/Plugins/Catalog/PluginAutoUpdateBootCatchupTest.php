<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Catalog\PluginAutoUpdateWorker;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Catalog\PluginUpdateService;
use Phlix\Plugins\PluginLoader;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Consequence tests for the post-boot plugin auto-update catch-up.
 *
 * ## The defect this pins
 *
 * `config/process.php` polls this worker every 86400 s, and `start()` used to
 * arm nothing but `Timer::add(86400, …)`. A bare periodic timer fires only
 * after a full interval of UNINTERRUPTED uptime, and every restart or reload
 * resets the countdown — so on a box that is deployed to, the tick never
 * arrives and plugins are never updated.
 *
 * That was the live production state, and it is observable rather than
 * theoretical: `plugins-*.log` recorded `PluginAutoUpdateWorker::start` on
 * every boot and `runOnce` **not once**, across six restarts on 2026-07-21
 * alone, while trakt sat at 1.2.1 against a catalog offering 1.3.0. Identical
 * defect and identical fix to the scheduled-backup timer.
 *
 * ## Workerman note
 *
 * `Timer::add()` throws outside a Workerman runtime unless
 * `Worker::getAllWorkers()` is non-empty, and `start()` does not swallow that —
 * so without seeding the runtime these tests would fail inside Workerman rather
 * than on the behaviour under test. Seeding makes `Timer::add()` take its real
 * `self::$tasks` branch, which is also what makes the schedule inspectable.
 */
final class PluginAutoUpdateBootCatchupTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedWorkers = Worker::getAllWorkers();

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $stub = new Worker();
        $workers->setValue(null, [spl_object_id($stub) => $stub]);

        Timer::delAll();
    }

    protected function tearDown(): void
    {
        Timer::delAll();

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        parent::tearDown();
    }


    /**
     * A real PluginCatalogService with auto-update opted out.
     *
     * PluginCatalogService and PluginUpdateService are both `final`, so they
     * cannot be doubled; this mirrors how PluginAutoUpdateWorkerTest builds
     * them — a real service over a mocked SettingsRepository and a fetcher that
     * would throw if it were ever called.
     */
    private function optedOutCatalog(): PluginCatalogService
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                PluginCatalogService::KEY_AUTO_UPDATE => false,
                default => null,
            },
        );

        return new PluginCatalogService(
            $settings,
            static function (string $url, int $t): string {
                throw new \RuntimeException('No catalog fetch expected in this test: ' . $url);
            },
        );
    }

    private function updateService(PluginCatalogService $catalog): PluginUpdateService
    {
        $loader = Mockery::mock(PluginLoader::class);
        // updateAll() is never reached while auto-update is off.
        $loader->shouldNotReceive('listInstalled');

        return new PluginUpdateService(
            $loader,
            $catalog,
            static function (string $url, int $t): string {
                throw new \RuntimeException('No manifest fetch expected in this test: ' . $url);
            },
        );
    }

    /**
     * Arm the real timers and return the scheduled tasks keyed by interval.
     *
     * @return array<int, array{persistent: bool, run: callable}> interval => task
     */
    private function armAndCollect(int $pollSeconds = 86400): array
    {
        // Opted OUT, so the catch-up tick is a cheap no-op. What is under test
        // is the SCHEDULE, not what a tick does — runOnce() has its own tests.
        $catalog = $this->optedOutCatalog();

        $worker = new PluginAutoUpdateWorker(
            $catalog,
            $this->updateService($catalog),
            $this->createMock(StructuredLogger::class),
        );

        $worker->start($pollSeconds);

        $tasksProp = new ReflectionProperty(Timer::class, 'tasks');
        $tasksProp->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = $tasksProp->getValue();

        $collected = [];
        foreach ($tasks as $group) {
            foreach ($group as $task) {
                // Recover the requested interval from the task tuple rather than
                // from the run time, which is subject to clock granularity.
                $collected[(int) $task[3]] = [
                    'persistent' => $task[2],
                    'run'        => static fn() => ($task[0])(...$task[1]),
                ];
            }
        }

        return $collected;
    }

    /**
     * CONSEQUENCE: an update check is armed within minutes of boot.
     *
     * This is the whole defect. With only the daily timer armed, a box that
     * restarts more often than daily never reaches a check, and installed
     * plugins stay stale forever — exactly what production showed.
     *
     * Mutation-verified: deleting the BOOT_CATCHUP_DELAY `Timer::add` call
     * leaves only the 86400 task and this fails.
     */
    public function test_an_update_check_is_armed_within_minutes_of_boot(): void
    {
        $tasks = $this->armAndCollect();

        $shortDelays = array_filter(array_keys($tasks), static fn(int $d): bool => $d < 3600);

        self::assertNotEmpty(
            $shortDelays,
            'No plugin update check is armed within an hour of boot. A poll-only '
            . 'timer requires a full interval of uninterrupted uptime, which a '
            . 'deployed-to box never has, so plugins are never updated.'
        );
    }

    /**
     * CONSEQUENCE: the catch-up runs ONCE, not on a 300-second loop.
     *
     * Workerman's `Timer::add` is persistent by DEFAULT — omitting the
     * `[], false` arguments would silently turn the catch-up into a
     * five-minute polling loop that re-fetches the catalog all day. The
     * steady-state timer must stay persistent.
     *
     * Mutation-verified: dropping `[], false` from the catch-up call fails this.
     */
    public function test_the_catchup_is_one_shot_and_the_poll_is_persistent(): void
    {
        $tasks = $this->armAndCollect();

        self::assertArrayHasKey(300, $tasks, 'Expected a 300s catch-up task.');
        self::assertFalse(
            $tasks[300]['persistent'],
            'The boot catch-up must be one-shot; a persistent 300s timer would '
            . 'poll the catalog every five minutes forever.'
        );

        self::assertArrayHasKey(86400, $tasks, 'Expected the steady-state poll task.');
        self::assertTrue(
            $tasks[86400]['persistent'],
            'The steady-state poll must remain persistent.'
        );
    }

    /**
     * CONSEQUENCE: the steady-state poll still honours its configured interval.
     *
     * The catch-up must not replace or hardcode the poll. Uses a non-default
     * interval so a hardcoded 86400 cannot pass by coincidence.
     *
     * Mutation-verified: hardcoding the poll to 86400 fails this.
     */
    public function test_the_poll_interval_is_the_one_configured(): void
    {
        $tasks = $this->armAndCollect(3600);

        self::assertArrayHasKey(3600, $tasks, 'The configured poll interval must be used verbatim.');
        self::assertTrue($tasks[3600]['persistent']);
        self::assertArrayHasKey(300, $tasks, 'The catch-up must still be armed alongside it.');
    }

    /**
     * CONSEQUENCE: the catch-up is safe to fire on every boot.
     *
     * Restart churn re-runs this, so it must be a no-op when the operator has
     * not opted in. Asserts the tick returns without touching the update
     * service at all — the mock fails the test if any method is called.
     */
    public function test_the_catchup_tick_is_a_no_op_when_auto_update_is_off(): void
    {
        // The mocked PluginLoader below asserts updateAll() is never reached:
        // its shouldNotReceive('listInstalled') fails the test if the guard is
        // removed, since that is updateAll()'s first call.
        $catalog = $this->optedOutCatalog();
        $worker = new PluginAutoUpdateWorker(
            $catalog,
            $this->updateService($catalog),
            $this->createMock(StructuredLogger::class),
        );

        self::assertFalse($worker->runOnce(), 'An opted-out tick must do nothing.');
    }
}
