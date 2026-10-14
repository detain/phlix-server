<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Server\WebSocket\SyncPlay\JoinPasswordThrottle;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * R17-04: sliding-window arithmetic of {@see JoinPasswordThrottle} plus the
 * production trip wiring in SyncPlayManager::handleGroupJoin().
 *
 * The manager-level cases drive the REAL wire handlers; every join inside a
 * burst uses a distinct connection id except the ones intentionally sharing
 * a throttle window.
 */
final class JoinPasswordThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        ConnectionPool::getInstance()->clear();
    }

    public function testCeilingIsTenPerMinuteByDefault(): void
    {
        $this->assertSame(10, JoinPasswordThrottle::MAX_FAILED_ATTEMPTS_PER_WINDOW);
        $this->assertSame(60, JoinPasswordThrottle::WINDOW_SECONDS);
    }

    public function testTripsOnlyAfterTheCeilingIsExceededWithinTheWindow(): void
    {
        $throttle = new JoinPasswordThrottle();
        $now = 1_000_000;

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertFalse(
                $throttle->recordFailure('conn-1', $now + $attempt),
                "attempt $attempt of 10 must stay allowed"
            );
        }

        $this->assertTrue(
            $throttle->recordFailure('conn-1', $now + 11),
            'the 11th failure inside the window must trip'
        );
    }

    public function testOldFailuresSlideOutOfTheWindow(): void
    {
        $throttle = new JoinPasswordThrottle();
        $start = 2_000_000;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $throttle->recordFailure('conn-2', $start + $attempt);
        }

        // 61s later the original burst is outside the 60s window: fresh
        // attempts get the full ceiling again.
        $later = $start + 61;
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertFalse(
                $throttle->recordFailure('conn-2', $later + $attempt),
                "post-window attempt $attempt must be judged against the slid window only"
            );
        }
        $this->assertTrue($throttle->recordFailure('conn-2', $later + 11));
    }

    public function testWindowsArePerConnection(): void
    {
        $throttle = new JoinPasswordThrottle();
        $now = 3_000_000;

        for ($attempt = 0; $attempt < 11; $attempt++) {
            $throttle->recordFailure('noisy', $now);
        }

        $this->assertFalse($throttle->recordFailure('quiet', $now), 'one socket must not exhaust another');
    }

    public function testResetClearsTheWindow(): void
    {
        $throttle = new JoinPasswordThrottle();
        $now = 4_000_000;

        for ($attempt = 0; $attempt < 11; $attempt++) {
            $this->assertTrue(
                $throttle->recordFailure('conn-3', $now) === ($attempt === 10),
                'exactly the 11th failure trips'
            );
        }

        $throttle->reset('conn-3');
        $this->assertFalse($throttle->recordFailure('conn-3', $now), 'reset must restore the full ceiling');
    }

    public function testConstructorRefusesDegenerateLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JoinPasswordThrottle(0);
    }

    // ------------------------------------------------------------------
    // Production wiring: handleGroupJoin reject-closes on trip and the
    // success arm resets the window.
    // ------------------------------------------------------------------

    public function testRepeatedWrongPasswordsRejectCloseTheSocket(): void
    {
        $wire = $this->wireManager();
        $groupId = $this->createLockedRoom($wire, 'throttle-host', 'user-throttle-host', 'Throttle Room');

        $guest = $this->authedConnection('throttle-guest', 'user-throttle-guest');
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->tryWrongPassword($wire, $guest, $groupId, 'wrong-' . $attempt);
            $this->assertFalse($guest->wasClosed(), "attempt $attempt of 10 must not close the socket");
        }

        // 11th wrong digest: told why, then reject-closed.
        $this->tryWrongPassword($wire, $guest, $groupId, 'wrong-11');

        $this->assertTrue($guest->wasClosed(), 'the 11th failure inside the window must close the socket');
        $frames = $guest->getSentMessages();
        $last = $frames[count($frames) - 1];
        $this->assertSame(Messages::TYPE_ERROR, $last['type'] ?? null);
        $this->assertSame('syncplay.join_failed', $last['error_code'] ?? null);
        $this->assertStringContainsString('Too many incorrect password attempts', (string) ($last['message'] ?? ''));

        // Close vacates the throttle bookkeeping (bounded map) — must not throw.
        $wire->onConnectionClose('throttle-guest');
        $this->assertFalse($wire->getGroup($groupId) === null);
    }

    public function testSuccessfulJoinResetsTheFailureWindow(): void
    {
        $wire = $this->wireManager();
        $hash = hash('sha256', 'hunter2');
        $groupId = $this->createLockedRoom($wire, 'reset-host', 'user-reset-host', 'Reset Room', $hash);

        $guest = $this->authedConnection('reset-guest', 'user-reset-guest');
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $this->tryWrongPassword($wire, $guest, $groupId, 'wrong-' . $attempt);
        }

        // Admitted join clears the window.
        $wire->publicHandleMessage($guest, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'password_hash' => $hash,
        ]);
        $this->assertCount(1, $guest->framesOfType(Messages::TYPE_GROUP_STATE));

        // Leave, then a fresh burst gets the FULL ceiling again (no carryover).
        $wire->publicHandleMessage($guest, ['type' => Messages::TYPE_GROUP_LEAVE]);
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->tryWrongPassword($wire, $guest, $groupId, 'wrong-again-' . $attempt);
            $this->assertFalse($guest->wasClosed(), "post-reset attempt $attempt of 10 must stay allowed");
        }
    }

    private function wireManager(): TestableSyncPlayManager
    {
        return new TestableSyncPlayManager(new MessageHandler(ConnectionPool::getInstance()));
    }

    /**
     * Create a password-protected room through the real wire handler.
     *
     * @param string|null $hash digest to gate the room; defaults to sha256('hunter2')
     */
    private function createLockedRoom(
        TestableSyncPlayManager $wire,
        string $hostId,
        string $hostUserId,
        string $roomName,
        ?string $hash = null
    ): string {
        $hash ??= hash('sha256', 'hunter2');
        $host = $this->authedConnection($hostId, $hostUserId);
        $wire->publicHandleMessage($host, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => $roomName,
            'password_hash' => $hash,
        ]);
        $stateFrames = $host->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertCount(1, $stateFrames, 'spec-shape create with a valid digest must succeed');
        $groupId = $stateFrames[0]['group']['group_id'];
        $this->assertIsString($groupId);

        return $groupId;
    }

    private function tryWrongPassword(
        TestableSyncPlayManager $wire,
        TestConnection $guest,
        string $groupId,
        string $candidate
    ): void {
        $wire->publicHandleMessage($guest, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'password_hash' => hash('sha256', $candidate),
        ]);
    }

    private function authedConnection(string $id, string $userId): TestConnection
    {
        $connection = new TestConnection($id);
        $connection->setAuthenticated(true, $userId);

        return $connection;
    }
}
