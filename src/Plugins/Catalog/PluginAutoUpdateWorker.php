<?php

/**
 * Phlix media server component: Catalog.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Catalog;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Throwable;
use Workerman\Timer;

/**
 * Periodically auto-updates installed plugins when the operator has opted in.
 *
 * On each tick, if {@see PluginCatalogService::autoUpdateEnabled()} is true, it
 * runs {@see PluginUpdateService::updateAll()} — re-installing any plugin a
 * configured catalog reports a newer version for. When the toggle is off the
 * tick is a cheap no-op (one settings read), so the worker can poll on a slow
 * cadence and respond to the toggle without a restart.
 *
 * **Resident-memory (Workerman) safety.** The loop uses {@see Timer::add()},
 * never a blocking `sleep()`, and holds no unbounded state — only its injected
 * dependencies. A failure in one cycle is logged and swallowed so the timer
 * keeps ticking.
 *
 * @package Phlix\Plugins\Catalog
 * @since 0.39.0
 */
final class PluginAutoUpdateWorker
{
    /**
     * Delay (seconds) before the post-boot catch-up update check.
     *
     * Long enough to stay off the boot path, short enough that any install which
     * stays up a few minutes performs the check. {@see self::start()} explains
     * why a poll-only timer never fires on a box that gets deployed to.
     */
    private const BOOT_CATCHUP_DELAY = 300;

    public function __construct(
        private readonly PluginCatalogService $catalog,
        private readonly PluginUpdateService $updates,
        private ?StructuredLogger $logger = null,
    ) {
    }

    /**
     * Run one auto-update cycle. No-op (other than a settings read) when the
     * auto-update toggle is off. Never throws — failures are logged.
     *
     * @return bool True when updates were applied this cycle, false otherwise.
     *
     * @since 0.39.0
     */
    public function runOnce(): bool
    {
        if (!$this->catalog->autoUpdateEnabled()) {
            return false;
        }

        $this->logger()->info('PluginAutoUpdateWorker::runOnce Starting update cycle');
        $startTime = hrtime(true);

        try {
            $result = $this->updates->updateAll();
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger()->info('PluginAutoUpdateWorker::runOnce Update cycle completed [duration='
                . round($durationMs, 2) . 'ms] [updated=' . count($result['updated']) . '] [failed='
                . count($result['failed']) . ']');
        } catch (Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
            $this->logger()->error('PluginAutoUpdateWorker::runOnce Update cycle FAILED [duration='
                . round($durationMs, 2) . 'ms] [error=' . $e->getMessage() . ']');
            return false;
        }

        if ($result['updated'] === [] && $result['failed'] === []) {
            return false;
        }

        $this->logger()->info('PluginAutoUpdateWorker: applied plugin updates', [
            'updated' => array_map(static fn (array $u): string => $u['name'], $result['updated']),
            'failed'  => array_map(static fn (array $f): string => $f['name'], $result['failed']),
        ]);
        return $result['updated'] !== [];
    }

    /**
     * Start the polling loop on the Workerman event loop. Must be called from a
     * worker's `onWorkerStart` (Timer requires a running event loop).
     *
     * @param int $pollSeconds Poll interval in seconds.
     *
     * @since 0.39.0
     */
    public function start(int $pollSeconds): void
    {
        $this->logger()->info('PluginAutoUpdateWorker::start [poll_interval=' .
            $pollSeconds . ']');

        // Steady-state poll.
        Timer::add($pollSeconds, fn(): bool => $this->runOnce());

        // Catch-up check shortly after boot.
        //
        // This is NOT belt-and-braces — without it the feature does not work on a
        // box that is deployed to, which is exactly what production looked like.
        // `config/process.php` polls every 86400 s, and a bare Timer::add(86400)
        // fires only after 24 h of UNINTERRUPTED uptime: every restart or reload
        // resets the countdown to zero. Production restarted SIX times on
        // 2026-07-21 alone, so the tick never arrived. The plugins log showed
        // `PluginAutoUpdateWorker::start` on every boot and `runOnce` never once,
        // while trakt sat at 1.2.1 against a catalog offering 1.3.0 (and anilist
        // 0.2.0 vs 0.3.0, musicbrainz 0.2.1 vs 0.3.0). Same defect, same shape,
        // and the same fix as the scheduled-backup timer.
        // {@see \Phlix\Server\Core\Application::registerBackupTimer()}
        //
        // Deciding at boot is safe because runOnce() is idempotent twice over: it
        // returns immediately unless the operator has opted in via
        // PluginCatalogService::autoUpdateEnabled(), and updateAll() re-installs
        // only plugins the catalog reports a NEWER version for, so restart churn
        // re-checks but cannot re-install. The delay only keeps catalog HTTP and
        // any install off the boot path — it is not a correctness guard.
        //
        // One-shot: Workerman's Timer::add repeats unless passed [], false.
        Timer::add(self::BOOT_CATCHUP_DELAY, fn(): bool => $this->runOnce(), [], false);
    }

    /**
     * Lazy-load the plugins-channel logger.
     */
    private function logger(): StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::PLUGINS);
        }
        return $this->logger;
    }
}
