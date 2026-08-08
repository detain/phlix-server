<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use InvalidArgumentException;
use Phlix\Media\Streaming\Trickplay\BifWriter;
use PHPUnit\Framework\TestCase;

/**
 * S275 — the Roku BIF container, asserted against the published byte layout.
 *
 * ## Why the assertions are shaped the way they are
 *
 * The brief this step came from specified a 4-byte magic, a 32-byte header and
 * **raw RGB** frames. All three are wrong, and all three would produce a file
 * that is structurally self-consistent and unreadable on the device. A test that
 * only checked "a file was produced", or that re-derived the offsets with the
 * same arithmetic the writer uses, would have passed that wrong format happily.
 *
 * So:
 *
 * - Every header field is asserted at a **literal byte offset** with a literal
 *   expected value. No offset here is computed from a `BifWriter` constant that
 *   the writer also uses, because a check derived from its subject cannot detect
 *   that subject changing ([[feedback_a_check_derived_from_its_subject_self_adjusts]]).
 * - The index is **walked**, and each recorded offset is required to land on a
 *   `0xFFD8` JPEG SOI marker *inside the same buffer*, with the derived length
 *   matching the payload that was handed in. A plausible-but-wrong offset table
 *   — off by the header size, off by one entry, or omitting the terminator — is
 *   exactly what that catches, and each of those three is exercised below as a
 *   negative control against a hand-built file.
 * - Frame sizes deliberately DIFFER, so an implementation that assumed a fixed
 *   stride would red. Equal-sized fixtures make the mutation a no-op
 *   ([[feedback_fixture_in_canonical_form_makes_the_mutation_a_noop]]).
 */
final class BifWriterTest extends TestCase
{
    /** Literal, spec-quoted magic. Not read from the class under test. */
    private const EXPECTED_MAGIC = "\x89\x42\x49\x46\x0d\x0a\x1a\x0a";

    /** @var list<string> Temporary files to clean up. */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Distinct-length JPEG payloads.
     *
     * @param int $count How many frames.
     *
     * @return list<string>
     */
    private function frames(int $count): array
    {
        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            // SOI ... EOI, with a filler run that grows per frame so that no two
            // frames share a length.
            $frames[] = "\xFF\xD8" . str_repeat(chr(65 + $i), 7 + $i * 5) . "\xFF\xD9";
        }

