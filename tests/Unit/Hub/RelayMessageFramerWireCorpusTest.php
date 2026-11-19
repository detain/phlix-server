<?php

/**
 * Relay message framer wire corpus test (FRAME lane, scan finding C-06).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\RelayMessageFramer;
use Phlix\Shared\Relay\RelayFrameType;

/**
 * Byte-identity pins for the relay-tunnel frame ENCODE legs.
 *
 * The hub repo's phlix-hub src/Relay/FrameDecoder.php and
 * phlix-hub src/Relay/FrameEncoder.php carry the twin encode path of this
 * framer. A single mid-session byte divergence between the two is the worst
 * available tunnel failure, so this test pins the COMPLETE corpus wire digest
 * as an absolute literal: the identical corpus builder, frame/byte counts and
 * md5 pin appear in three places —
 *
 *   - phlix-server tests/Unit/Hub/RelayMessageFramerWireCorpusTest.php
 *   - phlix-hub    tests/Unit/Relay/FrameWireCorpusTest.php
 *   - phlix-shared tests/Relay/FrameCodecTest.php   (post-v0.56.0 home)
 *
 * Changing any pin in one place without the other two reddens that repo,
 * which is the point: wire-format changes become a deliberate three-way
 * disclosure, never a silent one-sided edit (drift class of finding C-06).
 */
final class RelayMessageFramerWireCorpusTest extends TestCase
{
    /** md5 over the concatenated encode() output of the full corpus below. */
    public const WIRE_CORPUS_MD5 = '5d8d822e99c0d6a205f7702526f17fc2';

    /** Corpus frame count: 18 enum cases x 4 seq values x 7 payload shapes. */
    public const WIRE_CORPUS_FRAMES = 504;

    /** Total wire bytes of the concatenated corpus (count pin, see class docblock). */
    public const WIRE_CORPUS_BYTES = 151637;

    /** Exact HELLO JSON bytes emitted by encodeHello() on the corpus input. */
    public const HELLO_EXACT =
        '{"type":"hello","enrollment_jwt":"jwt.abc\/def+ghi","server_id":"srv-1"}';

    /** Exact HELLO_ACK JSON bytes emitted by encodeHelloAck() on the corpus input. */
    public const HELLO_ACK_EXACT =
        '{"type":"hello_ack","relay_session_id":"relay-sess-1","tunnel_id":"tunnel-9"}';

    private RelayMessageFramer $framer;

    protected function setUp(): void
    {
        $this->framer = new RelayMessageFramer();
    }

    /**
     * The absolute cross-repo wire pin. Iteration order is part of the
     * contract: cases() (declaration order) x corpusSeqs() x corpusPayloads().
     */
    public function test_encode_corpus_matches_cross_repo_wire_digest(): void
    {
        $concat = '';
        $frames = 0;

        foreach (RelayFrameType::cases() as $type) {
            foreach (self::corpusSeqs() as $seq) {
                foreach (self::corpusPayloads() as $payload) {
                    $concat .= $this->framer->encode($type, $seq, $payload);
                    $frames++;
                }
            }
        }

        $concat .= $this->framer->encodeHello('jwt.abc/def+ghi', 'srv-1');
        $concat .= $this->framer->encodeHelloAck('relay-sess-1', 'tunnel-9');

        $this->assertSame(
            self::WIRE_CORPUS_FRAMES,
            $frames,
            'corpus shape changed — the frame count is part of the cross-repo contract',
        );
        $this->assertSame(
            self::WIRE_CORPUS_BYTES,
            strlen($concat),
            'corpus wire volume changed — the byte count is part of the cross-repo contract',
        );
        $this->assertSame(
            self::WIRE_CORPUS_MD5,
            md5($concat),
            'encode() output diverged from the pinned cross-repo wire digest (C-06)',
        );
    }

