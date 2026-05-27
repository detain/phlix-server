<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Coroutine;

use PHPUnit\Framework\TestCase;
use Workerman\Worker;

/**
 * Guards the static identifier used by `start.php` to wire Swoole as
 * the global Workerman eventLoop driver.
 *
 * The first 0.2a PR shipped `Worker::$eventLoop = ...`, which raised
 * `Access to undeclared static property` on every `php start.php
 * <subcommand>` invocation because:
 *
 *   - `Workerman\Worker::$eventLoop` is a **non-static** instance
 *     property used to override the eventLoop on a single Worker.
 *   - `Workerman\Worker::$eventLoopClass` is the **static** property
 *     that sets the default driver for ALL workers in the process.
 *
 * `start.php` runs in the master process, before any Worker is created,
 * so it must set the static. This test asserts the correct identifier
 * exists and the wrong one doesn't (as a static), so a future hand
 * "fixing" the typo back to the broken form fails CI.
 *
 * @covers \Workerman\Worker
 * @package Phlix\Tests\Unit\Server\Coroutine
 * @since   0.10.x (Step 0.2c cumulative-fix)
 */
final class EventLoopBootstrapTest extends TestCase
{
    /**
     * `Worker::$eventLoopClass` must be a public static `?string` that
     * `start.php` can assign to. If this drifts, the bootstrap silently
     * stops switching the eventLoop (or fatals, as the original
     * regression did).
     */
    public function testWorkerExposesEventLoopClassStatic(): void
    {
        $rc = new \ReflectionClass(Worker::class);
        $this->assertTrue($rc->hasProperty('eventLoopClass'), 'Workerman\\Worker::$eventLoopClass missing');

        $prop = $rc->getProperty('eventLoopClass');
        $this->assertTrue($prop->isStatic(), '$eventLoopClass must be static so start.php can assign it process-wide');
        $this->assertTrue($prop->isPublic(), '$eventLoopClass must be public');
    }

    /**
     * The instance property `$eventLoop` (without the `Class` suffix)
     * exists too — but it is NOT a static. start.php must NEVER assign
     * to `Worker::$eventLoop` as if it were one. If a future hand
     * "simplifies" the bootstrap and reintroduces that assignment, we
     * want the test suite to scream rather than the daemon to fatal on
     * `status`/`reload`.
     */
    public function testWorkerEventLoopIsAnInstancePropertyNotStatic(): void
    {
        $rc = new \ReflectionClass(Worker::class);
        $this->assertTrue($rc->hasProperty('eventLoop'), '$eventLoop should exist as an instance override');

        $prop = $rc->getProperty('eventLoop');
        $this->assertFalse($prop->isStatic(), '$eventLoop is an instance property; do NOT assign to Worker::$eventLoop');
    }

    /**
     * Smoke-test that the start.php idiom actually compiles + executes
     * without raising "Access to undeclared static property". We can't
     * spawn a Worker from a unit test, but we CAN run the assignment
     * itself — it's just a static-property write.
     */
    public function testStartScriptIdiomCompilesAndAssignsWithoutFatal(): void
    {
        $originalDriver = Worker::$eventLoopClass;
        try {
            // The literal idiom from start.php (post-fix):
            Worker::$eventLoopClass = \Workerman\Events\Swoole::class;
            $this->assertSame(\Workerman\Events\Swoole::class, Worker::$eventLoopClass);
        } finally {
            // Restore so we don't affect any other test that depends on
            // the default driver selection.
            Worker::$eventLoopClass = $originalDriver;
        }
    }
}
