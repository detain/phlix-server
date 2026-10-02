<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Casting\CastingSessionStoreInterface;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Dlna\PlayToManager;
use Phlix\Dlna\RendererDiscovery;
use Phlix\Session\PlaybackController;
use Phlix\Tests\Support\Casting\FakeCastingSessionStore;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Device-M1: the shared-store arms of {@see PlayToManager} — identity gate,
 * fleet registration, cross-worker re-attach, ownership refusal, REPLACE
 * displacement, and the strike/touch eviction of the worker-local cache.
 *
 * The renderer endpoint is an unroutable TEST-NET-adjacent LAN address: the
 * SOAP transport fails fast (the session's no-throw-on-dead-renderer posture
 * is pre-Device-M1 behavior, pinned by the network-group sibling), so every
 * store interaction below is observable without a live renderer.
 */
final class PlayToManagerSharedStoreTest extends TestCase
{
    private const RENDERER_ID = 'uuid:shared-store-renderer';
    private const AV_URL = 'http://192.168.253.1:8200/AVTransport.xml';

    private FakeCastingSessionStore $store;

    protected function setUp(): void
    {
        $this->store = new FakeCastingSessionStore();
    }

    private function discovery(): RendererDiscovery
    {
        $discovery = $this->createMock(RendererDiscovery::class);
        $discovery->method('discoverRenderers')->willReturn([
            [
                'udn' => self::RENDERER_ID,
                'friendly_name' => 'Shared Store TV',
                'av_transport_url' => self::AV_URL,
            ],
        ]);

        return $discovery;
    }

    private function manager(?RendererDiscovery $discovery = null): PlayToManager
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $playbackController = new PlaybackController($db, $this->createMock(\Phlix\Session\SessionManager::class));

