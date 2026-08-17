<?php

/**
 * Phlix media server component: Arr.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
    /**
     * Creates a new SyncController instance.
     *
     * @param CustomFormatSyncer $syncer The custom format syncer instance.
     * @param AdminMiddleware $adminMiddleware Admin gate for EVERY handler on this
     *        controller ({@see self::requireAdmin()}). REQUIRED — see below.
     *
     * ## S323 — the admin gate is a construction-time requirement
     *
     * This used to be `private ?AdminMiddleware $adminMiddleware = null;` filled
     * by an OPTIONAL `setAdminMiddleware()` setter, with
     * {@see self::requireAdmin()} wrapping its decision in
     * `if ($this->adminMiddleware !== null)`. That combination **failed OPEN**: a
     * controller built without the setter returned "authorised" from
     * `requireAdmin()` without any admin decision having been taken, so all three
     * `/api/v1/admin/sync/*` endpoints — including the one that triggers a full
     * outbound TRaSH-Guides sync and the one that enables/disables it — were
     * reachable by any logged-in user.
     *
     * `requireAdmin()` calls `requireAuth()` first, so the fail-open never reached
     * an anonymous caller here (unlike `ThemeMediaController`, S323 phase 1). The
     * live wiring did call the setter, so the hole was latent — but "latent" is a
     * property of today's wiring, not of the class, and PHP-DI's `autowire()`
     * SKIPS optional parameters, which is how this estate has produced
     * silently-null dependencies before.
     *
     * Making the dependency REQUIRED removes the null state entirely: the gate has
     * no null branch left to take, and a construction that omits it is an
     * `ArgumentCountError` at the `new`, not a security downgrade at request time.
     *
     * Do NOT re-introduce a nullable type, a default value, or a setter.
     * `tests/Unit/Server/Http/Controllers/SyncControllerAdminGateIsStructuralTest.php`
     * fails on any of the three.
     */
    public function __construct(
        private readonly CustomFormatSyncer $syncer,
        private readonly AdminMiddleware $adminMiddleware
    ) {
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
     *
     * S323: there is deliberately NO `if ($this->adminMiddleware !== null)` guard
     * here. The middleware is a required constructor dependency
     * ({@see self::$adminMiddleware}), so the check is unconditional and this
     * method can only return `null` — "proceed" — after an admin decision was
     * actually taken. Re-adding a null guard would restore the S323 fail-open.
     */
    private function requireAdmin(Request $request): ?Response
    {
        // First require auth
        $authResponse = $this->requireAuth($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        // Then check admin status — unconditional; see the docblock above.
        $status = $this->adminMiddleware->checkAccess($request);
        if ($status !== null) {
            return (new Response())->status($status)->json([
                'error' => $status === 401 ? 'Unauthorized' : 'Forbidden',
                'code' => $status === 401 ? 'auth.required' : 'auth.not_admin',
            ]);
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
