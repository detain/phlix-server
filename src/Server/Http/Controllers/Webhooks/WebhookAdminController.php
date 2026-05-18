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

    public function index(Request $request, array $params): Response
    {
        $webhooks = $this->dispatcher->listWebhooks();

        return (new Response())->json(['webhooks' => $webhooks]);
    }

    public function create(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['name']) || empty($data['url']) || empty($data['secret'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: name, url, secret',
            ]);
        }

        $url = trim((string) $data['url']);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid URL format',
            ]);
        }

        $events = $data['events'] ?? [];
        if (!is_array($events)) {
            return (new Response())->status(400)->json([
                'error' => 'events must be an array',
            ]);
        }

        try {
            $id = $this->dispatcher->register(
                trim((string) $data['name']),
                $url,
                trim((string) $data['secret']),
                $events
            );

            return (new Response())->status(201)->json([
                'webhook' => [
                    'id' => $id,
                    'name' => trim((string) $data['name']),
                    'url' => $url,
                    'events' => $events,
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }
    }

    public function delete(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;

        if (empty($id)) {
            return (new Response())->status(400)->json([
                'error' => 'Missing webhook ID',
            ]);
        }

        $this->dispatcher->unregister($id);

        return (new Response())->status(204)->json([]);
    }

    public function test(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;

        if (empty($id)) {
            return (new Response())->status(400)->json([
                'error' => 'Missing webhook ID',
            ]);
        }

        $webhooks = $this->dispatcher->listWebhooks();
        $webhook = null;
        foreach ($webhooks as $w) {
            if ($w['id'] === $id) {
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