        return new PlayToManager(
            $discovery ?? $this->discovery(),
            $playbackController,
            $this->createMock(StructuredLogger::class),
            $this->store,
        );
    }

    /** @return array{0: PlayToManager, 1: string} started manager + session id */
    private function started(?FakeCastingSessionStore $store = null): array
    {
        $manager = $this->manager();
        $uri = 'http://media.example/x.m3u8';
        $session = $manager->startSession(self::RENDERER_ID, 'item-1', $uri, '<DIDL/>', 'user-a');

        self::assertNotNull($session, 'the dead-renderer start path is pre-Device-M1 tolerant and must still register');

        return [$manager, $session->getSessionId()];
    }

    // -----------------------------------------------------------------
    // Identity gate + fleet registration
    // -----------------------------------------------------------------

    public function testStartRefusedWithoutIdentityWhenStoreActive(): void
    {
        $discovery = $this->createMock(RendererDiscovery::class);
        $discovery->expects($this->never())->method('discoverRenderers');

        $manager = new PlayToManager(
            $discovery,
            new PlaybackController(
                $this->createMock(Connection::class),
                $this->createMock(\Phlix\Session\SessionManager::class)
            ),
            $this->createMock(StructuredLogger::class),
            $this->store,
        );

        self::assertNull($manager->startSession(self::RENDERER_ID, 'item-1', 'http://media/x.m3u8', '', null));
        self::assertSame([], $this->store->insertCalls, 'nothing may reach the register without an owner');
    }

    public function testStartRegistersFleetRowWithOwnerAndReattachState(): void
    {
        [$manager, $sessionId] = $this->started();
        unset($manager);

        self::assertCount(1, $this->store->insertCalls);
        $call = $this->store->insertCalls[0];
        self::assertSame($sessionId, $call['sessionId']);
        self::assertSame(CastingSessionStoreInterface::TYPE_PLAYTO, $call['type']);
        self::assertSame(self::RENDERER_ID, $call['deviceId']);
        self::assertSame('user-a', $call['userId']);
        self::assertSame(
            [
                'av_transport_url' => self::AV_URL,
                'friendly_name' => 'Shared Store TV',
                'media_item_id' => 'item-1',
                'uri' => 'http://media.example/x.m3u8',
            ],
            $call['state'],
            'the row is exactly the re-attach payload — no secrets, full endpoint',
        );
    }

    public function testStartFailsClosedWhenRowUnwritable(): void
    {
        $this->store->failInsert = true;

        $manager = $this->manager();
        self::assertNull($manager->startSession(self::RENDERER_ID, 'item-1', 'http://media/x.m3u8', '', 'user-a'));
        self::assertSame([], $manager->getActiveSessions(), 'an unregistered session must not stay worker-visible');
    }

    // -----------------------------------------------------------------
    // Ownership on the local hot cache
    // -----------------------------------------------------------------

    public function testLocalSessionServesOwnerAndRefusesStrangerWithoutStoreLookup(): void
    {
        [$manager] = $this->started();

        self::assertNotNull($manager->getSession(self::RENDERER_ID, 'user-a'));
        self::assertNull($manager->getSession(self::RENDERER_ID, 'user-b'));
        self::assertSame([], $this->store->findCalls, 'a local owner-mismatch is refused without a round trip');
    }

    // -----------------------------------------------------------------
    // Cross-worker re-attach
    // -----------------------------------------------------------------

    public function testReattachFromRowOnOtherWorker(): void
    {
        [$first] = $this->started();
        $firstSessionId = $first->getSession(self::RENDERER_ID, 'user-a')?->getSessionId();

        // "Worker B": same store, empty local map.
        $second = $this->manager();
        $reattached = $second->getSession(self::RENDERER_ID, 'user-a');

        self::assertNotNull($reattached, 'a fleet row must be controllable from any worker');
        self::assertSame($firstSessionId, $reattached->getSessionId());
        self::assertSame(self::RENDERER_ID, $reattached->getRendererId());
        self::assertSame('Shared Store TV', $reattached->getRendererName());

        $reattached->restoreMediaContext('item-1', 'http://media.example/x.m3u8');
        self::assertCount(1, $second->getActiveSessions(), 'the re-attached object joins the local map');

        $this->store->findCalls = [];
        self::assertNotNull($second->getSession(self::RENDERER_ID, 'user-a'));
        self::assertSame([], $this->store->findCalls, 'the second call is a hot-cache hit');
    }

    public function testReattachRefusedForForeignOwnerAndRowSurvives(): void
    {
        $this->store->seed('sess-a', CastingSessionStoreInterface::TYPE_PLAYTO, self::RENDERER_ID, 'user-a', [
            'av_transport_url' => self::AV_URL,
            'friendly_name' => 'TV',
            'media_item_id' => 'item-1',
            'uri' => 'http://media/x.m3u8',
        ]);

        $manager = $this->manager();
        self::assertNull($manager->getSession(self::RENDERER_ID, 'user-b'));
        self::assertSame([], $manager->getActiveSessions());
        self::assertArrayHasKey('sess-a', $this->store->rows, 'a refused re-attach never evicts the owner row');
        self::assertSame([], $this->store->deleteCalls);
    }

    public function testReattachOfCorruptRowIsPurgedAndRefused(): void
    {
        $this->store->seed('sess-bad', CastingSessionStoreInterface::TYPE_PLAYTO, self::RENDERER_ID, 'user-a', [
            'bogus' => true,
        ]);

        $manager = $this->manager();
        self::assertNull($manager->getSession(self::RENDERER_ID, 'user-a'));
        self::assertSame(['sess-bad'], $this->store->deleteCalls, 'a row no worker can ever rebuild is collected');
        self::assertSame([], $manager->getActiveSessions());
    }

    public function testUnauthenticatedControlNeverReattaches(): void
    {
        [$first] = $this->started();
        unset($first);

        $workerB = $this->manager();
        self::assertNull($workerB->getSession(self::RENDERER_ID, null));
        self::assertSame([], $this->store->findCalls, 'the identity gate precedes the register lookup');
    }

    // -----------------------------------------------------------------
    // Stop paths
    // -----------------------------------------------------------------

    public function testLocalStopDestroysAndDeletesRow(): void
    {
        [$manager, $sessionId] = $this->started();

        $manager->stopSession(self::RENDERER_ID, 'user-a');

        self::assertSame([], $manager->getActiveSessions());
        self::assertSame([$sessionId], $this->store->deleteCalls);
        self::assertSame([], $this->store->rows);
    }

    public function testForeignUserCannotStopLocallyCachedSession(): void
    {
        [$manager, $sessionId] = $this->started();

        $manager->stopSession(self::RENDERER_ID, 'user-b');

        self::assertCount(1, $manager->getActiveSessions(), 'kid B cannot kill kid A\'s cast');
        self::assertSame([], $this->store->deleteCalls);
        self::assertArrayHasKey($sessionId, $this->store->rows);
    }

    public function testCrossWorkerStopDeletesRowWithoutLocalObject(): void
    {
        [$first] = $this->started();
        unset($first);

        $workerB = $this->manager();
        $workerB->stopSession(self::RENDERER_ID, 'user-a');

        self::assertSame([], $workerB->getActiveSessions(), 'cross-worker stop must not publish a session object');
        self::assertSame([], $this->store->rows, 'the fleet row is gone after the stop');

        $workerB->stopSession(self::RENDERER_ID, 'user-b');
        // (second stop: no row at all — silent no-op, already asserted by emptiness)
    }

    public function testCrossWorkerStopRefusedForForeignOwner(): void
    {
        [$first] = $this->started();
        unset($first);

        $workerB = $this->manager();
        $workerB->stopSession(self::RENDERER_ID, 'user-b');

        self::assertNotEmpty($this->store->rows, 'a stranger cannot clear another user\'s row');
    }

    // -----------------------------------------------------------------
    // REPLACE / displacement semantics (cross-worker-visible)
    // -----------------------------------------------------------------

    public function testReplaceByOtherWorkerEvictsLocalViaTouchAndServesNewRow(): void
    {
        [$manager, $firstSessionId] = $this->started();

        // Another worker's start replaces the row (unique register) under a NEW id.
        $this->store->insert(
            'sess-replacement',
            CastingSessionStoreInterface::TYPE_PLAYTO,
            self::RENDERER_ID,
            'user-a',
            [
                'av_transport_url' => self::AV_URL,
                'friendly_name' => 'Shared Store TV',
                'media_item_id' => 'item-2',
                'uri' => 'http://media.example/y.m3u8',
            ],
        );

        $served = $manager->getSession(self::RENDERER_ID, 'user-a');

        self::assertNotNull($served);
        self::assertSame('sess-replacement', $served->getSessionId(), 'the live object yields to the fleet truth');
        $note = 'the evicted object deleted its own (already-replaced) id only';
        self::assertSame([$firstSessionId], $this->store->deleteCalls, $note);
        self::assertCount(1, $manager->getActiveSessions());
    }

    public function testStrikeTeardownEvictsMapAndDeletesRow(): void
    {
        [$manager, $sessionId] = $this->started();

        $session = $manager->getSession(self::RENDERER_ID, 'user-a');
        self::assertNotNull($session);

        // The unroutable endpoint makes every poll a failure; the 3-strike
        // teardown (d052b488) must now ALSO evict the map and the row
        // (pre-Device-M1 it left a dead object parked in the map).
        $session->syncFromRenderer();
        $session->syncFromRenderer();
        self::assertCount(1, $manager->getActiveSessions(), 'two strikes are survivable');
        $session->syncFromRenderer();

        self::assertSame([], $manager->getActiveSessions(), 'the third strike self-destructs');
        self::assertSame([$sessionId], $this->store->deleteCalls);
        self::assertSame([], $this->store->rows);
    }

    // -----------------------------------------------------------------
    // DB blips: control ops on verified local sessions fail OPEN; re-attach fails CLOSED
    // -----------------------------------------------------------------

    public function testTouchBlipKeepsServingLocalSession(): void
    {
        [$manager] = $this->started();
        $this->store->failTouchWith = new RuntimeException('db gone');

        self::assertNotNull($manager->getSession(self::RENDERER_ID, 'user-a'));
    }

    public function testFindBlipRefusesReattach(): void
    {
        [$first] = $this->started();
        unset($first);

        $workerB = $this->manager();
        $this->store->failFindWith = new RuntimeException('db gone');

        self::assertNull($workerB->getSession(self::RENDERER_ID, 'user-a'));
        self::assertSame([], $workerB->getActiveSessions());
    }

    public function testStopAllSessionsDeletesRegisteredRows(): void
    {
        [$manager, $sessionId] = $this->started();

        $manager->stopAllSessions();

        self::assertSame([], $manager->getActiveSessions());
        self::assertSame([$sessionId], $this->store->deleteCalls);
    }
}
