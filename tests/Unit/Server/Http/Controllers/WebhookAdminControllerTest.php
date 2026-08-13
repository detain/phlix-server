<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Webhooks;

use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
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
 *
 * ## S323 — every case here now runs THROUGH the admin gate
 *
 * Before S323 the controller was built with no middleware at all, so
 * `requireAdmin()` waved every request through on its `if ($this->adminMiddleware
 * !== null)` branch. Each case below already sent `userId = 'admin-1'` and
 * asserted a handler outcome, which meant they pinned the fail-open in EFFECT
 * while their names and docblocks only ever described the handler. The gate is
 * now a required constructor dependency and the fixture below admits exactly
 * `admin-1`, so every assertion in this file is unchanged but is now reached by
 * a request an admin decision actually admitted.
 *
 * The gate's own three arms (anonymous / non-admin / admin) live in
 * {@see \Phlix\Tests\Unit\Server\Http\Controllers\WebhookAdminControllerAdminGateIsStructuralTest}.
 */
final class WebhookAdminControllerTest extends TestCase
{
    private WebhookAdminController $controller;
    private FakeWebhookDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new FakeWebhookDispatcher();

        // A REAL gate that admits exactly `admin-1` — the user id every case
        // below sends. Not a permissive stub: a request from anyone else is
        // refused here exactly as it would be in production.
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $this->controller = new WebhookAdminController(
            $this->dispatcher,
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );

        // Deterministic, offline SSRF resolution for the create-path guard:
        // public test hosts resolve to a public IP.
        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $body): array
    {
        /** @var array<string, mixed> $decoded */
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

        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->index($request, []);

        self::assertSame(200, $response->statusCode);
        /** @var array{webhooks: list<array<string, mixed>>} $body */
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('webhooks', $body);
        self::assertCount(1, $body['webhooks']);
        self::assertSame('webhook-1', $body['webhooks'][0]['id']);
    }

    public function test_create_with_valid_data_returns_201(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'New Webhook',
            'url' => 'https://example.com/hook',
            'secret' => 'supersecret',
            'events' => ['media.played', 'media.added'],
        ];

        $response = $this->controller->create($request, []);

        self::assertSame(201, $response->statusCode);
        /** @var array{webhook: array<string, mixed>} $body */
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('webhook', $body);
        self::assertSame('New Webhook', $body['webhook']['name']);
        self::assertNotEmpty($body['webhook']['id']);
    }

    public function test_create_with_missing_fields_returns_400(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['name' => 'Incomplete'];

        $response = $this->controller->create($request, []);

        self::assertSame(400, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('error', $body);
    }

    public function test_create_with_invalid_url_returns_400(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
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

    public function test_create_rejects_cloud_metadata_url(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Metadata SSRF',
            'url' => 'http://169.254.169.254/latest/meta-data/',
            'secret' => 'secret',
            'events' => [],
        ];

        $response = $this->controller->create($request, []);

        self::assertSame(400, $response->statusCode);
        /** @var array{error: string} $body */
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('error', $body);
        self::assertStringContainsStringIgnoringCase('non-public', $body['error']);
    }

    public function test_create_rejects_loopback_url(): void
    {
        SsrfGuard::setResolver(static fn (string $host): array => ['127.0.0.1']);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Loopback SSRF',
            'url' => 'http://127.0.0.1/hook',
            'secret' => 'secret',
            'events' => [],
        ];

        $response = $this->controller->create($request, []);

        self::assertSame(400, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('error', $body);
    }

    public function test_delete_with_valid_id_returns_204(): void
    {
        $this->dispatcher->webhooks = [
            ['id' => 'webhook-to-delete', 'name' => 'Delete Me'],
        ];

        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->delete($request, ['id' => 'webhook-to-delete']);

        self::assertSame(204, $response->statusCode);
    }

    public function test_delete_with_missing_id_returns_400(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->delete($request, []);

        self::assertSame(400, $response->statusCode);
    }

    public function test_delete_with_nonexistent_id_succeeds(): void
    {
        // delete should not fail even if webhook doesn't exist
        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->delete($request, ['id' => 'nonexistent']);

        self::assertSame(204, $response->statusCode);
    }

    public function test_test_with_valid_webhook_id_returns_result(): void
    {
        $this->dispatcher->webhooks = [
            ['id' => 'webhook-1', 'name' => 'Test Webhook', 'url' => 'https://example.com/hook'],
        ];
        $this->dispatcher->dispatchResult = new DispatchResult(1, 0, []);

        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->test($request, ['id' => 'webhook-1']);

        self::assertSame(200, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertArrayHasKey('success', $body);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['success_count']);
        // The clicked webhook id must be the one delivered to, not routed
        // through the subscription-filtered dispatch() path.
        self::assertSame('webhook-1', $this->dispatcher->lastDispatchedWebhookId);
    }

    public function test_test_with_nonexistent_webhook_id_returns_404(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->test($request, ['id' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertSame('Webhook not found', $body['error']);
    }

    public function test_test_with_missing_webhook_id_returns_400(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->test($request, []);

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

    /** @var string|null Id passed to the last dispatchToWebhook() call. */
    public ?string $lastDispatchedWebhookId = null;

    public function dispatchToWebhook(string $webhookId, WebhookEvent $event): DispatchResult
    {
        $this->lastDispatchedWebhookId = $webhookId;
        return $this->dispatchResult ?? new DispatchResult(0, 0, []);
    }
}
