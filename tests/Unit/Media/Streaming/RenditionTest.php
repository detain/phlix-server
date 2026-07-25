<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Rendition;

/**
 * Unit tests for {@see Rendition} — the immutable ABR rung value object: its
 * derived encoder targets ({@see Rendition::maxrate()} / {@see Rendition::bufsize()}),
 * the advertised {@see Rendition::bandwidth()}, {@see Rendition::resolution()}, and
 * the snake_case {@see Rendition::toArray()} wire shape.
 */
final class RenditionTest extends TestCase
{
    public function testConstantsMatchContract(): void
    {
        self::assertSame(1.07, Rendition::MAXRATE_MULTIPLIER);
        self::assertSame(2, Rendition::BUFSIZE_MULTIPLIER);
        self::assertSame(128000, Rendition::AUDIO_BANDWIDTH);
        self::assertSame('mp4a.40.2', Rendition::AUDIO_CODEC);
    }

    public function testConstructorExposesReadonlyProperties(): void
    {
        $rendition = $this->fullHd();

        self::assertSame('1080p', $rendition->id);
        self::assertSame('1080p', $rendition->label);
        self::assertSame(1920, $rendition->width);
        self::assertSame(1080, $rendition->height);
        self::assertSame(5_478_000, $rendition->bitrate);
        self::assertSame(5_000_000, $rendition->videoBitrate);
        self::assertSame('avc1.640029,mp4a.40.2', $rendition->codecs);
        self::assertFalse($rendition->isOriginal);
        self::assertFalse($rendition->isCopy);
    }

    public function testBandwidthReturnsAdvertisedPeak(): void
    {
        self::assertSame(5_478_000, $this->fullHd()->bandwidth());
    }

    /**
     * `-maxrate` = round(videoBitrate * 1.07); units are bps.
     *
     * @param int<1, max> $videoBitrate
     * @param int<1, max> $expectedMaxrate
     */
    #[DataProvider('maxrateProvider')]
    public function testMaxrateIsSevenPercentHeadroomRounded(int $videoBitrate, int $expectedMaxrate): void
    {
        $rendition = new Rendition(
            id: 'x',
            label: 'x',
            width: 1920,
            height: 1080,
            bitrate: 999,
            videoBitrate: $videoBitrate,
            codecs: 'avc1.640029,mp4a.40.2',
            isOriginal: false,
            isCopy: false,
        );

        self::assertSame($expectedMaxrate, $rendition->maxrate());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function maxrateProvider(): iterable
    {
        yield '5_000_000 -> 5_350_000 (exact)' => [5_000_000, 5_350_000];
        yield '2_800_000 -> 2_996_000 (exact)' => [2_800_000, 2_996_000];
        yield '400_000 -> 428_000 (exact)' => [400_000, 428_000];
        yield '333_333 -> 356_666 (rounds .31 down)' => [333_333, 356_666];
        yield '900_000 -> 963_000' => [900_000, 963_000];
    }

    public function testBufsizeIsDoubleTheMaxrate(): void
    {
        $rendition = $this->fullHd();

        self::assertSame(5_350_000, $rendition->maxrate());
        self::assertSame(10_700_000, $rendition->bufsize());
        self::assertSame($rendition->maxrate() * 2, $rendition->bufsize());
    }

    public function testResolutionFormatsWidthByHeight(): void
    {
        self::assertSame('1920x1080', $this->fullHd()->resolution());
        self::assertSame(
            '426x240',
            (new Rendition('240p', '240p', 426, 240, 556_000, 400_000, 'avc1.64001E,mp4a.40.2', false, false))
                ->resolution(),
        );
    }

    public function testToArrayMirrorsWireContractWithSnakeCaseKeysAndNullUrl(): void
    {
        self::assertSame(
            [
                'id' => '1080p',
                'label' => '1080p',
                'width' => 1920,
                'height' => 1080,
                'bitrate' => 5_478_000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'url' => null,
                'is_original' => false,
                'is_copy' => false,
                'video_bitrate' => 5_000_000,
            ],
            $this->fullHd()->toArray(),
        );
    }

    public function testToArrayReflectsOriginalCopyFlags(): void
    {
        $copy = new Rendition(
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

        $array = $copy->toArray();

        self::assertTrue($array['is_original']);
        self::assertTrue($array['is_copy']);
        self::assertSame('Original (1080p)', $array['label']);
        self::assertNull($array['url']);
    }

    public function testConstantsIncludeTheAbrDuplicateTolerance(): void
    {
        self::assertSame(0.85, Rendition::ABR_DUPLICATE_TOLERANCE);
    }

    /**
     * S49 fix round 1 — `duplicatesForAbr()` is what keeps the master's ABR level
     * set free of two indistinguishable levels while still letting a genuinely
     * distinct transcode "Original" be advertised. Same frame + BANDWIDTH within
     * 15 % ⇒ duplicate; a different frame, or a ≥15 % bandwidth gap, ⇒ distinct.
     *
     * @param int<0, max> $bitrate
     */
    #[DataProvider('duplicateProvider')]
    public function testDuplicatesForAbrComparesFrameAndBandwidth(
        int $width,
        int $height,
        int $bitrate,
        bool $expected
    ): void {
        $candidate = new Rendition(
            id: 'original',
            label: 'Original',
            width: $width,
            height: $height,
            bitrate: $bitrate,
            videoBitrate: 5_000_000,
            codecs: 'avc1.640029,mp4a.40.2',
            isOriginal: true,
            isCopy: false,
        );

        // Symmetric in both directions — the tolerance is applied to the higher of
        // the two bandwidths, so neither argument order can flip the verdict.
        self::assertSame($expected, $candidate->duplicatesForAbr($this->fullHd()));
        self::assertSame($expected, $this->fullHd()->duplicatesForAbr($candidate));
    }

    /**
     * Reference rung: 1920×1080 @ 5,478,000. Tolerance floor = floor(5_478_000 *
     * 0.85) = 4,656,300.
     *
     * @return iterable<string, array{int, int, int, bool}>
     */
    public static function duplicateProvider(): iterable
    {
        yield 'identical frame + bandwidth' => [1920, 1080, 5_478_000, true];
        yield 'same frame, exactly at the tolerance floor' => [1920, 1080, 4_656_300, true];
        yield 'same frame, one bit below the floor' => [1920, 1080, 4_656_299, false];
        yield 'same frame, 10 Mbps original (a real distinct level)' => [1920, 1080, 10_000_000, false];
        yield 'same bandwidth, different height (DCI-2K original)' => [1920, 1012, 5_478_000, false];
        yield 'same bandwidth, different width' => [1440, 1080, 5_478_000, false];
        yield 'zero bandwidth is never a duplicate' => [1920, 1080, 0, false];
    }

    private function fullHd(): Rendition
    {
        return new Rendition(
            id: '1080p',
            label: '1080p',
            width: 1920,
            height: 1080,
            bitrate: 5_478_000,
            videoBitrate: 5_000_000,
            codecs: 'avc1.640029,mp4a.40.2',
            isOriginal: false,
            isCopy: false,
        );
    }
}
