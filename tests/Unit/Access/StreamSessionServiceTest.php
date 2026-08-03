<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Phlix\Access\StreamSessionService;
use Workerman\MySQL\Connection;
use Workerman\Worker;

/**
 * Unit tests for the per-session stream heartbeat timer accounting (SV-0.5).
 *
 * Verifies the heartbeat timer is one-shot, keyed + deduped per session, torn
 * down on stream release, and does not accumulate across many start/stop cycles.
 */
class StreamSessionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Workerman\Timer::add() throws unless at least one Worker exists in the
        // process. Construct a bare (non-listening) worker so the timer subsystem
        // is usable under PHPUnit's SIGALRM-scheduler path.
        if (!Worker::getAllWorkers()) {
            new Worker();
        }
    }

    /**
     * Build a service with a stubbed DB connection.
     */
    private function service(): StreamSessionService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        return new StreamSessionService($db);
    }

    public function testHeartbeatTimerIsKeyedDedupedAndSelfClearing(): void
    {
        $service = $this->service();
        $this->assertSame(0, $service->activeHeartbeatTimerCount());

        $service->registerHeartbeatTimer('sess-a');
        $this->assertSame(1, $service->activeHeartbeatTimerCount());

        // Dedup: a second request for the same session must NOT add a second timer.
        $service->registerHeartbeatTimer('sess-a');
        $this->assertSame(1, $service->activeHeartbeatTimerCount());

        $service->registerHeartbeatTimer('sess-b');
        $this->assertSame(2, $service->activeHeartbeatTimerCount());

        // One-shot: firing clears the session's own slot (so it can be re-armed),
        // leaving the other session's timer intact.
        $service->onHeartbeatTimerFired('sess-a');
        $this->assertSame(1, $service->activeHeartbeatTimerCount());

        // Refresh: the session can be re-armed after its one-shot timer fired.
        $service->registerHeartbeatTimer('sess-a');
        $this->assertSame(2, $service->activeHeartbeatTimerCount());

        // Teardown: releasing a stream cancels its heartbeat timer.
        $service->releaseStream('sess-a');
        $service->releaseStream('sess-b');
        $this->assertSame(0, $service->activeHeartbeatTimerCount());
    }

    public function testTimerFiringRefreshesHeartbeat(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('UPDATE active_streams'), ['sess-x'])
            ->willReturn([]);

        $service = new StreamSessionService($db);
        $service->registerHeartbeatTimer('sess-x');
        $this->assertSame(1, $service->activeHeartbeatTimerCount());

        // The one-shot callback refreshes last_heartbeat_at and clears its slot.
        $service->onHeartbeatTimerFired('sess-x');
        $this->assertSame(0, $service->activeHeartbeatTimerCount());
    }

    public function testNoTimerLeakAcrossManyStreamStartStopCycles(): void
    {
        $service = $this->service();
        $baseline = $service->activeHeartbeatTimerCount();

        $sessionCount = 100;

        for ($i = 0; $i < $sessionCount; $i++) {
            $sessionId = 'sess-' . $i;
            // Simulate many requests per session (e.g. every HLS segment fetch).
            // Dedup must keep this to a single timer per session, not one per call.
            $service->registerHeartbeatTimer($sessionId);
            $service->registerHeartbeatTimer($sessionId);
            $service->registerHeartbeatTimer($sessionId);
        }

        // One timer per session despite three registrations each.
        $this->assertSame($baseline + $sessionCount, $service->activeHeartbeatTimerCount());

        for ($i = 0; $i < $sessionCount; $i++) {
            $service->releaseStream('sess-' . $i);
        }

        // Active-timer count returns to baseline: no per-request accumulation.
        $this->assertSame($baseline, $service->activeHeartbeatTimerCount());
    }
}
