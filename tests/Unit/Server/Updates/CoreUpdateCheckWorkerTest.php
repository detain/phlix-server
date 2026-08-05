<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Updates;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Updates\CoreUpdateCheckService;
use Phlix\Server\Updates\CoreUpdateCheckWorker;
use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use Phlix\Tests\Support\Database\InMemoryServerSettingsConnection;
use Phlix\Tests\Support\Updates\RecordingVersionMarkerFetcher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\Timer;
use Workerman\Worker;

/**
 * S74 AC2 — {@see CoreUpdateCheckWorker} must fire on BOTH a fresh boot and the
 * steady-state daily poll.
 *
 * ## The defect these pin
 *
 * A bare `Timer::add(86400, …)` fires its first tick 86400 seconds after the
 * process starts, and EVERY restart resets that countdown. On a box that is
 * deployed to, the tick never arrives. That is not a hypothesis in this repo —
 * it has shipped twice:
 *
 *  - the scheduled-backup timer (`backups` empty on production for weeks), and
 *  - {@see \Phlix\Plugins\Catalog\PluginAutoUpdateWorker}, whose log recorded
 *    `start` on all six restarts of 2026-07-21 and `runOnce` not once.
 *
 * So the two arms are asserted SEPARATELY and are designed to fail separately:
 * deleting the boot catch-up must not be able to keep the poll assertions green,
 * and vice versa.
 *
 * ## Workerman note
 *
 * `Timer::add()` throws outside a Workerman runtime unless
 * `Worker::getAllWorkers()` is non-empty. Seeding the registry makes it take its
 * real `self::$tasks` branch, which is also what makes the schedule inspectable.
 * Same technique as {@see \Phlix\Tests\Unit\Plugins\Catalog\PluginAutoUpdateBootCatchupTest}.
 *
 * @package Phlix\Tests\Unit\Server\Updates
 */
final class CoreUpdateCheckWorkerTest extends TestCase
{
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
     * A fetcher that records every call, so a tick can be OBSERVED rather than
     * inferred.
     */
    private function recordingFetcher(): RecordingVersionMarkerFetcher
    {
        return new RecordingVersionMarkerFetcher('1.2.2');
    }

    private function worker(VersionMarkerFetcherInterface $fetcher): CoreUpdateCheckWorker
    {
        $service = new CoreUpdateCheckService(
            new SettingsRepository(new InMemoryServerSettingsConnection(), dirname(__DIR__, 4) . '/config'),
            $fetcher,
            $this->createMock(StructuredLogger::class),
            'https://example.invalid/VERSION',
            'noop',
            '1.2.2',
        );

        return new CoreUpdateCheckWorker($service, $this->createMock(StructuredLogger::class));
    }

