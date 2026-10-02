<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Chromecast;

use PHPUnit\Framework\TestCase;
use Phlix\Chromecast\CastDevice;
use Phlix\Chromecast\CastDiscovery;
use Phlix\Chromecast\CastManager;
use Phlix\Chromecast\CastSession;
use Phlix\Casting\CastingSessionStoreInterface;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Session\PlaybackController;
use Phlix\Tests\Support\Casting\FakeCastingSessionStore;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * Device-M1: the shared-store arms of {@see CastManager}.
 *
 * Chromecast start rides launchApp + loadMedia HTTP calls, so the dead-device
 * fail-closed arm is pinned here directly (the insert-glue shape is proven in
 * the PlayTo/AirPlay siblings); the rebuild-from-row path — the exact device
 * tuple (host, port, uuid) the CastApiClient needs — is pinned for this class.
 */
final class CastManagerSharedStoreTest extends TestCase
{
    private const DEVICE_ID = 'cast-shared-1';
    private const HOST = '192.168.253.1';

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
                'name' => 'Shared Cast',
                'host' => self::HOST,
                'port' => 8009,
                'model' => 'Chromecast Gen 3',
                'uuid' => 'cast-uuid-abc',
            ],
            'media_url' => 'http://media.example/x.mp4',
        ];
    }

    private function seed(string $sessionId, string $userId, array $state): void
    {
        $this->store->seed($sessionId, CastingSessionStoreInterface::TYPE_CAST, self::DEVICE_ID, $userId, $state);
    }

    private function manager(): CastManager
    {
        $device = new CastDevice(self::DEVICE_ID, 'Shared Cast', self::HOST, 8009, 'Chromecast Gen 3', 'cast-uuid-abc');

        $discovery = $this->createMock(CastDiscovery::class);
        $discovery->method('discoverDevices')->willReturn([$device]);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        return new CastManager(
            $discovery,
            new PlaybackController($db, $this->createMock(\Phlix\Session\SessionManager::class)),
            $this->createMock(StructuredLogger::class),
            $this->store,
        );
    }

    public function testStartRefusedWithoutIdentityWhenStoreActive(): void
    {
        $manager = $this->manager();
        self::assertNull($manager->startSession(self::DEVICE_ID, 'http://media/x.mp4', 'video/mp4', 'T', 0, null));
        self::assertSame([], $this->store->insertCalls);
    }

    public function testStartOnUnreachableDeviceRegistersNothing(): void
    {
        $manager = $this->manager();

        self::assertNull($manager->startSession(self::DEVICE_ID, 'http://media/x.mp4', 'video/mp4', 'T', 0, 'user-a'));
        self::assertSame([], $this->store->insertCalls);
        self::assertSame([], $manager->getActiveSessions());
    }

    public function testReattachFromRowOnOtherWorker(): void
    {
        $this->seed('sess-cast-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $session = $manager->getSession(self::DEVICE_ID, 'user-a');

        self::assertInstanceOf(CastSession::class, $session);
        self::assertSame('sess-cast-1', $session->getSessionId());
        self::assertSame(self::HOST, $session->getDevice()->host);
        self::assertSame(8009, $session->getDevice()->port);
        self::assertSame('cast-uuid-abc', $session->getDevice()->uuid);
        self::assertCount(1, $manager->getActiveSessions());

        $this->store->findCalls = [];
        self::assertNotNull($manager->getSession(self::DEVICE_ID, 'user-a'));
        self::assertSame([], $this->store->findCalls, 'hot cache after re-attach');
    }

    public function testReattachRefusedForForeignOwner(): void
    {
        $this->seed('sess-cast-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        self::assertNull($manager->getSession(self::DEVICE_ID, 'user-b'));
        self::assertArrayHasKey('sess-cast-1', $this->store->rows);
    }

    public function testCorruptRowPurgedOnReattach(): void
    {
        $this->seed('sess-cast-bad', 'user-a', ['device' => 'not-an-array']);

        $manager = $this->manager();
        self::assertNull($manager->getSession(self::DEVICE_ID, 'user-a'));
        self::assertSame(['sess-cast-bad'], $this->store->deleteCalls);
    }

    public function testCrossWorkerStopDeletesRow(): void
    {
        $this->seed('sess-cast-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $manager->stopSession(self::DEVICE_ID, 'user-a');

        self::assertSame(['sess-cast-1'], $this->store->deleteCalls);
        self::assertSame([], $this->store->rows);
    }

    public function testCrossWorkerStopRefusedForForeignOwner(): void
    {
        $this->seed('sess-cast-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $manager->stopSession(self::DEVICE_ID, 'user-b');

        self::assertSame([], $this->store->deleteCalls);
        self::assertArrayHasKey('sess-cast-1', $this->store->rows);
    }

    public function testTouchGoneEvictsLocalAndRowReplacementIsServed(): void
    {
        $this->seed('sess-old', 'user-a', self::deviceState());

        $manager = $this->manager();
        $first = $manager->getSession(self::DEVICE_ID, 'user-a');
        self::assertSame('sess-old', $first->getSessionId());

        // Same-user replacement elsewhere: the unique register carries a new id.
        $this->store->insert(
            'sess-new',
            CastingSessionStoreInterface::TYPE_CAST,
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
        $this->seed('sess-cast-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $session = $manager->getSession(self::DEVICE_ID, 'user-a');
        self::assertInstanceOf(CastSession::class, $session);

        $poll = new ReflectionMethod($session, 'pollMediaStatusOnce');
        $poll->setAccessible(true);

        // Dead device → getMediaStatus() answers [] → strike (pre-Device-M1 the
        // Cast poll could never die at all).
        $poll->invoke($session);
        $poll->invoke($session);
        self::assertCount(1, $manager->getActiveSessions(), 'two strikes are survivable');
        $poll->invoke($session);

        self::assertSame([], $manager->getActiveSessions(), 'the orphan evicted itself');
        self::assertSame(['sess-cast-1'], $this->store->deleteCalls);
    }
}
