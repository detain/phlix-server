<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayConfig;
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\RelayIdentityResolver;
use Phlix\Hub\RelayMessageFramer;
use Phlix\Hub\RelayStateStore;
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

    /**
     * Controls what send() returns (SV-2.3 backpressure tests): defaults to
     * true so every pre-existing test keeps its original always-succeeds
     * behavior; set to false to simulate a full Workerman send buffer.
     */
    public bool $sendShouldSucceed = true;

    /** @var int Number of times pauseRecv() was called. */
    public int $pauseRecvCalls = 0;

    /** @var int Number of times resumeRecv() was called. */
    public int $resumeRecvCalls = 0;

    public function connect(): void
    {
        $this->connected = true;
        // The real AsyncTcpConnection transitions to ESTABLISHED once the
        // underlying socket connects; this double never opens a real socket,
        // so set it synchronously here so getStatus() checks in the
        // backpressure resume paths (RelayConsumer::armTunnelDrainResume()/
        // onData()) see a live connection, exactly like production.
        $this->status = self::STATUS_ESTABLISHED;
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        if (!$this->sendShouldSucceed) {
            return false;
        }
        $this->sent[] = is_string($sendBuffer) ? $sendBuffer : '';
        return true;
    }

    public function pauseRecv(): void
    {
        $this->pauseRecvCalls++;
    }

    public function resumeRecv(): void
    {
        $this->resumeRecvCalls++;
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

    /** Simulate this connection's outbound send buffer draining. */
    public function fireBufferDrain(): void
    {
        if ($this->onBufferDrain !== null) {
            ($this->onBufferDrain)($this);
        }
    }
}

/**
 * A local-connection double that records, at the moment it is closed, whether
 * the relay state file exists — used to observe write amplification from INSIDE
 * a mass-close burst.
 */
class StateFileProbingConnection extends FakeRelayConnection
{
    private string $watchPath;

    /** @var \ArrayObject<int, bool> */
    private \ArrayObject $seen;

    /**
     * @param \ArrayObject<int, bool> $seen Collector for the per-close observations.
     */
    public function __construct(string $address, string $watchPath, \ArrayObject $seen)
    {
        parent::__construct($address);
        $this->watchPath = $watchPath;
        $this->seen = $seen;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->seen[] = file_exists($this->watchPath);
        parent::close($data, $raw);
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

    private function createConsumer(
        ?RelayConfig $config = null,
        ?callable $httpDispatcher = null,
        ?RelayStateStore $stateStore = null,
        ?RelayIdentityResolver $identityResolver = null,
    ): RelayConsumer {
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

        // S301 default: a resolver over a store that maps NOTHING, so every
        // pre-existing test keeps the pre-S301 identity behaviour (the raw hub
        // UUID stays the userId) unless the test supplies a mapping.
        $identityResolver ??= new RelayIdentityResolver(
            $this->createMock(\Phlix\Auth\UserIdentityRepository::class),
        );

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
            httpDispatcher: $httpDispatcher,
            stateStore: $stateStore,
            identityResolver: $identityResolver,
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

    public function test_connect_closes_a_prior_connection_instead_of_leaking_it(): void
    {
        // A factory that hands out a FRESH connection per call so the prior and
        // the replacement can be told apart.
        $opened = [];
        $consumer = new RelayConsumer(
            new RelayConfig(
                enabled: true,
                hubRelayWsUrl: 'ws://hub.example.com:8802',
                localHttpAddress: '127.0.0.1:8096',
            ),
            $this->createMockHubClient(),
            new StructuredLogger('relay', []),
            'server-uuid-123',
            hubConnectionFactory: static function (string $url) use (&$opened): AsyncTcpConnection {
                $conn = new FakeRelayConnection($url);
                $opened[] = $conn;
                return $conn;
            },
            localConnectionFactory: static function (string $url): AsyncTcpConnection {
                return new FakeRelayConnection($url);
            },
        );

        $connect = new \ReflectionMethod(RelayConsumer::class, 'connect');
        $connect->setAccessible(true);

        $connect->invoke($consumer); // opens $opened[0]
        $connect->invoke($consumer); // must close $opened[0] before opening $opened[1]

        $this->assertCount(2, $opened);
        $this->assertTrue($opened[0]->closed, 'the prior connection must be closed, not leaked');
        $this->assertTrue($opened[1]->connected, 'the replacement connection must be initiated');
        $this->assertFalse($opened[1]->closed, 'the replacement connection must stay open');
    }

    /**
     * SV-RELAYNULLCONN: connect() must not throw when the factory or the underlying
     * AsyncTcpConnection factory returns null — it must close any prior connection
     * and leave the consumer disconnected rather than crashing.
     */
    public function test_connect_does_not_throw_when_factory_returns_null(): void
    {
        $opened = [];
        $consumer = new RelayConsumer(
            new RelayConfig(
                enabled: true,
                hubRelayWsUrl: 'ws://hub.example.com:8802',
                localHttpAddress: '127.0.0.1:8096',
            ),
            $this->createMockHubClient(),
            new StructuredLogger('relay', []),
            'server-uuid-null',
            hubConnectionFactory: static function (string $url) use (&$opened): ?AsyncTcpConnection {
                $opened[] = $url;
                return null; // simulate connection failure
            },
            localConnectionFactory: static fn (string $url): AsyncTcpConnection
                => new FakeRelayConnection($url),
        );

        $connect = new \ReflectionMethod(RelayConsumer::class, 'connect');
        $connect->setAccessible(true);

        // Must not throw — should close prior connection and remain disconnected.
        $connect->invoke($consumer);

        $this->assertCount(1, $opened);
        $this->assertFalse($consumer->isConnected(), 'consumer must remain disconnected when factory returns null');
    }

    /**
     * connect() called twice: first with a real connection (succeeds), second
     * with null (must close the first, not leak it, and remain disconnected).
     */
    public function test_connect_closes_prior_connection_when_reconnect_factory_returns_null(): void
    {
        $opened = [];
        $consumer = new RelayConsumer(
            new RelayConfig(
                enabled: true,
                hubRelayWsUrl: 'ws://hub.example.com:8802',
                localHttpAddress: '127.0.0.1:8096',
            ),
            $this->createMockHubClient(),
            new StructuredLogger('relay', []),
            'server-uuid-null2',
            hubConnectionFactory: static function (string $url) use (&$opened): ?AsyncTcpConnection {
                // First call returns a real connection (succeeds); second returns null.
                $connection = count($opened) === 0 ? new FakeRelayConnection($url) : null;
                $opened[] = $connection;
                return $connection;
            },
            localConnectionFactory: static fn (string $url): AsyncTcpConnection
                => new FakeRelayConnection($url),
        );

        $connect = new \ReflectionMethod(RelayConsumer::class, 'connect');
        $connect->setAccessible(true);

        $connect->invoke($consumer); // First: opens connection
        $this->assertCount(1, $opened);
        $this->assertTrue($consumer->isConnected(), 'consumer should be connected after first connect');

        $connect->invoke($consumer); // Second: factory returns null, prior must be closed
        $this->assertCount(2, $opened);
        $this->assertFalse(
            $consumer->isConnected(),
            'consumer must be disconnected when reconnect factory returns null'
        );
        // The prior hub connection must have been closed, not leaked.
        $this->assertTrue($opened[0]->closed, 'the prior hub connection must be closed when reconnect returns null');
    }

    /**
     * SV-RELAYSYNCCLOSE: a hub connection whose connect() fails synchronously
     * (immediate DNS/socket error) invokes onClose synchronously, which runs
     * handleDisconnect() and NULLS $this->connection mid-connect(). The debug
     * logging that follows connect() must tolerate that null instead of calling
     * spl_object_id(null)/getStatus() on it and throwing a spurious TypeError
     * (which the live hub log mis-reported as "$connection->connect() threw").
     * The reconnect path must still run exactly once.
     */
    public function test_synchronous_close_during_connect_does_not_spl_object_id_null(): void
    {
        // A connection whose connect() immediately fires onClose (as Workerman's
        // AsyncTcpConnection does on a synchronous connect failure).
        $syncClosing = new class ('ws://hub.example.com:8802') extends FakeRelayConnection {
            public function connect(): void
            {
                $this->connected = true;
                // Synchronous connect failure → onClose fires before connect() returns.
                if ($this->onClose !== null) {
                    ($this->onClose)($this);
                }
            }
        };

        $consumer = new RelayConsumer(
            new RelayConfig(
                enabled: true,
                hubRelayWsUrl: 'ws://hub.example.com:8802',
                localHttpAddress: '127.0.0.1:8096',
            ),
            $this->createMockHubClient(),
            new StructuredLogger('relay', []),
            'server-uuid-syncclose',
            hubConnectionFactory: static function (string $url) use ($syncClosing): AsyncTcpConnection {
                return $syncClosing;
            },
            localConnectionFactory: static fn (string $url): AsyncTcpConnection
                => new FakeRelayConnection($url),
        );

        // running=true so handleDisconnect() proceeds to the reconnect path
        // (mirrors a live consumer that start()ed before the socket dropped).
        $runningProp = new \ReflectionProperty(RelayConsumer::class, 'running');
        $runningProp->setAccessible(true);
        $runningProp->setValue($consumer, true);

        $connect = new \ReflectionMethod(RelayConsumer::class, 'connect');
        $connect->setAccessible(true);

        // Must NOT throw a TypeError from spl_object_id(null)/getStatus() in the
        // post-connect debug log.
        $connect->invoke($consumer);

        $this->assertFalse(
            $consumer->isConnected(),
            'the synchronously-closed connection must have been nulled',
        );
        // handleDisconnect() ran to completion (incremented attempts + scheduled
        // reconnect) exactly once — not twice via a caught spurious TypeError.
        $this->assertSame(
            1,
            $consumer->getReconnectAttempts(),
            'reconnect must be scheduled once for the synchronous close, not doubled by a caught TypeError',
        );
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
        // seq carries the per-client channel id (1).
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

        // DATA carries the SAME channel id (1) as the CLIENT_CONNECT.
        $raw = "GET / HTTP/1.1\r\nHost: x\r\n\r\n";
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::DATA, 1, $raw));

        $this->assertSame([$raw], $this->local(0)->sent, 'raw bytes should be written verbatim to local conn');
    }

