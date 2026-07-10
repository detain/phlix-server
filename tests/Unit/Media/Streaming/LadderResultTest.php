<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\LadderResult;
use Phlix\Media\Streaming\Rendition;

/**
 * Unit tests for {@see LadderResult} — the immutable ladder output and its
 * de-duplicating {@see LadderResult::streamVariants()} / {@see LadderResult::toArray()}.
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

    public function testStreamVariantsPrependsNonCopyOriginalToo(): void
    {
        // The transcode (non-copy) Original is ALSO a distinct master variant now —
        // it is never dropped, so the client's "Original" pick is always playable.
        $r1080 = $this->rung('1080p', 1920, 1080);
        $r720 = $this->rung('720p', 1280, 720);
        $best = $this->bestAvailableOriginal();

        $variants = (new LadderResult([$r1080, $r720], $best))->streamVariants();

        self::assertSame([$best, $r1080, $r720], $variants);
        self::assertSame($best, $variants[0], 'transcode Original is prepended as the highest master variant');
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
}
