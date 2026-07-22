<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Dlna;

use Phlix\Admin\SettingsRepository;
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Http\Controllers\Admin\AdminRestartController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * AdminDlnaServerController — admin endpoints for DLNA CDS server status and control.
 *
 * Provides:
 * - GET  /api/v1/admin/dlna/status — current server state
 * - POST /api/v1/admin/dlna/start  — enable the CDS server
 * - POST /api/v1/admin/dlna/stop   — disable the CDS server
 *
 * ## Why Start/Stop persist a setting and reload, rather than flipping a flag
 *
 * The ContentDirectory browse/stream routes are registered ONCE per worker at
 * boot in {@see \Phlix\Server\Core\Application::loadCdsRoutes()}, gated on the
 * effective `dlna.cds_enabled` setting. This is a resident-memory,
 * multi-worker server: flipping an in-memory bool on the single `CdsServer`
 * instance this controller happens to hold would neither register the routes
 * nor reach the other worker processes, and would evaporate on the next
 * reload. An HONEST toggle therefore (1) PERSISTS `dlna.cds_enabled` via the
 * shared {@see SettingsRepository} — the same store the generic admin settings
 * page writes — and (2) schedules a GRACEFUL reload via the existing
 * {@see AdminRestartController}, so every worker re-reads the setting at its
 * next `onWorkerStart` and registers or drops the CDS routes accordingly.
 *
 * `status()` reports both the persisted intent (`enabled`) and whether THIS
 * worker is actually serving the routes right now (`running`, the value frozen
 * at its boot); a transient `reloadPending` flags the gap while a reload
 * propagates.
 *
 * ⚠️ Enabling the CDS exposes the library with NO authentication on the LAN —
 * see {@see config/dlna.php} for the full warning. That is why it ships off.
 *
 * @since 2.2
 */
class AdminDlnaServerController
{
    /** Dotted setting key gating the ContentDirectory browse/stream routes. */
    private const SETTING_KEY = 'dlna.cds_enabled';

    /** @var \Phlix\Dlna\CdsServer|null */
    private ?\Phlix\Dlna\CdsServer $cdsServer = null;

    /** @var SettingsRepository|null Persistent settings store for `dlna.cds_enabled`. */
    private ?SettingsRepository $settings = null;

    /** @var AdminRestartController|null Used to schedule the graceful reload. */
    private ?AdminRestartController $restartController = null;

    /**
     * @param \Phlix\Dlna\CdsServer|null $cdsServer The CDS server instance
     *
     * @since 2.2
     */
    public function setCdsServer(?\Phlix\Dlna\CdsServer $cdsServer): void
    {
        $this->cdsServer = $cdsServer;
    }

