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

    /**
     * 🔴 S131 — `'0'` is what a SUCCESSFUL write returns here, and it is FALSY.
     *
     * `updateStreamLimit()` issues an `INSERT … ON DUPLICATE KEY UPDATE` into
     * `profile_stream_limits`, whose primary key is `profile_id CHAR(36)` with
     * no `AUTO_INCREMENT` (`migrations/063_device_stream_limits.sql:6-11`). So
     * `PDO::lastInsertId()` — which is what
     * `Workerman\MySQL\Connection::query()` returns for an INSERT that affected
     * rows — answers the string `'0'`.
     *
     * `'0'` is falsy in PHP. Rewriting this method as `return (bool) $result;`
     * or `return !WriteResult::wroteNothing($result);` reports every real
     * update as a failure. This test is the tripwire on that.
     *
     * @return void
     */
    public function testUpdateStreamLimitTreatsTheFalsyStringZeroAsSuccess(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn('0');

        $service = new StreamSessionService($db);

        $this->assertTrue(
            $service->updateStreamLimit('profile-1', 3, 8000),
            "'0' is lastInsertId() on a CHAR(36)-PK table — a SUCCESSFUL write. "
            . 'A falsy or wroteNothing() test here reports every real update as a failure.'
        );
    }

    /**
     * ⚠ S131 — `null` is ALSO success here, and this is the one insert-result
     * site in the repo where the shared strict helper is the WRONG predicate.
     *
     * Measured against real MySQL 8 (S96 review r3): an
     * `INSERT … ON DUPLICATE KEY UPDATE` whose values are already current
     * affects **0 rows**, and the client answers `null`. That means "the row
     * already says exactly what you asked for" — success, not failure.
     *
     * So `return !WriteResult::wroteNothing($result);` — which reads like the
     * obvious completion of S131 — is a REGRESSION: it makes an idempotent
     * PUT to `/api/v1/profiles/{id}/stream-limits` report a failed update.
     * This test exists to redden that, not to bless it.
     *
     * @return void
     */
    public function testUpdateStreamLimitTreatsANoOpUpsertAsSuccessNotFailure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $service = new StreamSessionService($db);

        $this->assertTrue(
            $service->updateStreamLimit('profile-1', 3, 8000),
            'an ON DUPLICATE KEY UPDATE whose values are already current returns null '
            . '(measured, real MySQL 8) — the limit IS what was asked for, so this is success'
        );
    }

    /**
     * S131 — `cleanupStaleStreams()` is a DELETE, and its `@return int` used to
     * be a lie.
     *
     * The client returns `rowCount()` for a `delete` — an int — so the method
     * must report that count. It used to read `return $result !== false ? 1 : 0;`,
     * which reported `1` for every sweep no matter how many rows it removed,
     * and could never report `0` (the client has no `return false`).
     *
     * @return void
     */
    public function testCleanupStaleStreamsReportsTheRowCountItActuallyDeleted(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(7);

        $service = new StreamSessionService($db);

        $this->assertSame(
            7,
            $service->cleanupStaleStreams(),
            'a DELETE returns rowCount(); the documented "number of stale streams removed" '
            . 'must be that number, not a constant 1'
        );
    }

    /**
     * S131 — and a sweep that removed nothing must report `0`, not `1`.
     *
     * @return void
     */
    public function testCleanupStaleStreamsReportsZeroWhenNothingWasStale(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(0);

        $service = new StreamSessionService($db);

        $this->assertSame(0, $service->cleanupStaleStreams());
    }

    /**
     * S131 — a non-int result (the `null` shape from
     * {@see \Phlix\Common\Database\WriteResult} trap 3, where a reformat hides
     * the leading `DELETE` keyword from the client's keyword match) degrades to
     * `0` rather than crashing on a bad cast.
     *
     * @return void
     */
    public function testCleanupStaleStreamsDegradesToZeroWhenTheResultIsNotACount(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $service = new StreamSessionService($db);

        $this->assertSame(0, $service->cleanupStaleStreams());
    }
}
