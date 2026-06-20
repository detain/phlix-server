<?php

declare(strict_types=1);

namespace Phlix\Server\Runtime;

/**
 * Resolves the Swoole coroutine runtime configuration for the daemon
 * bootstrap ({@see /start.php}).
 *
 * ## Why this exists
 *
 * The HTTP worker runs under Swoole's event loop with the coroutine runtime
 * hook enabled. Hooking *every* native call — `Swoole\Runtime::enableCoroutine(
 * SWOOLE_HOOK_ALL)` — crashed the worker with recurring **general-protection
 * faults inside `swoole.so`** (`exit with status 139` = SIGSEGV) on the
 * production stack: PHP 8.5 + Swoole 6.2.1 + kernel 7 with `io_uring` enabled.
 * The `dmesg` traps pointed at `swoole.so`, and the crashes correlated with
 * hooked operations that re-drive a *native* blocking call on the coroutine
 * scheduler:
 *
 *  - `SWOOLE_HOOK_FILE`        — file IO routed through `io_uring` (unstable on
 *                                this kernel; also see the prior io_uring ENOMEM
 *                                startup bug).
 *  - `SWOOLE_HOOK_PROC`        — `proc_open()` / `exec()` / `shell_exec()`, used
 *                                by the on-demand HLS/CMAF transcode to spawn a
 *                                detached `ffmpeg` from inside a request.
 *  - `SWOOLE_HOOK_CURL` /
 *    `SWOOLE_HOOK_NATIVE_CURL` — libcurl re-driven through the reactor.
 *  - `SWOOLE_HOOK_STDIO`       — stdin/stdout/stderr.
 *
 * Dropping those hooks lets the calls run as ordinary blocking syscalls (safe,
 * just not coroutine-yielding within a worker) while the socket/sleep/stream
 * hooks the coroutine MySQL pool and network IO rely on stay on. The mask is
 * configurable so an operator can re-enable specific hooks or disable the
 * coroutine runtime entirely without editing code.
 *
 * @package Phlix\Server\Runtime
 * @since 0.33.0
 */
final class SwooleRuntime
{
    /**
     * Hooks excluded from the curated default mask because they have been
     * crashing the worker on the PHP 8.5 / Swoole 6.2.1 / io_uring stack.
     *
     * @var list<string>
     */
    private const UNSAFE_HOOK_NAMES = [
        'SWOOLE_HOOK_FILE',
        'SWOOLE_HOOK_PROC',
        'SWOOLE_HOOK_CURL',
        'SWOOLE_HOOK_NATIVE_CURL',
        'SWOOLE_HOOK_STDIO',
    ];

    /**
     * Whether the coroutine runtime hook should be enabled at all.
     *
     * Defaults to true (Swoole stays the event-loop driver and the curated
     * hook mask is applied). Set `coroutine.enabled => false` in
     * `config/server.php` to leave Swoole as the event loop but apply no
     * runtime hooks — the most conservative option if crashes persist.
     *
     * @param mixed $config The full server config array.
     *
     * @since 0.33.0
     */
    public static function coroutineEnabled(mixed $config): bool
    {
        $coroutine = self::coroutineConfig($config);
        return !array_key_exists('enabled', $coroutine) || $coroutine['enabled'] !== false;
    }

    /**
     * Resolve the `SWOOLE_HOOK_*` bitmask to pass to
     * `Swoole\Runtime::enableCoroutine()`.
     *
     * An explicit integer `coroutine.hook_flags` in config wins (escape hatch
     * for operators who need a specific mask); otherwise the curated
     * {@see self::safeHookFlags()} default is used.
     *
     * @param mixed $config The full server config array.
     *
     * @since 0.33.0
     */
    public static function resolveHookFlags(mixed $config): int
    {
        $coroutine = self::coroutineConfig($config);
        if (isset($coroutine['hook_flags']) && is_int($coroutine['hook_flags'])) {
            return $coroutine['hook_flags'];
        }
        return self::safeHookFlags();
    }

    /**
     * `SWOOLE_HOOK_ALL` minus the crash-prone native hooks.
     *
     * Returns 0 when ext-swoole is not loaded (the constants are absent) —
     * harmless, because `start.php` only consults this inside an
     * `extension_loaded('swoole')` guard.
     *
     * @since 0.33.0
     */
    public static function safeHookFlags(): int
    {
        if (!defined('SWOOLE_HOOK_ALL')) {
            return 0;
        }

        $flags = (int) constant('SWOOLE_HOOK_ALL');
        foreach (self::UNSAFE_HOOK_NAMES as $name) {
            if (defined($name)) {
                $flags &= ~((int) constant($name));
            }
        }

        return $flags;
    }

    /**
     * Extract the `coroutine` sub-array from the server config, tolerating any
     * malformed shape.
     *
     * @param mixed $config
     *
     * @return array<string, mixed>
     */
    private static function coroutineConfig(mixed $config): array
    {
        if (is_array($config) && isset($config['coroutine']) && is_array($config['coroutine'])) {
            /** @var array<string, mixed> $coroutine */
            $coroutine = $config['coroutine'];
            return $coroutine;
        }
        return [];
    }
}
