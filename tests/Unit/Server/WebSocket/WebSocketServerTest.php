<?php

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
 * Unit tests for WebSocketServer and SyncPlayManager initialization.
 *
 * @covers \Phlix\Server\WebSocket\WebSocketServer
 * @covers \Phlix\Session\SyncPlay\SyncPlayManager
 */
class WebSocketServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear the connection pool singleton between tests
        ConnectionPool::getInstance()->clear();
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::__construct
     * @covers \Phlix\Server\WebSocket\WebSocketServer::getHandler
     */
    public function testCanConstructWithConfig(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
        ];

        $server = new WebSocketServer($config);

        $this->assertInstanceOf(WebSocketServer::class, $server);
        $this->assertInstanceOf(MessageHandler::class, $server->getHandler());
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::__construct
     * @covers \Phlix\Server\WebSocket\WebSocketServer::getHandler
     */
    public function testCanConstructWithInjectedMessageHandler(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
        ];

        $pool = ConnectionPool::getInstance();
        $customHandler = new MessageHandler($pool);

        $server = new WebSocketServer($config, $customHandler);

        $this->assertSame($customHandler, $server->getHandler());
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::setSyncPlayManager
     */
    public function testCanSetSyncPlayManager(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
        ];

        $server = new WebSocketServer($config);
        $syncPlayManager = new SyncPlayManager();

        // setSyncPlayManager should not throw
        $this->expectNotToPerformAssertions();
        $server->setSyncPlayManager($syncPlayManager);
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::initialize
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::getStats
     */
    public function testSyncPlayManagerCanBeInitializedWithMessageHandler(): void
    {
        $pool = ConnectionPool::getInstance();
        $handler = new MessageHandler($pool);
        $syncPlayManager = new SyncPlayManager();

        // Initialize should not throw
        $syncPlayManager->initialize($handler);

        // getStats should return valid stats after initialization
        $stats = $syncPlayManager->getStats();
        $this->assertArrayHasKey('total_groups', $stats);
        $this->assertArrayHasKey('total_members', $stats);
        $this->assertArrayHasKey('time_sync_status', $stats);
        $this->assertEquals(0, $stats['total_groups']);
        $this->assertEquals(0, $stats['total_members']);
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::createGroup
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::getGroupState
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::cleanupStaleGroups
     */
    public function testSyncPlayManagerGroupOperations(): void
    {
        $pool = ConnectionPool::getInstance();
        $handler = new MessageHandler($pool);
        $syncPlayManager = new SyncPlayManager();
        $syncPlayManager->initialize($handler);

        // Create a group
        $result = $syncPlayManager->createGroup('Test Group', null, 'member_1', 'Host User');
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('group', $result);

        /** @var string $groupId */
        $groupId = $result['group']['group_id'];

        // Get group state
        $state = $syncPlayManager->getGroupState($groupId);
        $this->assertNotNull($state);
        $this->assertEquals('Test Group', $state['group_name']);

        // Cleanup stale groups (none should be stale)
        $removed = $syncPlayManager->cleanupStaleGroups(3600);
        $this->assertEquals(0, $removed);
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::joinGroup
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::leaveGroup
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::listGroups
     */
    public function testSyncPlayManagerJoinAndLeaveGroup(): void
    {
        $pool = ConnectionPool::getInstance();
        $handler = new MessageHandler($pool);
        $syncPlayManager = new SyncPlayManager();
        $syncPlayManager->initialize($handler);

        // Create a group
        $result = $syncPlayManager->createGroup('Join Test Group', null, 'host_1', 'Host');
        $this->assertTrue($result['success']);
        /** @var string $groupId */
        $groupId = $result['group']['group_id'];

        // Join the group
        $joinResult = $syncPlayManager->joinGroup($groupId, 'member_2', 'Guest User');
        $this->assertTrue($joinResult['success']);

        // List groups
        $groups = $syncPlayManager->listGroups();
        $this->assertCount(1, $groups);
        $this->assertEquals('Join Test Group', $groups[0]['name']);
        $this->assertEquals(2, $groups[0]['member_count']);

        // Leave the group
        $leaveResult = $syncPlayManager->leaveGroup('member_2');
        $this->assertTrue($leaveResult['success']);

        // List groups again - should still have 1 group with 1 member
        $groups = $syncPlayManager->listGroups();
        $this->assertCount(1, $groups);
        $this->assertEquals(1, $groups[0]['member_count']);
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::getTimeSync
     */
    public function testSyncPlayManagerGetTimeSync(): void
    {
        $syncPlayManager = new SyncPlayManager();
        $timeSync = $syncPlayManager->getTimeSync();

        $this->assertInstanceOf(\Phlix\Session\SyncPlay\TimeSync::class, $timeSync);
    }

    /**
     * @covers \Phlix\Session\SyncPlay\SyncPlayManager::getMemberGroup
     */
    public function testSyncPlayManagerGetMemberGroup(): void
    {
        $pool = ConnectionPool::getInstance();
        $handler = new MessageHandler($pool);
        $syncPlayManager = new SyncPlayManager();
        $syncPlayManager->initialize($handler);

        // Member not in any group
        $this->assertNull($syncPlayManager->getMemberGroup('unknown_member'));

        // Create group and add member
        $result = $syncPlayManager->createGroup('Member Test Group', null, 'member_x', 'Member X');
        $this->assertTrue($result['success']);
        $groupId = $result['group']['group_id'];

        $this->assertEquals($groupId, $syncPlayManager->getMemberGroup('member_x'));
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::onStart
     */
    public function testOnStartDoesNotThrow(): void
    {
        $config = [
            'host' => '0.0.0.0',
            'port' => 8097,
            'stale_connection_timeout' => 300,
            'stale_group_timeout' => 3600,
        ];

        $server = new WebSocketServer($config);
        $syncPlayManager = new SyncPlayManager();
        $server->setSyncPlayManager($syncPlayManager);

        // onStart should not throw even without Workerman\Timer
        // (the function_exists check will cause early return)
        $this->expectNotToPerformAssertions();
        $server->onStart();
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::touchActiveConnections
     */
    public function testTouchActiveConnectionsRecordsLiveConnectionBytes(): void
    {
        $registry = new MetricsRegistry();
        $collector = new MetricsCollector($registry, true, static fn (): int => 1_725_000_000);

        $server = new WebSocketServer(['host' => '0.0.0.0', 'port' => 8097]);
        $server->setMetricsCollector($collector);

        $tcp = $this->createMock(TcpConnection::class);
        $tcp->bytesRead = 1234;
        $tcp->bytesWritten = 5678;
        $wsConnection = new Connection($tcp);
        ConnectionPool::getInstance()->add($wsConnection);

        // Between flushes the touch timer calls this; the live connection's current
        // cumulative bytes must land in the registry (previously stayed a zero row
        // until close, then were deleted before any flush persisted them).
        $server->touchActiveConnections();

        $snapshot = $registry->snapshotConnections();
        $this->assertArrayHasKey($wsConnection->getId(), $snapshot);
        $this->assertSame(1234, $snapshot[$wsConnection->getId()]['bytes_in']);
        $this->assertSame(5678, $snapshot[$wsConnection->getId()]['bytes_out']);
    }

    /**
     * @covers \Phlix\Server\WebSocket\WebSocketServer::touchActiveConnections
     */
    public function testTouchActiveConnectionsIsNoOpWithoutCollector(): void
    {
        $server = new WebSocketServer(['host' => '0.0.0.0', 'port' => 8097]);

        $tcp = $this->createMock(TcpConnection::class);
        ConnectionPool::getInstance()->add(new Connection($tcp));

        // No collector wired -> must not throw / dereference null.
        $this->expectNotToPerformAssertions();
        $server->touchActiveConnections();
    }
}
