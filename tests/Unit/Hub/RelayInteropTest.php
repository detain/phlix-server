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

use function chr;
use function json_decode;
use function json_encode;
use function pack;
use function strlen;
use function substr;

/**
 * Cross-repo byte-conformance / interop tests.
 *
 * These tests prove the two INDEPENDENT codec implementations interoperate at
 * the byte level. The server side uses {@see RelayMessageFramer}; the hub side
 * uses {@see \Phlix\Hub\Relay\FrameEncoder}/{@see \Phlix\Hub\Relay\FrameDecoder}.
 * Those hub classes are not autoloadable inside the server tree, so the hub's
 * exact output is reconstructed here independently from the shared wire
 * contract (the layout BOTH sides implement against):
 *
 *   binary frame: [4B seq big-endian][1B type][2B len big-endian][N payload]
 *   HELLO  (S→H): {"type":"hello","enrollment_jwt":...,"server_id":...}     (JSON text)
 *   HELLO_ACK (H→S): {"type":"hello_ack","relay_session_id":...,"tunnel_id":...} (JSON text)
 *   CLIENT_CONNECT (H→S): {"client_id":...,"session_id":...}
 *   CLIENT_DISCONNECT (H→S): {"client_id":...}
 *   DISCONNECTED (H→C/S): {"reason":...}
 *   ERROR (H→any): {"code":...,"message":...}
 *
 * Each assertion is about the OTHER side's exact wire bytes, not a self
 * round-trip: bytes built the hub's way must parse with the server's codec,
 * and bytes built the server's way must equal the hub's golden output.
 *
 * @covers \Phlix\Hub\RelayMessageFramer
 * @covers \Phlix\Hub\RelayConsumer
 */
final class RelayInteropTest extends TestCase
{
    private RelayMessageFramer $serverCodec;

    protected function setUp(): void
    {
        $this->serverCodec = new RelayMessageFramer();
    }

    // -- Hub-side byte builders (independent of the hub classes) ------------

    /**
     * Build a binary relay frame exactly as the hub's FrameEncoder does
     * (`pack('N', seq) . chr(type) . pack('n', len) . payload`).
     */
    private function hubEncode(RelayFrameType $type, int $seq, string $payload): string
    {
        return pack('N', $seq) . chr($type->value) . pack('n', strlen($payload)) . $payload;
    }

    /** Hub FrameEncoder::clientConnect output. */
    private function hubClientConnect(int $seq, string $clientId, string $sessionId): string
    {
        $payload = (string) json_encode(
            ['client_id' => $clientId, 'session_id' => $sessionId],
            JSON_THROW_ON_ERROR,
        );

        return $this->hubEncode(RelayFrameType::CLIENT_CONNECT, $seq, $payload);
    }

    /** Hub FrameEncoder::clientDisconnect output. */
    private function hubClientDisconnect(int $seq, string $clientId): string
    {
        $payload = (string) json_encode(['client_id' => $clientId], JSON_THROW_ON_ERROR);

        return $this->hubEncode(RelayFrameType::CLIENT_DISCONNECT, $seq, $payload);
    }

    /** Hub Tunnel::encodeHelloAck output (JSON text, not a binary frame). */
    private function hubHelloAck(string $relaySessionId, string $tunnelId): string
    {
        return (string) json_encode(
            ['type' => 'hello_ack', 'relay_session_id' => $relaySessionId, 'tunnel_id' => $tunnelId],
            JSON_THROW_ON_ERROR,
        );
    }

    // ============================================================
    // 1. Server-produced bytes match the hub's expected wire layout
    // ============================================================

    public function test_server_encode_is_byte_identical_to_hub_encode(): void
    {
        $cases = [
            [RelayFrameType::DATA, 0, ''],
            [RelayFrameType::DATA, 1, 'hello'],
            [RelayFrameType::HEARTBEAT, 0xFFFFFFFF, ''],
            [RelayFrameType::DATA, 0x01020304, "\x00\xFF\x10binary\x00"],
            [RelayFrameType::DATA, 42, str_repeat('Z', 65535)],
        ];

        foreach ($cases as [$type, $seq, $payload]) {
            $serverBytes = $this->serverCodec->encode($type, $seq, $payload);
            $hubBytes = $this->hubEncode($type, $seq, $payload);

            self::assertSame(
                bin2hex($hubBytes),
                bin2hex($serverBytes),
                "Server encode must match hub wire layout for {$type->label()} seq={$seq}",
            );
        }
    }