    /**
     * Human-readable single-frame goldens: [4-byte seq BE][1 type][2 len BE][payload].
     *
     * @return array<string, array{RelayFrameType, int, string, string}>
     */
    public static function goldenVectorProvider(): array
    {
        return [
            'data-hi' => [RelayFrameType::DATA, 0x01020304, 'hi', '010203040500026869'],
            'heartbeat-empty' => [RelayFrameType::HEARTBEAT, 0, '', '00000000060000'],
            'http-cancel-nul-highbit' => [
                RelayFrameType::HTTP_CANCEL,
                0xFFFFFFFF,
                "\x00\xFF",
                'ffffffff12000200ff',
            ],
            'hello-ack-opcode-binary' => [RelayFrameType::HELLO_ACK, 1, 'x', '0000000102000178'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goldenVectorProvider')]
    public function test_encode_matches_exact_golden_bytes(
        RelayFrameType $type,
        int $seq,
        string $payload,
        string $expectedHex
    ): void {
        $this->assertSame($expectedHex, bin2hex($this->framer->encode($type, $seq, $payload)));
    }

    public function test_encode_hello_emits_exact_json_bytes(): void
    {
        $this->assertSame(self::HELLO_EXACT, $this->framer->encodeHello('jwt.abc/def+ghi', 'srv-1'));
    }

    public function test_encode_hello_ack_emits_exact_json_bytes(): void
    {
        $this->assertSame(
            self::HELLO_ACK_EXACT,
            $this->framer->encodeHelloAck('relay-sess-1', 'tunnel-9'),
        );
    }

    /**
     * Oversized-payload rejection keeps its exception TYPE pinned. The message
     * WORDING is deliberately NOT pinned here: server and hub currently phrase
     * the >65535 throw differently and DESIGN M-21 rules that the shared
     * FrameCodec home takes this repo's wording at v0.56.0 adoption. Pinning
     * the words now would pre-empt that disclosed, scheduled harmonisation.
     */
    public function test_encode_rejects_oversized_payload_with_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->framer->encode(RelayFrameType::DATA, 1, str_repeat('A', 65536));
    }

    public function test_encode_accepts_exactly_max_size_payload(): void
    {
        $payload = str_repeat('A', 65535);
        $encoded = $this->framer->encode(RelayFrameType::DATA, 0xABCD, $payload);

        $this->assertSame(7 + 65535, strlen($encoded));
        $this->assertSame("\x00\x00\xAB\xCD\x05\xFF\xFF", substr($encoded, 0, 7));
        $this->assertSame($payload, substr($encoded, 7));
    }

    /**
     * Full 0x00..0xFF byte ramp as payload — every byte value must survive
     * the encode leg verbatim (binary-unsafe bytes: NUL, CR/LF, high-bit).
     */
    public function test_encode_carries_every_byte_value_verbatim(): void
    {
        $ramp = self::byteRamp();
        $encoded = $this->framer->encode(RelayFrameType::DATA, 0, $ramp);

        $this->assertSame($ramp, substr($encoded, 7));
    }

    /**
     * The corpus payload set. ORDER AND CONTENT are part of the cross-repo
     * contract — mirror exactly in the hub and shared twin tests. NOTE: the
     * truncation bounds 65534/65535 exceed their sources and substr cannot
     * extend a string, so the last two payloads measure 512B/768B (ramp x2
     * and x3), NOT the uint16 caps. Cap-boundary coverage lives in the
     * dedicated max-size/oversized-rejection tests below; changing these
     * bounds would change the pinned digest and belongs to the three-file
     * disclosure ritual, never to a labels fix.
     *
     * @return list<string>
     */
    private static function corpusPayloads(): array
    {
        $ramp = self::byteRamp();

        return [
            '',                                   // zero-length
            'hi',                                 // plain ASCII
            "\x00\xFF\x80\x01\r\n",               // binary-unsafe: NUL, high-bit, CR, LF
            $ramp,                                // 256 bytes (length crosses one byte)
            $ramp . substr($ramp, 0, 255),         // 511 bytes
            substr($ramp . $ramp, 0, 65534),       // 512 bytes (ramp x2 — see docblock)
            substr($ramp . $ramp . $ramp, 0, 65535), // 768 bytes (ramp x3 — see docblock)
        ];
    }

    /**
     * The corpus seq set. ORDER AND CONTENT are part of the cross-repo contract.
     *
     * @return list<int>
     */
    private static function corpusSeqs(): array
    {
        return [0, 1, 0x7FFFFFFF, 0xFFFFFFFF];
    }

    private static function byteRamp(): string
    {
        $ramp = '';
        for ($byte = 0; $byte <= 255; $byte++) {
            $ramp .= chr($byte);
        }

        return $ramp;
    }
}
