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

    /**
     * Whether the caller is executing inside a Swoole coroutine.
     *
     * Returns true when `Swoole\Coroutine::getCid() > 0` — i.e. there is a live
     * coroutine on the current stack. This is the ONLY context in which a
     * `Swoole\Coroutine\Channel` may be used: calling `Channel::pop()` /
     * `push()` outside a coroutine emits "API must be called in the coroutine"
     * and returns false immediately, which manifests as a spurious HTTP timeout
     * while the async callback is still pending (S-F12 / SV-0.4).
     *
     * HTTP clients MUST gate their Channel-based cooperative wait on this: use
     * the async Channel path only when this returns true, and the blocking
     * client otherwise (never spin, never touch a Channel out of a coroutine).
     *
     * Returns false when the Swoole extension is absent (plain CLI/PHPUnit),
     * where there are no coroutines at all.
     */
    public static function inCoroutine(): bool
    {
        if (! \extension_loaded('swoole')) {
            return false;
        }

        return \Swoole\Coroutine::getCid() > 0;
    }
}