        return $frames;
    }

    /**
     * Reads a little-endian uint32 at an absolute offset.
     */
    private function u32(string $bif, int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('V', substr($bif, $offset, 4));

        return $unpacked[1];
    }

    public function testMagicIsTheEightByteRokuSignature(): void
    {
        $bif = BifWriter::build($this->frames(3), 10000);

        $this->assertSame(self::EXPECTED_MAGIC, substr($bif, 0, 8));
        // Spelled out byte by byte too, so a wrong-but-similar constant (the
        // 4-byte 0x42494600 the source doc specified) cannot slip through as a
        // prefix match.
        $this->assertSame(0x89, ord($bif[0]));
        $this->assertSame('B', $bif[1]);
        $this->assertSame('I', $bif[2]);
        $this->assertSame('F', $bif[3]);
        $this->assertSame(0x0d, ord($bif[4]));
        $this->assertSame(0x0a, ord($bif[5]));
        $this->assertSame(0x1a, ord($bif[6]));
        $this->assertSame(0x0a, ord($bif[7]));
    }

    public function testHeaderFieldsSitAtTheirSpecifiedOffsets(): void
    {
        $bif = BifWriter::build($this->frames(4), 10000);

        $this->assertSame(0, $this->u32($bif, 8), 'version at bytes 8-11 must be 0');
        $this->assertSame(4, $this->u32($bif, 12), 'image count N at bytes 12-15');
        $this->assertSame(10000, $this->u32($bif, 16), 'timestamp multiplier (ms) at bytes 16-19');
    }

    public function testBytes20To63AreReservedAndZero(): void
    {
        $bif = BifWriter::build($this->frames(2), 1000);

        $this->assertSame(str_repeat("\x00", 44), substr($bif, 20, 44));
    }

    public function testHeaderIsSixtyFourBytesSoTheIndexStartsAtByte64(): void
    {
        $frames = $this->frames(3);
        $bif = BifWriter::build($frames, 5000);

        // The first index entry is (timestamp 0, offset of frame 0). Reading it
        // at literal byte 64 is what proves the header length, without asking
        // BifWriter what it thinks its header length is.
        $this->assertSame(0, $this->u32($bif, 64), 'first index timestamp');
        $this->assertSame(
            64 + 8 * (3 + 1),
            $this->u32($bif, 68),
            'first frame offset must be 64 + 8 * (N + 1)'
        );
    }

    public function testIndexHasNPlusOneEntriesTerminatedByFfffffffAndTheEofOffset(): void
    {
        $frames = $this->frames(5);
        $bif = BifWriter::build($frames, 10000);

        $terminatorAt = 64 + 8 * 5;
        $this->assertSame(0xFFFFFFFF, $this->u32($bif, $terminatorAt), 'index terminator timestamp');
        $this->assertSame(
            strlen($bif),
            $this->u32($bif, $terminatorAt + 4),
            'terminator offset must be last byte of data + 1, i.e. the file length'
        );
    }

    public function testFrameTimestampsAreSequentialMultiplierUnits(): void
    {
        $bif = BifWriter::build($this->frames(4), 10000);

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($i, $this->u32($bif, 64 + 8 * $i), "index entry {$i} timestamp");
        }
    }

    public function testTotalLengthIsHeaderPlusIndexPlusPayloads(): void
    {
        $frames = $this->frames(6);
        $bif = BifWriter::build($frames, 2000);

        $payloadBytes = 0;
        foreach ($frames as $frame) {
            $payloadBytes += strlen($frame);
        }

        $this->assertSame(64 + 8 * (6 + 1) + $payloadBytes, strlen($bif));
    }

    public function testEveryIndexOffsetResolvesToAJpegSoiMarkerInsideTheSameFile(): void
    {
        $frames = $this->frames(7);
        $bif = BifWriter::build($frames, 10000);

        $resolved = $this->walkIndex($bif);

        $this->assertCount(7, $resolved, 'walking the index must recover exactly N payloads');
        foreach ($resolved as $i => $payload) {
            $this->assertSame(
                "\xFF\xD8",
                substr($payload, 0, 2),
                "index offset {$i} does not land on a JPEG SOI marker"
            );
            $this->assertSame($frames[$i], $payload, "payload {$i} round-trips byte for byte");
        }
    }

    public function testWriteFromFilesProducesTheIdenticalArchiveAsBuild(): void
    {
        $frames = $this->frames(5);
        $paths = [];
        foreach ($frames as $i => $frame) {
            $path = sys_get_temp_dir() . '/bifwriter_frame_' . getmypid() . "_{$i}.jpg";
            file_put_contents($path, $frame);
            $this->tempFiles[] = $path;
            $paths[] = $path;
        }
        $out = sys_get_temp_dir() . '/bifwriter_' . getmypid() . '.bif';
        $this->tempFiles[] = $out;

        $written = BifWriter::writeFromFiles($paths, 10000, $out);
        $onDisk = (string) file_get_contents($out);

        $this->assertSame(strlen($onDisk), $written, 'reported byte count matches the file');
        $this->assertSame(BifWriter::build($frames, 10000), $onDisk);

        // And the streamed file is walkable by the same reader.
        $this->assertCount(5, $this->walkIndex($onDisk));
    }

    public function testRejectsAPayloadThatIsNotAJpeg(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a JPEG');

        // Raw RGB — precisely what the source doc specified. Structurally the
        // archive would be fine; the device would render nothing.
        BifWriter::build(["\xFF\xD8ok\xFF\xD9", str_repeat("\x10\x20\x30", 64)], 10000);
    }

    public function testRejectsAnEmptyFrameList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one frame');

        BifWriter::build([], 10000);
    }

    public function testRejectsAnOutOfRangeTimestampMultiplier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timestamp multiplier');

        BifWriter::build($this->frames(2), 0);
    }

    public function testWriteFromFilesRejectsAMissingFrame(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        BifWriter::writeFromFiles(
            ['/nonexistent/frame.jpg'],
            10000,
            sys_get_temp_dir() . '/bifwriter_never_written.bif'
        );
    }

    // ------------------------------------------------------------ controls ---

    /**
     * @return list<array{0: string, 1: string}> [mutation label, mutated archive]
     */
    public static function corruptedArchiveProvider(): array
    {
        $frames = [];
        for ($i = 0; $i < 4; $i++) {
            $frames[] = "\xFF\xD8" . str_repeat(chr(65 + $i), 7 + $i * 5) . "\xFF\xD9";
        }
        $good = BifWriter::build($frames, 10000);

        // (a) every offset shifted by the header length — the classic
        //     "offsets are relative to the data section" mistake.
        $shifted = substr($good, 0, 64);
        for ($i = 0; $i <= 4; $i++) {
            $at = 64 + 8 * $i;
            /** @var array{1: int} $ts */
            $ts = unpack('V', substr($good, $at, 4));
            /** @var array{1: int} $off */
            $off = unpack('V', substr($good, $at + 4, 4));
            $shifted .= pack('V', $ts[1]) . pack('V', max(0, $off[1] - 64));
        }
        $shifted .= substr($good, 64 + 8 * 5);

        // (b) index sized N instead of N+1 — the terminator omitted, so the last
        //     frame's length is underivable.
        $truncated = substr($good, 0, 64 + 8 * 4) . substr($good, 64 + 8 * 5);

        // (c) an off-by-one-entry index: every offset taken from the NEXT frame.
        $offByOne = substr($good, 0, 64);
        for ($i = 0; $i <= 4; $i++) {
            $src = min($i + 1, 4);
            $at = 64 + 8 * $src;
            /** @var array{1: int} $off */
            $off = unpack('V', substr($good, $at + 4, 4));
            $ts = $i === 4 ? 0xFFFFFFFF : $i;
            $offByOne .= pack('V', $ts) . pack('V', $off[1]);
        }
        $offByOne .= substr($good, 64 + 8 * 5);

        return [
            ['offsets shifted by the header length', $shifted],
            ['terminator entry omitted', $truncated],
            ['offsets taken from the next entry', $offByOne],
        ];
    }

    /**
     * @dataProvider corruptedArchiveProvider
     */
    public function testTheIndexWalkerRejectsAPlausibleButWrongOffsetTable(
        string $label,
        string $corrupted
    ): void {
        $failure = null;
        try {
            $payloads = $this->walkIndex($corrupted);
            foreach ($payloads as $payload) {
                if (substr($payload, 0, 2) !== "\xFF\xD8") {
                    $failure = 'payload does not start with SOI';
                    break;
                }
            }
            if ($failure === null && count($payloads) !== 4) {
                $failure = 'wrong payload count: ' . count($payloads);
            }
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }

        // Recorded, then asserted OUTSIDE the try/catch — an assertion inside a
        // catchable block is an assertion the block can swallow.
        $this->assertNotNull(
            $failure,
            "the walker accepted a BIF whose index was corrupted by: {$label}. "
            . 'It therefore cannot prove the real writer emits correct offsets.'
        );
    }

    public function testTheWalkerAcceptsTheGenuineArticle(): void
    {
        // The control beside the three negative controls: the same walker, on an
        // unmutated archive, must succeed. Without this, three failures could
        // just mean the walker rejects everything.
        $frames = $this->frames(4);
        $payloads = $this->walkIndex(BifWriter::build($frames, 10000));

        $this->assertCount(4, $payloads);
        $this->assertSame($frames, $payloads);
    }

    /**
     * Independent BIF reader: parses the header and index from literal offsets
     * and slices each payload out by (offset, next offset - offset).
     *
     * Written against the published spec rather than against `BifWriter`, so it
     * is a genuine second opinion on the bytes.
     *
     * @param string $bif Archive bytes.
     *
     * @return list<string> Payloads in index order.
     *
     * @throws \RuntimeException If the archive is structurally invalid.
     */
    private function walkIndex(string $bif): array
    {
        if (strlen($bif) < 64) {
            throw new \RuntimeException('shorter than the 64-byte header');
        }
        if (substr($bif, 0, 8) !== self::EXPECTED_MAGIC) {
            throw new \RuntimeException('bad magic');
        }

        $count = $this->u32($bif, 12);
        $indexEnd = 64 + 8 * ($count + 1);
        if (strlen($bif) < $indexEnd) {
            throw new \RuntimeException('index runs past the end of the file');
        }

        $entries = [];
        for ($i = 0; $i <= $count; $i++) {
            $entries[] = [
                'timestamp' => $this->u32($bif, 64 + 8 * $i),
                'offset' => $this->u32($bif, 64 + 8 * $i + 4),
            ];
        }

        if ($entries[$count]['timestamp'] !== 0xFFFFFFFF) {
            throw new \RuntimeException('final index entry is not the 0xffffffff terminator');
        }
        if ($entries[$count]['offset'] !== strlen($bif)) {
            throw new \RuntimeException('terminator offset is not the end of the file');
        }

        $payloads = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $entries[$i]['offset'];
            $end = $entries[$i + 1]['offset'];
            if ($start < $indexEnd || $end <= $start || $end > strlen($bif)) {
                throw new \RuntimeException("entry {$i} offset {$start}..{$end} is outside the data section");
            }
            $payloads[] = substr($bif, $start, $end - $start);
        }

        return $payloads;
    }
}
