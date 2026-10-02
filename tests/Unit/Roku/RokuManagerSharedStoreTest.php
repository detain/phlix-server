<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlix\Casting\CastingSessionStoreInterface;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Roku\RokuDevice;
use Phlix\Roku\RokuDiscovery;
use Phlix\Roku\RokuManager;
use Phlix\Roku\RokuSession;
use Phlix\Session\PlaybackController;
use Phlix\Tests\Support\Casting\FakeCastingSessionStore;
use ReflectionMethod;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Device-M1: the shared-store arms of {@see RokuManager}.
 *
 * A Roku start rides the ECP launch POST, so a dead (unroutable LAN) device
 * fails the start before any register write — that fail-closed arm is pinned
 * here directly; the insert-glue shape itself is proven per-class in the
 * PlayTo/AirPlay siblings and the rebuild-from-row path is proven here for the
 * ECP device tuple.
 */
final class RokuManagerSharedStoreTest extends TestCase
{
    private const DEVICE_ID = 'roku-shared-1';
    private const HOST = '192.168.253.1';
    private const MEDIA_URL = 'http://media/x.m3u8';

    private FakeCastingSessionStore $store;

    protected function setUp(): void
    {
        $this->store = new FakeCastingSessionStore();
    }

    /** @return array<string, mixed> */
    private static function deviceState(): array
    {
        return [
            'device' => [
                'deviceId' => self::DEVICE_ID,
                'name' => 'Shared Roku',
                'host' => self::HOST,
                'port' => 8060,
                'model' => 'Roku Express',
                'softwareVersion' => '14.0',
            ],
            'media_url' => self::MEDIA_URL,
        ];
    }

    private function seed(string $sessionId, string $userId, array $state): void
    {
        $this->store->seed($sessionId, CastingSessionStoreInterface::TYPE_ROKU, self::DEVICE_ID, $userId, $state);
    }

    private function manager(): RokuManager
    {
        $device = new RokuDevice(self::DEVICE_ID, 'Shared Roku', self::HOST, 8060, 'Roku Express', '14.0');

        $discovery = $this->createMock(RokuDiscovery::class);
        $discovery->method('discoverDevices')->willReturn([$device]);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        return new RokuManager(
            $discovery,
            new PlaybackController($db, $this->createMock(\Phlix\Session\SessionManager::class)),
            $this->createMock(StructuredLogger::class),
            $this->store,
        );
    }

    public function testStartRefusedWithoutIdentityWhenStoreActive(): void
    {
        $manager = $this->manager();
        self::assertNull($manager->startSession(self::DEVICE_ID, 'http://media/x.m3u8', 'video/mp4', 'T', '', null));
        self::assertSame([], $this->store->insertCalls);
    }

    public function testStartOnUnreachableDeviceRegistersNothing(): void
    {
        $manager = $this->manager();

        self::assertNull($manager->startSession(self::DEVICE_ID, self::MEDIA_URL, 'video/mp4', 'T', '', 'user-a'));
        self::assertSame([], $this->store->insertCalls, 'no row for a session the device never accepted');
        self::assertSame([], $manager->getActiveSessions());
    }

    public function testReattachFromRowOnOtherWorker(): void
    {
        $this->seed('sess-roku-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $session = $manager->getSession(self::DEVICE_ID, 'user-a');

        self::assertInstanceOf(RokuSession::class, $session);
        self::assertSame('sess-roku-1', $session->getSessionId());
        self::assertSame(self::HOST, $session->getDevice()->host);
        self::assertSame(8060, $session->getDevice()->port);
        self::assertCount(1, $manager->getActiveSessions());

        $this->store->findCalls = [];
        self::assertNotNull($manager->getSession(self::DEVICE_ID, 'user-a'));
        self::assertSame([], $this->store->findCalls, 'hot cache after re-attach');
    }

    public function testReattachRefusedForForeignOwner(): void
    {
        $this->seed('sess-roku-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        self::assertNull($manager->getSession(self::DEVICE_ID, 'user-b'));
        self::assertArrayHasKey('sess-roku-1', $this->store->rows);
    }

    public function testCorruptRowPurgedOnReattach(): void
    {
        $this->seed('sess-roku-bad', 'user-a', ['bogus' => 1]);

        $manager = $this->manager();
        self::assertNull($manager->getSession(self::DEVICE_ID, 'user-a'));
        self::assertSame(['sess-roku-bad'], $this->store->deleteCalls);
    }

    public function testCrossWorkerStopDeletesRow(): void
    {
        $this->seed('sess-roku-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $manager->stopSession(self::DEVICE_ID, 'user-a');

        self::assertSame(['sess-roku-1'], $this->store->deleteCalls);
        self::assertSame([], $this->store->rows);
        self::assertSame([], $manager->getActiveSessions());
    }

    public function testCrossWorkerStopRefusedForForeignOwner(): void
    {
        $this->seed('sess-roku-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $manager->stopSession(self::DEVICE_ID, 'user-b');

        self::assertSame([], $this->store->deleteCalls);
        self::assertArrayHasKey('sess-roku-1', $this->store->rows);
    }

    public function testTouchGoneEvictsLocalAndRowReplacementIsServed(): void
    {
        $this->seed('sess-old', 'user-a', self::deviceState());

        $manager = $this->manager();
        $first = $manager->getSession(self::DEVICE_ID, 'user-a');
        self::assertSame('sess-old', $first->getSessionId());

        // Same-user replacement elsewhere: unique register now carries a new id.
        $this->store->insert(
            'sess-new',
            CastingSessionStoreInterface::TYPE_ROKU,
            self::DEVICE_ID,
            'user-a',
            self::deviceState(),
        );

        $served = $manager->getSession(self::DEVICE_ID, 'user-a');
        self::assertSame('sess-new', $served->getSessionId());
        self::assertSame(['sess-old'], $this->store->deleteCalls);
    }

    public function testThreeFailedPollsSelfEvictAndDeleteRow(): void
    {
        $this->seed('sess-roku-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $session = $manager->getSession(self::DEVICE_ID, 'user-a');
        self::assertInstanceOf(RokuSession::class, $session);

        $poll = new ReflectionMethod($session, 'pollPlayerStateOnce');
        $poll->setAccessible(true);

        // The unroutable ECP endpoint makes getPlayerState() answer [] — the
        // strike signal. Device-M1 added the counter: 3 strikes must tear the
        // orphan down instead of polling forever (pre-Device-M1 behavior).
        $poll->invoke($session);
        $poll->invoke($session);
        self::assertCount(1, $manager->getActiveSessions(), 'two strikes are survivable');
        $poll->invoke($session);

        self::assertSame([], $manager->getActiveSessions(), 'the orphan evicted itself');
        self::assertSame(['sess-roku-1'], $this->store->deleteCalls);
        self::assertSame([], $this->store->rows);
    }

    public function testTouchBlipKeepsServingLocalSession(): void
    {
        $this->seed('sess-roku-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        self::assertNotNull($manager->getSession(self::DEVICE_ID, 'user-a'));

        $this->store->failTouchWith = new RuntimeException('db gone');
        $msg = 'a DB blip never breaks a verified local session';
        self::assertNotNull($manager->getSession(self::DEVICE_ID, 'user-a'), $msg);
    }
}
