<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Updates\CoreUpdateCheckService;

/**
 * Core update-check admin API (S74 / updates.md #48).
 *
 * `GET /api/v1/admin/updates/status`    — current version, latest known
 * version, whether an update is available, when the last check ran, and the
 * copy-to-clipboard update command.
 *
 * `PUT /api/v1/admin/updates/settings`  — persist the `updates.check_enabled`
 * toggle. Body: `{"checkEnabled": bool}`.
 *
 * ## Three properties this controller must keep
 *
 * 1. **No outbound I/O.** `status()` reads persisted state only
 *    ({@see CoreUpdateCheckService::status()}); the network fetch belongs to the
 *    background-timer worker. An HTTP handler in a resident-memory Workerman
 *    process must not wait on a third-party host.
 * 2. **No apply action.** There is deliberately no `POST .../apply`: the server
 *    never runs git/composer/systemctl. `updateCommand` in the status payload is
 *    a string for the operator to paste into a root shell.
 * 3. **Its own admin gate.** The route group in
 *    {@see \Phlix\Server\Http\Routes\AdminRoutes::register()} already carries
 *    {@see AdminMiddleware}, but a controller that mutates configuration must
 *    not depend on a route table staying correct — mirrors
 *    {@see \Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController}.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since   S74 (core update check)
 */
final class AdminUpdatesController
{
    /**
     * Both parameters are REQUIRED and neither carries a default.
     *
     * PHP-DI's `autowire()` SILENTLY SKIPS optional constructor parameters, so a
     * `?AdminMiddleware $adminMiddleware = null` would resolve to `null` in
     * production and the in-handler gate below would be permanently dead while
     * every unit test that passes one explicitly stayed green. That failure mode
     * has already shipped in this repo more than once (`BackupManager`'s
     * `auditLogger`, `AdminUserController`'s `authManager`).
     *
     * @param CoreUpdateCheckService $updates         Update-check service.
     * @param AdminMiddleware        $adminMiddleware Admin gate (defence in depth).
     */
    public function __construct(
        private readonly CoreUpdateCheckService $updates,
        private readonly AdminMiddleware $adminMiddleware,
    ) {
    }

    /**
     * `GET /api/v1/admin/updates/status` — report the last known update state.
     *
     * Status codes: 200 ok · 401 unauthenticated · 403 not admin.
     *
     * @param Request               $request Inbound request.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response
     */
    public function status(Request $request, array $params = []): Response
    {
        $denied = $this->requireAdmin($request);
        if ($denied !== null) {
            return $denied;
        }

        return (new Response())->json([
            'success' => true,
            'data'    => $this->updates->status()->toArray(),
        ]);
    }

    /**
     * `PUT /api/v1/admin/updates/settings` — persist the check toggle.
     *
     * Body: `{"checkEnabled": bool}`. The response echoes the re-resolved status
     * so the admin UI can repaint without a follow-up GET.
     *
     * Status codes: 200 ok · 400 invalid payload · 401 unauthenticated ·
     * 403 not admin.
     *
     * @param Request               $request Inbound request.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response
     */
    public function updateSettings(Request $request, array $params = []): Response
    {
        $denied = $this->requireAdmin($request);
        if ($denied !== null) {
            return $denied;
        }

        $body = $request->body;
        if (!array_key_exists('checkEnabled', $body)) {
            return (new Response())->status(400)->json([
                'success' => false,
                'error'   => 'Invalid payload',
                'code'    => 'invalid_payload',
                'message' => 'Body must contain a boolean "checkEnabled".',
            ]);
        }

        /** @var mixed $value */
        $value = $body['checkEnabled'];
        if (!is_bool($value)) {
            // Deliberately NOT coerced. `"false"`, `0` and `[]` are all truthy
            // or falsy in ways a caller did not mean, and this toggle decides
            // whether a server ever learns about a security release.
            return (new Response())->status(400)->json([
                'success' => false,
                'error'   => 'Validation failed',
                'code'    => 'invalid_payload',
                'errors'  => [
                    'checkEnabled' => sprintf('Expected type bool, got %s.', gettype($value)),
                ],
            ]);
        }

        $this->updates->setCheckEnabled($value);

        return (new Response())->json([
            'success' => true,
            'message' => 'Settings updated.',
            'data'    => $this->updates->status()->toArray(),
        ]);
    }

    /**
     * Defence-in-depth admin gate.
     *
     * @param Request $request Inbound request.
     *
     * @return Response|null Denial response, or null when the caller is an admin.
     */
    private function requireAdmin(Request $request): ?Response
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return (new Response())->status(401)->json([
                'success' => false,
                'error'   => 'Unauthorized',
                'code'    => 'auth.required',
            ]);
        }

        $status = $this->adminMiddleware->checkAccess($request);
        if ($status === null) {
            return null;
        }

        return (new Response())->status($status)->json([
            'success' => false,
            'error'   => $status === 401 ? 'Unauthorized' : 'Forbidden',
            'code'    => $status === 401 ? 'auth.required' : 'auth.not_admin',
        ]);
    }
}