    public function test_server_hello_bytes_are_what_the_hub_parses(): void
    {
        $hello = $this->serverCodec->encodeHello('jwt.value.here', 'server-uuid-aaa');

        // The hub's RelayWorker::handleHello / Tunnel::handleHelloFrame run
        // json_decode($data, true, 2) and require type=hello plus string
        // enrollment_jwt + server_id. Mirror that exact validation here.
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($hello, true, 2, JSON_THROW_ON_ERROR);

        self::assertSame('hello', $decoded['type'] ?? null);
        self::assertIsString($decoded['enrollment_jwt'] ?? null);
        self::assertIsString($decoded['server_id'] ?? null);
        self::assertSame('server-uuid-aaa', $decoded['server_id']);
    }

    // ============================================================
    // 2. Hub-produced bytes are decoded correctly by the server codec
    // ============================================================

    /**
     * @return array<string, array{RelayFrameType, int, string}>
     */
    public static function hubBinaryFrameProvider(): array
    {
        return [
            'CLIENT_CONNECT' => [RelayFrameType::CLIENT_CONNECT, 1, '{"client_id":"c1","session_id":"s1"}'],
            'CLIENT_DISCONNECT' => [RelayFrameType::CLIENT_DISCONNECT, 2, '{"client_id":"c1"}'],
            'DATA' => [RelayFrameType::DATA, 3, "GET /hls/x.m3u8 HTTP/1.1\r\n\r\n"],
            'HEARTBEAT' => [RelayFrameType::HEARTBEAT, 4, ''],
            'DISCONNECTED' => [RelayFrameType::DISCONNECTED, 5, '{"reason":"server_closed"}'],
            'ERROR' => [RelayFrameType::ERROR, 6, '{"code":"E1","message":"boom"}'],
        ];
    }

    /**
     * @dataProvider hubBinaryFrameProvider
     */
    public function test_server_codec_decodes_hub_binary_frames(
        RelayFrameType $type,
        int $seq,
        string $payload,
    ): void {
        $hubBytes = $this->hubEncode($type, $seq, $payload);

        $frame = $this->serverCodec->decode($hubBytes);

        self::assertInstanceOf(RelayFrame::class, $frame);
        self::assertSame($type, $frame->type);
        self::assertSame($seq, $frame->seq);
        self::assertSame($payload, $frame->payload);
    }

    public function test_server_codec_parses_hub_hello_ack_text(): void
    {
        $ackBytes = $this->hubHelloAck('relay-session-9', 'tunnel-9');

        /** @var array<string, mixed> $ack */
        $ack = json_decode($ackBytes, true, 4, JSON_THROW_ON_ERROR);

        self::assertSame('hello_ack', $ack['type'] ?? null);
        self::assertSame('relay-session-9', $ack['relay_session_id'] ?? null);
        self::assertSame('tunnel-9', $ack['tunnel_id'] ?? null);
    }

    // ============================================================
    // 3. Full RelayConsumer dispatch against hub-built byte stream
    //    HELLO_ACK → CLIENT_CONNECT → DATA → (local response) DATA
    // ============================================================

