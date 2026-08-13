<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Webhooks;

use Phlix\Common\Net\SsrfGuard;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Webhooks\DispatchResult;
use Phlix\Webhooks\WebhookDispatcher;
use Phlix\Webhooks\WebhookEvent;
use DateTimeImmutable;
use InvalidArgumentException;

class WebhookAdminController
{
    /**
     * @param WebhookDispatcher $dispatcher Webhook registry + delivery.
     * @param AdminMiddleware $adminMiddleware Admin gate for EVERY handler on
     *        this controller ({@see self::requireAdmin()}). REQUIRED — see below.
     *
     * ## S323 — the admin gate is a construction-time requirement
     *
     * This used to be `private ?AdminMiddleware $adminMiddleware = null;` filled
     * by an OPTIONAL `setAdminMiddleware()` setter, with
     * {@see self::requireAdmin()} wrapping its decision in
     * `if ($this->adminMiddleware !== null)`. That combination **failed OPEN**: a
     * controller built without the setter returned "authorised" from
     * `requireAdmin()` without any admin decision having been taken, downgrading
     * all five webhook-admin endpoints — including create/update/delete, which
     * register outbound URLs the server will sign and POST to — to auth-only.
     *
     * `requireAdmin()` calls `requireAuth()` first, so the fail-open never reached
     * an anonymous caller here (unlike `ThemeMediaController`, S323 phase 1); it
     * reached ANY logged-in user. The live wiring did call the setter, so the hole
     * was latent — but "latent" is a property of today's wiring, not of the class,
     * and PHP-DI's `autowire()` SKIPS optional parameters, which is how this
     * estate has produced silently-null dependencies before.
     *
     * Making the dependency REQUIRED removes the null state entirely: the gate has
     * no null branch left to take, and a construction that omits it is an
     * `ArgumentCountError` at the `new`, not a security downgrade at request time.
     *
     * Do NOT re-introduce a nullable type, a default value, or a setter.
     * `tests/Unit/Server/Http/Controllers/WebhookAdminControllerAdminGateIsStructuralTest.php`
     * fails on any of the three.
     */
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly AdminMiddleware $adminMiddleware,
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
     * @param array<string, string> $params Path parameters (unused).
     */
    public function index(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $webhooks = $this->dispatcher->listWebhooks();

        return (new Response())->json(['webhooks' => $webhooks]);
    }

    /**
     * @param array<string, string> $params Path parameters (unused).
     */
    public function create(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $data = $request->body;

        $nameRaw = $data['name'] ?? null;
        $urlRaw = $data['url'] ?? null;
        $secretRaw = $data['secret'] ?? null;

        if (
            !is_string($nameRaw) || $nameRaw === ''
            || !is_string($urlRaw) || $urlRaw === ''
            || !is_string($secretRaw) || $secretRaw === ''
        ) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: name, url, secret',
            ]);
        }

        $url = trim($urlRaw);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid URL format',
            ]);
        }

        // SSRF guard: reject loopback/link-local/private/metadata targets at
        // admin-config time. DNS resolution here is blocking, but webhook
        // creation is an operator-triggered admin action off the media hot path.
        try {
            SsrfGuard::assertPublicUrl($url);
        } catch (InvalidArgumentException $e) {
            return (new Response())->status(400)->json([
                'error' => $e->getMessage(),
            ]);
        }

        $eventsRaw = $data['events'] ?? [];
        if (!is_array($eventsRaw)) {
            return (new Response())->status(400)->json([
                'error' => 'events must be an array',
            ]);
        }
        $events = [];
        foreach ($eventsRaw as $event) {
            if (is_string($event)) {
                $events[] = $event;
            }
        }

        $name = trim($nameRaw);

        try {
            $id = $this->dispatcher->register(
                $name,
                $url,
                trim($secretRaw),
                $events
            );

            return (new Response())->status(201)->json([
                'webhook' => [
                    'id' => $id,
                    'name' => $name,
                    'url' => $url,
                    'events' => $events,
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, string> $params Path parameters (id).
     */
    public function delete(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $id = $params['id'] ?? null;

        if (!is_string($id) || $id === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing webhook ID',
            ]);
        }

        $this->dispatcher->unregister($id);

        return (new Response())->status(204);
    }

    /**
     * @param array<string, string> $params Path parameters (id).
     */
    public function update(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $id = $params['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return (new Response())->status(400)->json(['error' => 'Missing webhook ID']);
        }

        $data = $request->body;
        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (isset($data['url'])) {
            $url = is_string($data['url']) ? trim($data['url']) : '';
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid URL format',
                ]);
            }

            // SSRF guard on the operator surface, mirroring create(): reject
            // loopback/link-local/private/metadata targets at admin-config
            // time. (The dispatch path also guards, but keep the operator
            // surface consistent.) DNS here is blocking but this is an
            // operator-triggered admin action off the media hot path.
            try {
                SsrfGuard::assertPublicUrl($url);
            } catch (InvalidArgumentException $e) {
                return (new Response())->status(400)->json([
                    'error' => $e->getMessage(),
                ]);
            }

            $updateData['url'] = $url;
        }
        if (isset($data['events'])) {
            $updateData['events'] = $data['events'];
        }

        if ($updateData === []) {
            return (new Response())->status(400)->json(['error' => 'No fields to update']);
        }

        $this->dispatcher->update($id, $updateData);

        $webhooks = $this->dispatcher->listWebhooks();
        $webhook = null;
        foreach ($webhooks as $w) {
            if (isset($w['id']) && $w['id'] === $id) {
                $webhook = $w;
                break;
            }
        }

        return (new Response())->json(['webhook' => $webhook]);
    }

    /**
     * @param array<string, string> $params Path parameters (id).
     */
    public function test(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $id = $params['id'] ?? null;

        if (!is_string($id) || $id === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing webhook ID',
            ]);
        }

        $webhooks = $this->dispatcher->listWebhooks();
        $webhook = null;
        foreach ($webhooks as $w) {
            if (isset($w['id']) && $w['id'] === $id) {
                $webhook = $w;
                break;
            }
        }

        if ($webhook === null) {
            return (new Response())->status(404)->json([
                'error' => 'Webhook not found',
            ]);
        }

        $testEvent = new WebhookEvent(
            'webhook.test',
            [
                'message' => 'This is a test webhook event',
                'webhook_id' => $id,
            ],
            new DateTimeImmutable()
        );

        // Deliver to THE webhook the operator clicked, not to whichever rows
        // happen to subscribe to `webhook.test`. Routing this through
        // dispatch() meant the subscription filter dropped every UI-created
        // webhook (the admin catalogue excludes `webhook.test`), leaving
        // failureCount === 0 and this endpoint answering `success: true` for a
        // request that never left the process.
        // {@see WebhookDispatcher::dispatchToWebhook()}
        $result = $this->dispatcher->dispatchToWebhook($id, $testEvent);

        return (new Response())->json([
            'success' => $result->failureCount === 0,
            'success_count' => $result->successCount,
            'failure_count' => $result->failureCount,
            'failures' => $result->failures,
        ]);
    }
}
