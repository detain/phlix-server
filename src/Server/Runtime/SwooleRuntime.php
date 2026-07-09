<?php

/**
 * Phlix media server component: Runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 * scheduler — file IO via `io_uring` (`SWOOLE_HOOK_FILE`), `proc_open()`
 * (`SWOOLE_HOOK_PROC`), libcurl (`SWOOLE_HOOK_NATIVE_CURL`), and — critically —
 * `exec()`/`shell_exec()` via the blocking-function hook, which is exactly how
 * the on-demand HLS/CMAF transcode spawns its detached `ffmpeg` from inside a
 * request.
 *
 * The curated default mask is therefore an **allowlist** ({@see self::SAFE_HOOK_NAMES})
 * of just the network + sleep hooks the coroutine MySQL pool and async network
 * IO need — *not* "`SWOOLE_HOOK_ALL` minus a blocklist". The blocklist approach
 * is unsafe here because `SWOOLE_HOOK_BLOCKING_FUNCTION` (the `exec`/`shell_exec`
 * hook) is not exposed as a named constant in this Swoole build, so it cannot be
 * name-subtracted from `SWOOLE_HOOK_ALL` — yet its bit is still set in `ALL`.
 * Allowlisting guarantees it (and any other unnamed native hook) is never
 * enabled. Excluded calls run as ordinary blocking syscalls (safe, just not
 * coroutine-yielding within a worker). The mask is configurable so an operator
 * can override it or disable the coroutine runtime entirely without editing code.
 *
 * @package Phlix\Server\Runtime
 * @since 0.33.0
 */
final class SwooleRuntime
{
    /**
     * The ONLY coroutine hooks the curated default mask enables — an allowlist
     * of network + sleep hooks the coroutine MySQL pool and async network IO
     * rely on. Everything else (file IO/io_uring, `proc_open`, curl, stdio, the
     * PDO drivers, and the blocking-function hook that covers `exec`/
     * `shell_exec`) is excluded BY CONSTRUCTION — see the class docblock for why
     * an allowlist is used instead of subtracting a blocklist from
     * `SWOOLE_HOOK_ALL`.
     *
     * @var list<string>
     */
    private const SAFE_HOOK_NAMES = [
        'SWOOLE_HOOK_TCP',
        'SWOOLE_HOOK_UDP',
        'SWOOLE_HOOK_UNIX',
        'SWOOLE_HOOK_UDG',
        'SWOOLE_HOOK_SSL',
        'SWOOLE_HOOK_TLS',
        'SWOOLE_HOOK_STREAM_FUNCTION',
        'SWOOLE_HOOK_SLEEP',
        'SWOOLE_HOOK_SOCKETS',
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
     * The OR of the {@see self::SAFE_HOOK_NAMES} allowlist (network + sleep
     * hooks only).
     *
     * Returns 0 when ext-swoole is not loaded (none of the constants are
     * defined) — harmless, because `start.php` only consults this inside an
     * `extension_loaded('swoole')` guard.
     *
     * @since 0.33.0
     */
    public static function safeHookFlags(): int
    {
        $flags = 0;
        foreach (self::SAFE_HOOK_NAMES as $name) {
            if (defined($name)) {
                $flags |= (int) constant($name);
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
