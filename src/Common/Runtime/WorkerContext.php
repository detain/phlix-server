<?php

declare(strict_types=1);

namespace Phlix\Common\Runtime;

/**
 * Detects whether the current execution context is inside a running Workerman worker.
 *
 * This is used to decide between async (Workerman\Http\Client) and blocking
 * (synchronous cURL) HTTP paths. The Workerman HTTP client requires an active
 * event loop; in CLI/testing contexts where no worker is running we must fall
 * back to synchronous cURL.
 *
 * @internal
 */
final class WorkerContext
{
    /**
     * Whether a Workerman worker event loop is currently running.
     *
     * Returns true when inside a running Workerman worker process (i.e. after
     * Worker::runAll() has been called and the event loop is active). Returns
     * false in plain CLI, PHPUnit, or FPM contexts.
     */
    public static function isEventLoopRunning(): bool
    {
        if (! class_exists(\Workerman\Worker::class)) {
            return false;
        }

        return \Workerman\Worker::isRunning();
    }
}
