<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\LadderResult;
use Phlix\Media\Streaming\Rendition;

/**
 * Unit tests for {@see LadderResult} — the immutable ladder output and its
 * {@see LadderResult::streamVariants()} / {@see LadderResult::toArray()} views.
 */
final class LadderResultTest extends TestCase
{
    public function testConstructorExposesRenditionsAndOriginal(): void
    {
        $rungs = [$this->rung('1080p', 1920, 1080), $this->rung('720p', 1280, 720)];
        $original = $this->copyOriginal();

        $result = new LadderResult($rungs, $original);

        self::assertSame($rungs, $result->renditions);
        self::assertSame($original, $result->original);
    }

    public function testStreamVariantsPrependsCopyOriginalExactlyOnce(): void
    {
        $r1080 = $this->rung('1080p', 1920, 1080);
        $r720 = $this->rung('720p', 1280, 720);
        $copy = $this->copyOriginal();

        $variants = (new LadderResult([$r1080, $r720], $copy))->streamVariants();

        self::assertSame([$copy, $r1080, $r720], $variants);
        self::assertCount(3, $variants);
        self::assertSame($copy, $variants[0], 'copy Original is the highest master variant');
    }

    public function testStreamVariantsPrependsDistinctNonCopyOriginal(): void
    {
        // A transcode (non-copy) Original that is DISTINCT from the top rung (here
        // a genuinely higher BANDWIDTH at the same frame — a normal-bitrate HEVC
        // source whose re-encode sits above its 1080p rung) is prepended as the
        // highest master variant, so the client's "Original" pick stays playable.
        $r1080 = $this->rung('1080p', 1920, 1080);          // 5_478_000
        $r720 = $this->rung('720p', 1280, 720);
        $best = $this->distinctNonCopyOriginal();            // 1920x1080 @ 8_688_000

        $variants = (new LadderResult([$r1080, $r720], $best))->streamVariants();

        self::assertSame([$best, $r1080, $r720], $variants);
        self::assertSame($best, $variants[0], 'a distinct transcode Original is the highest master variant');
    }

    public function testStreamVariantsKeepsNonCopyOriginalThatDuplicatesTopRung(): void
    {
        // S49 (inverted from the pre-v9 assertion): a re-encoded (non-copy) Original
        // whose frame AND BANDWIDTH coincide with the top rung — the low-bitrate
        // source-cap collapse, e.g. a 1.2 Mbps HEVC/AC-3 1080p title — is NO LONGER
        // folded away. It stays a first-class variant so it gets its own
        // media_voriginal.m3u8 and "Original" is selectable for exactly the titles
        // that used to 404 on it. The duplicate-BANDWIDTH problem the old fold
        // existed to solve is now handled downstream, by keeping a DUPLICATE
        // `original` out of the MASTER's switchable ABR set only (TranscodeManager's
        // SV-4.6 filter / switchableVariants()) — a non-duplicate one is still a
        // legitimate master level.
        $r1080 = $this->rung('1080p', 1920, 1080);           // 5_478_000
        $r720 = $this->rung('720p', 1280, 720);
        $dupe = $this->bestAvailableOriginal();              // 1920x1080 @ 5_478_000

        $variants = (new LadderResult([$r1080, $r720], $dupe))->streamVariants();

        self::assertSame([$dupe, $r1080, $r720], $variants, 'the Original is never dropped');
        self::assertSame($dupe, $variants[0]);
        self::assertSame(
            $r1080->bitrate,
            $dupe->bitrate,
            'the fixture really is the duplicate-BANDWIDTH case the old fold removed',
        );
    }

    public function testStreamVariantsAlwaysPrependsOriginalWhateverTheLadder(): void
    {
        // Belt-and-braces on the S49 invariant: whatever the rung set, the emitted
        // variant list is exactly [original, ...renditions] — no filtering step may
        // creep back into this method (it decides which variants EXIST, and every
        // entry gets a media playlist written for it).
        $cases = [
            'duplicate bandwidth' => $this->bestAvailableOriginal(),
            'distinct bandwidth' => $this->distinctNonCopyOriginal(),
            'stream copy' => $this->copyOriginal(),
        ];
        $rungs = [$this->rung('1080p', 1920, 1080), $this->rung('720p', 1280, 720)];

        foreach ($cases as $label => $original) {
            $variants = (new LadderResult($rungs, $original))->streamVariants();

            self::assertSame([$original, ...$rungs], $variants, $label);
            self::assertCount(3, $variants, $label);
        }
    }

    public function testStreamVariantsKeepsCopyOriginalEvenWhenItMatchesTopRung(): void
    {
        // A stream-COPY Original is a genuinely distinct passthrough (different
        // bytes, no transcode) and is NEVER folded, even when its frame/BANDWIDTH
        // coincide with the top rung.
        $r1080 = $this->rung('1080p', 1920, 1080);           // 5_478_000
        $copy = new Rendition(
            id: 'original',
            label: 'Original (1080p)',
            width: 1920,
            height: 1080,
            bitrate: 5_478_000,
            videoBitrate: 5_000_000,
            codecs: 'avc1.640029,mp4a.40.2',
            isOriginal: true,
            isCopy: true,
        );

        $variants = (new LadderResult([$r1080], $copy))->streamVariants();

        self::assertSame([$copy, $r1080], $variants);
    }

    public function testToArrayNestsRenditionsAndOriginal(): void
    {
        $result = new LadderResult(
            [$this->rung('1080p', 1920, 1080)],
            $this->bestAvailableOriginal(),
        );

        $array = $result->toArray();

        self::assertArrayHasKey('renditions', $array);
        self::assertArrayHasKey('original', $array);
        self::assertCount(1, $array['renditions']);
        self::assertSame('1080p', $array['renditions'][0]['id']);
        self::assertSame('original', $array['original']['id']);
        self::assertTrue($array['original']['is_original']);
        self::assertFalse($array['original']['is_copy']);
        self::assertNull($array['renditions'][0]['url']);
    }

    private function rung(string $id, int $width, int $height): Rendition
    {
        return new Rendition(
            id: $id,
            label: $id,
            width: $width,
            height: $height,
            bitrate: 5_478_000,
            videoBitrate: 5_000_000,
            codecs: 'avc1.640029,mp4a.40.2',
            isOriginal: false,
            isCopy: false,
        );
    }

    private function copyOriginal(): Rendition
    {
        return new Rendition(
            id: 'original',
            label: 'Original (1080p)',
            width: 1920,
            height: 1080,
            bitrate: 8_192_000,
            videoBitrate: 8_000_000,
            codecs: 'avc1.640029,mp4a.40.2',
            isOriginal: true,
            isCopy: true,
        );
    }

    private function bestAvailableOriginal(): Rendition
    {
        return new Rendition(
            id: 'original',
            label: 'Original (best available)',
            width: 1920,
            height: 1080,
            bitrate: 5_478_000,
            videoBitrate: 5_000_000,
            codecs: 'avc1.640029,mp4a.40.2',
            isOriginal: true,
            isCopy: false,
        );
    }

    private function distinctNonCopyOriginal(): Rendition
    {
        return new Rendition(
            id: 'original',
            label: 'Original (1080p)',
            width: 1920,
            height: 1080,
            bitrate: 8_688_000,
            videoBitrate: 8_000_000,
            codecs: 'avc1.640029,mp4a.40.2',
            isOriginal: true,
            isCopy: false,
        );
    }
}
