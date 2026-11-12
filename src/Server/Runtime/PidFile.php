<?php

/**
 * Phlix media server component: Server runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
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
 * SRV-S17 (SRV-28): the path is env-seamed — `PHLIX_PID_FILE` (read by
 * `config/server.php` through `EnvConfig::text()`, empty = unset) wins over the
 * shipped `/var/run/phlix/pid`, so a container/chart whose `/var/run` is not
 * writable can point the master at a volume it owns. And the degraded branch
 * no longer strands the restart endpoint: {@see workermanDefaultPath()}
 * reproduces Workerman's own fallback so start.php can LOG the path the master
 * really writes, and {@see effectivePath()} is what the restart controller's
 * DI factory reads — the writer and the reader resolve the SAME file whether
 * the configured path held or not (before this, a fallen-back boot answered
 * every `POST /api/v1/admin/restart` with "PID file not found").
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
     * The PID file the running master actually writes — the restart
     * endpoint's read path.
     *
     * Resolution order:
     *  1. `Worker::$pidFile` when non-empty. Workerman's master assigns it in
     *     `Worker::init()` (from {@see apply()} or its own default) BEFORE it
     *     forks, so every served worker inherits the master's decision through
     *     memory — no re-derivation, no TOCTOU against the directory state.
     *  2. The configured path when its directory exists and is writable now
     *     (a process that never ran `Worker::init()`: CLI, tests).
     *  3. Workerman's own default ({@see workermanDefaultPath()}) — the same
     *     fallback the master takes when {@see apply()} returns null.
     *
     * @param array<string, mixed> $config    The `config/server.php` array.
     * @param string|null          $startFile Entry script; null = this repo's `start.php`.
     *
     * @return string Absolute path of the master PID file.
     *
     * @since 1.3.0
     */
    public static function effectivePath(array $config, ?string $startFile = null): string
    {
        if (Worker::$pidFile !== '') {
            return Worker::$pidFile;
        }

        $path = self::configuredPath($config);
        if ($path !== null && self::isWritableDirectory(dirname($path))) {
            return $path;
        }

        return self::workermanDefaultPath($startFile);
    }

    /**
     * Workerman's own PID path when `Worker::$pidFile` is left unassigned.
     *
     * Mirrors `Worker::init()` at the vendored pin (workerman/workerman 5.x):
     * a pre-existing legacy file at `<workerman pkg>/../../<start path with
     * '/' → '_'>.pid` wins (its "compatible with older workerman versions"
     * arm), otherwise `sprintf('%s/workerman.%s.pid', dirname($startFile),
     * basename($startFile))` — `/var/www/phlix/workerman.start.php.pid` in
     * production.
     *
     * @param string|null $startFile Entry script; null = this repo's `start.php`.
     *
     * @return string Absolute path Workerman falls back to.
     *
     * @since 1.3.0
     */
    public static function workermanDefaultPath(?string $startFile = null): string
    {
        $startFile ??= dirname(__DIR__, 3) . '/start.php';

        $workerFile = (new \ReflectionClass(Worker::class))->getFileName();
        if ($workerFile !== false) {
            $legacy = dirname($workerFile) . '/../../' . str_replace('/', '_', $startFile) . '.pid';
            if (is_file($legacy)) {
                return $legacy;
            }
        }

        return sprintf('%s/workerman.%s.pid', dirname($startFile), basename($startFile));
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

        return self::isWritableDirectory($dir);
    }

    /**
     * @param string $dir Directory to probe (never created here).
     *
     * @return bool True when `$dir` exists and this process may write into it.
     */
    private static function isWritableDirectory(string $dir): bool
    {
        return is_dir($dir) && is_writable($dir);
    }
}
