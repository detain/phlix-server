<?php

/**
 * Phlix media server component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Updates;

use Phlix\Common\Logger\StructuredLogger;
use Throwable;
use Workerman\Timer;

/**
 * Background driver for {@see CoreUpdateCheckService} (S74).
 *
 * ## The boot catch-up is not optional
 *
 * A bare `Timer::add(86400, ...)` fires its FIRST tick 86400 seconds after the
 * process starts. On a server that is restarted (deploy, `install.sh --update`,
 * reboot, the admin Restart button's SIGUSR2 reload) more often than the
 * interval, that tick never happens and the feature silently does nothing.
 *
 * This is not a hypothetical: the identical defect shipped TWICE in this
 * repository — the scheduled-backup timer
 * ({@see \Phlix\Server\Core\Application::registerBackupTimer()}, `backups` empty
 * on production for weeks) and the plugin auto-updater
 * ({@see \Phlix\Plugins\Catalog\PluginAutoUpdateWorker::start()}, which logged
 * `start` on all six restarts of 2026-07-21 and `runOnce` not once). Both were
 * fixed with the same two-arm shape, and {@see start()} uses it here:
 *
 *  - **ARM A — boot catch-up.** A ONE-SHOT timer a few minutes after boot, so a
 *    box that restarts daily still checks daily. It is one-shot on purpose:
 *    `Timer::add()` is persistent unless passed `[], false`, and a persistent
 *    300 s timer would poll GitHub every five minutes forever.
 *  - **ARM B — steady-state poll.** The persistent `poll_seconds` timer.
 *
 * The delay on ARM A keeps the HTTP fetch off the boot path; it is not a
 * correctness guard. Running it on every boot is safe because a check has no
 * side effect beyond three `server_settings` rows.
 *
 * ## Where it runs
 *
 * On the dedicated `count=1` `phlix-background-timers` worker, armed from
 * {@see \Phlix\Server\Core\Application::startBackgroundTimers()} — which is
 * where `start.php` reaches it. `count=1` means the poll runs once server-wide
 * rather than once per HTTP worker.
 *
 * The `start(int $pollSeconds): void` + `runOnce()` signature also matches the
 * `config/managed_workers.php` contract, so this worker could be promoted to
 * its own supervised process without a signature change — but a single daily
 * HTTP GET does not justify a resident process today.
 *
 * @package Phlix\Server\Updates
 * @since   S74 (core update check)
 */
final class CoreUpdateCheckWorker
{
    /**
     * Delay (seconds) before the post-boot catch-up check.
     *
     * Same value as {@see \Phlix\Plugins\Catalog\PluginAutoUpdateWorker} and the
     * backup timer: long enough to stay off the boot path, short enough that
     * any install which stays up a few minutes performs the check.
     */
    public const BOOT_CATCHUP_DELAY = 300;

    /** Default steady-state poll interval: once a day. */
    public const DEFAULT_POLL_SECONDS = 86400;

    /**
     * @param CoreUpdateCheckService $service Check service.
     * @param StructuredLogger       $logger  Application logger.
     */
    public function __construct(
        private readonly CoreUpdateCheckService $service,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Arm the worker: a one-shot boot catch-up check, plus the repeating poll.
     *
     * @param int $pollSeconds Steady-state poll interval, seconds. Values ≤ 0
     *                         fall back to {@see self::DEFAULT_POLL_SECONDS}
     *                         rather than throwing out of a boot path
     *                         (`Timer::add()` rejects a negative interval).
     *
     * @return void
     */
    public function start(int $pollSeconds = self::DEFAULT_POLL_SECONDS): void
    {
        $interval = $pollSeconds > 0 ? $pollSeconds : self::DEFAULT_POLL_SECONDS;

        // ARM A — boot catch-up. One-shot: Workerman's Timer::add repeats
        // unless passed [], false.
        Timer::add(self::BOOT_CATCHUP_DELAY, fn(): bool => $this->runOnce(), [], false);

        // ARM B — steady-state poll. Persistent by default, which is what we
        // want: one arming, ticks forever.
        Timer::add($interval, fn(): bool => $this->runOnce());

        $this->logger->info('Updates: core update check worker started', [
            'poll_seconds'      => $interval,
            'boot_catchup_secs' => self::BOOT_CATCHUP_DELAY,
        ]);
    }

    /**
     * Perform a single check.
     *
     * Public so both arms — and a test — can reach it. Fully guarded: a throw
     * escaping a Workerman timer callback takes the worker's tick with it.
     *
     * @return bool True when the check was dispatched, false when it threw.
     */
    public function runOnce(): bool
    {
        try {
            $this->service->check();
            return true;
        } catch (Throwable $e) {
            $this->logger->error('Updates: core update check tick failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
