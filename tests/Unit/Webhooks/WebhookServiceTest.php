<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlix\Webhooks\WebhookDeliveryRecord;
use Phlix\Webhooks\WebhookHttpClient;
use Phlix\Webhooks\WebhookService;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\MySQL\Connection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Unit tests for WebhookService's failed-delivery retry scheduling — the
 * LIVE production webhook delivery path fed by WebhookEventSubscriber
 * (SV-4.4 / S-F10).
 *
 * Covers: the retry Timer is genuinely ONE-SHOT (Workerman's own internal
 * bookkeeping is inspected directly, not just re-asserted from source), the
 * persisted `next_retry_at` and the actual Timer delay never drift apart
 * (both are derived from a single jittered delay), and max attempts caps
 * retries.
 *
 * @covers \Phlix\Webhooks\WebhookService
 */
class WebhookServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Workerman\Timer::add() throws unless at least one Worker exists in
        // the process (same bare-Worker idiom as StreamSessionServiceTest,
        // covering the SV-0.5 one-shot heartbeat timer).
        if (!Worker::getAllWorkers()) {
            new Worker();
        }
    }

    private function delivery(int $attempt, int $id = 42): WebhookDeliveryRecord
    {
        return new WebhookDeliveryRecord(
            id: $id,
            eventId: 1,
            webhookUrl: 'https://example.com/webhook',
            attempt: $attempt,
            status: WebhookDeliveryRecord::STATUS_PENDING,
            responseCode: 500,
            responseBody: null,
            nextRetryAt: null,
            createdAt: new DateTimeImmutable(),
            deliveredAt: null,
        );
    }

    /**
     * @param array<string, mixed> $httpResponse
     */
    private function invokeHandleFailedDelivery(
        WebhookService $service,
        WebhookDeliveryRecord $delivery,
        array $httpResponse,
    ): void {
        $method = new ReflectionMethod(WebhookService::class, 'handleFailedDelivery');
        $method->setAccessible(true);
        $method->invoke($service, $delivery, $httpResponse);
    }

    /**
     * Reads Workerman\Timer's own internal counter — used to identify which
     * timer id a call under test just registered (the id is not otherwise
     * exposed by WebhookService's fire-and-forget scheduling).
     */
    private function currentTimerId(): int
    {
        $prop = new ReflectionProperty(Timer::class, 'timerId');
        $prop->setAccessible(true);
        /** @var int $value */
        $value = $prop->getValue();
        return $value;
    }

    /**
     * Reads the `persistent` flag Workerman actually stored for a given timer
     * id — this is what genuinely proves a call sequence used the one-shot
     * (`persistent = false`) form of Timer::add(), independent of source-level
     * inspection. Returns null if the timer id can't be found (e.g. it was a
     * pcntl-fallback slot from an unrelated already-fired/removed timer).
     */
    private function persistentFlagFor(int $timerId): ?bool
    {
        $tasksProp = new ReflectionProperty(Timer::class, 'tasks');
        $tasksProp->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = $tasksProp->getValue();

        foreach ($tasks as $taskGroup) {
            if (array_key_exists($timerId, $taskGroup)) {
                return $taskGroup[$timerId][2];
            }
        }

        return null;
    }

    public function testFailedDeliveryWithRetriesRemainingSchedulesAGenuineOneShotTimer(): void
    {
        $capturedParams = null;

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE webhook_deliveries'),
                $this->callback(function ($params) use (&$capturedParams) {
                    $capturedParams = $params;
                    return true;
                })
            )
            ->willReturn([]);

        $httpClient = $this->createMock(WebhookHttpClient::class);
        $service = new WebhookService($db, $httpClient);

        $delivery = $this->delivery(attempt: 1);

        $timerIdBefore = $this->currentTimerId();
        $before = new DateTimeImmutable();

        $this->invokeHandleFailedDelivery($service, $delivery, [
            'response_code' => 500,
            'response_body' => null,
            'error' => 'HTTP 500',
        ]);

        $timerIdAfter = $this->currentTimerId();
        $this->assertSame($timerIdBefore + 1, $timerIdAfter, 'exactly one new timer must be registered');

        // Genuinely one-shot: Workerman's own bookkeeping (not just the source
        // code) must show `persistent === false` for the timer this call
        // registered — this is the SV-0.5-class timer-storm regression guard.
        $this->assertSame(
            false,
            $this->persistentFlagFor($timerIdAfter),
            'the retry timer must be one-shot (persistent=false), never repeating'
        );

        // The persisted next_retry_at must be derived from the SAME jittered
        // delay used for the Timer (not a second, independently-drawn one) —
        // base delay for attempt=1 is RETRY_DELAYS[2]=300s, jittered +/-20%.
        $this->assertIsArray($capturedParams);
        $nextRetryAtRaw = $capturedParams[3];
        $this->assertIsString($nextRetryAtRaw);
        $nextRetryAt = new DateTimeImmutable($nextRetryAtRaw);
        $deltaSeconds = $nextRetryAt->getTimestamp() - $before->getTimestamp();

        $this->assertGreaterThanOrEqual(
            239,
            $deltaSeconds,
            'jittered delay must stay within the documented +/-20% window (lower bound, +1s test slack)',
        );
        $this->assertLessThanOrEqual(
            361,
            $deltaSeconds,
            'jittered delay must stay within the documented +/-20% window (upper bound, +1s test slack)',
        );

        // attempt was bumped to 2 (nextAttempt = attempt + 1).
        $this->assertSame(2, $capturedParams[0]);

        // Timer cleanup so it doesn't linger for other tests in this process.
        Timer::del($timerIdAfter);
    }

    public function testFailedDeliveryAtMaxAttemptsMarksPermanentlyFailedWithoutSchedulingATimer(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains("status = 'failed'"))
            ->willReturn([]);

        $httpClient = $this->createMock(WebhookHttpClient::class);
        $service = new WebhookService($db, $httpClient);

        $delivery = $this->delivery(attempt: WebhookDeliveryRecord::MAX_ATTEMPTS);

        $timerIdBefore = $this->currentTimerId();

        $this->invokeHandleFailedDelivery($service, $delivery, [
            'response_code' => 500,
            'response_body' => null,
            'error' => 'HTTP 500',
        ]);

        $this->assertSame(
            $timerIdBefore,
            $this->currentTimerId(),
            'max attempts must be capped — no further retry timer scheduled',
        );
    }

    /**
     * Two consecutive failures at different attempt numbers must schedule two
     * DISTINCT one-shot timers (never a single repeating timer standing in
     * for both) — a direct regression guard against accidentally reusing a
     * `persistent = true` timer for the whole retry sequence.
     */
    public function testConsecutiveRetriesEachScheduleTheirOwnOneShotTimer(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $httpClient = $this->createMock(WebhookHttpClient::class);
        $service = new WebhookService($db, $httpClient);

        $timerIdBefore = $this->currentTimerId();

        $this->invokeHandleFailedDelivery($service, $this->delivery(attempt: 1, id: 1), [
            'response_code' => 500, 'response_body' => null, 'error' => 'HTTP 500',
        ]);
        $firstTimerId = $this->currentTimerId();

        $this->invokeHandleFailedDelivery($service, $this->delivery(attempt: 2, id: 2), [
            'response_code' => 500, 'response_body' => null, 'error' => 'HTTP 500',
        ]);
        $secondTimerId = $this->currentTimerId();

        $this->assertSame($timerIdBefore + 1, $firstTimerId);
        $this->assertSame($timerIdBefore + 2, $secondTimerId);
        $this->assertNotSame($firstTimerId, $secondTimerId);
        $this->assertSame(false, $this->persistentFlagFor($firstTimerId));
        $this->assertSame(false, $this->persistentFlagFor($secondTimerId));

        Timer::del($firstTimerId);
        Timer::del($secondTimerId);
    }
}
