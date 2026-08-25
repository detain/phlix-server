<?php

/**
 * Phlix media server test support: run a body while the Workerman event loop
 * *reports* running.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Support\Coroutine;

use Workerman\Worker;

/**
 * S196 — flips `Worker::isRunning()` to true for the duration of a body.
 *
 * ## WHY THIS EXISTS
 *
 * The HTTP-client family forks on
 * `WorkerContext::isEventLoopRunning() && WorkerContext::inCoroutine()` to pick
 * the async workerman/http-client arm over the blocking cURL arm. Under PHPUnit
 * neither term is true: there is no coroutine AND `Worker::$status` is
 * `STATUS_INITIAL`, so `Worker::isRunning()` is false. The shipped
 * `RunsInCoroutine` harness crosses the first term; this crosses the second,
 * so the REAL fork decision can be exercised on both arms instead of only the
 * cURL arm. It is deliberately a thin reflection flip of the one static that
 * `Worker::isRunning()` reads — no Worker is constructed, no event loop runs,
 * and the value is restored in a `finally` so no later test can observe the
 * fake state.
 *
 * The workerman/http-client instance is still faked (injected via reflection
 * into the client's private `$asyncClient` property) so no real socket is
 * involved; the fork decision is what this proves, not the network stack.
 *
 * ## WHY NOT `new Worker()` / `Worker::runAll()`
 *
 * A real worker needs a real event loop and would spin forever under PHPUnit.
 * The estate already has the out-of-process harness for that shape
 * (`tests/Support/Browser/hls-controller-server.php`); this trait is for the
 * in-process fork-decision tests only.
 */
trait WithWorkerEventLoop
{
    /**
     * Runs $body with `Worker::isRunning()` reporting true, restoring the
     * original status afterwards.
     *
     * @param callable(): mixed $body Body to execute.
     *
     * @return mixed Whatever $body returned.
     */
    protected function runWithWorkerEventLoop(callable $body): mixed
    {
        $status = new \ReflectionProperty(Worker::class, 'status');
        $status->setAccessible(true);
        $original = $status->getValue(null);

        $status->setValue(null, Worker::STATUS_RUNNING);
        try {
            return $body();
        } finally {
            $status->setValue(null, $original);
        }
    }

    /**
     * Asserts the Workerman event-loop status is back to INITIAL — a guard
     * against a broken `finally` above silently leaking the fake state into
     * later tests in the same process.
     */
    protected function assertWorkerEventLoopRestored(): void
    {
        $status = new \ReflectionProperty(Worker::class, 'status');
        $status->setAccessible(true);
        self::assertSame(
            Worker::STATUS_INITIAL,
            $status->getValue(null),
            'Worker::$status must be restored to STATUS_INITIAL after the event-loop fake.'
        );
    }
}