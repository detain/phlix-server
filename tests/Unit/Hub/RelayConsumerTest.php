<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayConfig;
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\RelayMessageFramer;
use Phlix\Hub\StoredEnrollment;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Fake AsyncTcpConnection for driving the relay state machine in tests.
 *
 * The real AsyncTcpConnection constructor only parses the address (no socket
 * is opened until connect()), so a subclass that overrides connect()/send()/
 * close() is a cheap, network-free test double that still exposes the real
 * public onConnect/onMessage/onClose/onError callback properties.
 */
class FakeRelayConnection extends AsyncTcpConnection
{
    /** @var list<string> Everything written via send(). */
    public array $sent = [];

    /** @var bool Whether connect() was called. */
    public bool $connected = false;

    /** @var bool Whether close() was called. */
    public bool $closed = false;

    public function connect(): void
    {
        $this->connected = true;
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        $this->sent[] = is_string($sendBuffer) ? $sendBuffer : '';
        return true;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->closed = true;
        if ($this->onClose !== null) {
            ($this->onClose)($this);
        }
    }

    /** Simulate the WS handshake completing. */
    public function fireConnect(): void
    {
        if ($this->onConnect !== null) {
            ($this->onConnect)($this);
        }
    }

    /** Simulate an inbound message. */
    public function fireMessage(string $data): void
    {
        if ($this->onMessage !== null) {
            ($this->onMessage)($this, $data);
        }
    }
}

class RelayConsumerTest extends TestCase
{
    private RelayMessageFramer $codec;

    private FakeRelayConnection $hub;

    /** @var \ArrayObject<string, FakeRelayConnection> */
    private \ArrayObject $locals;

    protected function setUp(): void
    {
        $this->codec = new RelayMessageFramer();
    }

    private function createMockHubClient(): HubClient
    {
        $enrollment = new StoredEnrollment(
            enrollmentJwt: 'test-jwt',
            hubJwksUrl: 'https://hub.example.com/.well-known/jwks.json',
            serverId: 'server-uuid-123',
            hubBaseUrl: 'https://hub.example.com',
            enrolledAt: time(),
        );

        $mock = $this->createMock(HubClient::class);
        $mock->method('loadEnrollment')->willReturn($enrollment);

        return $mock;
    }

    private function createConsumer(?RelayConfig $config = null): RelayConsumer
    {
        $config = $config ?? new RelayConfig(
            enabled: true,
            hubRelayWsUrl: 'ws://hub.example.com:8802',
            localHttpAddress: '127.0.0.1:8096',
        );

        $this->hub = new FakeRelayConnection('ws://hub.example.com:8802');
        /** @var \ArrayObject<string, FakeRelayConnection> $locals */
        $locals = new \ArrayObject();
        $this->locals = $locals;

        $hub = $this->hub;

        return new RelayConsumer(
            $config,
            $this->createMockHubClient(),
            new StructuredLogger('relay', []),
            'server-uuid-123',
            hubConnectionFactory: static function (string $url) use ($hub): AsyncTcpConnection {
                return $hub;
            },
            localConnectionFactory: static function (string $url) use ($locals): AsyncTcpConnection {
                $conn = new FakeRelayConnection($url);
                $locals['local-' . $locals->count()] = $conn;
                return $conn;
            },
        );
    }

    /** Bring a consumer to the ACTIVE (binary) state. */
    private function activate(RelayConsumer $consumer): void
    {
        $consumer->start();
        $this->hub->fireConnect();
        $this->hub->fireMessage($this->codec->encodeHelloAck('relay-session-1', 'tunnel-1'));
    }

    private function local(int $index): FakeRelayConnection
    {
        $key = 'local-' . $index;
        $conn = $this->locals[$key] ?? null;
        $this->assertInstanceOf(FakeRelayConnection::class, $conn);
        return $conn;
    }

    public function test_start_does_nothing_when_disabled(): void
    {
        $consumer = $this->createConsumer(new RelayConfig(enabled: false));
        $consumer->start();
        $this->assertFalse($consumer->isConnected());
    }

