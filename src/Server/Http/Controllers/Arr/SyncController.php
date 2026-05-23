<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Arr;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Arr\CustomFormatSyncer;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Handles TRaSH-Guides sync API endpoints.
 *
 * Provides admin endpoints for triggering syncs, checking status,
 * and enabling/disabling auto-sync.
 *
 * @package Phlix\Server\Http\Controllers\Arr
 * @since 0.12.0
 */
class SyncController
{
    private ?AdminMiddleware $adminMiddleware = null;

    /**
     * Creates a new SyncController instance.
     *
     * @param CustomFormatSyncer $syncer The custom format syncer instance.
     */
    public function __construct(
        private readonly CustomFormatSyncer $syncer
    ) {
    }

    /**
     * Set the admin middleware (used for admin-only operations).
     */
    public function setAdminMiddleware(AdminMiddleware $middleware): void
    {
        $this->adminMiddleware = $middleware;
    }

    /**
     * Require authentication for the request.
     */
    private function requireAuth(Request $request): ?Response
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }
        return null;
    }

    /**
     * Require admin access for the request.
     */
    private function requireAdmin(Request $request): ?Response
    {
        // First require auth
        $authResponse = $this->requireAuth($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        // Then check admin status
        if ($this->adminMiddleware !== null) {
            $status = $this->adminMiddleware->checkAccess($request);
            if ($status !== null) {
                return (new Response())->status($status)->json([
                    'error' => $status === 401 ? 'Unauthorized' : 'Forbidden',
                    'code' => $status === 401 ? 'auth.required' : 'auth.not_admin',
                ]);
            }
        }

        return null;
    }

    /**
     * Triggers a full TRaSH-Guides sync.
     *
     * POST /api/v1/admin/sync/trash-guides
     *
     * @param Request $request The HTTP request.
     * @param array<string, string> $params Path parameters (unused).
     * @return Response JSON response with sync results.
     *
     * @since 0.12.0
     */
    public function triggerSync(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        try {
            $result = $this->syncer->syncAll();

            return (new Response())->json([
                'success' => true,
                'message' => 'TRaSH-Guides sync completed',
                'data' => $result->toArray(),
            ]);
        } catch (\Throwable $e) {
            // Log the full exception internally but return a generic message
            try {
                $logger = LoggerFactory::get(LogChannels::APPLICATION);
                $logger->error('TRaSH-Guides sync failed', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } catch (\Throwable) {
                // Logging failed - silently continue
            }

            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Sync failed',
            ]);
        }
    }

    /**
     * Gets the current sync status.
     *
     * GET /api/v1/admin/sync/status
     *
     * Returns the last sync time and current configuration status.
     *
     * @param Request $request The HTTP request.
     * @param array<string, string> $params Path parameters (unused).
     * @return Response JSON response with sync status.
     *
     * @since 0.12.0
     */
    public function getSyncStatus(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $lastSyncTime = $this->syncer->getLastSyncTime();
        $isEnabled = $this->syncer->isEnabled();

        return (new Response())->json([
            'enabled' => $isEnabled,
            'last_sync_at' => $lastSyncTime !== null
                ? date('c', $lastSyncTime)
                : null,
            'last_sync_timestamp' => $lastSyncTime,
        ]);
    }

    /**
     * Enables or disables the TRaSH-Guides sync.
     *
     * PUT /api/v1/admin/sync/enable
     *
     * Expects a JSON body with an 'enabled' boolean field.
     *
     * @param Request $request The HTTP request.
     * @param array<string, string> $params Path parameters (unused).
     * @return Response JSON response confirming the change.
     *
     * @since 0.12.0
     */
    public function setEnabled(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $data = $request->body;

        if (!isset($data['enabled'])) {
            return (new Response())->status(400)->json([
                'success' => false,
                'error' => 'Missing required field: enabled',
            ]);
        }

        $enabled = (bool) $data['enabled'];
        $this->syncer->setEnabled($enabled);

        return (new Response())->json([
            'success' => true,
            'message' => 'TRaSH-Guides sync ' . ($enabled ? 'enabled' : 'disabled'),
            'enabled' => $enabled,
        ]);
    }
}
