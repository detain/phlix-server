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
     * Returns false when the Swoole coroutine API is absent (plain CLI/PHPUnit),
     * where there are no coroutines at all.
     *
     * E-18 settle — this is now the ONE coroutine predicate. LocalAddressProbe
     * carried a second copy gated on `class_exists(Swoole\Coroutine)` instead
     * of `extension_loaded('swoole')`. The union semantics asked for
     * (`extension_loaded || class_exists`, then `getCid() > 0`) reduce exactly
     * to the `class_exists` gate: a loaded extension always registers its
     * classes (so `extension_loaded` alone never answers true where
     * `class_exists` answers false), while the converse divergence — a
     * userland `Swoole\Coroutine` without the extension — answers true under
     * `class_exists` and can only ever FATAL under `extension_loaded` + a real
     * call, never true. The repo defines no such stub and depends on no
     * userland Swoole polyfill (grep: zero `namespace Swoole` declarations in
     * src/tests, zero swoole packages in composer.json), so the two original
     * gates are behavior-identical on every reachable runtime and the settled
     * form adopts the stricter-true one that both this class's consumers and
     * the migrated Network/relay carriers already compute identically.
     */
    public static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }
}