    public function test_stop_does_nothing_when_not_running(): void
    {
        $consumer = $this->createConsumer(new RelayConfig(enabled: false));
        $consumer->stop();
        $this->assertFalse($consumer->isConnected());
    }

    public function test_hello_is_sent_on_connect(): void
    {
        $consumer = $this->createConsumer();

        $consumer->start();
        $this->assertTrue($this->hub->connected, 'consumer should initiate the WS connection');

        $this->hub->fireConnect();

        $this->assertCount(1, $this->hub->sent);
        /** @var array<string, mixed> $hello */
        $hello = json_decode($this->hub->sent[0], true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('hello', $hello['type']);
        $this->assertSame('test-jwt', $hello['enrollment_jwt']);
        $this->assertSame('server-uuid-123', $hello['server_id']);
    }

    public function test_hello_ack_transitions_to_binary_mode(): void
    {
        $consumer = $this->createConsumer();

        $consumer->start();
        $this->hub->fireConnect();
        $this->assertFalse($consumer->isActive());

        $this->hub->fireMessage($this->codec->encodeHelloAck('relay-session-1', 'tunnel-1'));
        $this->assertTrue($consumer->isActive());
    }

    public function test_garbage_hello_ack_closes_tunnel(): void
    {
        $consumer = $this->createConsumer();

        $consumer->start();
        $this->hub->fireConnect();
        $this->hub->fireMessage('not json at all');

        $this->assertTrue($this->hub->closed);
        $this->assertFalse($consumer->isActive());
    }

    public function test_unexpected_hello_ack_type_closes_tunnel(): void
    {
        $consumer = $this->createConsumer();

        $consumer->start();
        $this->hub->fireConnect();
        $this->hub->fireMessage(json_encode(['type' => 'nope'], JSON_THROW_ON_ERROR));

        $this->assertTrue($this->hub->closed);
        $this->assertFalse($consumer->isActive());
    }

    public function test_client_connect_opens_local_connection(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $payload = json_encode([
            'client_id' => 'client-1',
            'session_id' => 'sess-1',
        ], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $payload));

        $this->assertCount(1, $this->locals);
        $this->assertTrue($this->local(0)->connected, 'local connection should be opened');
    }

