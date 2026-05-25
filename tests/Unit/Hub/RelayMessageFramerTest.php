<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\RelayMessageFramer;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;

/**
 * Tests for the multiplexed WS relay wire codec.
 *
 * Verifies the binary frame format matches the shared contract and the
 * hub's FrameDecoder/FrameEncoder:
 *   [4-byte seq (uint32 BE)][1-byte type][2-byte len (uint16 BE)][payload]
 */
class RelayMessageFramerTest extends TestCase
{
    private RelayMessageFramer $framer;

    protected function setUp(): void
    {
        $this->framer = new RelayMessageFramer();
    }

    public function test_encode_produces_expected_wire_layout(): void
    {
        $seq = 0x01020304;
        $payload = 'hi';
        $encoded = $this->framer->encode(RelayFrameType::DATA, $seq, $payload);

        // 7-byte header + 2-byte payload
        $this->assertSame(9, strlen($encoded));
        // seq big-endian
        $this->assertSame("\x01\x02\x03\x04", substr($encoded, 0, 4));
        // type byte
        $this->assertSame(chr(RelayFrameType::DATA->value), $encoded[4]);
        // length big-endian uint16
        $this->assertSame("\x00\x02", substr($encoded, 5, 2));
        // payload verbatim
        $this->assertSame('hi', substr($encoded, 7));
    }

    public function test_encode_decode_round_trips_all_binary_types(): void
    {
        $types = [
            RelayFrameType::CLIENT_CONNECT,
            RelayFrameType::CLIENT_DISCONNECT,
            RelayFrameType::DATA,
            RelayFrameType::HEARTBEAT,
            RelayFrameType::DISCONNECTED,
            RelayFrameType::ERROR,
        ];

        foreach ($types as $type) {
            $encoded = $this->framer->encode($type, 7, 'payload-bytes');
            $frame = $this->framer->decode($encoded);

            $this->assertInstanceOf(RelayFrame::class, $frame);
            $this->assertSame($type, $frame->type);
            $this->assertSame(7, $frame->seq);
            $this->assertSame('payload-bytes', $frame->payload);
        }
    }

    /**
     * @dataProvider boundarySizeProvider
     */
    public function test_encode_decode_round_trips_at_boundary_sizes(int $size): void
    {
        $payload = str_repeat('A', $size);
        $encoded = $this->framer->encode(RelayFrameType::DATA, 1, $payload);
        $frame = $this->framer->decode($encoded);

        $this->assertInstanceOf(RelayFrame::class, $frame);
        $this->assertSame($size, strlen($frame->payload));
        $this->assertSame($payload, $frame->payload);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function boundarySizeProvider(): array
    {
        return [
            'empty' => [0],
            'one' => [1],
            'size-255' => [255],
            'size-256' => [256],
            'size-65534' => [65534],
            'size-65535-max' => [65535],
        ];
    }

    public function test_encode_rejects_payload_over_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->framer->encode(RelayFrameType::DATA, 1, str_repeat('A', 65536));
    }

    public function test_decode_returns_null_for_incomplete_header(): void
    {
        $this->assertNull($this->framer->decode("\x00\x00\x00"));
    }

    public function test_decode_returns_null_for_incomplete_payload(): void
    {
        $encoded = $this->framer->encode(RelayFrameType::DATA, 1, 'abcdef');
        $truncated = substr($encoded, 0, 9); // header + 2 of 6 payload bytes
        $this->assertNull($this->framer->decode($truncated));
    }

    public function test_decode_returns_null_for_invalid_type_byte(): void
    {
        // seq=1, type=0xFF (invalid), len=0
        $invalid = "\x00\x00\x00\x01\xFF\x00\x00";
        $this->assertNull($this->framer->decode($invalid));
    }

    public function test_seq_is_32bit_unsigned(): void
    {
        $seq = 0xFFFFFFFF;
        $encoded = $this->framer->encode(RelayFrameType::HEARTBEAT, $seq, '');
        $frame = $this->framer->decode($encoded);

        $this->assertInstanceOf(RelayFrame::class, $frame);
        $this->assertSame($seq, $frame->seq);
    }

    public function test_encode_hello_matches_hub_json_shape(): void
    {
        $json = $this->framer->encodeHello('jwt-abc', 'server-123');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

        $this->assertSame('hello', $decoded['type']);
        $this->assertSame('jwt-abc', $decoded['enrollment_jwt']);
        $this->assertSame('server-123', $decoded['server_id']);
    }

    public function test_encode_hello_ack_matches_hub_json_shape(): void
    {
        $json = $this->framer->encodeHelloAck('relay-session-1', 'tunnel-1');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

        $this->assertSame('hello_ack', $decoded['type']);
        $this->assertSame('relay-session-1', $decoded['relay_session_id']);
        $this->assertSame('tunnel-1', $decoded['tunnel_id']);
    }

    public function test_decode_reads_first_frame_only_from_concatenation(): void
    {
        $a = $this->framer->encode(RelayFrameType::DATA, 1, 'first');
        $b = $this->framer->encode(RelayFrameType::DATA, 2, 'second');

        $frame = $this->framer->decode($a . $b);
        $this->assertInstanceOf(RelayFrame::class, $frame);
        $this->assertSame(1, $frame->seq);
        $this->assertSame('first', $frame->payload);
    }
}
