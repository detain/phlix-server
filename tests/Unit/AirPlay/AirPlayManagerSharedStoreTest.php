<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\AirPlay;

use PHPUnit\Framework\TestCase;
use Phlix\AirPlay\AirPlayDevice;
use Phlix\AirPlay\AirPlayDiscovery;
use Phlix\AirPlay\AirPlayManager;
use Phlix\AirPlay\AirPlaySession;
use Phlix\Casting\CastingSessionStoreInterface;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Tests\Support\Casting\FakeCastingSessionStore;
use RuntimeException;

/**
 * Device-M1: the shared-store arms of {@see AirPlayManager}.
 *
 * AirPlay is the class the audit flagged as possibly socket-bound — it is not:
 * `RaopClient::sendRaw()` opens a fresh socket per command and the session's
 * ANNOUNCE/RECORD path is command-simulated, so the full start → fleet-row →
 * re-attach cycle runs here with zero device I/O and the row contract is
 * proven end to end (state payload shape included).
 */
final class AirPlayManagerSharedStoreTest extends TestCase
{
    private const DEVICE_ID = 'airplay-shared-1';
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
                'name' => 'Shared Speaker',
                'host' => self::HOST,
                'port' => 7000,
                'raopPort' => 6101,
                'model' => 'HomePod mini',
                'supportsVideo' => false,
            ],
            'media_url' => 'http://media.example/x.mp4',
            'content_type' => 'audio/mp4',
        ];
    }

    private function seed(string $sessionId, string $userId, array $state): void
    {
        $this->store->seed($sessionId, CastingSessionStoreInterface::TYPE_AIRPLAY, self::DEVICE_ID, $userId, $state);
    }

    private function manager(): AirPlayManager
    {
        $device = new AirPlayDevice(
            self::DEVICE_ID,
            'Shared Speaker',
            self::HOST,
            7000,
            6101,
            'HomePod mini',
            false,
        );

        $discovery = $this->createMock(AirPlayDiscovery::class);
        $discovery->method('discoverDevices')->willReturn([$device]);

        return new AirPlayManager(
            $discovery,
            $this->createMock(StructuredLogger::class),
            $this->store,
        );
    }

    public function testStartRefusedWithoutIdentityWhenStoreActive(): void
    {
        self::assertNull($this->manager()->startSession(self::DEVICE_ID, 'http://media/x.mp4', 'audio/mp4', 0, null));
        self::assertSame([], $this->store->insertCalls);
    }

    public function testStartRegistersFleetRowWithFullDeviceTupleAndStreamContext(): void
    {
        $manager = $this->manager();
        $session = $manager->startSession(self::DEVICE_ID, 'http://media.example/x.mp4', 'audio/mp4', 42, 'user-a');

        self::assertNotNull($session);
        self::assertCount(1, $this->store->insertCalls);

        $call = $this->store->insertCalls[0];
        self::assertSame($session->getSessionId(), $call['sessionId']);
        self::assertSame(CastingSessionStoreInterface::TYPE_AIRPLAY, $call['type']);
        self::assertSame('user-a', $call['userId']);
        self::assertSame(self::deviceState(), $call['state'], 'the row is exactly the re-attach payload');
    }

    public function testStartFailsClosedWhenRowUnwritable(): void
    {
        $this->store->failInsert = true;

        $manager = $this->manager();
        self::assertNull($manager->startSession(self::DEVICE_ID, 'http://media/x.mp4', 'audio/mp4', 0, 'user-a'));
        self::assertSame([], $manager->getActiveSessions(), 'an unregistered stream must not stay worker-visible');
    }

    public function testRecastReplacesRowForSameDevice(): void
    {
        $manager = $this->manager();
        $first = $manager->startSession(self::DEVICE_ID, 'http://media/a.mp4', 'audio/mp4', 0, 'user-a');
        self::assertNotNull($first);

        $second = $manager->startSession(self::DEVICE_ID, 'http://media/b.mp4', 'audio/mp4', 0, 'user-a');
        self::assertNotNull($second);

        self::assertCount(1, $this->store->rows, 'the unique device register keeps exactly one live row');
        $row = array_values($this->store->rows)[0];
        self::assertSame($second->getSessionId(), $row->sessionId);
        self::assertNotSame($first->getSessionId(), $second->getSessionId());
        self::assertNotEmpty($this->store->deleteByDeviceCalls, 'the cross-worker displacement runs on every start');
    }

    public function testLocalSessionServesOwnerAndRefusesStranger(): void
    {
        $manager = $this->manager();
        self::assertNotNull($manager->startSession(self::DEVICE_ID, 'http://media/x.mp4', 'audio/mp4', 0, 'user-a'));

        self::assertNotNull($manager->getSession(self::DEVICE_ID, 'user-a'));
        self::assertNull($manager->getSession(self::DEVICE_ID, 'user-b'));
    }

    public function testReattachFromRowOnOtherWorkerRestoresStreamContext(): void
    {
        $first = $this->manager();
        $started = $first->startSession(self::DEVICE_ID, 'http://media.example/x.mp4', 'audio/mp4', 0, 'user-a');
        self::assertNotNull($started);

        // "Worker B" — fresh manager, same register, empty local map.
        $second = $this->manager();
        $session = $second->getSession(self::DEVICE_ID, 'user-a');

        self::assertInstanceOf(AirPlaySession::class, $session);
        self::assertSame($started->getSessionId(), $session->getSessionId());
        self::assertSame(self::HOST, $session->getDevice()->host);
        self::assertSame(6101, $session->getDevice()->raopPort);
        self::assertCount(1, $second->getActiveSessions());

        $second->getSession(self::DEVICE_ID, 'user-a');
        self::assertCount(1, $this->store->findCalls, 'one register read, then hot cache');
    }

    public function testReattachRefusedForForeignOwnerAndRowSurvives(): void
    {
        $this->seed('sess-ap-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        self::assertNull($manager->getSession(self::DEVICE_ID, 'user-b'));
        self::assertArrayHasKey('sess-ap-1', $this->store->rows);
    }

    public function testCrossWorkerStopDeletesRow(): void
    {
        $this->seed('sess-ap-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $manager->stopSession(self::DEVICE_ID, 'user-a');

        self::assertSame(['sess-ap-1'], $this->store->deleteCalls);
        self::assertSame([], $this->store->rows);
    }

    public function testCrossWorkerStopRefusedForForeignOwner(): void
    {
        $this->seed('sess-ap-1', 'user-a', self::deviceState());

        $manager = $this->manager();
        $manager->stopSession(self::DEVICE_ID, 'user-b');

        self::assertSame([], $this->store->deleteCalls);
        self::assertArrayHasKey('sess-ap-1', $this->store->rows);
    }

    public function testTouchGoneEvictsLocalAndServesNothing(): void
    {
        $manager = $this->manager();
        $session = $manager->startSession(self::DEVICE_ID, 'http://media/x.mp4', 'audio/mp4', 0, 'user-a');
        self::assertNotNull($session);

        // Sweep collected the row (24h idle horizon): the local metadata object
        // evicts itself and the control op answers the honest 404.
        $this->store->goneFor = [$session->getSessionId()];

        self::assertNull($manager->getSession(self::DEVICE_ID, 'user-a'));
        self::assertSame([], $manager->getActiveSessions());
    }

    public function testTouchBlipKeepsServingLocalSession(): void
    {
        $manager = $this->manager();
        self::assertNotNull($manager->startSession(self::DEVICE_ID, 'http://media/x.mp4', 'audio/mp4', 0, 'user-a'));

        $this->store->failTouchWith = new RuntimeException('db gone');
        $msg = 'a DB blip never breaks a verified local session';
        self::assertNotNull($manager->getSession(self::DEVICE_ID, 'user-a'), $msg);
    }

    public function testStorelessModeKeepsPreDeviceM1Semantics(): void
    {
        $device = new AirPlayDevice(self::DEVICE_ID, 'Shared Speaker', self::HOST, 7000, 6101, 'HomePod mini', false);
        $discovery = $this->createMock(AirPlayDiscovery::class);
        $discovery->method('discoverDevices')->willReturn([$device]);

        $manager = new AirPlayManager($discovery, $this->createMock(StructuredLogger::class));

        // No store, no identity: anonymous casting still works, exactly as before.
        self::assertNotNull($manager->startSession(self::DEVICE_ID, 'http://media/x.mp4', 'audio/mp4', 0));
        self::assertNotNull($manager->getSession(self::DEVICE_ID));
    }
}