    /**
     * @param SettingsRepository|null $settings Persistent settings store.
     *
     * @since 2.4
     */
    public function setSettingsRepository(?SettingsRepository $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * @param AdminRestartController|null $restartController Graceful-reload signaller.
     *
     * @since 2.4
     */
    public function setRestartController(?AdminRestartController $restartController): void
    {
        $this->restartController = $restartController;
    }

    /**
     * GET /api/v1/admin/dlna/status — returns current DLNA CDS server state.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response with server status
     *
     * @since 2.2
     */
    public function status(Request $request, array $params): Response
    {
        $enabled = $this->isEnabled();
        $running = $this->isRunningInThisWorker();

        $payload = [
            'enabled' => $enabled,
            'running' => $running,
            // enabled != running means a persisted change has not yet been
            // applied by a worker reload; the SPA can surface "applying…".
            'reloadPending' => $enabled !== $running,
            'serverId' => null,
            'friendlyName' => null,
            'port' => null,
            'baseUrl' => null,
        ];

        if ($this->cdsServer !== null) {
            $dlnaServer = $this->cdsServer->getDlnaServer();
            $payload['serverId'] = $this->cdsServer->getServerUdn();
            $payload['friendlyName'] = $dlnaServer->getFriendlyName();
            $payload['port'] = $this->cdsServer->getPort();
            $payload['baseUrl'] = $this->cdsServer->getBaseUrl();
        }

        return (new Response())->status(200)->json($payload);
    }

    /**
     * POST /api/v1/admin/dlna/start — enables the CDS server.
     *
     * Persists `dlna.cds_enabled = true` and schedules a graceful reload so the
     * ContentDirectory routes come up across every worker.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response indicating success or failure
     *
     * @since 2.2
     */
    public function start(Request $request, array $params): Response
    {
        return $this->applyEnabled(true);
    }

    /**
     * POST /api/v1/admin/dlna/stop — disables the CDS server.
     *
     * Persists `dlna.cds_enabled = false` and schedules a graceful reload so the
     * ContentDirectory routes are dropped across every worker.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response indicating success or failure
     *
     * @since 2.2
     */
    public function stop(Request $request, array $params): Response
    {
        return $this->applyEnabled(false);
    }

    /**
     * Persist the desired CDS-enabled state and schedule a graceful reload.
     *
     * @param bool $desired True to enable the CDS, false to disable it.
     * @return Response
     */
    private function applyEnabled(bool $desired): Response
    {
        if ($this->settings === null) {
            return (new Response())
                ->status(503)
                ->json([
                    'success' => false,
                    'message' => 'DLNA settings store is unavailable; cannot change CDS state.',
                ]);
        }

        if ($this->isEnabled() === $desired) {
            return (new Response())
                ->status(409)
                ->json([
                    'success' => false,
                    'enabled' => $desired,
                    'message' => $desired
                        ? 'DLNA content directory is already enabled.'
                        : 'DLNA content directory is already disabled.',
                ]);
        }

        try {
            $this->settings->set(self::SETTING_KEY, $desired, 'bool');
        } catch (\Throwable $e) {
            return (new Response())
                ->status(500)
                ->json([
                    'success' => false,
                    'message' => 'Failed to persist DLNA setting: ' . $e->getMessage(),
                ]);
        }

        // Best-effort immediate SSDP announce / teardown on THIS worker. The
        // authoritative effect comes from the reload below; this just avoids a
        // needless multicast delay on the worker handling the request.
        try {
            if ($desired) {
                $this->cdsServer?->start();
            } else {
                $this->cdsServer?->stop();
            }
        } catch (\Throwable) {
            // Non-fatal: the reload re-establishes the correct state.
        }

        $reloadScheduled = $this->restartController !== null
            && $this->restartController->scheduleGracefulReload();

        return (new Response())
            ->status(200)
            ->json([
                'success' => true,
                'enabled' => $desired,
                'reloadScheduled' => $reloadScheduled,
                'message' => $this->applyMessage($desired, $reloadScheduled),
            ]);
    }

    /**
     * Human-readable outcome for the SPA toast.
     */
    private function applyMessage(bool $desired, bool $reloadScheduled): string
    {
        $verb = $desired ? 'enabled' : 'disabled';

        if ($reloadScheduled) {
            return sprintf('DLNA content directory %s; workers are reloading to apply it.', $verb);
        }

        return sprintf(
            'DLNA content directory %s; restart the server to apply it (automatic reload unavailable).',
            $verb,
        );
    }

    /**
     * The persisted (intended) CDS-enabled state, read live from the store.
     *
     * Falls back to this worker's frozen config when no settings store is
     * wired, so status stays truthful even in a degraded DI state.
     */
    private function isEnabled(): bool
    {
        if ($this->settings !== null) {
            return $this->settings->getEffective(self::SETTING_KEY) === true;
        }

        return $this->isRunningInThisWorker();
    }

    /**
     * Whether the CDS routes are actually registered in THIS worker right now.
     *
     * `EffectiveConfig::file()` returns the per-process snapshot captured at
     * this worker's `onWorkerStart`, i.e. exactly what `loadCdsRoutes()` gated
     * on — so this reflects real route state, not persisted intent.
     */
    private function isRunningInThisWorker(): bool
    {
        return (EffectiveConfig::file('dlna')['cds_enabled'] ?? false) === true;
    }
}
