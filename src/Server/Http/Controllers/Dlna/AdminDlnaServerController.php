<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Dlna;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * AdminDlnaServerController — admin endpoints for DLNA CDS server status and control.
 *
 * Provides:
 * - GET /api/v1/admin/dlna/status — current server state
 * - POST /api/v1/admin/dlna/start — start the CDS server
 * - POST /api/v1/admin/dlna/stop — stop the CDS server
 *
 * @since 2.2
 */
class AdminDlnaServerController
{
    /** @var \Phlix\Dlna\CdsServer|null */
    private ?\Phlix\Dlna\CdsServer $cdsServer = null;

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
        if ($this->cdsServer === null) {
            return (new Response())
                ->status(200)
                ->json([
                    'enabled' => false,
                    'running' => false,
                    'serverId' => null,
                    'friendlyName' => null,
                    'port' => null,
                    'baseUrl' => null,
                    'message' => 'DLNA server not configured',
                ]);
        }

        $dlnaServer = $this->cdsServer->getDlnaServer();

        return (new Response())
            ->status(200)
            ->json([
                'enabled' => true,
                'running' => $this->cdsServer->isRunning(),
                'serverId' => $this->cdsServer->getServerUdn(),
                'friendlyName' => $dlnaServer->getFriendlyName(),
                'port' => $this->cdsServer->getPort(),
                'baseUrl' => $this->cdsServer->getBaseUrl(),
            ]);
    }

    /**
     * POST /api/v1/admin/dlna/start — starts the CDS server.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response indicating success or failure
     *
     * @since 2.2
     */
    public function start(Request $request, array $params): Response
    {
        if ($this->cdsServer === null) {
            return (new Response())
                ->status(409)
                ->json([
                    'success' => false,
                    'message' => 'DLNA server not configured',
                ]);
        }

        if ($this->cdsServer->isRunning()) {
            return (new Response())
                ->status(409)
                ->json([
                    'success' => false,
                    'message' => 'DLNA server is already running',
                ]);
        }

        try {
            $this->cdsServer->start();
            return (new Response())
                ->status(200)
                ->json(['success' => true]);
        } catch (\Throwable $e) {
            return (new Response())
                ->status(500)
                ->json([
                    'success' => false,
                    'message' => 'Failed to start DLNA server: ' . $e->getMessage(),
                ]);
        }
    }

    /**
     * POST /api/v1/admin/dlna/stop — stops the CDS server.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response indicating success or failure
     *
     * @since 2.2
     */
    public function stop(Request $request, array $params): Response
    {
        if ($this->cdsServer === null) {
            return (new Response())
                ->status(409)
                ->json([
                    'success' => false,
                    'message' => 'DLNA server not configured',
                ]);
        }

        if (!$this->cdsServer->isRunning()) {
            return (new Response())
                ->status(409)
                ->json([
                    'success' => false,
                    'message' => 'DLNA server is not running',
                ]);
        }

        try {
            $this->cdsServer->stop();
            return (new Response())
                ->status(200)
                ->json(['success' => true]);
        } catch (\Throwable $e) {
            return (new Response())
                ->status(500)
                ->json([
                    'success' => false,
                    'message' => 'Failed to stop DLNA server: ' . $e->getMessage(),
                ]);
        }
    }
}
