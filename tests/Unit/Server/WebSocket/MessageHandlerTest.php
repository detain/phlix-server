<?php

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Session\SyncPlay\Messages;

/**
 * Unit tests for MessageHandler class.
 *
 * @covers \Phlix\Server\WebSocket\MessageHandler
 */
class MessageHandlerTest extends TestCase
{
    /**
     * @covers \Phlix\Server\WebSocket\MessageHandler::on
     */
    public function testCanRegisterCallback(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        $handler = new MessageHandler($pool);

        $this->expectNotToPerformAssertions();
        $called = false;
        $handler->on('test_event', function ($conn, $payload) use (&$called) {
            $called = true;
        });
    }

    /**
     * @covers \Phlix\Server\WebSocket\MessageHandler::broadcast
     * @covers \Phlix\Server\WebSocket\MessageHandler::getConnectionCount
     */
    public function testCanBroadcast(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();

        $handler = new MessageHandler($pool);

        // Should not throw and return 0 connections broadcasted to
        $this->assertEquals(0, $handler->getConnectionCount());
        $handler->broadcast('test_event', ['data' => 'value']);
    }

    /**
     * SV-4.7 Gap 4: a privileged event (SyncPlay control) from an
     * unauthenticated connection is rejected with NOT_AUTHENTICATED and NOT
     * dispatched to the registered handler.
     *
     * @covers \Phlix\Server\WebSocket\MessageHandler::handle
     */
    public function testPrivilegedEventFromUnauthenticatedConnectionIsRejected(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        $handler = new MessageHandler($pool);

        $errorCode = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('isAuthenticated')->willReturn(false);
        $connection->method('sendFlat')->willReturnCallback(
            function (string $type, array $data) use (&$errorCode): void {
                if ($type === Messages::TYPE_ERROR) {
                    $errorCode = $data['error_code'] ?? null;
                }
            }
        );

        $dispatched = false;
        $handler->on(Messages::TYPE_GROUP_CREATE, function () use (&$dispatched): void {
            $dispatched = true;
        });

        $handler->handle($connection, (string) json_encode([
            'type' => Messages::TYPE_GROUP_CREATE,
            'protocol_version' => 1,
        ]));

        $this->assertSame('NOT_AUTHENTICATED', $errorCode);
        $this->assertFalse($dispatched, 'Privileged event must not dispatch for unauthenticated connection');
        $this->assertSame(0, $handler->getConnectionCount(), 'Rejected connection must not be pooled');
    }

    /**
     * SV-4.7 Gap 4: subscribe_dashboard is privileged; an unauthenticated
     * subscription is rejected before now-playing data is streamed.
     *
     * @covers \Phlix\Server\WebSocket\MessageHandler::handle
     */
    public function testSubscribeDashboardRejectedForUnauthenticated(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        $handler = new MessageHandler($pool);

        $nowPlayingCalled = false;
        $handler->setNowPlayingProvider(function () use (&$nowPlayingCalled): array {
            $nowPlayingCalled = true;
            return [];
        });

        $errorCode = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('isAuthenticated')->willReturn(false);
        $connection->method('sendFlat')->willReturnCallback(
            function (string $type, array $data) use (&$errorCode): void {
                if ($type === Messages::TYPE_ERROR) {
                    $errorCode = $data['error_code'] ?? null;
                }
            }
        );

        $handler->handle($connection, (string) json_encode([
            'type' => 'subscribe_dashboard',
            'data' => [],
        ]));

        $this->assertSame('NOT_AUTHENTICATED', $errorCode);
        $this->assertFalse($nowPlayingCalled, 'Now-playing must not be streamed to an unauthenticated subscriber');
    }

    /**
     * SV-4.7 Gap 4: a privileged event from an AUTHENTICATED connection is
     * dispatched normally.
     *
     * @covers \Phlix\Server\WebSocket\MessageHandler::handle
     */
    public function testPrivilegedEventFromAuthenticatedConnectionDispatches(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        $handler = new MessageHandler($pool);

        $connection = $this->createMock(Connection::class);
        $connection->method('isAuthenticated')->willReturn(true);
        $connection->method('getId')->willReturn('conn-auth');

        $dispatched = false;
        $handler->on(Messages::TYPE_GROUP_CREATE, function () use (&$dispatched): void {
            $dispatched = true;
        });

        $handler->handle($connection, (string) json_encode([
            'type' => Messages::TYPE_GROUP_CREATE,
            'protocol_version' => 1,
        ]));

        $this->assertTrue($dispatched, 'Privileged event must dispatch for an authenticated connection');
    }

    /**
     * SV-4.7 Gap 4/6: a public event (ping) is never gated — it dispatches for an
     * unauthenticated connection with no NOT_AUTHENTICATED error.
     *
     * @covers \Phlix\Server\WebSocket\MessageHandler::handle
     */
    public function testPublicEventNotGatedForUnauthenticated(): void
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        $handler = new MessageHandler($pool);

        $errorSent = false;
        $connection = $this->createMock(Connection::class);
        $connection->method('isAuthenticated')->willReturn(false);
        $connection->method('getId')->willReturn('conn-pub');
        $connection->method('sendFlat')->willReturnCallback(
            function (string $type) use (&$errorSent): void {
                if ($type === Messages::TYPE_ERROR) {
                    $errorSent = true;
                }
            }
        );

        $dispatched = false;
        $handler->on('ping', function () use (&$dispatched): void {
            $dispatched = true;
        });

        $handler->handle($connection, (string) json_encode(['type' => 'ping']));

        $this->assertFalse($errorSent, 'Public event must not be gated');
        $this->assertTrue($dispatched, 'Public event must dispatch for an unauthenticated connection');
    }
}