    public function test_data_frame_pipes_to_local_connection(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect));

        $raw = "GET / HTTP/1.1\r\nHost: x\r\n\r\n";
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::DATA, 2, $raw));

        $this->assertSame([$raw], $this->local(0)->sent, 'raw bytes should be written verbatim to local conn');
    }

    public function test_local_response_bytes_round_trip_back_as_data_frames(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect));

        $hubSentBefore = count($this->hub->sent);

        // Local listener emits response bytes.
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi";
        $this->local(0)->fireMessage($response);

        $this->assertCount($hubSentBefore + 1, $this->hub->sent);
        $frame = $this->codec->decode($this->hub->sent[$hubSentBefore]);
        $this->assertInstanceOf(RelayFrame::class, $frame);
        $this->assertSame(RelayFrameType::DATA, $frame->type);
        $this->assertSame($response, $frame->payload);
    }

    public function test_large_local_response_is_chunked_to_max_payload(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect));

        $hubSentBefore = count($this->hub->sent);

        $big = str_repeat('Z', 65535 + 100);
        $this->local(0)->fireMessage($big);

        $newFrames = array_slice($this->hub->sent, $hubSentBefore);
        $this->assertCount(2, $newFrames, '65635 bytes should split into two DATA frames');

        $reassembled = '';
        foreach ($newFrames as $bytes) {
            $frame = $this->codec->decode($bytes);
            $this->assertInstanceOf(RelayFrame::class, $frame);
            $this->assertSame(RelayFrameType::DATA, $frame->type);
            $this->assertLessThanOrEqual(65535, strlen($frame->payload));
            $reassembled .= $frame->payload;
        }
        $this->assertSame($big, $reassembled);
    }

    public function test_client_disconnect_closes_local_connection(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect));
        $local = $this->local(0);

        $disconnect = json_encode(['client_id' => 'client-1'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_DISCONNECT, 2, $disconnect));

        $this->assertTrue($local->closed, 'local connection should be closed on CLIENT_DISCONNECT');
    }

    public function test_heartbeat_frame_is_answered_with_heartbeat(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $hubSentBefore = count($this->hub->sent);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HEARTBEAT, 1, ''));

        $this->assertCount($hubSentBefore + 1, $this->hub->sent);
        $frame = $this->codec->decode($this->hub->sent[$hubSentBefore]);
        $this->assertInstanceOf(RelayFrame::class, $frame);
        $this->assertSame(RelayFrameType::HEARTBEAT, $frame->type);
    }

    public function test_disconnected_frame_closes_tunnel(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $payload = json_encode(['reason' => 'bye'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::DISCONNECTED, 1, $payload));

        $this->assertTrue($this->hub->closed);
    }

    public function test_connection_close_resets_state_for_reconnect(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);
        $this->assertTrue($consumer->isActive());

        // Simulate the WS dropping.
        $this->hub->close();

        $this->assertFalse($consumer->isConnected());
        $this->assertFalse($consumer->isActive());
    }

    public function test_multiple_frames_in_one_message_are_all_dispatched(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $f1 = $this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect);
        $f2 = $this->codec->encode(RelayFrameType::DATA, 2, 'abc');

        // Both frames arrive in a single WS message (buffer must split them).
        $this->hub->fireMessage($f1 . $f2);

        $this->assertCount(1, $this->locals);
        $this->assertSame(['abc'], $this->local(0)->sent);
    }

    // ---- Config tests (carried over / extended) ----

    public function test_relay_config_from_env_disabled(): void
    {
        putenv('PHLIX_RELAY_ENABLED=false');
        $config = RelayConfig::fromEnv();
        $this->assertFalse($config->enabled);
        putenv('PHLIX_RELAY_ENABLED');
    }

    public function test_relay_config_from_env_enabled(): void
    {
        putenv('PHLIX_RELAY_ENABLED=true');
        putenv('PHLIX_RELAY_HUB_URL=wss://hub.example.com/api/v1/servers/{id}/relay');
        $config = RelayConfig::fromEnv();
        $this->assertTrue($config->enabled);
        $this->assertStringContainsString('hub.example.com', $config->hubWssUrl);
        putenv('PHLIX_RELAY_ENABLED');
        putenv('PHLIX_RELAY_HUB_URL');
    }

    public function test_build_hub_relay_ws_url_uses_explicit_value(): void
    {
        $config = new RelayConfig(
            enabled: true,
            hubRelayWsUrl: 'ws://hub.example.com:8802',
        );
        $this->assertSame('ws://hub.example.com:8802', $config->buildHubRelayWsUrl());
    }

    public function test_build_hub_relay_ws_url_derives_from_https_template(): void
    {
        $config = new RelayConfig(
            enabled: true,
            hubWssUrl: 'wss://hub.example.com/api/v1/servers/{id}/relay',
        );
        $this->assertSame('wss://hub.example.com:8802', $config->buildHubRelayWsUrl());
    }

    public function test_build_local_http_url(): void
    {
        $config = new RelayConfig(localHttpAddress: '127.0.0.1:8096');
        $this->assertSame('tcp://127.0.0.1:8096', $config->buildLocalHttpUrl());
    }

    public function test_relay_config_builds_legacy_wss_url_with_server_id(): void
    {
        $config = new RelayConfig(
            enabled: true,
            hubWssUrl: 'wss://hub.example.com/api/v1/servers/{id}/relay',
        );
        $this->assertSame(
            'wss://hub.example.com/api/v1/servers/abc-123/relay',
            $config->buildHubWssUrl('abc-123'),
        );
    }

    public function test_register_mount_is_noop_shim(): void
    {
        $consumer = $this->createConsumer(new RelayConfig(enabled: false));
        // Should not throw — shim retained for HLS/DLNA/Roku callers.
        $consumer->registerMount('/relay/live/abc', static fn (string $p): ?string => null);
        $consumer->unregisterMount('/relay/live/abc');
        $this->assertFalse($consumer->isConnected());
    }
}
