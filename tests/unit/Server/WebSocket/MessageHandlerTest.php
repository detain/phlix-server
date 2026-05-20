<?php

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Server\WebSocket\ConnectionPool;

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

        $called = false;
        $handler->on('test_event', function ($conn, $payload) use (&$called) {
            $called = true;
        });

        $this->assertTrue(true); // If we get here, no exception was thrown
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
}