    /**
     * Arm the real timers and return the scheduled tasks keyed by interval.
     *
     * @return array<int, array{persistent: bool, run: callable}> interval => task
     */
    private function armAndCollect(CoreUpdateCheckWorker $worker, ?int $pollSeconds = null): array
    {
        if ($pollSeconds === null) {
            $worker->start();
        } else {
            $worker->start($pollSeconds);
        }

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

    // ------------------------------------------------------------------
    // ARM A — fresh boot
    // ------------------------------------------------------------------

    /**
     * AC2, arm A: an update check is armed within minutes of boot.
     */
    public function testACheckIsArmedWithinMinutesOfBoot(): void
    {
        $tasks = $this->armAndCollect($this->worker($this->recordingFetcher()));

        $shortDelays = array_filter(array_keys($tasks), static fn(int $d): bool => $d < 3600);

        self::assertNotEmpty(
            $shortDelays,
            'No core update check is armed within an hour of boot. A poll-only timer needs a '
            . 'full interval of uninterrupted uptime, which a deployed-to box never has, so the '
            . 'server would never learn a new release exists.',
        );
        self::assertContains(CoreUpdateCheckWorker::BOOT_CATCHUP_DELAY, array_keys($tasks));
    }

    /**
     * ARM A must be ONE-SHOT. `Timer::add()` is persistent by default, so
     * omitting `[], false` would turn the catch-up into a five-minute poll that
     * hits GitHub 288 times a day.
     */
    public function testTheBootCatchupIsOneShot(): void
    {
        $tasks = $this->armAndCollect($this->worker($this->recordingFetcher()));

        self::assertArrayHasKey(CoreUpdateCheckWorker::BOOT_CATCHUP_DELAY, $tasks);
        self::assertFalse(
            $tasks[CoreUpdateCheckWorker::BOOT_CATCHUP_DELAY]['persistent'],
            'The boot catch-up must be one-shot.',
        );
    }

    /**
     * ARM A must actually PERFORM a check when it fires — an armed timer whose
     * callback does nothing is the same outage with a different shape.
     */
    public function testFiringTheBootCatchupPerformsARealCheck(): void
    {
        $fetcher = $this->recordingFetcher();
        $tasks = $this->armAndCollect($this->worker($fetcher));

        self::assertSame([], $fetcher->urls, 'Arming alone must not fetch — the delay is the point.');

        ($tasks[CoreUpdateCheckWorker::BOOT_CATCHUP_DELAY]['run'])();

        self::assertSame(
            ['https://example.invalid/VERSION'],
            $fetcher->urls,
            'The boot catch-up callback must reach CoreUpdateCheckService::check().',
        );
    }

    // ------------------------------------------------------------------
    // ARM B — steady-state daily poll
    // ------------------------------------------------------------------

    /**
     * AC2, arm B: the steady-state poll is armed and PERSISTENT.
     */
    public function testTheSteadyStatePollIsArmedAndPersistent(): void
    {
        $tasks = $this->armAndCollect($this->worker($this->recordingFetcher()));

        self::assertArrayHasKey(
            CoreUpdateCheckWorker::DEFAULT_POLL_SECONDS,
            $tasks,
            'Expected the steady-state daily poll task.',
        );
        self::assertTrue(
            $tasks[CoreUpdateCheckWorker::DEFAULT_POLL_SECONDS]['persistent'],
            'The steady-state poll must be persistent, or it checks exactly once and stops.',
        );
    }

    /**
     * ARM B must honour the CONFIGURED interval, not a hardcoded 86400. Uses a
     * non-default value so a hardcoded constant cannot pass by coincidence.
     */
    public function testTheSteadyStatePollUsesTheConfiguredInterval(): void
    {
        $tasks = $this->armAndCollect($this->worker($this->recordingFetcher()), 3600);

        self::assertArrayHasKey(3600, $tasks, 'The configured poll interval must be used verbatim.');
        self::assertTrue($tasks[3600]['persistent']);
        self::assertArrayNotHasKey(
            CoreUpdateCheckWorker::DEFAULT_POLL_SECONDS,
            $tasks,
            'A hardcoded 86400 alongside the configured interval would double-poll.',
        );
        self::assertArrayHasKey(
            CoreUpdateCheckWorker::BOOT_CATCHUP_DELAY,
            $tasks,
            'The catch-up must still be armed alongside a custom interval.',
        );
    }

    /**
     * ARM B must actually PERFORM a check when it fires.
     */
    public function testFiringTheSteadyStatePollPerformsARealCheck(): void
    {
        $fetcher = $this->recordingFetcher();
        $tasks = $this->armAndCollect($this->worker($fetcher), 3600);

        ($tasks[3600]['run'])();

        self::assertSame(
            ['https://example.invalid/VERSION'],
            $fetcher->urls,
            'The steady-state poll callback must reach CoreUpdateCheckService::check().',
        );
    }

    /**
     * The two arms are DISTINCT timers, not one timer counted twice.
     */
    public function testBothArmsAreArmedAndAreDifferentTimers(): void
    {
        $tasks = $this->armAndCollect($this->worker($this->recordingFetcher()), 3600);

        self::assertCount(2, $tasks, 'Exactly two timers: one-shot catch-up + persistent poll.');
        self::assertNotSame(
            $tasks[CoreUpdateCheckWorker::BOOT_CATCHUP_DELAY]['persistent'],
            $tasks[3600]['persistent'],
            'One arm must be one-shot and the other persistent.',
        );
    }

    // ------------------------------------------------------------------
    // Robustness
    // ------------------------------------------------------------------

    /**
     * A non-positive interval must fall back rather than throw out of a boot
     * path — `Timer::add()` rejects a negative interval with a RuntimeException.
     */
    public function testANonPositiveIntervalFallsBackToTheDefault(): void
    {
        $tasks = $this->armAndCollect($this->worker($this->recordingFetcher()), 0);

        self::assertArrayHasKey(CoreUpdateCheckWorker::DEFAULT_POLL_SECONDS, $tasks);
        self::assertTrue($tasks[CoreUpdateCheckWorker::DEFAULT_POLL_SECONDS]['persistent']);
    }

    /**
     * A tick that throws must be swallowed: an exception escaping a Workerman
     * timer callback takes the worker's tick with it.
     */
    public function testAThrowingTickIsSwallowed(): void
    {
        $exploding = new RecordingVersionMarkerFetcher(null, null, true);

        self::assertFalse($this->worker($exploding)->runOnce());
    }

    public function testAHealthyTickReportsSuccess(): void
    {
        self::assertTrue($this->worker($this->recordingFetcher())->runOnce());
    }
}
