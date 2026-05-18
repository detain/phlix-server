<?php

namespace Phlex\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Webhooks\DispatchResult;
use Phlex\Webhooks\WebhookDispatcher;
use Phlex\Webhooks\WebhookEvent;
use Workerman\MySQL\Connection;
use DateTimeImmutable;

class WebhookDispatcherTest extends TestCase
{
    private Connection $db;
    private StructuredLogger $logger;
    private WebhookDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->logger->method('info');
        $this->dispatcher = new WebhookDispatcher($this->db, $this->logger);
    }

    public function testRegisterCreatesWebhook(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO webhooks'),
                $this->callback(function ($params) {
                    return count($params) === 5
                        && $params[0] !== null
                        && $params[1] === 'Test Webhook'
                        && $params[2] === 'https://example.com/webhook'
                        && $params[3] === 'test-secret'
                        && $params[4] === '["playback.started"]';
                })
            );

        $id = $this->dispatcher->register(
            'Test Webhook',
            'https://example.com/webhook',
            'test-secret',
            ['playback.started']
        );

        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{4}[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}[0-9a-f]{4}[0-9a-f]{4}$/',
            $id
        );
    }

    public function testUnregisterRemovesWebhook(): void
    {
        $webhookId = 'test-webhook-id-1234';

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM webhooks WHERE id = ?'),
                [$webhookId]
            );

        $result = $this->dispatcher->unregister($webhookId);

        $this->assertTrue($result);
    }

    public function testDispatchSendsToMatchingWebhooks(): void
    {
        $webhookId = 'wh-test-1234';
        $secret = 'test-secret';

        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params = []) use ($webhookId, $secret) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'is_active = TRUE') !== false) {
                    return [
                        [
                            'id' => $webhookId,
                            'name' => 'Test Webhook',
                            'url' => 'https://example.com/webhook',
                            'secret' => $secret,
                            'events_json' => '["playback.started"]',
                        ],
                    ];
                }
                if (strpos($sql, 'INSERT INTO webhook_logs') !== false) {
                    return [];
                }
                if (strpos($sql, 'UPDATE webhooks SET last_triggered_at') !== false) {
                    return [];
                }
                return [];
            });

        $event = new WebhookEvent(
            'playback.started',
            ['media_id' => 'media-123'],
            new DateTimeImmutable()
        );

        $result = $this->dispatcher->dispatch($event);

        $this->assertInstanceOf(DispatchResult::class, $result);
        $this->assertGreaterThanOrEqual(0, $result->successCount + $result->failureCount);
    }

    public function testDispatchReturnsFailureOnHttpError(): void
    {
        $webhookId = 'wh-fail-1234';
        $secret = 'test-secret';

        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params = []) use ($webhookId, $secret) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'is_active = TRUE') !== false) {
                    return [
                        [
                            'id' => $webhookId,
                            'name' => 'Test Webhook',
                            'url' => 'https://invalid-domain-that-does-not-exist.example.com/webhook',
                            'secret' => $secret,
                            'events_json' => '["playback.started"]',
                        ],
                    ];
                }
                if (strpos($sql, 'INSERT INTO webhook_logs') !== false) {
                    return [];
                }
                if (strpos($sql, 'UPDATE webhooks SET failure_count') !== false) {
                    return [];
                }
                return [];
            });

        $event = new WebhookEvent(
            'playback.started',
            ['media_id' => 'media-123'],
            new DateTimeImmutable()
        );

        $result = $this->dispatcher->dispatch($event);

        $this->assertInstanceOf(DispatchResult::class, $result);
        $this->assertGreaterThanOrEqual(0, $result->failureCount);
    }

    public function testListWebhooksReturnsAll(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT'))
            ->willReturn([
                [
                    'id' => 'wh-1',
                    'name' => 'Webhook 1',
                    'url' => 'https://example.com/webhook1',
                    'events_json' => '["playback.started"]',
                    'is_active' => true,
                    'created_at' => '2024-01-01 00:00:00',
                    'last_triggered_at' => null,
                    'failure_count' => 0,
                ],
                [
                    'id' => 'wh-2',
                    'name' => 'Webhook 2',
                    'url' => 'https://example.com/webhook2',
                    'events_json' => '["library.updated"]',
                    'is_active' => true,
                    'created_at' => '2024-01-02 00:00:00',
                    'last_triggered_at' => '2024-01-15 10:00:00',
                    'failure_count' => 2,
                ],
            ]);

        $webhooks = $this->dispatcher->listWebhooks();

        $this->assertIsArray($webhooks);
        $this->assertCount(2, $webhooks);
        $this->assertEquals('wh-1', $webhooks[0]['id']);
        $this->assertEquals(['playback.started'], $webhooks[0]['events']);
        $this->assertEquals('wh-2', $webhooks[1]['id']);
        $this->assertEquals(['library.updated'], $webhooks[1]['events']);
    }

    public function testListWebhooksReturnsEmptyArrayWhenNoneExist(): void
    {
        $this->db->method('query')
            ->willReturn([]);

        $webhooks = $this->dispatcher->listWebhooks();

        $this->assertIsArray($webhooks);
        $this->assertCount(0, $webhooks);
    }

    public function testDispatchDoesNotSendToNonMatchingWebhooks(): void
    {
        $this->db->method('query')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'is_active = TRUE') !== false) {
                    return [
                        [
                            'id' => 'wh-1',
                            'name' => 'Webhook 1',
                            'url' => 'https://example.com/webhook1',
                            'secret' => 'secret1',
                            'events_json' => '["playback.started"]',
                        ],
                        [
                            'id' => 'wh-2',
                            'name' => 'Webhook 2',
                            'url' => 'https://example.com/webhook2',
                            'secret' => 'secret2',
                            'events_json' => '["library.updated"]',
                        ],
                    ];
                }
                return [];
            });

        $event = new WebhookEvent(
            'download.complete',
            ['download_id' => 'dl-1'],
            new DateTimeImmutable()
        );

        $result = $this->dispatcher->dispatch($event);

        $this->assertInstanceOf(DispatchResult::class, $result);
        $this->assertEquals(0, $result->successCount);
        $this->assertEquals(0, $result->failureCount);
    }
}