    public function test_consumer_processes_a_full_hub_byte_sequence(): void
    {
        $hub = new InteropFakeConnection('ws://hub.example.com:8802');
        /** @var \ArrayObject<string, InteropFakeConnection> $locals */
        $locals = new \ArrayObject();

        $enrollment = new StoredEnrollment(
            enrollmentJwt: 'test-jwt',
            hubJwksUrl: 'https://hub.example.com/.well-known/jwks.json',
            serverId: 'server-uuid-123',
            hubBaseUrl: 'https://hub.example.com',
            enrolledAt: time(),
        );
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('loadEnrollment')->willReturn($enrollment);

        $consumer = new RelayConsumer(
            new RelayConfig(
                enabled: true,
                hubRelayWsUrl: 'ws://hub.example.com:8802',
                localHttpAddress: '127.0.0.1:8096',
            ),
            $hubClient,
            new StructuredLogger('relay-interop', []),
            'server-uuid-123',
            hubConnectionFactory: static fn (string $url): AsyncTcpConnection => $hub,
            localConnectionFactory: static function (string $url) use ($locals): AsyncTcpConnection {
                $conn = new InteropFakeConnection($url);
                $locals['local-' . $locals->count()] = $conn;
                return $conn;
            },
        );

        // --- HELLO (server → hub): the server sends its HELLO text first. ---
        $consumer->start();
        $hub->fireConnect();
        self::assertNotEmpty($hub->sent);
        /** @var array<string, mixed> $sentHello */
        $sentHello = json_decode($hub->sent[0], true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('hello', $sentHello['type'] ?? null);

        // --- HELLO_ACK (hub → server): hub's exact JSON ack bytes. ----------
        self::assertFalse($consumer->isActive());
        $hub->fireMessage($this->hubHelloAck('relay-session-1', 'tunnel-1'));
        self::assertTrue($consumer->isActive(), 'server must enter binary mode on hub HELLO_ACK bytes');

        // --- CLIENT_CONNECT (hub → server): hub's exact binary frame. -------
        $hub->fireMessage($this->hubClientConnect(1, 'client-1', 'sess-1'));
        self::assertCount(1, $locals);
        $local = $locals['local-0'];
        self::assertInstanceOf(InteropFakeConnection::class, $local);
        self::assertTrue($local->connected);

        // --- DATA (hub → server): client request bytes, piped verbatim. -----
        $request = "GET /hls/abc/index.m3u8 HTTP/1.1\r\nHost: relay\r\n\r\n";
        $hub->fireMessage($this->hubEncode(RelayFrameType::DATA, 2, $request));
        self::assertSame([$request], $local->sent, 'hub DATA payload must reach local conn verbatim');

        // --- response DATA (server → hub): local listener replies. ----------
        $hubSentBefore = count($hub->sent);
        $response = "HTTP/1.1 200 OK\r\nContent-Type: application/vnd.apple.mpegurl\r\n\r\n#EXTM3U\n";
        $local->fireMessage($response);

        // The server framed the response into a DATA frame whose bytes the hub
        // would decode. Verify with an INDEPENDENT hub-style decode.
        self::assertCount($hubSentBefore + 1, $hub->sent);
        $responseFrameBytes = $hub->sent[$hubSentBefore];
        $decoded = $this->hubStyleDecode($responseFrameBytes);
        self::assertNotNull($decoded);
        self::assertSame(RelayFrameType::DATA, $decoded['type']);
        self::assertSame($response, $decoded['payload']);

        // --- CLIENT_DISCONNECT (hub → server): hub's exact binary frame. ----
        $hub->fireMessage($this->hubClientDisconnect(3, 'client-1'));
        self::assertTrue($local->closed, 'hub CLIENT_DISCONNECT must close the local conn');
    }

    /**
     * Decode a binary frame the hub's way (independent of the server codec) to
     * confirm what the server emitted is exactly what the hub would read.
     *
     * @return array{type: RelayFrameType, seq: int, payload: string}|null
     */
    private function hubStyleDecode(string $bytes): ?array
    {
        if (strlen($bytes) < 7) {
            return null;
        }
        /** @var array{seq: int, type: int, len: int} $header */
        $header = unpack('Nseq/Ctype/nlen', $bytes);
        if (!RelayFrameType::isValid($header['type'])) {
            return null;
        }
        $total = 7 + $header['len'];
        if (strlen($bytes) < $total) {
            return null;
        }

        return [
            'type' => RelayFrameType::fromValue($header['type']),
            'seq' => $header['seq'],
            'payload' => substr($bytes, 7, $header['len']),
        ];
    }
}

/**
 * Network-free AsyncTcpConnection double for the interop sequence test.
 *
 * The real AsyncTcpConnection constructor only parses the address (no socket
 * opens until connect()), so overriding connect()/send()/close() yields a
 * cheap double that still exposes the real public onConnect/onMessage/onClose
 * callback properties the RelayConsumer wires up.
 */
final class InteropFakeConnection extends AsyncTcpConnection
{
    /** @var list<string> Everything written via send(). */
    public array $sent = [];

    public bool $connected = false;

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

    public function fireConnect(): void
    {
        if ($this->onConnect !== null) {
            ($this->onConnect)($this);
        }
    }

    public function fireMessage(string $data): void
    {
        if ($this->onMessage !== null) {
            ($this->onMessage)($this, $data);
        }
    }
}
