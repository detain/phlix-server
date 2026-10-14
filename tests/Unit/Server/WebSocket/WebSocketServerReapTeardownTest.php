<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Server\WebSocket\WebSocketServer;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Stats\Metrics\MetricsRegistry;
use Workerman\Connection\TcpConnection;

/**
 * R16-02 regression pins for the ping-sweep reaper's teardown arm.
 *
 * Workerman's TcpConnection::close() DEFERS destroy() — and with it the worker's
 * onClose callback — while its sendBuffer still holds bytes. A half-open socket
 * (the exact peer the ping sweep reaps) is the case whose send buffer cannot
 * drain, so the reaper may never see onClose; and even when it eventually fires,
 * the pool wrapper is already gone, so onClose's findConnection() lookup returns
 * null and the whole arm — SyncPlay vacate + final metrics touch — is skipped.
 * Before the fix, the reaped member lingered in its active group as a ghost.
 *
 * The reaper now completes both side effects synchronously BEFORE close/remove
 * (see WebSocketServer::reapConnection), and both effects are idempotent, so the
 * synchronous-close case (empty buffer → onClose fires inside close()) stays
 * correct without double-vacating.
 *
 * The mocked TcpConnection never fires the worker's onClose on close() — that IS
 * the deferred-close scenario, which is exactly how the pre-fix ghost-member bug
 * reproduced.
 */
final class WebSocketServerReapTeardownTest extends TestCase
{
    /** @var array<int, \Workerman\Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedWorkers = \Workerman\Worker::getAllWorkers();
        ConnectionPool::getInstance()->clear();
    }

    protected function tearDown(): void
    {
        $workers = new \ReflectionProperty(\Workerman\Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);
        parent::tearDown();
    }

    /**
     * Deferred-close scenario: the sweep reaps a connected SyncPlay member and
     * onClose NEVER fires — the vacate must still run and the group must empty.
     */
    public function testDeferredCloseReapStillVacatesSyncPlayGroup(): void
    {
        $handler = new MessageHandler(ConnectionPool::getInstance());
        $server = new WebSocketServer(['host' => '0.0.0.0', 'port' => 8097], $handler);
        $manager = new SyncPlayManager();
        $manager->initialize($handler);
        $server->setSyncPlayManager($manager);

        $tcp = $this->createMock(TcpConnection::class);
        $wsConnection = new Connection($tcp);
        ConnectionPool::getInstance()->add($wsConnection);

        $created = $manager->createGroup('Reap Group', null, 'solo_host', 'Host', $wsConnection->getId());
        self::assertTrue($created['success']);
        $groupId = $created['group']['group_id'];
        self::assertSame($groupId, $manager->getMemberGroup('solo_host'));

        // Three sweeps at limit 2: the third reaps (pendingPings >= 2).
        $server->pingConnections(2);
        $server->pingConnections(2);
        $server->pingConnections(2);

        self::assertSame(0, ConnectionPool::getInstance()->count(), 'reaped connection must leave the pool');
        self::assertNull(
            $manager->getMemberGroup('solo_host'),
            'R16-02: the reaper must vacate the member even when onClose never fires',
        );
        self::assertSame([], $manager->listGroups(), 'the emptied group must be deleted');
    }

    /**
     * Synchronous-close scenario: close() fires the worker's onClose inline
     * (empty send buffer). The teardown must run exactly once — no double vacate,
     * no exception — and a LATE onClose replay after the pool removal must also
     * be inert.
     */
    public function testSynchronousCloseReapIsIdempotent(): void
    {
        $handler = new MessageHandler(ConnectionPool::getInstance());
        $server = new WebSocketServer(['host' => '0.0.0.0', 'port' => 8097], $handler);
        $manager = new SyncPlayManager();
        $manager->initialize($handler);
        $server->setSyncPlayManager($manager);

        $tcp = $this->createMock(TcpConnection::class);
        $wsConnection = new Connection($tcp);
        ConnectionPool::getInstance()->add($wsConnection);

        // close() re-enters the server exactly like Workerman does when the send
        // buffer is already empty: destroy() → worker onClose, BEFORE the reaper
        // gets a chance to remove the pool wrapper.
        $tcp->method('close')->willReturnCallback(function () use ($server, $tcp): void {
            $server->onClose($tcp);
        });

        $created = $manager->createGroup('Sync Close Group', null, 'solo_host', 'Host', $wsConnection->getId());
        self::assertTrue($created['success']);

        $server->pingConnections(2);
        $server->pingConnections(2);
        $server->pingConnections(2);

        self::assertSame(0, ConnectionPool::getInstance()->count());
        self::assertNull($manager->getMemberGroup('solo_host'));
        self::assertSame([], $manager->listGroups());

        // A late onClose replay (deferred destroy finally completing after the
        // reaper removed the wrapper) finds no pool entry and must be a pure no-op.
        $server->onClose($tcp);
        self::assertSame(0, ConnectionPool::getInstance()->count());
        self::assertSame([], $manager->listGroups());
    }

    /**
     * The idempotency the reaper relies on, pinned at the manager level:
     * SyncPlayManager::onConnectionClose is mapping-gated, so calling it twice
     * for the same connection vacates once and never errors.
     */
    public function testManagerOnConnectionCloseDoubleVacateIsNoOp(): void
    {
        $handler = new MessageHandler(ConnectionPool::getInstance());
        $manager = new SyncPlayManager();
        $manager->initialize($handler);

        $created = $manager->createGroup('Vacate Group', null, 'host_1', 'Host', 'conn-42');
        self::assertTrue($created['success']);

        $manager->onConnectionClose('conn-42');
        self::assertNull($manager->getMemberGroup('host_1'), 'first close vacates');

        // Second call on the same connection id: the connection→member mapping was
        // cleared by leaveGroup, so this must be an early return, not a re-vacate.
        $manager->onConnectionClose('conn-42');
        self::assertSame([], $manager->listGroups(), 'group stays deleted; second vacate is inert');
    }

    /**
     * The final metrics touch belongs to the same teardown arm: a reaped
     * connection whose onClose never fires must still have its cumulative byte
     * counts pushed into the registry (previously they were lost with the ghost).
     */
    public function testReapTouchesFinalMetricsWithoutOnClose(): void
    {
        $registry = new MetricsRegistry();
        $server = new WebSocketServer(['host' => '0.0.0.0', 'port' => 8097]);
        $server->setMetricsCollector(new MetricsCollector($registry, true));

        $tcp = $this->createMock(TcpConnection::class);
        $tcp->method('getRemoteIp')->willReturn('203.0.113.50');
        $request = new \Workerman\Protocols\Http\Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $server->onConnect($tcp);
        $server->onWebSocketConnect($tcp, $request);
        $wsConnection = ConnectionPool::getInstance()->all()[0];
        self::assertInstanceOf(Connection::class, $wsConnection);

        // Bytes the peer managed to push before vanishing.
        $tcp->bytesRead = 4321;
        $tcp->bytesWritten = 8765;

        $server->pingConnections(2);
        $server->pingConnections(2);
        $server->pingConnections(2);

        self::assertSame(0, ConnectionPool::getInstance()->count(), 'connection was reaped');

        $record = $registry->snapshotConnections()[$wsConnection->getId()] ?? null;
        self::assertNotNull($record, 'the reaped connection must still have a metrics row');
        self::assertSame('websocket', $record['kind']);
        self::assertSame(
            4321,
            $record['bytes_in'],
            'R16-02: the reaper arm must perform the final metrics touch onClose would have done',
        );
        self::assertSame(8765, $record['bytes_out']);
    }
}
