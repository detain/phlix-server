<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Arr;

use Phlix\Server\Arr\CustomFormatSyncer;
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
        try {
            $result = $this->syncer->syncAll();

            return (new Response())->json([
                'success' => true,
                'message' => 'TRaSH-Guides sync completed',
                'data' => $result->toArray(),
            ]);
        } catch (\Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error' => 'Sync failed: ' . $e->getMessage(),
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
