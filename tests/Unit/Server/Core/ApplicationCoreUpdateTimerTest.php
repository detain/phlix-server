<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Core\Application;
use Phlix\Server\Updates\CoreUpdateCheckService;
use Phlix\Server\Updates\CoreUpdateCheckWorker;
use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use Phlix\Tests\Support\Database\InMemoryServerSettingsConnection;
use Phlix\Tests\Support\Updates\RecordingVersionMarkerFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Workerman\MySQL\Connection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * S74 AC2, at the WIRING layer: {@see Application::startBackgroundTimers()} must
 * actually arm the core update check, on the count=1 `phlix-background-timers`
 * worker that `start.php` runs.
 *
 * ## Why this is a separate file from CoreUpdateCheckWorkerTest
 *
 * `CoreUpdateCheckWorker::start()` being correct is worthless if nothing calls
 * it — that is precisely the defect `startBackgroundTimers()` itself exists to
 * fix (backups, storage snapshots, the transcode reaper and the newsletter were
 * all registered only in the CGI-era `Application::run()`, which the daemon
 * never calls, so none of them ever ran on a Workerman install).
 *
 * ## Why the assertions FIRE the timers instead of reading intervals
 *
 * `config/backup.php` ships enabled, so the backup timer already occupies both
 * the 300 s one-shot slot and the 86400 s persistent slot. Asserting "a task
 * with interval 300 exists" would therefore pass with the update check deleted
 * entirely. Every assertion below instead RUNS the scheduled callbacks and
 * observes whether the marker fetcher was reached — a consequence only the
 * update check can produce.
 *
 * @package Phlix\Tests\Unit\Server\Core
 */
final class ApplicationCoreUpdateTimerTest extends TestCase
{
    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/phlix_s74_apptimer_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

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

        foreach (glob($this->tempDir . '/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir . '/config');
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * Records every marker fetch, so a tick can be OBSERVED.
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
            'https://marker.invalid/VERSION',
            'noop',
            '1.2.2',
        );

        return new CoreUpdateCheckWorker($service, $this->createMock(StructuredLogger::class));
    }

    /**
     * Build an Application without running the heavy route-loading constructor,
     * matching the convention in the sibling Application tests.
     *
     * @param array<string, mixed> $config
     */
    private function makeApp(array $config, ConnectionPool $pool, ContainerInterface $container): Application
    {
        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        foreach (['config' => $config, 'connectionPool' => $pool, 'container' => $container] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($app, $value);
        }

        return $app;
    }

    private function pool(): ConnectionPool
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($db);

