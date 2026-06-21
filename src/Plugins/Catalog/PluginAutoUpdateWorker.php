<?php

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

        try {
            $result = $this->updates->updateAll();
        } catch (Throwable $e) {
            $this->logger()->error('PluginAutoUpdateWorker: update cycle failed', [
                'error' => $e->getMessage(),
            ]);
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
        Timer::add($pollSeconds, fn(): bool => $this->runOnce());
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
