<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\WebSocketServer;
use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;

/**
 * item5+: the S2 connection-metrics record must carry the REAL client IP, not the
 * loopback proxy peer.
 *
 * The `:8097` WS worker is fronted by HAProxy over loopback with NO PROXY-protocol,
 * so `$connection->getRemoteIp()` is ALWAYS `127.0.0.1` for EVERY client at
 * TCP-accept. Opening the metrics record in {@see WebSocketServer::onConnect} (which
 * fired BEFORE the WS upgrade request and its `X-Forwarded-For` were parsed) stamped
 * every WebSocket row with the loopback peer. The record is now opened in
 * {@see WebSocketServer::onWebSocketConnect}, where the same trusted-proxy-aware
 * resolution the connect rate-limiter uses yields the real client address.
 *
 * @covers \Phlix\Server\WebSocket\WebSocketServer
 */
final class WsMetricsRemoteIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConnectionPool::getInstance()->clear();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Workerman\Connection\TcpConnection
     */
    private function mockTcpConnection(
        string $remoteIp,
    ): \Workerman\Connection\TcpConnection|\PHPUnit\Framework\MockObject\MockObject {
        $mock = $this->createMock(\Workerman\Connection\TcpConnection::class);
        $mock->method('getRemoteIp')->willReturn($remoteIp);

        return $mock;
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeRequest(array $headers = []): \Workerman\Protocols\Http\Request
    {
        $raw = "GET / HTTP/1.1\r\nHost: localhost\r\n";
        foreach ($headers as $name => $value) {
            $raw .= "{$name}: {$value}\r\n";
        }
        $raw .= "\r\n";

        return new \Workerman\Protocols\Http\Request($raw);
    }

    private function makeServerWithMetrics(MetricsRegistry $registry): WebSocketServer
    {
        // Anonymous/dev config (no jwt_secret, no limiter): onWebSocketConnect runs
        // straight through the metrics open + returns, which is all we assert here.
        $server = new WebSocketServer(['host' => '0.0.0.0', 'port' => 8097]);
        $server->setMetricsCollector(new MetricsCollector($registry, true));

        return $server;
    }

    /**
     * Behind the loopback HAProxy front, the metrics row must record the REAL
     * client appended to X-Forwarded-For — never the shared loopback peer.
     */
    public function testMetricsRecordsResolvedClientIpNotLoopback(): void
    {
        $registry = new MetricsRegistry();
        $server = $this->makeServerWithMetrics($registry);

        $conn = $this->mockTcpConnection('127.0.0.1');
        $server->onConnect($conn);
        $server->onWebSocketConnect($conn, $this->makeRequest(['X-Forwarded-For' => '203.0.113.88']));

        $connections = $registry->snapshotConnections();
        self::assertCount(1, $connections, 'exactly one WS connection recorded');

        $record = array_values($connections)[0];
        self::assertSame('websocket', $record['kind']);
        self::assertSame(
            '203.0.113.88',
            $record['remote_ip'],
            'metrics must record the resolved client IP, not the loopback proxy peer',
        );
        self::assertNotSame('127.0.0.1', $record['remote_ip']);
    }

    /**
     * Direct (non-proxied) connect: with no trusted proxy in front and no
     * forwarding headers, the resolver falls back to the peer address, so the
     * metrics row records the direct client IP correctly.
     */
    public function testMetricsRecordsDirectPeerIpWhenNotProxied(): void
    {
        $registry = new MetricsRegistry();
        $server = $this->makeServerWithMetrics($registry);

        $conn = $this->mockTcpConnection('203.0.113.50');
        $server->onConnect($conn);
        $server->onWebSocketConnect($conn, $this->makeRequest());

        $connections = $registry->snapshotConnections();
        self::assertCount(1, $connections);
        self::assertSame('203.0.113.50', array_values($connections)[0]['remote_ip']);
    }

    /**
     * The record is opened at the HANDSHAKE, not at TCP-accept: onConnect alone
     * (before the upgrade request is parsed) must NOT create a metrics row — that
     * was the code path that could only ever see the loopback peer.
     */
    public function testOnConnectAloneDoesNotRecordConnection(): void
    {
        $registry = new MetricsRegistry();
        $server = $this->makeServerWithMetrics($registry);

        $conn = $this->mockTcpConnection('127.0.0.1');
        $server->onConnect($conn);

        self::assertCount(
            0,
            $registry->snapshotConnections(),
            'no metrics row until the WS handshake resolves the real client IP',
        );
    }
}