        return $pool;
    }

    /**
     * A container that binds ONLY the update worker; everything else is
     * unbound, exactly as it looks to the other timers in this method.
     */
    private function container(?CoreUpdateCheckWorker $worker): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($worker): mixed {
                if ($id === CoreUpdateCheckWorker::class && $worker !== null) {
                    return $worker;
                }

                throw new \RuntimeException('not bound: ' . $id);
            },
        );

        return $container;
    }

    /**
     * Every scheduled task, as a flat list of `[interval, persistent, run]`.
     *
     * Deliberately a LIST, not an interval-keyed map: the backup timer already
     * occupies 300 and 86400, so a map would silently drop one of the two tasks
     * at each interval and hide exactly the regression under test.
     *
     * @return list<array{interval: int, persistent: bool, run: callable}>
     */
    private function scheduledTasks(): array
    {
        $tasksProp = new ReflectionProperty(Timer::class, 'tasks');
        $tasksProp->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = $tasksProp->getValue();

        $out = [];
        foreach ($tasks as $group) {
            foreach ($group as $task) {
                $out[] = [
                    'interval'   => (int) $task[3],
                    'persistent' => $task[2],
                    'run'        => static fn() => ($task[0])(...$task[1]),
                ];
            }
        }

        return $out;
    }

    private function bootTimers(?CoreUpdateCheckWorker $worker, ?string $configDir = null): void
    {
        $app = $this->makeApp(
            ['_config_dir' => $configDir ?? dirname(__DIR__, 4) . '/config'],
            $this->pool(),
            $this->container($worker),
        );

        $app->startBackgroundTimers();
    }

    /**
     * A config directory carrying ONLY `updates.php`.
     *
     * Used by the tests that FIRE the scheduled callbacks. With the real config
     * directory the backup timer is enabled and its callback reaches
     * `BackupManager`, which `mkdir()`s a backup root the test user cannot
     * create — noise unrelated to what is being measured. Everything else in
     * `startBackgroundTimers()` still runs: the newsletter gate returns early
     * (no `newsletter` config key), the storage-snapshot timer arms against the
     * mocked pool, and the transcode reaper's container lookup throws and is
     * caught.
     */
    private function updatesOnlyConfigDir(): string
    {
        $dir = $this->tempDir . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $dir . '/updates.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['poll_seconds' => 86400];\n",
        );

        return $dir;
    }

    // ------------------------------------------------------------------
    // AC2 through Application
    // ------------------------------------------------------------------

    /**
     * ARM A — a one-shot task armed by `startBackgroundTimers()` performs a real
     * core update check.
     */
    public function testTheBootCatchupArmedByStartBackgroundTimersPerformsACheck(): void
    {
        $fetcher = $this->recordingFetcher();
        $this->bootTimers($this->worker($fetcher), $this->updatesOnlyConfigDir());

        self::assertSame([], $fetcher->urls, 'Arming must not fetch — the catch-up is delayed.');

        $fired = 0;
        foreach ($this->scheduledTasks() as $task) {
            if (!$task['persistent']) {
                ($task['run'])();
                $fired++;
            }
        }

        self::assertGreaterThan(0, $fired, 'No one-shot task was armed at all.');
        self::assertSame(
            ['https://marker.invalid/VERSION'],
            $fetcher->urls,
            'Firing every one-shot timer must reach the core update check exactly once. A server '
            . 'that restarts more often than the poll interval otherwise never checks at all.',
        );
    }

    /**
     * ARM B — a persistent task armed by `startBackgroundTimers()` performs a
     * real core update check.
     */
    public function testTheSteadyStatePollArmedByStartBackgroundTimersPerformsACheck(): void
    {
        $fetcher = $this->recordingFetcher();
        $this->bootTimers($this->worker($fetcher), $this->updatesOnlyConfigDir());

        $fired = 0;
        foreach ($this->scheduledTasks() as $task) {
            if ($task['persistent']) {
                ($task['run'])();
                $fired++;
            }
        }

        self::assertGreaterThan(0, $fired, 'No persistent task was armed at all.');
        self::assertSame(
            ['https://marker.invalid/VERSION'],
            $fetcher->urls,
            'Firing every persistent timer must reach the core update check exactly once.',
        );
    }

    /**
     * CONTROL. The two assertions above would also pass if ONE timer served both
     * roles; this pins that the update check contributes a one-shot AND a
     * persistent task, and that firing both yields two checks.
     */
    public function testBothArmsAreContributedAndAreIndependent(): void
    {
        $fetcher = $this->recordingFetcher();
        $configDir = $this->updatesOnlyConfigDir();

        self::assertCount(0, $this->scheduledTasks(), 'The timer table must start empty.');

        $this->bootTimers(null, $configDir);
        $withoutUpdateCheck = $this->scheduledTasks();
        Timer::delAll();

        $this->bootTimers($this->worker($fetcher), $configDir);
        $withUpdateCheck = $this->scheduledTasks();

        self::assertCount(
            count($withoutUpdateCheck) + 2,
            $withUpdateCheck,
            'The update check must add exactly TWO timers to the background-timer worker.',
        );

        foreach ($withUpdateCheck as $task) {
            ($task['run'])();
        }

        self::assertCount(
            2,
            $fetcher->urls,
            'Both arms must independently reach the check — one timer serving both roles is the '
            . 'defect, not the fix.',
        );
    }

    /**
     * The same +2 claim against the REAL `config/` directory, without firing
     * anything: this is what proves the production config path also arms both
     * arms, alongside the isolated-config tests that fire the callbacks.
     */
    public function testTheRealConfigDirectoryAlsoContributesExactlyTwoTimers(): void
    {
        $this->bootTimers(null);
        $withoutUpdateCheck = count($this->scheduledTasks());
        Timer::delAll();

        $this->bootTimers($this->worker($this->recordingFetcher()));

        self::assertCount(
            $withoutUpdateCheck + 2,
            $this->scheduledTasks(),
            'With the shipped config/updates.php, startBackgroundTimers() must arm the boot '
            . 'catch-up AND the steady-state poll.',
        );
    }

    /**
     * A missing binding must degrade to "no update check", never to a crashed
     * background-timer worker: this runs inside a forked child where an uncaught
     * throwable costs availability.
     */
    public function testAnUnboundUpdateWorkerDoesNotAbortTheOtherTimers(): void
    {
        $this->bootTimers(null);

        self::assertNotEmpty(
            $this->scheduledTasks(),
            'The other background timers must still be armed when the update worker is unbound.',
        );
    }

    /**
     * The arming must NOT be gated on `updates.check_enabled`. Gating it there
     * would mean an admin re-enabling the check silently needs a restart; the
     * toggle is read per-tick by the service instead.
     */
    public function testTheArmingIsNotGatedOnTheCheckEnabledToggle(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Server/Core/Application.php');

        $start = strpos($source, 'private function startCoreUpdateCheckTimer');
        self::assertIsInt($start, 'startCoreUpdateCheckTimer() must exist on Application.');
        $body = substr($source, $start, 2600);

        self::assertStringNotContainsString('isCheckEnabled', $body);
        self::assertStringNotContainsString('check_enabled', $body);
    }
}
