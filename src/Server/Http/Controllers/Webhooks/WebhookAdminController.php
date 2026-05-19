<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Webhooks;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Webhooks\DispatchResult;
use Phlex\Webhooks\WebhookDispatcher;
use Phlex\Webhooks\WebhookEvent;
use DateTimeImmutable;
use InvalidArgumentException;

class WebhookAdminController
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
    ) {
    }

    /**
     * @param array<string, string> $params Path parameters (unused).
     */
    public function index(Request $request, array $params): Response
    {
        $webhooks = $this->dispatcher->listWebhooks();

        return (new Response())->json(['webhooks' => $webhooks]);
    }

    /**
     * @param array<string, string> $params Path parameters (unused).
     */
    public function create(Request $request, array $params): Response
    {
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
        $id = $params['id'] ?? null;

        if (!is_string($id) || $id === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing webhook ID',
            ]);
        }

        $this->dispatcher->unregister($id);

        return (new Response())->status(204)->json([]);
    }

    /**
     * @param array<string, string> $params Path parameters (id).
     */
    public function test(Request $request, array $params): Response
    {
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

        $result = $this->dispatcher->dispatch($testEvent);

        return (new Response())->json([
            'success' => $result->failureCount === 0,
            'success_count' => $result->successCount,
            'failure_count' => $result->failureCount,
            'failures' => $result->failures,
        ]);
    }
}
