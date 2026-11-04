<?php

/**
 * Phlix media server test: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use InvalidArgumentException;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Webhooks\WebhookDeliveryRecord;
use Phlix\Webhooks\WebhookHttpClient;
use Phlix\Webhooks\WebhookService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\Worker;
use Workerman\MySQL\Connection;

/**
 * SRV-M20 (review server__secaudit): the unsigned subscription subsystem
 * ({@see WebhookService}) must carry the same SSRF law its signed sibling
 * {@see WebhookDispatcher} already enforces — at write time INSIDE the
 * service (not controller-only), and at dispatch time against the STORED
 * row, so a delivery mutated after creation cannot reach link-local,
 * loopback, or private targets.
 *
 * Denied legs use IPv4 literals (169.254.169.254 / 127.0.0.1): SsrfGuard
 * resolves literals without DNS, keeping these tests network-free. The
 * allowed leg uses the public literal 93.184.216.34 for the same reason.
 */
class WebhookServiceSsrfTest extends TestCase
{
    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedWorkers = Worker::getAllWorkers();
        // handleFailedDelivery schedules one-shot retry timers; Timer::add()
        // requires a registered Worker (same bare-Worker idiom as
        // WebhookServiceTest).
        if (!Worker::getAllWorkers()) {
            new Worker();
        }
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        $workers = new \ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);
        parent::tearDown();
    }

    // ─── write-time leg (service-layer law) ───────────────────────────────────

    public function testCreateSubscriptionRefusesLinkLocalMetadataUrlBeforeAnyInsert(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $httpClient = $this->createMock(WebhookHttpClient::class);
        $service = new WebhookService($db, $httpClient);

        try {
            $service->createSubscription('http://169.254.169.254/latest/meta-data/', ['user.login']);
            $this->fail('createSubscription accepted a link-local metadata URL');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('169.254.169.254', $e->getMessage());
        }
    }

    public function testCreateSubscriptionRefusesLoopbackUrlBeforeAnyInsert(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $service = new WebhookService($db, $this->createMock(WebhookHttpClient::class));

        $this->expectException(InvalidArgumentException::class);
        $service->createSubscription('http://127.0.0.1:8097/admin/oops', ['user.login']);
    }

    public function testCreateSubscriptionStillAcceptsAPublicUrl(): void
    {
        /** @var array<int, array{0: string, 1: array<mixed>}> $inserts */
        $inserts = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $bind = []) use (&$inserts) {
                $inserts[] = [$sql, $bind];
                return [];
            });
        $db->method('lastInsertId')->willReturn(7);

        $service = new WebhookService($db, $this->createMock(WebhookHttpClient::class));

        $this->assertSame(7, $service->createSubscription('http://93.184.216.34/hook', ['user.login']));
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString('INSERT INTO webhook_subscriptions', $inserts[0][0]);
    }

    // ─── dispatch-time leg (stored-row re-validation) ─────────────────────────

    public function testProcessDeliveryRefusesARowMutatedToTheMetadataIpWithoutPosting(): void
    {
        $updated = $this->runProcessDeliveryWithStoredUrl('http://169.254.169.254/latest/meta-data/');

        $this->assertNotNull($updated, 'the refused delivery must still be recorded as failed');
        // bind order: [attempt, response_code, response_body, next_retry_at, id]
        $this->assertStringContainsString('SSRF guard blocked URL', (string) $updated[1][2]);
    }

    public function testProcessDeliveryRefusesARowMutatedToLoopbackWithoutPosting(): void
    {
        $updated = $this->runProcessDeliveryWithStoredUrl('http://127.0.0.1/internal');

        $this->assertNotNull($updated);
        $this->assertStringContainsString('SSRF guard blocked URL', (string) $updated[1][2]);
    }

    public function testRetryDeliveryAlsoRefusesAPoisonedStoredRow(): void
    {
        $deliveryRow = $this->deliveryRow('http://169.254.169.254/latest/meta-data/', attempt: 2);
        /** @var array<int, array{0: string, 1: array<mixed>}> $writes */
        $writes = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $bind = []) use (&$writes, $deliveryRow): array {
                if (str_starts_with($sql, 'SELECT id, event_id, webhook_url')) {
                    return [$deliveryRow];
                }
                if (str_starts_with($sql, 'SELECT id, event_type, payload')) {
                    return [[
                        'id' => 1,
                        'event_type' => 'user.login',
                        'payload' => '{"ok":true}',
                        'created_at' => '2026-11-04 00:00:00',
                    ]];
                }
                $writes[] = [$sql, $bind];
                return [];
            });

        $httpClient = $this->createMock(WebhookHttpClient::class);
        $httpClient->expects($this->never())->method('post');

        $service = new WebhookService($db, $httpClient);
        $retry = new ReflectionMethod(WebhookService::class, 'retryDelivery');
        $retry->setAccessible(true);
        $retry->invoke($service, 42);

        $update = $this->findDeliveryUpdate($writes);
        $this->assertNotNull($update);
        $this->assertStringContainsString('SSRF guard blocked URL', (string) $update[1][2]);
    }

    public function testProcessDeliveryPostsWhenTheStoredUrlIsPublic(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $bind = []): array {
                if (str_starts_with($sql, 'SELECT id, event_id, webhook_url')) {
                    return [$this->deliveryRow('http://93.184.216.34/hook')];
                }
                return [];
            });

        $httpClient = $this->createMock(WebhookHttpClient::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('http://93.184.216.34/hook')
            ->willReturn(['success' => true, 'response_code' => 200, 'response_body' => 'ok']);

        $service = new WebhookService($db, $httpClient);
        $process = new ReflectionMethod(WebhookService::class, 'processDelivery');
        $process->setAccessible(true);
        $process->invoke($service, 1, 'user.login', [], 42);
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function deliveryRow(string $url, int $attempt = 1): array
    {
        return [
            'id' => 42,
            'event_id' => 1,
            'webhook_url' => $url,
            'attempt' => $attempt,
            'status' => WebhookDeliveryRecord::STATUS_PENDING,
            'response_code' => null,
            'response_body' => null,
            'next_retry_at' => null,
            'created_at' => '2026-11-04 00:00:00',
            'delivered_at' => null,
        ];
    }

    /**
     * Run processDelivery against a stored row carrying $url and return the
     * bound parameters of the webhook_deliveries UPDATE (response_body at
     * index 2), or null when no UPDATE was issued.
     *
     * @return array{0: string, 1: array<mixed>}|null
     */
    private function runProcessDeliveryWithStoredUrl(string $url): ?array
    {
        /** @var array<int, array{0: string, 1: array<mixed>}> $writes */
        $writes = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $bind = []) use (&$writes, $url): array {
                if (str_starts_with($sql, 'SELECT id, event_id, webhook_url')) {
                    return [$this->deliveryRow($url)];
                }
                $writes[] = [$sql, $bind];
                return [];
            });

        $httpClient = $this->createMock(WebhookHttpClient::class);
        $httpClient->expects($this->never())->method('post');

        $service = new WebhookService($db, $httpClient);
        $process = new ReflectionMethod(WebhookService::class, 'processDelivery');
        $process->setAccessible(true);
        $process->invoke($service, 1, 'user.login', [], 42);

        return $this->findDeliveryUpdate($writes);
    }

    /**
     * @param array<int, array{0: string, 1: array<mixed>}> $writes
     * @return array{0: string, 1: array<mixed>}|null
     */
    private function findDeliveryUpdate(array $writes): ?array
    {
        foreach ($writes as $write) {
            if (str_starts_with($write[0], 'UPDATE webhook_deliveries')) {
                return $write;
            }
        }
        return null;
    }
}
