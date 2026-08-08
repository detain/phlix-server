<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Trickplay;

use Phlix\Media\Streaming\Trickplay\BifWriter;

/**
 * The byte-wise BIF checks S275 shipped, extracted so a second producer path can
 * REUSE them rather than re-implement something weaker.
 *
 * ## Why it is a trait and why it moved here (S284)
 *
 * S275 wrote an independent index walker — written from Roku's published spec
 * rather than from {@see BifWriter} — inside
 * `tests/Integration/Media/Transcoding/TrickplayBifProductionTest`. S284 adds a
 * SECOND way to reach the producer (the admin re-enqueue path, for libraries
 * scanned before the producer worked), and its acceptance criterion is that the
 * resulting `.bif` passes *those* checks. Copying them would have produced two
 * walkers that drift; a walker written afresh for the new test would have been
 * free to be weaker in exactly the places that matter.
 *
 * ## What {@see self::assertWalkableBifArchive()} actually proves
 *
 * - the 8-byte magic, compared to the full literal (a 4-byte `0x42494600` prefix
 *   cannot pass);
 * - the header length, pinned INDIRECTLY: the first index entry's offset must
 *   equal `64 + 8*(N+1)`, i.e. derived from the image count read at literal
 *   offset 12, never from anything the writer asserts about itself;
 * - the image count is read at literal offset 12 and must be > 0;
 * - the terminator entry is `0xFFFFFFFF` at `64 + 8N` and its offset is the file
 *   length exactly;
 * - every payload offset resolves inside the same buffer to a `0xFFD8` SOI
 *   marker, ends with `0xFFD9`, and decodes as a real JPEG;
 * - offsets strictly increase, so a fixed-stride or never-advancing writer reds.
 *
 * ⚠ Nothing here calls {@see BifWriter} to compute an expectation. The only
 * BifWriter symbol referenced is {@see BifWriter::MAGIC}, and it is compared as
 * an 8-byte literal length so a shortened constant cannot make the check agree
 * with a shortened writer.
 */
trait AssertsWalkableBifArchives
{
    /**
     * Full-strength check on a BIF archive's bytes.
     *
     * @param string $bif        Archive bytes.
     * @param int    $minPayloads Lower bound on the image count.
     *
     * @return list<string> The payloads, in index order.
     */
    protected function assertWalkableBifArchive(string $bif, int $minPayloads = 2): array
    {
        $this->assertSame(BifWriter::MAGIC, substr($bif, 0, 8), 'BIF magic');
        $this->assertSame(8, strlen(BifWriter::MAGIC), 'the magic is 8 bytes, not a 4-byte prefix');

        $payloads = $this->walkBifIndex($bif);
        $this->assertGreaterThanOrEqual($minPayloads, count($payloads));

        foreach ($payloads as $i => $payload) {
            $this->assertSame("\xFF\xD8", substr($payload, 0, 2), "BIF payload {$i} is not a JPEG");
            $this->assertSame("\xFF\xD9", substr($payload, -2), "BIF payload {$i} has no EOI marker");
            // Each payload must be a JPEG a decoder actually accepts, not merely
            // one that starts with the right two bytes.
            $this->assertIsArray(getimagesizefromstring($payload), "BIF payload {$i} is not decodable");
        }

        return $payloads;
    }

    /**
     * Independent BIF reader, written from the published spec.
     *
     * @param string $bif Archive bytes.
     *
     * @return list<string> Payloads in index order.
     */
    protected function walkBifIndex(string $bif): array
    {
        $this->assertGreaterThanOrEqual(64, strlen($bif));

        /** @var array{1: int} $countField */
        $countField = unpack('V', substr($bif, 12, 4));
        $count = $countField[1];
        $this->assertGreaterThan(0, $count);

        $offsets = [];
        for ($i = 0; $i <= $count; $i++) {
            /** @var array{1: int} $ts */
            $ts = unpack('V', substr($bif, 64 + 8 * $i, 4));
            /** @var array{1: int} $off */
            $off = unpack('V', substr($bif, 64 + 8 * $i + 4, 4));
            if ($i === $count) {
                $this->assertSame(0xFFFFFFFF, $ts[1], 'index terminator timestamp');
                $this->assertSame(strlen($bif), $off[1], 'terminator offset must be the file length');
            } else {
                $this->assertSame($i, $ts[1], "index entry {$i} timestamp");
            }
            $offsets[] = $off[1];
        }

        $payloads = [];
        for ($i = 0; $i < $count; $i++) {
            $this->assertGreaterThanOrEqual(64 + 8 * ($count + 1), $offsets[$i]);
            $this->assertGreaterThan($offsets[$i], $offsets[$i + 1]);
            $payloads[] = substr($bif, $offsets[$i], $offsets[$i + 1] - $offsets[$i]);
        }

        return $payloads;
    }
}
