<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Webhooks;

use Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController;
use Phlix\Server\Http\Request;
use Phlix\Webhooks\DispatchResult;
use Phlix\Webhooks\WebhookDispatcher;
use Phlix\Webhooks\WebhookEvent;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WebhookAdminController.
 *
 * Covers all four controller actions: index, create, delete, test.
 */
final class WebhookAdminControllerTest extends TestCase
{
    private WebhookAdminController $controller;
    private FakeWebhookDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new FakeWebhookDispatcher();
        $this->controller = new WebhookAdminController($this->dispatcher);
    }

    private function decodeBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to decode JSON body: ' . $body);
        }
        return $decoded;
    }

    public function test_index_returns_webhook_list(): void
    {
        $this->dispatcher->webhooks = [
            ['id' => 'webhook-1', 'name' => 'Test Webhook', 'url' => 'https://example.com/hook'],
        ];

        $response = $this->controller->index(new Request(), []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('webhooks', $body);
        self::assertCount(1, $body['webhooks']);
        self::assertSame('webhook-1', $body['webhooks'][0]['id']);
    }

    public function test_create_with_valid_data_returns_201(): void
    {
        $request = new Request();
        $request->body = [
            'name' => 'New Webhook',
            'url' => 'https://example.com/hook',
            'secret' => 'supersecret',
            'events' => ['media.played', 'media.added'],
        ];

        $response = $this->controller->create($request, []);

        self::assertSame(201, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('webhook', $body);
        self::assertSame('New Webhook', $body['webhook']['name']);
        self::assertNotEmpty($body['webhook']['id']);
    }

    public function test_create_with_missing_fields_returns_400(): void
    {
        $request = new Request();
        $request->body = ['name' => 'Incomplete'];

        $response = $this->controller->create($request, []);

        self::assertSame(400, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('error', $body);
    }

    public function test_create_with_invalid_url_returns_400(): void
    {
        $request = new Request();
        $request->body = [
            'name' => 'Bad URL',
            'url' => 'not-a-valid-url',
            'secret' => 'secret',
            'events' => [],
        ];

        $response = $this->controller->create($request, []);

        self::assertSame(400, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertSame('Invalid URL format', $body['error']);
    }

    public function test_delete_with_valid_id_returns_204(): void
    {
        $this->dispatcher->webhooks = [
            ['id' => 'webhook-to-delete', 'name' => 'Delete Me'],
        ];

        $response = $this->controller->delete(new Request(), ['id' => 'webhook-to-delete']);

        self::assertSame(204, $response->statusCode);
    }

    public function test_delete_with_missing_id_returns_400(): void
    {
        $response = $this->controller->delete(new Request(), []);

        self::assertSame(400, $response->statusCode);
    }

    public function test_delete_with_nonexistent_id_succeeds(): void
    {
        // delete should not fail even if webhook doesn't exist
        $response = $this->controller->delete(new Request(), ['id' => 'nonexistent']);

        self::assertSame(204, $response->statusCode);
    }

    public function test_test_with_valid_webhook_id_returns_result(): void
    {
        $this->dispatcher->webhooks = [
            ['id' => 'webhook-1', 'name' => 'Test Webhook', 'url' => 'https://example.com/hook'],
        ];
        $this->dispatcher->dispatchResult = new DispatchResult(1, 0, []);

        $response = $this->controller->test(new Request(), ['id' => 'webhook-1']);

        self::assertSame(200, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('success', $body);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['success_count']);
    }

    public function test_test_with_nonexistent_webhook_id_returns_404(): void
    {
        $response = $this->controller->test(new Request(), ['id' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertSame('Webhook not found', $body['error']);
    }

    public function test_test_with_missing_webhook_id_returns_400(): void
    {
        $response = $this->controller->test(new Request(), []);

        self::assertSame(400, $response->statusCode);
    }
}

/**
 * Fake WebhookDispatcher for testing.
 *
 * @internal Test fixture only.
 */
final class FakeWebhookDispatcher extends WebhookDispatcher
{
    /** @var list<array<string, mixed>> */
    public array $webhooks = [];

    public DispatchResult $dispatchResult;

    public function __construct()
    {
        // Skip parent constructor which needs a DB connection
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWebhooks(): array
    {
        return $this->webhooks;
    }

    public function register(string $name, string $url, string $secret, array $events): string
    {
        $id = 'generated-webhook-id-' . count($this->webhooks);
        $this->webhooks[] = [
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'events' => $events,
        ];
        return $id;
    }

    public function unregister(string $webhookId): bool
    {
        $this->webhooks = array_values(array_filter(
            $this->webhooks,
            fn(array $w) => ($w['id'] ?? '') !== $webhookId
        ));
        return true;
    }

    public function dispatch(WebhookEvent $event): DispatchResult
    {
        return $this->dispatchResult ?? new DispatchResult(0, 0, []);
    }
}
