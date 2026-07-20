<?php

/**
 * Phlix media server component: Server runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Runtime;

use Workerman\Worker;

/**
 * Makes `config/server.php`'s `worker.pid_file` the ACTUAL master PID file.
 *
 * Before this existed, `Worker::$pidFile` was never assigned anywhere in the
 * repo, so Workerman fell back to its own default —
 * `dirname(start.php)/workerman.start.php.pid` (`Worker.php`'s
 * `sprintf('%s/workerman.%s.pid', $startFileDir, $startFilePrefix)`), i.e.
 * `/var/www/phlix/workerman.start.php.pid` in production. Meanwhile
 * {@see \Phlix\Server\Http\Controllers\Admin\AdminRestartController} read
 * `config/server.php`'s `/var/run/phlix/pid`. Nothing wrote that path, so
 * `POST /api/v1/admin/restart` returned HTTP 500 "PID file not found" on every
 * real box.
 *
 * Pointing Workerman at the configured path also matters for the systemd
 * sandbox: `systemd/phlix-server.service` runs under `ProtectSystem=strict`
 * and grants `/var/run/phlix` via `ReadWritePaths`, while Workerman's default
 * location under `/var/www/phlix/` is NOT writable there — so the default was
 * one `Worker::saveMasterPid()` throw away from failing boot outright.
 *
 * {@see apply()} is deliberately non-fatal: if the configured directory cannot
 * be created or written, it leaves Workerman's default in place and reports
 * the failure to the caller rather than aborting boot. Serving media is more
 * important than the restart button working.
 *
 * @package Phlix\Server\Runtime
 * @since   1.3.0
 */
final class PidFile
{
    /**
     * The PID path declared by the app config, if any.
     *
     * This is the SAME lookup {@see \Phlix\Common\Container\Providers\AdminServicesProvider}
     * performs when constructing the restart controller; keeping it in one
     * place is what lets a test assert the writer and the reader agree.
     *
     * @param array<string, mixed> $config The `config/server.php` array.
     *
     * @return string|null Absolute path, or null when unconfigured.
     *
     * @since 1.3.0
     */
    public static function configuredPath(array $config): ?string
    {
        $worker = $config['worker'] ?? null;
        if (!is_array($worker)) {
            return null;
        }

        $path = $worker['pid_file'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Assign `Worker::$pidFile` from config, creating its directory if needed.
     *
     * MUST be called from the master process before `Worker::runAll()` — the
     * master writes the PID file during its own startup.
     *
     * @param array<string, mixed> $config The `config/server.php` array.
     *
     * @return string|null The path that was applied, or null when nothing was
     *                     configured or the location is unusable (in which case
     *                     Workerman's own default remains in force).
     *
     * @since 1.3.0
     */
    public static function apply(array $config): ?string
    {
        $path = self::configuredPath($config);
        if ($path === null) {
            return null;
        }

        if (!self::ensureWritableDirectory($path)) {
            return null;
        }

        Worker::$pidFile = $path;

        return $path;
    }

    /**
     * Ensure the PID file's parent directory exists and is writable.
     *
     * @param string $path Absolute PID file path.
     *
     * @return bool True when the master will be able to write `$path`.
     */
    private static function ensureWritableDirectory(string $path): bool
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return false;
        }

        return is_dir($dir) && is_writable($dir);
    }
}