    public function test_data_for_unknown_channel_is_dropped(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect));

        // DATA for a channel that was never opened (7) must NOT reach channel 1.
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::DATA, 7, 'stray bytes'));

        $this->assertSame([], $this->local(0)->sent, 'DATA for an unknown channel must be dropped');
    }

    public function test_local_response_bytes_round_trip_back_as_data_frames(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 5, $connect));

        $hubSentBefore = count($this->hub->sent);

        // Local listener emits response bytes.
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi";
        $this->local(0)->fireMessage($response);

        $this->assertCount($hubSentBefore + 1, $this->hub->sent);
        $frame = $this->codec->decode($this->hub->sent[$hubSentBefore]);
        $this->assertInstanceOf(RelayFrame::class, $frame);
        $this->assertSame(RelayFrameType::DATA, $frame->type);
        $this->assertSame($response, $frame->payload);
        // Response DATA must be tagged with the originating channel id (5).
        $this->assertSame(5, $frame->channelId(), 'response DATA must carry the originating channel id');
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
            $this->assertSame(1, $frame->channelId(), 'each chunk keeps the owning channel id');
            $this->assertLessThanOrEqual(65535, strlen($frame->payload));
            $reassembled .= $frame->payload;
        }
        $this->assertSame($big, $reassembled);
    }

    public function test_zero_length_local_read_emits_no_data_frame(): void
    {
        // SV-2.3 ([S-F36]): onLocalData() used to be a do…while loop, which
        // always ran its body at least once — a spurious zero-length
        // onMessage (an empty local read) would relay a meaningless empty
        // DATA frame to the hub. It must now be a pure no-op.
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect));

        $hubSentBefore = count($this->hub->sent);

        $this->local(0)->fireMessage('');

        $this->assertCount($hubSentBefore, $this->hub->sent, 'a zero-length local read must not emit a DATA frame');
    }

    public function test_local_to_hub_backpressure_pauses_and_resumes_on_drain(): void
    {
        // SV-2.3 ([S-F36]): the local->hub direction was previously
        // fire-and-forget (send()'s boolean return was ignored). Simulate a
        // full hub-tunnel send buffer and confirm the LOCAL connection that
        // produced the bytes gets paused, then resumes once the tunnel's
        // buffer drains — mirroring the already-fixed hub->local (onData)
        // discipline, applied to the opposite side of the pipe.
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect));
        $local = $this->local(0);

        // Simulate the hub tunnel's send buffer being full.
        $this->hub->sendShouldSucceed = false;

        $hubSentBefore = count($this->hub->sent);
        $local->fireMessage('a slow-reader response chunk');

        $this->assertSame(
            $hubSentBefore,
            count($this->hub->sent),
            'the dropped frame must not appear in sent (buffer was full)',
        );
        $this->assertSame(1, $local->pauseRecvCalls, 'local connection must be paused while the tunnel is full');
        $this->assertSame(0, $local->resumeRecvCalls, 'must not resume before the tunnel actually drains');

        // The tunnel drains — resume must fire for the paused channel.
        $this->hub->sendShouldSucceed = true;
        $this->hub->fireBufferDrain();

        $this->assertSame(1, $local->resumeRecvCalls, 'local connection must resume once the tunnel drains');
    }

    public function test_local_to_hub_backpressure_resumes_every_paused_channel_on_one_drain(): void
    {
        // The hub tunnel is ONE shared connection multiplexing every
        // channel, so it exposes a single onBufferDrain slot. Two channels
        // hitting a full tunnel buffer back-to-back must BOTH still resume
        // when it drains — a naive per-call callback registration would
        // clobber the first channel's pending resume with the second's.
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_CONNECT,
            1,
            json_encode(['client_id' => 'client-1', 'session_id' => 's1'], JSON_THROW_ON_ERROR),
        ));
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_CONNECT,
            2,
            json_encode(['client_id' => 'client-2', 'session_id' => 's2'], JSON_THROW_ON_ERROR),
        ));
        $local1 = $this->local(0);
        $local2 = $this->local(1);

        $this->hub->sendShouldSucceed = false;
        $local1->fireMessage('resp-one');
        $local2->fireMessage('resp-two');

        $this->assertSame(1, $local1->pauseRecvCalls);
        $this->assertSame(1, $local2->pauseRecvCalls);

        $this->hub->sendShouldSucceed = true;
        $this->hub->fireBufferDrain();

        $this->assertSame(1, $local1->resumeRecvCalls, 'first channel must still resume, not be clobbered');
        $this->assertSame(1, $local2->resumeRecvCalls, 'second channel must resume too');
    }

    public function test_client_disconnect_closes_local_connection(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $connect = json_encode(['client_id' => 'client-1', 'session_id' => 's'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_CONNECT, 1, $connect));
        $local = $this->local(0);

        // CLIENT_DISCONNECT carries the SAME channel id (1).
        $disconnect = json_encode(['client_id' => 'client-1'], JSON_THROW_ON_ERROR);
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::CLIENT_DISCONNECT, 1, $disconnect));

        $this->assertTrue($local->closed, 'local connection should be closed on CLIENT_DISCONNECT');
    }

    // ---- Concurrent multi-client isolation (relay-mux) ----

    public function test_two_channels_route_data_independently(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        // Two clients connect on channels 1 and 2.
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_CONNECT,
            1,
            json_encode(['client_id' => 'client-1', 'session_id' => 's1'], JSON_THROW_ON_ERROR),
        ));
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_CONNECT,
            2,
            json_encode(['client_id' => 'client-2', 'session_id' => 's2'], JSON_THROW_ON_ERROR),
        ));

        $this->assertCount(2, $this->locals, 'each channel gets its own local connection');
        $local1 = $this->local(0);
        $local2 = $this->local(1);

        // DATA for channel 1 must reach ONLY local 1; channel 2 ONLY local 2.
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::DATA, 1, 'for-one'));
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::DATA, 2, 'for-two'));

        $this->assertSame(['for-one'], $local1->sent, 'channel 1 bytes only to local 1');
        $this->assertSame(['for-two'], $local2->sent, 'channel 2 bytes only to local 2');
    }

    public function test_responses_are_tagged_with_their_own_channel(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_CONNECT,
            1,
            json_encode(['client_id' => 'client-1', 'session_id' => 's1'], JSON_THROW_ON_ERROR),
        ));
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_CONNECT,
            2,
            json_encode(['client_id' => 'client-2', 'session_id' => 's2'], JSON_THROW_ON_ERROR),
        ));
        $local1 = $this->local(0);
        $local2 = $this->local(1);

        $before = count($this->hub->sent);
        $local2->fireMessage('resp-two');
        $local1->fireMessage('resp-one');

        $frames = array_slice($this->hub->sent, $before);
        $this->assertCount(2, $frames);

        $f2 = $this->codec->decode($frames[0]);
        $f1 = $this->codec->decode($frames[1]);
        $this->assertInstanceOf(RelayFrame::class, $f2);
        $this->assertInstanceOf(RelayFrame::class, $f1);
        $this->assertSame(2, $f2->channelId());
        $this->assertSame('resp-two', $f2->payload);
        $this->assertSame(1, $f1->channelId());
        $this->assertSame('resp-one', $f1->payload);
    }

    public function test_disconnecting_one_channel_leaves_the_other_active(): void
    {
        $consumer = $this->createConsumer();
        $this->activate($consumer);

        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_CONNECT,
            1,
            json_encode(['client_id' => 'client-1', 'session_id' => 's1'], JSON_THROW_ON_ERROR),
        ));
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_CONNECT,
            2,
            json_encode(['client_id' => 'client-2', 'session_id' => 's2'], JSON_THROW_ON_ERROR),
        ));
        $local1 = $this->local(0);
        $local2 = $this->local(1);

        // Disconnect channel 1 only.
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::CLIENT_DISCONNECT,
            1,
            json_encode(['client_id' => 'client-1'], JSON_THROW_ON_ERROR),
        ));

        $this->assertTrue($local1->closed, 'channel 1 local conn is closed');
        $this->assertFalse($local2->closed, 'channel 2 local conn is unaffected');

        // Channel 2 still routes; channel 1 DATA is now dropped.
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::DATA, 2, 'still-here'));
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::DATA, 1, 'should-drop'));

        $this->assertSame(['still-here'], $local2->sent, 'channel 2 keeps working after channel 1 leaves');
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
        // DATA carries the same channel id (1) as the CLIENT_CONNECT.
        $f2 = $this->codec->encode(RelayFrameType::DATA, 1, 'abc');

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

    public function test_build_hub_relay_ws_url_derives_plaintext_by_default(): void
    {
        // S38: relay TLS is independent of HTTP TLS and OFF by default, so even
        // an https/wss template derives a PLAINTEXT ws:// relay URL — the hub
        // relay listener is plaintext by default and a wss handshake to it hangs.
        $config = new RelayConfig(
            enabled: true,
            hubWssUrl: 'wss://hub.example.com/api/v1/servers/{id}/relay',
        );
        $this->assertSame('ws://hub.example.com:8802', $config->buildHubRelayWsUrl());
    }

    public function test_build_hub_relay_ws_url_derives_wss_when_relay_tls_enabled(): void
    {
        // With relay TLS explicitly enabled the derived scheme becomes wss://.
        $config = new RelayConfig(
            enabled: true,
            hubWssUrl: 'wss://hub.example.com/api/v1/servers/{id}/relay',
            relayTls: true,
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

    /**
     * Decode the HTTP_RESPONSE frames the consumer sent (skipping the leading
     * HELLO at index 0) and reassemble them into [status, headers, body].
     *
     * @return array{status: int, headers: array<string, string>, body: string, request_id: int|null}
     */
    private function collectHttpResponse(): array
    {
        $status = 0;
        $headers = [];
        $body = '';
        $ended = false;
        $requestId = null;

        foreach ($this->hub->sent as $i => $raw) {
            if ($i === 0) {
                continue; // HELLO JSON text
            }
            $frame = $this->codec->decode($raw);
            $this->assertNotNull($frame, 'frame should decode');
            $this->assertSame(RelayFrameType::HTTP_RESPONSE, $frame->type);
            $requestId = $frame->channelId();

            $chunk = \Phlix\Shared\Relay\RelayHttpResponseCodec::decode($frame->payload);
            if ($chunk->kind === \Phlix\Shared\Relay\RelayHttpResponseChunk::KIND_HEAD && $chunk->head !== null) {
                $status = $chunk->head->status;
                $headers = $chunk->head->headers;
            } elseif ($chunk->kind === \Phlix\Shared\Relay\RelayHttpResponseChunk::KIND_BODY) {
                $body .= $chunk->body;
            } elseif ($chunk->kind === \Phlix\Shared\Relay\RelayHttpResponseChunk::KIND_END) {
                $ended = true;
                break; // P8: stop after END — CANCEL frame follows but is not part of the response stream
            }
        }

        $this->assertTrue($ended, 'response stream should terminate with END');

        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'request_id' => $requestId];
    }

    public function test_http_request_dispatches_and_streams_response(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            $res = new \Phlix\Server\Http\Response();
            return $res->json(['libraries' => ['Movies', 'TV']]);
        };

        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest(
            'GET',
            '/api/v1/libraries',
            'libraryId=abc',
            ['Accept' => 'application/json', 'X-Phlix-Relay-User' => 'user-42'],
            '',
        );
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 0x80000001, $envelope->toJson()));

        // The dispatcher saw a faithfully-rebuilt request.
        $this->assertNotNull($captured);
        $this->assertSame('GET', $captured->method);
        $this->assertSame('/api/v1/libraries', $captured->path);
        $this->assertSame('libraryId=abc', $captured->queryString);
        $this->assertSame('abc', $captured->query['libraryId'] ?? null);
        $this->assertSame('user-42', $captured->userId, 'forwarded relay user should authenticate the request');

        // The response streamed back on the same request id.
        $result = $this->collectHttpResponse();
        $this->assertSame(0x80000001, $result['request_id']);
        $this->assertSame(200, $result['status']);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result['body'], true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(['libraries' => ['Movies', 'TV']], $decoded);
    }

    public function test_http_request_without_relay_user_defaults_identity(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            return (new \Phlix\Server\Http\Response())->json(['ok' => true]);
        };

        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest('GET', '/api/v1/libraries', '', [], '');
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 7, $envelope->toJson()));

        $this->assertNotNull($captured);
        $this->assertSame('hub-relay', $captured->userId);
    }

    /**
     * S301 review r1 Finding 1: a relayed 206 window carries its Content-Range.
     *
     * On the direct transport Workerman's encoder derives `Content-Range` from
     * `withFile()`'s offset/length at encode time; the tunnel head is built
     * from `$response->headers` verbatim (no encoder), so without this arm a
     * ranged direct-play seek over the relay would reach the client as a 206
     * with no range information. The hub PRESERVES `Content-Range` — it does
     * not synthesize it.
     */
    public function test_relayed_range_response_carries_content_range_in_the_head(): void
    {
        $file = sys_get_temp_dir() . '/phlix-relay-range-' . bin2hex(random_bytes(6)) . '.mp4';
        file_put_contents($file, 'ABCDEFGHIJKLMNOP');

        try {
            $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
                => (new \Phlix\Server\Http\Response())
                    ->status(206)
                    ->header('Content-Type', 'video/mp4')
                    ->withFile($file, 4, 8);

            $consumer = $this->createConsumer(null, $dispatcher);
            $this->activate($consumer);

            $envelope = new \Phlix\Shared\Relay\RelayHttpRequest('GET', '/media/m1/stream', '', ['Range' => 'bytes=4-11'], '');
            $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 0x80000005, $envelope->toJson()));

            $result = $this->collectHttpResponse();

            $this->assertSame(206, $result['status']);
            $this->assertSame('bytes 4-11/16', $result['headers']['Content-Range'] ?? null);
            $this->assertSame('EFGHIJKL', $result['body']);
        } finally {
            @unlink($file);
        }
    }

    // -----------------------------------------------------------------------
    // S301 — the re-derived S247 identity pin: the server resolves the
    // authenticated relay principal to a server user ENTIRELY from its own
    // rows. A mapped principal runs as the server user (the rating gate and
    // per-profile limits finally fire); an unmapped principal gets NO server
    // identity; a PROFILE identity never crosses the tunnel and is never read.
    // -----------------------------------------------------------------------

    /**
     * GREEN FOR THE AUTHENTICATED PRINCIPAL: a hub UUID that the server's own
     * `user_identities` (provider `hub`) maps to a server user makes the
     * relayed request run as THAT server user.
     */
    public function test_mapped_relay_principal_runs_as_the_server_user(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            return (new \Phlix\Server\Http\Response())->json(['ok' => true]);
        };

        $identities = $this->createMock(\Phlix\Auth\UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')
            ->with('hub', '', 'user-42')
            ->willReturn(['user_id' => 'server-user-9']);

        $consumer = $this->createConsumer(
            null,
            $dispatcher,
            null,
            new RelayIdentityResolver($identities),
        );
        $this->activate($consumer);

        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest(
            'GET',
            '/api/v1/libraries',
            '',
            ['X-Phlix-Relay-User' => 'user-42'],
            '',
        );
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 0x80000002, $envelope->toJson()));

        $this->assertNotNull($captured);
        $this->assertSame(
            'server-user-9',
            $captured->userId,
            'the authenticated relay principal must resolve to the linked server user',
        );
    }

    /**
     * RED ON A PROFILE IDENTITY STARTING TO CROSS (the S247 pin, re-derived):
     * the tunnel carries ONLY the authenticated relay principal. A profile
     * marker smuggled into the envelope is not just ignored — it REFUSES the
     * identity mapping entirely (with a warning), so an unexplained
     * `x-phlix-relay-*` marker can never influence identity, and it is never
     * forwarded. Mutating `buildRequest()` to read a profile claim (or letting
     * one survive into the headers) reddens this.
     */
    public function test_a_profile_identity_never_crosses_the_tunnel(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            return (new \Phlix\Server\Http\Response())->json(['ok' => true]);
        };

        $identities = $this->createMock(\Phlix\Auth\UserIdentityRepository::class);
        // The mapping EXISTS — the tripwire must refuse it anyway.
        $identities->method('findByProviderExternalId')
            ->with('hub', '', 'user-42')
            ->willReturn(['user_id' => 'server-user-9']);

        $consumer = $this->createConsumer(
            null,
            $dispatcher,
            null,
            new RelayIdentityResolver($identities),
        );
        $this->activate($consumer);

        // A malicious/broken producer smugglES a profile marker next to the
        // hub-stamped principal (the hub strips client copies on the way in;
        // this is the hypothetical where it did not).
        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest(
            'GET',
            '/api/v1/libraries',
            '',
            [
                'X-Phlix-Relay-User' => 'user-42',
                'X-Phlix-Relay-Profile' => 'profile-1',
            ],
            '',
        );
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 0x80000003, $envelope->toJson()));

        $this->assertNotNull($captured);
        $this->assertSame(
            'user-42',
            $captured->userId,
            'an unexpected relay marker must REFUSE the identity mapping — no server identity may '
            . 'be granted while a profile claim is anywhere on the tunnel',
        );
        foreach ($captured->headers as $name => $_value) {
            $this->assertStringNotContainsStringIgnoringCase(
                'profile',
                (string) $name,
                'no PROFILE marker may survive as a forwardable header — a profile identity '
                . 'must never cross the tunnel',
            );
        }
    }

    /**
     * NO SERVER IDENTITY WITHOUT A LINKAGE ROW: the resolver is the ONLY door
     * to a server identity. A principal that maps to nothing keeps the raw
     * hub-stamped UUID (auth-presence + log attribution — the pre-S301
     * behaviour) but grants NO server identity, so the server-side protections
     * that resolve against own user rows (RatingGate, per-profile stream
     * limits) stay inert for it. The resolver's null is what the stream path's
     * honest `profile_not_found` refusal reports.
     */
    public function test_unmapped_principal_grants_no_server_identity(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            return (new \Phlix\Server\Http\Response())->json(['ok' => true]);
        };

        // The store maps NOTHING — no linkage row exists.
        $identities = $this->createMock(\Phlix\Auth\UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn(null);

        $consumer = $this->createConsumer(
            null,
            $dispatcher,
            null,
            new RelayIdentityResolver($identities),
        );
        $this->activate($consumer);

        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest(
            'GET',
            '/api/v1/libraries',
            '',
            // A value that no `user_identities` row backs: whatever it names, the
            // resolver must refuse to turn it into a server identity.
            ['X-Phlix-Relay-User' => 'user-42'],
            '',
        );
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 0x80000004, $envelope->toJson()));

        $this->assertNotNull($captured);
        $this->assertSame(
            'user-42',
            $captured->userId,
            'the hub-stamped UUID is kept for auth-presence, but NO server identity may be '
            . 'granted without a server-side linkage row',
        );
    }

    public function test_http_request_strips_smuggled_auth_and_cookie_headers(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            return (new \Phlix\Server\Http\Response())->json(['ok' => true]);
        };

        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        // A relay producer tries to smuggle its own credentials.
        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest(
            'GET',
            '/api/v1/libraries',
            '',
            [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer evil',
                'Cookie' => 'phlix_session=stolen',
                'X-Phlix-Relay-User' => 'owner-1',
            ],
            '',
        );
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 21, $envelope->toJson()));

        $this->assertNotNull($captured);

        // Neither the smuggled Authorization nor Cookie may reach the dispatcher,
        // case-insensitively, so AuthMiddleware cannot be tricked.
        foreach ($captured->headers as $name => $_value) {
            $this->assertNotSame('authorization', strtolower($name), 'Authorization must be stripped from relayed request');
            $this->assertNotSame('cookie', strtolower($name), 'Cookie must be stripped from relayed request');
            $this->assertNotSame('x-phlix-relay-user', strtolower($name), 'x-phlix-relay-user must not survive as a forwardable header');
        }

        // The benign header survives.
        $this->assertSame('application/json', $captured->headers['Accept'] ?? null);

        // The hub-stamped identity is still injected so relay browse works.
        $this->assertSame('owner-1', $captured->userId);
    }

    public function test_http_request_ignores_spoofed_x_forwarded_for(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            return (new \Phlix\Server\Http\Response())->json(['ok' => true]);
        };

        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest(
            'GET',
            '/api/v1/libraries',
            '',
            ['X-Forwarded-For' => '203.0.113.7, 10.0.0.1'],
            '',
        );
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 22, $envelope->toJson()));

        $this->assertNotNull($captured);

        // remoteIp must come from the relay session (loopback marker), never the
        // producer-suppliable x-forwarded-for value.
        $this->assertNotSame('203.0.113.7', $captured->remoteIp);
        $this->assertSame('127.0.0.1', $captured->remoteIp);

        // And the spoofable header itself must not be forwarded.
        foreach ($captured->headers as $name => $_value) {
            $this->assertNotSame('x-forwarded-for', strtolower($name), 'x-forwarded-for must be stripped from relayed request');
        }
    }

    public function test_http_request_large_body_fragments_across_frames(): void
    {
        $bigBody = str_repeat('x', 200000); // > 3 frames at 65534 bytes
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use ($bigBody): \Phlix\Server\Http\Response {
            $res = new \Phlix\Server\Http\Response();
            $res->body = $bigBody;
            $res->statusCode = 200;
            return $res;
        };

        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest('GET', '/api/v1/media', '', [], '');
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 42, $envelope->toJson()));

        // HELLO + HEAD + (multiple BODY) + END — proves fragmentation happened.
        $this->assertGreaterThan(4, count($this->hub->sent));
        $result = $this->collectHttpResponse();
        $this->assertSame(200, $result['status']);
        $this->assertSame($bigBody, $result['body']);
    }

    public function test_http_request_without_dispatcher_replies_503(): void
    {
        $consumer = $this->createConsumer(); // no dispatcher
        $this->activate($consumer);

        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest('GET', '/api/v1/libraries', '', [], '');
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 9, $envelope->toJson()));

        $result = $this->collectHttpResponse();
        $this->assertSame(503, $result['status']);
    }

    public function test_http_request_malformed_envelope_replies_400(): void
    {
        $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
            => new \Phlix\Server\Http\Response();

        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 11, 'not json'));

        $result = $this->collectHttpResponse();
        $this->assertSame(400, $result['status']);
    }

    // -- HB-2.1: chunked request-body reassembly (HEAD + BODY* + END) ----------

    /**
     * A >64KB binary body (NUL/0xFF bytes) split across HEAD + N·BODY + END
     * frames is reassembled byte-identically and dispatched as one request.
     */
    public function test_http_request_chunked_reassembles_binary_body_and_dispatches(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            return (new \Phlix\Server\Http\Response())->json(['ok' => true]);
        };

        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        // Binary body > 64KB (spans >2 BODY frames at 65534 bytes) with the
        // full byte range incl NUL and 0xFF so a text/base64 path would corrupt it.
        $body = '';
        for ($i = 0; $i < 140000; $i++) {
            $body .= chr($i % 256);
        }
        $this->assertGreaterThan(65534 * 2, strlen($body));

        $requestId = 0x90000007;
        $head = (new \Phlix\Shared\Relay\RelayHttpRequestHead(
            'POST',
            '/api/v1/media/abc/poster',
            'replace=1',
            ['Content-Type' => 'application/octet-stream', 'X-Phlix-Relay-User' => 'owner-9'],
        ))->withBodySize(strlen($body));

        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            $requestId,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeHead($head),
        ));
        $bodyFrameCount = 0;
        foreach (\Phlix\Shared\Relay\RelayHttpRequestCodec::chunkBody($body) as $bodyChunk) {
            $bodyFrameCount++;
            $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, $requestId, $bodyChunk));
        }
        $this->assertGreaterThan(2, $bodyFrameCount, 'body must fragment across multiple BODY frames');
        // Nothing dispatched until END arrives.
        $this->assertNull($captured, 'request must not dispatch before the END chunk');

        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            $requestId,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeEnd(),
        ));

        // The dispatcher saw a faithfully-reassembled request.
        $this->assertNotNull($captured);
        $this->assertSame('POST', $captured->method);
        $this->assertSame('/api/v1/media/abc/poster', $captured->path);
        $this->assertSame('replace=1', $captured->queryString);
        $this->assertSame($body, $captured->rawBody, 'reassembled body must be byte-identical');
        $this->assertSame(strlen($body), strlen($captured->rawBody));
        $this->assertSame('application/octet-stream', $captured->headers['Content-Type'] ?? null);
        // The forwarded relay user still authenticates the request.
        $this->assertSame('owner-9', $captured->userId);

        // The response streamed back on the same request id.
        $result = $this->collectHttpResponse();
        $this->assertSame($requestId, $result['request_id']);
        $this->assertSame(200, $result['status']);

        // The accumulator was finalized/cleared — no memory left behind.
        $this->assertSame(0, $this->pendingAccumulatorCount($consumer));
    }

    /**
     * The legacy single-frame JSON envelope path (small body) is untouched.
     */
    public function test_http_request_legacy_single_frame_with_body_still_dispatches(): void
    {
        /** @var \Phlix\Server\Http\Request|null $captured */
        $captured = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (&$captured): \Phlix\Server\Http\Response {
            $captured = $req;
            return (new \Phlix\Server\Http\Response())->json(['ok' => true]);
        };

        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $smallBody = json_encode(['rating' => 8], JSON_THROW_ON_ERROR);
        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest(
            'PUT',
            '/api/v1/media/abc/rating',
            '',
            ['Content-Type' => 'application/json', 'X-Phlix-Relay-User' => 'owner-3'],
            $smallBody,
        );
        // toJson() begins with '{', selecting the legacy path.
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 33, $envelope->toJson()));

        $this->assertNotNull($captured);
        $this->assertSame('PUT', $captured->method);
        $this->assertSame($smallBody, $captured->rawBody);
        $this->assertSame('owner-3', $captured->userId);
        $this->assertSame(0, $this->pendingAccumulatorCount($consumer), 'legacy path opens no accumulator');

        $result = $this->collectHttpResponse();
        $this->assertSame(200, $result['status']);
    }

    public function test_http_request_body_chunk_without_head_replies_400(): void
    {
        $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
            => new \Phlix\Server\Http\Response();
        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            51,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeBody('orphan bytes'),
        ));

        $result = $this->collectHttpResponse();
        $this->assertSame(400, $result['status']);
        $this->assertSame(0, $this->pendingAccumulatorCount($consumer));
    }

    public function test_http_request_end_chunk_without_head_replies_400(): void
    {
        $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
            => new \Phlix\Server\Http\Response();
        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            52,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeEnd(),
        ));

        $result = $this->collectHttpResponse();
        $this->assertSame(400, $result['status']);
        $this->assertSame(0, $this->pendingAccumulatorCount($consumer));
    }

    public function test_http_request_duplicate_head_replies_400_and_clears(): void
    {
        $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
            => new \Phlix\Server\Http\Response();
        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $head = new \Phlix\Shared\Relay\RelayHttpRequestHead('POST', '/api/v1/media/abc/watched', '', []);
        $frame = $this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            53,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeHead($head),
        );

        $this->hub->fireMessage($frame); // first HEAD — accumulates, no output
        $this->assertSame(1, $this->pendingAccumulatorCount($consumer));

        $this->hub->fireMessage($frame); // duplicate HEAD — 400 + drop

        $result = $this->collectHttpResponse();
        $this->assertSame(400, $result['status']);
        $this->assertSame(0, $this->pendingAccumulatorCount($consumer), 'duplicate HEAD must clear the accumulator');
    }

    public function test_http_request_malformed_head_chunk_replies_400(): void
    {
        $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
            => new \Phlix\Server\Http\Response();
        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        // Tag byte HEAD (0x01) but the JSON that follows is garbage.
        $badHead = chr(\Phlix\Shared\Relay\RelayHttpRequestCodec::TAG_HEAD) . 'not json at all';
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_REQUEST, 54, $badHead));

        $result = $this->collectHttpResponse();
        $this->assertSame(400, $result['status']);
        $this->assertSame(0, $this->pendingAccumulatorCount($consumer));
    }

    /**
     * A body that grows past the reassembly cap is dropped with 413 and its
     * accumulator cleared (no throw escapes, no unbounded growth). The size is
     * seeded via reflection so the test stays fast and deterministic rather than
     * shovelling 25 MiB through the framer.
     */
    public function test_http_request_body_overflow_replies_413_and_clears(): void
    {
        $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
            => new \Phlix\Server\Http\Response();
        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $requestId = 55;

        // Open a real accumulator with a HEAD frame.
        $head = new \Phlix\Shared\Relay\RelayHttpRequestHead('POST', '/api/v1/upload', '', []);
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            $requestId,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeHead($head),
        ));

        // Seed its accumulated size to just under the cap via reflection.
        $capRef = new \ReflectionClassConstant(RelayConsumer::class, 'MAX_REASSEMBLED_REQUEST_BODY');
        $cap = (int) $capRef->getValue();
        $accProp = new \ReflectionProperty(RelayConsumer::class, 'requestAccumulators');
        $accProp->setAccessible(true);
        /** @var array<int, array{head: mixed, body: string, size: int}> $acc */
        $acc = $accProp->getValue($consumer);
        $acc[$requestId]['size'] = $cap - 10;
        $accProp->setValue($consumer, $acc);

        // One more BODY frame (65534 bytes) tips it over the cap.
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            $requestId,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeBody(str_repeat("\xff", 65534)),
        ));

        $result = $this->collectHttpResponse();
        $this->assertSame(413, $result['status']);
        $this->assertSame(0, $this->pendingAccumulatorCount($consumer), 'overflow must clear the accumulator');
    }

    public function test_http_cancel_clears_pending_chunk_accumulator(): void
    {
        $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
            => new \Phlix\Server\Http\Response();
        $consumer = $this->createConsumer(null, $dispatcher);
        $this->activate($consumer);

        $requestId = 56;
        $head = new \Phlix\Shared\Relay\RelayHttpRequestHead('POST', '/api/v1/upload', '', []);
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            $requestId,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeHead($head),
        ));
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            $requestId,
            \Phlix\Shared\Relay\RelayHttpRequestCodec::encodeBody('partial'),
        ));
        $this->assertSame(1, $this->pendingAccumulatorCount($consumer));

        // The hub cancels the in-flight request before END.
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_CANCEL, $requestId, ''));

        $this->assertSame(0, $this->pendingAccumulatorCount($consumer), 'cancel must drop the partial assembly');
    }

    /**
     * SV-4.2 ([S-F23], X1 server half): an HTTP_CANCEL frame kills any on-demand
     * ffmpeg encode tracked for that request id in the segment-process registry,
     * so an abandoned scrub-storm segment stops burning CPU immediately. The
     * registry's signal sender is injected so no real process is touched.
     */
    public function test_http_cancel_kills_tracked_segment_encode(): void
    {
        $dispatcher = static fn (\Phlix\Server\Http\Request $req): \Phlix\Server\Http\Response
            => new \Phlix\Server\Http\Response();
        $consumer = $this->createConsumer(null, $dispatcher);

        $signalled = [];
        $registry = new \Phlix\Media\Transcoding\SegmentProcessRegistry(
            null,
            static function (int $pid, int $signal) use (&$signalled): void {
                $signalled[] = $pid;
            },
            static fn (int $pid): bool => false,
            0.01,
            // No-op temp cleaner so the test touches no real files.
            static function (string $key): void {
            },
        );
        $consumer->setSegmentProcessRegistry($registry);
        $this->activate($consumer);

        $requestId = 77;
        // An on-demand encode is tracked by its SEGMENT PATH but grouped under the
        // hub channel/request id (as the live dispatch path does), so a cancel that
        // arrives with only the channel id can still find and kill it — this is the
        // finding-#3 fix (the old code keyed the kill on the channel id directly,
        // which never matched the segment-path key → an inert 0-op).
        $registry->register('/tmp/hls/job/seg-00007.ts', 9090, (string) $requestId);
        $this->assertSame(1, $registry->registeredKeyCount());
        $this->assertSame(1, $registry->registeredGroupCount());

        // The hub cancels the request.
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_CANCEL, $requestId, ''));

        $this->assertSame([9090], $signalled, 'HTTP_CANCEL must signal the tracked PID (not a 0-op)');
        $this->assertSame(0, $registry->registeredKeyCount(), 'kill must drop the entry — no leak');
        $this->assertSame(0, $registry->registeredGroupCount(), 'group torn down — no leak');
    }

    /**
     * SV-4.2 ([S-F23], X1): the relay dispatch publishes the hub channel/request
     * id as the relay cancel group into {@see \Phlix\Server\Http\RequestContext}
     * for the duration of the dispatch, so an on-demand encode the dispatcher
     * launches in-process registers under it and a later HTTP_CANCEL kills it by
     * channel id. This exercises the end-to-end threading (not a hand-registered
     * fixture): the dispatcher reads the group from RequestContext exactly as
     * TranscodeManager does.
     */
    public function test_relay_dispatch_publishes_cancel_group_so_cancel_finds_the_encode(): void
    {
        $signalled = [];
        $registry = new \Phlix\Media\Transcoding\SegmentProcessRegistry(
            null,
            static function (int $pid, int $signal) use (&$signalled): void {
                $signalled[] = $pid;
            },
            static fn (int $pid): bool => false,
            0.01,
            static function (string $key): void {
            },
        );

        // The dispatcher stands in for the app: it registers a segment encode
        // under the cancel group it reads from RequestContext (exactly as
        // TranscodeManager does when it launches an encode).
        $capturedGroup = null;
        $dispatcher = static function (\Phlix\Server\Http\Request $req) use (
            $registry,
            &$capturedGroup
        ): \Phlix\Server\Http\Response {
            $capturedGroup = \Phlix\Server\Http\RequestContext::getRelayCancelGroup();
            if ($capturedGroup !== null) {
                $registry->register('/tmp/hls/job/seg-00042.ts', 1212, $capturedGroup);
            }
            return new \Phlix\Server\Http\Response();
        };

        $consumer = $this->createConsumer(null, $dispatcher);
        $consumer->setSegmentProcessRegistry($registry);
        $this->activate($consumer);

        $requestId = 42;
        $envelope = new \Phlix\Shared\Relay\RelayHttpRequest(
            'GET',
            '/hls/job/seg-00042.ts',
            '',
            [],
            '',
        );
        $this->hub->fireMessage($this->codec->encode(
            RelayFrameType::HTTP_REQUEST,
            $requestId,
            $envelope->toJson(),
        ));

        $this->assertSame((string) $requestId, $capturedGroup, 'dispatch must publish the channel id as the cancel group');
        $this->assertSame(1, $registry->registeredGroupCount(), 'encode registered under the channel group');
        // After the dispatch the context must be cleared (no leak into the next request).
        $this->assertNull(\Phlix\Server\Http\RequestContext::getRelayCancelGroup(), 'cancel group cleared after dispatch');

        // Now the client abandons: HTTP_CANCEL with only the channel id kills it.
        $this->hub->fireMessage($this->codec->encode(RelayFrameType::HTTP_CANCEL, $requestId, ''));

        $this->assertSame([1212], $signalled, 'HTTP_CANCEL kills the encode launched during dispatch');
        $this->assertSame(0, $registry->registeredGroupCount(), 'group torn down — no leak');
    }

    /**
     * Read the number of in-flight chunked-request accumulators via reflection.
     */
    private function pendingAccumulatorCount(RelayConsumer $consumer): int
    {
        $prop = new \ReflectionProperty(RelayConsumer::class, 'requestAccumulators');
        $prop->setAccessible(true);
        /** @var array<int, mixed> $acc */
        $acc = $prop->getValue($consumer);

        return count($acc);
    }

    public function test_relay_config_with_auto_enable_derives_plaintext_ws_url_by_default(): void
    {
        // S38: relay TLS is INDEPENDENT of HTTP TLS and OFF by default, so even
        // an https hub derives a plaintext ws:// relay URL (the hub relay port is
        // plaintext by default; a wss handshake to it would hang forever).
        $config = new RelayConfig(enabled: false);
        $enabled = $config->withAutoEnable('https://hub.phlix.interserver.net');

        $this->assertTrue($enabled->enabled);
        $this->assertSame('ws://hub.phlix.interserver.net:8802', $enabled->buildHubRelayWsUrl());
    }

    public function test_relay_config_with_auto_enable_derives_wss_when_relay_tls_enabled(): void
    {
        // With relay TLS explicitly enabled (PHLIX_RELAY_TLS=1) the derived
        // scheme becomes wss:// even though it is decided independently of the
        // hub's https scheme.
        $config = new RelayConfig(enabled: false, relayTls: true);
        $enabled = $config->withAutoEnable('https://hub.phlix.interserver.net');
        $this->assertSame('wss://hub.phlix.interserver.net:8802', $enabled->buildHubRelayWsUrl());
    }

    public function test_relay_config_with_auto_enable_uses_ws_for_http_hub(): void
    {
        $config = new RelayConfig(enabled: false);
        $enabled = $config->withAutoEnable('http://localhost:8800');
        $this->assertSame('ws://localhost:8802', $enabled->buildHubRelayWsUrl());
    }

    public function test_relay_config_with_auto_enable_keeps_explicit_ws_url(): void
    {
        // S38: an explicit hubRelayWsUrl (PHLIX_RELAY_HUB_WS_URL / config
        // hub_relay_ws_url) is HIGHEST precedence and must NOT be overwritten by
        // the URL derived from the enrollment's hub_base_url.
        $config = new RelayConfig(enabled: false, hubRelayWsUrl: 'ws://explicit:9999');
        $enabled = $config->withAutoEnable('https://hub.phlix.interserver.net');
        $this->assertSame('ws://explicit:9999', $enabled->buildHubRelayWsUrl());
    }

    // ---------------------------------------------------------------------
    // S38: transport decision (TLS-vs-scheme) — the pure helper.
    // ---------------------------------------------------------------------

    private function bareConsumer(RelayConfig $config): RelayConsumer
    {
        return new RelayConsumer(
            $config,
            $this->createMockHubClient(),
            new StructuredLogger('relay', []),
            'server-uuid-123',
        );
    }

    /**
     * @return array{address: string, useTls: bool, context: array<string, mixed>}
     */
    private function resolveTransport(RelayConfig $config, string $wsUrl): array
    {
        $m = new \ReflectionMethod(RelayConsumer::class, 'resolveHubTransport');
        $m->setAccessible(true);
        /** @var array{address: string, useTls: bool, context: array<string, mixed>} $result */
        $result = $m->invoke($this->bareConsumer($config), $wsUrl);
        return $result;
    }

    public function test_transport_plaintext_for_ws_scheme(): void
    {
        $t = $this->resolveTransport(new RelayConfig(), 'ws://hub.example.com:8802');

        $this->assertFalse($t['useTls']);
        $this->assertSame('ws://hub.example.com:8802', $t['address']);
        $this->assertSame([], $t['context'], 'plaintext ws:// must attach no SSL context');
    }

    public function test_transport_tls_for_wss_scheme(): void
    {
        $t = $this->resolveTransport(new RelayConfig(), 'wss://hub.example.com:8802');

        $this->assertTrue($t['useTls']);
        // Workerman needs the ws:// address + transport=ssl for wss.
        $this->assertSame('ws://hub.example.com:8802', $t['address']);
        $this->assertArrayHasKey('ssl', $t['context']);
        /** @var array<string, mixed> $ssl */
        $ssl = $t['context']['ssl'];
        $this->assertTrue($ssl['verify_peer'], 'verification must default ON (secure)');
        $this->assertSame(RelayConfig::DEFAULT_TLS_CAFILE, $ssl['cafile']);
        $this->assertArrayNotHasKey('allow_self_signed', $ssl);
    }

    public function test_transport_tls_permissive_when_verify_disabled(): void
    {
        $config = new RelayConfig(relayTlsVerify: false);
        $t = $this->resolveTransport($config, 'wss://hub.example.com:8802');

        $this->assertTrue($t['useTls']);
        /** @var array<string, mixed> $ssl */
        $ssl = $t['context']['ssl'];
        $this->assertFalse($ssl['verify_peer']);
        $this->assertTrue($ssl['allow_self_signed']);
    }

    public function test_transport_tls_uses_configured_cafile(): void
    {
        $config = new RelayConfig(relayTlsCafile: '/etc/custom/bundle.pem');
        $t = $this->resolveTransport($config, 'wss://hub.example.com:8802');

        /** @var array<string, mixed> $ssl */
        $ssl = $t['context']['ssl'];
        $this->assertSame('/etc/custom/bundle.pem', $ssl['cafile']);
    }

    // ---------------------------------------------------------------------
    // S38: relay fork persists its state to the cross-process store.
    // ---------------------------------------------------------------------

    public function test_relay_fork_persists_active_then_disconnected_state(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-relay-consumer-state-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $store = new RelayStateStore($dir);
            $hub = new FakeRelayConnection('ws://hub.example.com:8802');

            $consumer = new RelayConsumer(
                new RelayConfig(
                    enabled: true,
                    hubRelayWsUrl: 'ws://hub.example.com:8802',
                    localHttpAddress: '127.0.0.1:8096',
                ),
                $this->createMockHubClient(),
                new StructuredLogger('relay', []),
                'server-uuid-123',
                hubConnectionFactory: static fn (string $url): AsyncTcpConnection => $hub,
                localConnectionFactory: static fn (string $url): AsyncTcpConnection
                    => new FakeRelayConnection($url),
                httpDispatcher: null,
                stateStore: $store,
            );

            $consumer->start();
            $hub->fireConnect();
            $hub->fireMessage($this->codec->encodeHelloAck('relay-session-1', 'tunnel-1'));

            $active = $store->readRelayState();
            $this->assertTrue($active['connected']);
            $this->assertTrue($active['active']);
            $this->assertNull($active['lastConnectError']);
            $this->assertSame(0, $active['reconnectAttempts']);

            // Simulate an unexpected tunnel close.
            $hub->close();

            $down = $store->readRelayState();
            $this->assertFalse($down['connected']);
            $this->assertFalse($down['active']);
            $this->assertNotNull($down['lastDisconnectTime']);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_relay_fork_persists_not_enrolled_reason(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-relay-consumer-noenroll-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $store = new RelayStateStore($dir);

            // HubClient that reports NO enrollment.
            $hubClient = $this->createMock(HubClient::class);
            $hubClient->method('loadEnrollment')->willReturn(null);

            $consumer = new RelayConsumer(
                new RelayConfig(
                    enabled: true,
                    hubRelayWsUrl: 'ws://hub.example.com:8802',
                ),
                $hubClient,
                new StructuredLogger('relay', []),
                'server-uuid-123',
                hubConnectionFactory: static fn (string $url): AsyncTcpConnection
                    => new FakeRelayConnection($url),
                localConnectionFactory: static fn (string $url): AsyncTcpConnection
                    => new FakeRelayConnection($url),
                httpDispatcher: null,
                stateStore: $store,
            );

            $consumer->start();

            $state = $store->readRelayState();
            $this->assertFalse($state['connected']);
            $this->assertIsString($state['lastConnectError']);
            $this->assertStringContainsString('not enrolled', $state['lastConnectError']);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_relay_fork_persists_no_endpoint_reason(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-relay-consumer-noendpoint-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $store = new RelayStateStore($dir);

            // Enabled but with no resolvable relay URL at all.
            $consumer = new RelayConsumer(
                new RelayConfig(enabled: true),
                $this->createMockHubClient(),
                new StructuredLogger('relay', []),
                'server-uuid-123',
                hubConnectionFactory: static fn (string $url): AsyncTcpConnection
                    => new FakeRelayConnection($url),
                localConnectionFactory: static fn (string $url): AsyncTcpConnection
                    => new FakeRelayConnection($url),
                httpDispatcher: null,
                stateStore: $store,
            );

            $consumer->start();

            $state = $store->readRelayState();
            $this->assertFalse($state['connected']);
            $this->assertIsString($state['lastConnectError']);
            $this->assertStringContainsString('no hub relay endpoint', $state['lastConnectError']);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_relay_fork_persists_connect_throw_reason(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-relay-consumer-connthrow-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $store = new RelayStateStore($dir);

            // A hub connection whose connect() throws — exercises the connect()
            // catch branch (recordConnectError('connect() failed: …') +
            // scheduleReconnect() → writeRelayState()). openHubConnection() itself
            // succeeds (returns this double); the failure happens on connect().
            $throwingHub = new class ('ws://hub.example.com:8802') extends FakeRelayConnection {
                public function connect(): void
                {
                    throw new \RuntimeException('boom-socket');
                }
            };

            $consumer = new RelayConsumer(
                new RelayConfig(
                    enabled: true,
                    hubRelayWsUrl: 'ws://hub.example.com:8802',
                ),
                $this->createMockHubClient(),
                new StructuredLogger('relay', []),
                'server-uuid-123',
                hubConnectionFactory: static fn (string $url): AsyncTcpConnection => $throwingHub,
                localConnectionFactory: static fn (string $url): AsyncTcpConnection
                    => new FakeRelayConnection($url),
                httpDispatcher: null,
                stateStore: $store,
            );

            $consumer->start();

            $state = $store->readRelayState();
            $this->assertFalse($state['connected']);
            $this->assertFalse($state['active']);
            $this->assertIsString($state['lastConnectError']);
            $this->assertStringContainsString('connect() failed', $state['lastConnectError']);
            $this->assertStringContainsString('boom-socket', $state['lastConnectError']);
            $this->assertNotNull($state['lastConnectErrorAt']);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_relay_fork_does_not_persist_tls_mismatch_advisory(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-relay-consumer-tlswarn-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $store = new RelayStateStore($dir);
            // The real openHubConnection rewrites wss->ws before constructing the
            // connection; here we hand back a pre-built ws:// fake so the raw
            // wss:// URL never reaches AsyncTcpConnection's protocol lookup.
            $hub = new FakeRelayConnection('ws://hub.example.com:8802');

            // Explicit wss:// URL but relay TLS NOT enabled. After S38's scheme
            // derivation fix this branch is only reachable via an explicit
            // wss:// override, and the transport still keys off the scheme
            // (genuine TLS) — so the tunnel is HEALTHY when the hub relay port
            // is TLS. The start-time heads-up is LOG-ONLY: it must NOT persist a
            // spurious `lastConnectError` down-reason (a false positive that
            // would mislead S40's Network Health panel into showing a "down
            // reason" while the tunnel is actually up).
            $consumer = new RelayConsumer(
                new RelayConfig(
                    enabled: true,
                    hubRelayWsUrl: 'wss://hub.example.com:8802',
                    relayTls: false,
                ),
                $this->createMockHubClient(),
                new StructuredLogger('relay', []),
                'server-uuid-123',
                hubConnectionFactory: static fn (string $url): AsyncTcpConnection => $hub,
                localConnectionFactory: static fn (string $url): AsyncTcpConnection
                    => new FakeRelayConnection($url),
                httpDispatcher: null,
                stateStore: $store,
            );

            $consumer->start();

            // The advisory branch fired (wss:// + relayTls=false), but it wrote
            // NO preemptive down-reason: the state store carries no hang-advisory.
            $afterStart = $store->readRelayState();
            $this->assertArrayNotHasKey('lastConnectError', $afterStart);

            // Complete a healthy handshake — the wss:// override to a TLS hub
            // relay port works fine — and confirm the persisted state reflects an
            // active tunnel with NO lingering/spurious connect-error down-reason.
            $hub->fireConnect();
            $hub->fireMessage($this->codec->encodeHelloAck('relay-session-1', 'tunnel-1'));

            $active = $store->readRelayState();
            $this->assertTrue($active['connected']);
            $this->assertTrue($active['active']);
            $this->assertNull($active['lastConnectError']);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    // ---------------------------------------------------------------------
    // S40: activeSessions must track the live session count.
    //
    // The three original writeRelayState() call sites (HELLO_ACK, disconnect,
    // reconnect) all run with localConnections EMPTY, so `activeSessions` was
    // STRUCTURALLY pinned to 0 in /api/v1/health/relay and the admin relay
    // status endpoint even with live relayed sessions.
    // ---------------------------------------------------------------------

    /** Run $body with a temp state dir, cleaning up afterwards. */
    private function withStateDir(callable $body): void
    {
        $dir = sys_get_temp_dir() . '/phlix-relay-sessions-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $body(new RelayStateStore($dir));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_active_session_count_is_persisted_when_a_client_connects(): void
    {
        $this->withStateDir(function (RelayStateStore $store): void {
            $consumer = $this->createConsumer(null, null, $store);
            $this->activate($consumer);

            $this->assertSame(0, $store->readRelayState()['activeSessions']);

            $this->hub->fireMessage($this->codec->encode(
                RelayFrameType::CLIENT_CONNECT,
                1,
                json_encode(['client_id' => 'client-1', 'session_id' => 's1'], JSON_THROW_ON_ERROR),
            ));

            $this->assertSame(
                1,
                $store->readRelayState()['activeSessions'],
                'a live relayed session must be visible in the persisted state'
            );

            $this->hub->fireMessage($this->codec->encode(
                RelayFrameType::CLIENT_CONNECT,
                2,
                json_encode(['client_id' => 'client-2', 'session_id' => 's2'], JSON_THROW_ON_ERROR),
            ));

            $this->assertSame(2, $store->readRelayState()['activeSessions']);
        });
    }

    public function test_active_session_count_is_persisted_when_a_client_disconnects(): void
    {
        $this->withStateDir(function (RelayStateStore $store): void {
            $consumer = $this->createConsumer(null, null, $store);
            $this->activate($consumer);

            foreach ([1, 2] as $channel) {
                $this->hub->fireMessage($this->codec->encode(
                    RelayFrameType::CLIENT_CONNECT,
                    $channel,
                    json_encode(['client_id' => 'client-' . $channel, 'session_id' => 's'], JSON_THROW_ON_ERROR),
                ));
            }
            $this->assertSame(2, $store->readRelayState()['activeSessions']);

            // Hub-initiated close of channel 1 (closeLocalConnection path).
            $this->hub->fireMessage($this->codec->encode(
                RelayFrameType::CLIENT_DISCONNECT,
                1,
                json_encode(['client_id' => 'client-1'], JSON_THROW_ON_ERROR),
            ));
            $this->assertSame(1, $store->readRelayState()['activeSessions']);

            // Local-side close of channel 2 (onLocalClose path).
            $this->local(1)->close();
            $this->assertSame(0, $store->readRelayState()['activeSessions']);
        });
    }

    public function test_mass_session_close_coalesces_into_a_single_state_write(): void
    {
        // A tunnel drop closes every local connection in a tight loop, and each
        // close re-enters onLocalClose(). Without the suspend/flush guard that
        // would cost one atomic rewrite PER session. Probe: delete the state
        // file, then record — from inside each close() — whether it has
        // reappeared. Coalesced => nobody sees it; per-close writes => every
        // close after the first does.
        $dir = sys_get_temp_dir() . '/phlix-relay-burst-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $path = $dir . '/' . RelayStateStore::RELAY_STATE_FILE;

        try {
            $store = new RelayStateStore($dir);
            $hub = new FakeRelayConnection('ws://hub.example.com:8802');
            /** @var \ArrayObject<int, bool> $seen */
            $seen = new \ArrayObject();

            $consumer = new RelayConsumer(
                new RelayConfig(
                    enabled: true,
                    hubRelayWsUrl: 'ws://hub.example.com:8802',
                    localHttpAddress: '127.0.0.1:8096',
                ),
                $this->createMockHubClient(),
                new StructuredLogger('relay', []),
                'server-uuid-123',
                hubConnectionFactory: static fn (string $url): AsyncTcpConnection => $hub,
                localConnectionFactory: static fn (string $url): AsyncTcpConnection
                    => new StateFileProbingConnection($url, $path, $seen),
                httpDispatcher: null,
                stateStore: $store,
            );

            $consumer->start();
            $hub->fireConnect();
            $hub->fireMessage($this->codec->encodeHelloAck('relay-session-1', 'tunnel-1'));

            foreach ([1, 2, 3] as $channel) {
                $hub->fireMessage($this->codec->encode(
                    RelayFrameType::CLIENT_CONNECT,
                    $channel,
                    json_encode(['client_id' => 'c' . $channel, 'session_id' => 's'], JSON_THROW_ON_ERROR),
                ));
            }
            $this->assertSame(3, $store->readRelayState()['activeSessions']);

            unlink($path);
            $hub->close();

            $this->assertSame(
                [false, false, false],
                $seen->getArrayCopy(),
                'the mass close must not rewrite the state file once per session'
            );
            $this->assertSame(0, $store->readRelayState()['activeSessions']);
            $this->assertFalse($store->readRelayState()['connected']);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_relay_state_declares_its_refresh_cadence_for_the_staleness_gate(): void
    {
        $this->withStateDir(function (RelayStateStore $store): void {
            $consumer = $this->createConsumer(
                new RelayConfig(
                    enabled: true,
                    hubRelayWsUrl: 'ws://hub.example.com:8802',
                    localHttpAddress: '127.0.0.1:8096',
                    pingInterval: 30,
                ),
                null,
                $store,
            );
            $this->activate($consumer);

            $state = $store->readRelayState();
            $this->assertSame(90, $state['staleAfterSeconds'], '3x the 30s ping cadence');
            $this->assertFalse(
                RelayStateStore::isStateStale($state),
                'a state file just written by a live fork is not stale'
            );
        });
    }
}
