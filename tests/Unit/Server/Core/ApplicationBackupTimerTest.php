<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\BackupManager;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Server\Core\Application;
use Psr\Container\ContainerInterface;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Regression tests for the automatic-backup timer's ARMING SCHEDULE.
 *
 * ## The defect these pin
 *
 * `registerBackupTimer()` armed a single `Timer::add(86400, ...)` and nothing
 * else, so the "is a backup due?" decision required **24 hours of uninterrupted
 * uptime**. A restart or reload resets a Workerman timer's countdown to zero, so
 * any install that is deployed to more than once a day never backed up at all.
 *
 * Production proved it: at the 2026-07-20 deploy the timer worker was alive, its
 * sibling storage-snapshot timer had written 5 rows to `stats_storage`, and the
 * `backups` table was still empty. The feature was armed and non-functional.
 *
 * ## Why the sibling suite did not catch it
 *
 * {@see ApplicationBackgroundTimersTest} asserts the timers take DB connections,
 * which they do — the connection is taken in `startBackupTimerIfEnabled()` before
 * `registerBackupTimer()` is ever reached. Worse, `Timer::add()` throws outside a
 * Workerman runtime and `startBackupTimerIfEnabled()` swallows it, so under that
 * suite the scheduling code never executed at all. These tests seed a worker so
 * `Timer::add()` takes its real in-process path and the schedule is observable.
 *
 * Every assertion below was mutation-verified — see each test's docblock.
 */
class ApplicationBackupTimerTest extends TestCase
{
    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    /**
     * `Timer::add()` refuses to schedule unless `Worker::getAllWorkers()` is
     * non-empty, taking its `self::$tasks` path only inside a "running"
     * environment. Seeding one worker makes the real scheduling observable
     * instead of exception-swallowed.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $workers = new \ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        /** @var array<int, Worker> $existing */
        $existing = $workers->getValue();
        $this->savedWorkers = $existing;

        $stub = new Worker();
        $workers->setValue(null, [spl_object_id($stub) => $stub]);

        Timer::delAll();
    }

    protected function tearDown(): void
    {
        Timer::delAll();

        $workers = new \ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        parent::tearDown();
    }

    /**
     * Arm the real timer and return the scheduled tasks keyed by delay.
     *
     * @return array<int, array{persistent: bool, run: callable}> delay => task
     */
    private function armAndCollect(BackupManager $manager, int $intervalDays = 7): array
    {
        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        $register = $ref->getMethod('registerBackupTimer');
        $register->setAccessible(true);

        $before = time();
        $register->invoke($app, $manager, $intervalDays);

        $tasksProp = new \ReflectionProperty(Timer::class, 'tasks');
        $tasksProp->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = $tasksProp->getValue();

        $collected = [];
        foreach ($tasks as $runTime => $group) {
            foreach ($group as $task) {
                // Recover the requested interval from the task tuple rather than
                // from $runTime - $before, which is subject to clock granularity.
                $collected[(int) $task[3]] = [
                    'persistent' => $task[2],
                    'run' => static fn() => ($task[0])(...$task[1]),
                ];
            }
        }

        self::assertGreaterThanOrEqual($before, max(array_keys($tasks)));

        return $collected;
    }

    /**
     * CONSEQUENCE: a backup decision happens within minutes of boot.
     *
     * This is the whole defect. If the only armed timer is the daily one, a box
     * that restarts daily never reaches a decision point, and `backups` stays
     * empty forever — exactly what production showed.
     *
     * Mutation-verified: deleting the BACKUP_INITIAL_CHECK_DELAY `Timer::add`
     * call leaves only the 86400 task and this fails on the boundary assertion.
     */
    public function test_a_backup_check_is_armed_within_minutes_of_boot(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->method('getNextScheduledBackup')->willReturn(time());

        $tasks = $this->armAndCollect($manager);

        $shortDelays = array_filter(array_keys($tasks), static fn(int $d): bool => $d < 3600);

        self::assertNotEmpty(
            $shortDelays,
            'No backup check is armed within an hour of boot. A daily-only timer '
            . 'requires 24h of uninterrupted uptime, which a deployed-to box never '
            . 'has, so automatic backups never run.'
        );
    }

    /**
     * CONSEQUENCE: the post-boot check actually creates a backup when one is due.
     *
     * Arming a timer is worthless if its callback does not do the work. This
     * invokes the scheduled closure for real and requires `createBackup('auto')`.
     *
     * Mutation-verified: changing the closure's `$now >= $nextBackup` comparison
     * to `>` alone does not fail this (times are equal only incidentally), but
     * removing the `createBackup()` call does — which is the behaviour asserted.
     */
    public function test_the_post_boot_check_creates_a_backup_when_one_is_due(): void
    {
        $manager = $this->createMock(BackupManager::class);
        // getNextScheduledBackup() returns time() when the backups table is empty,
        // i.e. "due now" — the state every fresh install is in.
        $manager->method('getNextScheduledBackup')->willReturn(time() - 1);
        $manager->expects($this->once())
            ->method('createBackup')
            ->with('auto')
            ->willReturn(['backup_id' => 'b1', 'size_bytes' => 1024]);

        $tasks = $this->armAndCollect($manager);

        $shortDelay = min(array_keys($tasks));
        ($tasks[$shortDelay]['run'])();
    }

    /**
     * CONSEQUENCE: restart churn cannot produce a backup per restart.
     *
     * The post-boot check makes the decision run on every boot, so its safety
     * rests entirely on the decision being idempotent. When a backup is NOT yet
     * due, the check must create nothing — otherwise a crash-looping or
     * frequently-deployed box would fill the disk with archives.
     *
     * Mutation-verified: dropping the `$now >= $nextBackup` guard makes the
     * closure back up unconditionally and fails this test.
     */
    public function test_the_post_boot_check_creates_nothing_when_a_backup_is_not_due(): void
    {
        $manager = $this->createMock(BackupManager::class);
        // A backup ran an hour ago and the interval is 7 days: not due.
        $manager->method('getNextScheduledBackup')->willReturn(time() + 6 * 86400);
        $manager->expects($this->never())->method('createBackup');

        $tasks = $this->armAndCollect($manager);

        foreach ($tasks as $task) {
            ($task['run'])();
        }
    }

    /**
     * CONSEQUENCE: the post-boot check runs ONCE, and the daily one repeats.
     *
     * Workerman's `Timer::add()` is persistent by default — a one-shot needs the
     * explicit `[], false` tail. Getting that wrong turns the catch-up check into
     * a second timer firing every 5 minutes forever, which would hammer
     * `getNextScheduledBackup()` (a DB query) 288 times a day for no reason.
     *
     * Mutation-verified: dropping the `[], false` arguments makes the short task
     * persistent and fails this test.
     */
    public function test_the_post_boot_check_is_one_shot_and_the_daily_check_repeats(): void
    {
        $manager = $this->createMock(BackupManager::class);
        $manager->method('getNextScheduledBackup')->willReturn(null);

        $tasks = $this->armAndCollect($manager);

        $shortDelay = min(array_keys($tasks));
        $longDelay = max(array_keys($tasks));

        self::assertNotSame($shortDelay, $longDelay, 'Expected both a catch-up and a steady-state timer.');

        self::assertFalse(
            $tasks[$shortDelay]['persistent'],
            'The post-boot catch-up check must be one-shot (Timer::add needs [], false); '
            . 'Workerman timers are persistent by default.'
        );
        self::assertTrue(
            $tasks[$longDelay]['persistent'],
            'The steady-state daily check must repeat.'
        );
        self::assertSame(86400, $longDelay, 'The steady-state check should stay daily.');
    }
}
