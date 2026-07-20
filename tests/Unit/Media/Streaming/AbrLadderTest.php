<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\AbrLadder;
use Phlix\Media\Streaming\LadderResult;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Streaming\Rendition;
use Phlix\Media\Streaming\SourceProfile;

/**
 * Exhaustive unit tests for {@see AbrLadder} — the pure, deterministic ABR ladder
 * builder. Covers ladder generation across a wide source matrix (SD..4K, h264 vs
 * hevc, anamorphic/DCI/ultrawide shapes), the no-upscale + source-bitrate +
 * device-profile clamps, highest-first ordering, the "Original" (D4) copy vs
 * best-available decision, and the H.264 macroblock-derived level strings (the
 * fixed MAJOR: no under-declaration on any anamorphic frame).
 *
 * All expected numbers are the deterministic output of the implementation under
 * test (verified against the documented contract in the class docblock).
 */
final class AbrLadderTest extends TestCase
{
    private const AUDIO = 'mp4a.40.2';

    /**
     * H.264 High-profile level => MaxFS (macroblocks), mirroring AbrLadder's table.
     * Used to prove every advertised level legally covers its frame (no
     * under-declaration).
     *
     * @var array<string, int>
     */
    private const LEVEL_MAXFS = [
        'avc1.64001E' => 1620,   // 3.0
        'avc1.64001F' => 3600,   // 3.1
        'avc1.640020' => 5120,   // 3.2
        'avc1.640029' => 8192,   // 4.1
        'avc1.64002A' => 8704,   // 4.2
        'avc1.640032' => 22080,  // 5.0
        'avc1.640033' => 36864,  // 5.1
    ];

    /**
     * Named device profiles => [maxWidth, maxHeight, maxBitrate].
     *
     * @var array<string, array{int, int, int}>
     */
    private const PROFILE_CAPS = [
        'generic' => [3840, 2160, 100_000_000],
        'mobile-low' => [854, 480, 1_500_000],
        'mobile-high' => [1280, 720, 4_000_000],
        'web' => [1920, 1080, 10_000_000],
        'tv-4k' => [3840, 2160, 50_000_000],
    ];

    private AbrLadder $ladder;

    protected function setUp(): void
    {
        $this->ladder = new AbrLadder();
    }

    // -----------------------------------------------------------------
    // Golden canonical numbers
    // -----------------------------------------------------------------

    public function testCanonicalFullHdLadderProducesExactRungs(): void
    {
        $result = $this->ladder->build(new SourceProfile(1920, 1080, 'h264'), 'generic');

        self::assertCount(5, $result->renditions);

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
            $result->renditions[0]->toArray(),
        );

        self::assertSame(
            [
                'id' => '240p',
                'label' => '240p',
                'width' => 426,
                'height' => 240,
                'bitrate' => 556_000,
                'codecs' => 'avc1.64001E,mp4a.40.2',
                'url' => null,
                'is_original' => false,
                'is_copy' => false,
                'video_bitrate' => 400_000,
            ],
            $result->renditions[4]->toArray(),
        );
    }

    // -----------------------------------------------------------------
    // #7 — H.264 macroblock-derived codec level (the fixed MAJOR)
    // -----------------------------------------------------------------

    /**
     * The top rung of a full-height source advertises the LOWEST legal H.264 level
     * for its (possibly anamorphic) frame — never an under-declared level.
     *
     * @param int<2, max>      $width
     * @param int<2, max>      $height
     * @param non-empty-string $expectedVideoCodec
     */
    #[DataProvider('topRungCodecProvider')]
    public function testTopRungAdvertisesLowestLegalH264Level(
        int $width,
        int $height,
        string $expectedVideoCodec,
    ): void {
        $top = $this->ladder->build(new SourceProfile($width, $height, 'h264'), 'generic')->renditions[0];

        self::assertSame($width, $top->width, 'anamorphic width is preserved from the source aspect');
        self::assertSame($height, $top->height);
        self::assertSame($expectedVideoCodec . ',' . self::AUDIO, $top->codecs);
        self::assertSame(self::AUDIO, self::audioCodecOf($top), 'AAC-LC audio is always advertised');
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function topRungCodecProvider(): iterable
    {
        // 1920x1080 -> 8160 MB -> L4.1
        yield '1920x1080 -> L4.1' => [1920, 1080, 'avc1.640029'];
        // 2048x1080 (DCI-2K) -> 8704 MB -> L4.2 (would under-declare as L4.1 by height alone)
        yield '2048x1080 DCI-2K -> L4.2' => [2048, 1080, 'avc1.64002A'];
        // 2560x1080 (ultrawide) -> 10880 MB -> L5.0
        yield '2560x1080 ultrawide -> L5.0' => [2560, 1080, 'avc1.640032'];
        // 1280x720 -> 3600 MB -> L3.1
        yield '1280x720 -> L3.1' => [1280, 720, 'avc1.64001F'];
        // 2560x1440 -> 14400 MB -> L5.0
        yield '2560x1440 -> L5.0' => [2560, 1440, 'avc1.640032'];
        // 3840x2160 -> 32400 MB -> L5.1
        yield '3840x2160 -> L5.1' => [3840, 2160, 'avc1.640033'];
    }

    // -----------------------------------------------------------------
    // #1/#2/#5/#7 — Ladder invariants across a wide source matrix (generic)
    // -----------------------------------------------------------------

    /**
     * @param int<2, max> $srcWidth
     * @param int<2, max> $srcHeight
     */
    #[DataProvider('sourceSweepProvider')]
    public function testLadderInvariantsHoldForEverySource(int $srcWidth, int $srcHeight): void
    {
        $result = $this->ladder->build(new SourceProfile($srcWidth, $srcHeight, 'h264'), 'generic');
        $rungs = $result->renditions;

        self::assertGreaterThanOrEqual(1, count($rungs), 'always at least one rung');
        self::assertSame($rungs[0], $result->renditions[0]);

        $previousHeight = PHP_INT_MAX;
        $previousBandwidth = PHP_INT_MAX;
        foreach ($rungs as $rung) {
            // No upscale (heights >= 240 in this matrix so the min-2 floor never applies).
            self::assertLessThanOrEqual($srcHeight, $rung->height, 'no vertical upscale');
            self::assertLessThanOrEqual($srcWidth, $rung->width, 'no horizontal upscale');
            self::assertLessThanOrEqual(3840, $rung->width, 'never exceeds generic max width');
            self::assertLessThanOrEqual(2160, $rung->height, 'never exceeds generic max height');

            // Even dimensions >= 2.
            self::assertSame(0, $rung->width % 2, 'width is even');
            self::assertSame(0, $rung->height % 2, 'height is even');
            self::assertGreaterThanOrEqual(2, $rung->width);
            self::assertGreaterThanOrEqual(2, $rung->height);

            // Strictly highest-first + monotonic non-increasing bandwidth.
            self::assertLessThan($previousHeight, $rung->height, 'strictly highest-first ordering');
            self::assertLessThanOrEqual($previousBandwidth, $rung->bandwidth(), 'bandwidth non-increasing');
            $previousHeight = $rung->height;
            $previousBandwidth = $rung->bandwidth();

            $this->assertRungSane($rung);
        }

        self::assertTrue($result->original->isOriginal);
    }

    /**
     * Source-height tiers (h264 vs hevc identical for rung geometry) + anamorphic /
     * DCI / ultrawide / portrait / pathological shapes.
     *
     * @return iterable<string, array{int, int}>
     */
    public static function sourceSweepProvider(): iterable
    {
        yield '2160p 3840x2160' => [3840, 2160];
        yield '1440p 2560x1440' => [2560, 1440];
        yield '1080p 1920x1080' => [1920, 1080];
        yield '720p 1280x720' => [1280, 720];
        yield '480p 854x480' => [854, 480];
        yield '240p 426x240' => [426, 240];
        yield 'sub-240 320x180' => [320, 180];
        yield 'DCI-2K 2048x1080' => [2048, 1080];
        yield 'ultrawide 2560x1080' => [2560, 1080];
        yield 'UHD 2.40:1 3840x1608' => [3840, 1608];
        yield '2.35:1 1920x816' => [1920, 816];
        yield 'DCI flat 1998x1080' => [1998, 1080];
        yield 'DCI-4K wider-than-cap 4096x2160' => [4096, 2160];
        yield 'odd width 1279x720' => [1279, 720];
        yield 'portrait 1000x1500' => [1000, 1500];
        yield 'pathological wide 5000x100' => [5000, 100];
    }

    /**
     * The hevc matrix yields byte-identical rung geometry/codecs to h264 (only the
     * Original decision differs); proves rung codecs are the transcode target, not
     * the source codec.
     *
     * @param int<2, max> $srcWidth
     * @param int<2, max> $srcHeight
     */
    #[DataProvider('sourceSweepProvider')]
    public function testHevcSourceProducesIdenticalH264Rungs(int $srcWidth, int $srcHeight): void
    {
        $h264 = $this->ladder->build(new SourceProfile($srcWidth, $srcHeight, 'h264'), 'generic');
        $hevc = $this->ladder->build(new SourceProfile($srcWidth, $srcHeight, 'hevc'), 'generic');

        self::assertSame(
            array_map(static fn (Rendition $r): array => $r->toArray(), $h264->renditions),
            array_map(static fn (Rendition $r): array => $r->toArray(), $hevc->renditions),
        );
    }

    // -----------------------------------------------------------------
    // #2 — No upscale: odd source width clamps DOWN, never up
    // -----------------------------------------------------------------

    public function testOddSourceWidthClampsDownToEvenNeverUp(): void
    {
        $result = $this->ladder->build(new SourceProfile(1279, 720, 'h264'), 'generic');
        $top = $result->renditions[0];

        self::assertSame(720, $top->height);
        self::assertSame(1278, $top->width, '1279 clamps to 1278 (even floor), never rounds up to 1280');
        self::assertLessThanOrEqual(1279, $top->width);
    }

    // -----------------------------------------------------------------
    // #3 — Source-bitrate clamp
    // -----------------------------------------------------------------

    public function testEveryRungIsCappedAtKnownSourceVideoBitrate(): void
    {
        $result = $this->ladder->build(new SourceProfile(854, 480, 'h264', 900_000, 'aac', 128_000), 'generic');

        self::assertNotSame([], $result->renditions);
        foreach ($result->renditions as $rung) {
            self::assertLessThanOrEqual(900_000, $rung->videoBitrate, 'no rung claims more than the source has');
        }

        // The 480p tier target (1.4 Mbps) is clamped to the 900 kbps source, which
        // drags its BANDWIDTH (1_091_000) to within ~11 % of the 360p tier
        // (984_000) — too close to be a distinct ABR choice — so the 360p rung is
        // pruned by the monotonic-gradient guard. What survives is the
        // highest-resolution rung of that bandwidth cluster (480p) plus the
        // genuinely-lower 240p tier: a real descending gradient, no duplicates.
        self::assertSame([480, 240], self::heights($result));
        self::assertSame(900_000, $result->renditions[0]->videoBitrate);
        self::assertSame(400_000, $result->renditions[1]->videoBitrate);
        self::assertDescendingDistinctBandwidths($result->renditions);
    }

    // -----------------------------------------------------------------
    // Monotonic BANDWIDTH gradient + native-rung de-dup (the fix)
    // -----------------------------------------------------------------

    /**
     * A LOW-bitrate (~1.2 Mbps) 1080p HEVC source: the source-bitrate cap
     * collapses the upper rungs toward one identical BANDWIDTH, and the
     * re-encoded (non-copy) Original duplicates the top rung. The ladder must NOT
     * advertise several identical-BANDWIDTH variants — it must fold the native
     * rung and prune any collapsed rungs to a genuine descending gradient.
     *
     * The clamp budget is the source's H.264 EQUIVALENT (1.2 Mbps HEVC x1.5 =
     * 1.8 Mbps), not its raw figure: every rung is re-encoded to H.264, so
     * clamping to 1.2 Mbps would spend the codec-generation gap as quality loss.
     * The extra headroom also keeps 480p genuinely distinct from 1080p instead of
     * collapsing onto it, so the gradient degrades in steps rather than falling
     * off a 1080p -> 360p cliff.
     */
    public function testLowBitrate1080pSourceCollapsesToDistinctGradient(): void
    {
        $result = $this->ladder->build(
            new SourceProfile(1920, 1080, 'hevc', 1_200_000, 'aac', 128_000),
            'generic',
        );

        self::assertSame([1080, 480, 360, 240], self::heights($result));
        foreach ($result->renditions as $rung) {
            self::assertLessThanOrEqual(
                1_800_000,
                $rung->videoBitrate,
                'no rung exceeds the H.264-equivalent source budget',
            );
        }
        self::assertDescendingDistinctBandwidths($result->renditions);

        // No two RETAINED rungs share a BANDWIDTH.
        $bandwidths = array_map(static fn (Rendition $r): int => $r->bandwidth(), $result->renditions);
        self::assertSame($bandwidths, array_unique($bandwidths), 'no two rungs share a BANDWIDTH');

        // The non-copy Original re-encode is byte-identical to the 1080p rung, so
        // the master emits ONE 1080p variant, not a duplicate native+1080p pair.
        self::assertFalse($result->original->isCopy, 'HEVC source → transcode Original');
        $variants = $result->streamVariants();
        self::assertSame([1080, 480, 360, 240], array_map(static fn (Rendition $r): int => $r->height, $variants));
        $variantBandwidths = array_map(static fn (Rendition $r): int => $r->bandwidth(), $variants);
        self::assertSame($variantBandwidths, array_unique($variantBandwidths), 'master has no duplicate BANDWIDTH');
        self::assertSame(
            $variantBandwidths,
            self::sortedDescending($variantBandwidths),
            'master is strictly descending',
        );
    }

    /**
     * A NORMAL-bitrate (~8 Mbps) 1080p HEVC source: the full canonical ladder is
     * preserved (its rungs are already far apart) and the distinct
     * higher-bandwidth Original sits above it — the fix must NOT strip a healthy
     * ladder.
     */
    public function testNormalBitrate1080pSourceKeepsFullLadder(): void
    {
        $result = $this->ladder->build(
            new SourceProfile(1920, 1080, 'hevc', 8_000_000, 'aac', 192_000),
            'generic',
        );

        // Full descending ladder, untouched by the gradient prune.
        self::assertSame([1080, 720, 480, 360, 240], self::heights($result));
        self::assertDescendingDistinctBandwidths($result->renditions);

        // The transcode Original (~8 Mbps) is genuinely above the 1080p rung
        // (~5 Mbps) → kept as a distinct highest master variant.
        self::assertFalse($result->original->isCopy);
        $variants = $result->streamVariants();
        self::assertSame(
            [1080, 1080, 720, 480, 360, 240],
            array_map(static fn (Rendition $r): int => $r->height, $variants),
            'Original (1080p) + full rung ladder',
        );
        $variantBandwidths = array_map(static fn (Rendition $r): int => $r->bandwidth(), $variants);
        self::assertSame($variantBandwidths, array_unique($variantBandwidths), 'master has no duplicate BANDWIDTH');
        self::assertSame(
            $variantBandwidths,
            self::sortedDescending($variantBandwidths),
            'master is strictly descending',
        );
    }

    /**
     * A small (480p) source is never upscaled and its retained rungs still have
     * distinct, strictly-descending BANDWIDTHs.
     */
    public function testSmall480pSourceStaysAtOrBelowSourceResolutionWithDistinctBandwidths(): void
    {
        $result = $this->ladder->build(
            new SourceProfile(854, 480, 'hevc', 5_000_000, 'aac', 128_000),
            'generic',
        );

        foreach ($result->renditions as $rung) {
            self::assertLessThanOrEqual(480, $rung->height, 'never upscales beyond the 480p source');
        }
        self::assertSame(480, $result->renditions[0]->height, 'top rung is the source resolution');
        self::assertDescendingDistinctBandwidths($result->renditions);
    }

    // -----------------------------------------------------------------
    // #4/#9 — Device-profile caps
    // -----------------------------------------------------------------

    /**
     * @param non-empty-string $profileName
     * @param list<int>        $expectedHeights
     */
    #[DataProvider('profileCapProvider')]
    public function testProfileCapsAreRespected(string $profileName, array $expectedHeights): void
    {
        [$maxWidth, $maxHeight, $maxBitrate] = self::PROFILE_CAPS[$profileName];

        // A 4K source so the profile is the only binding constraint; null bitrate
        // so rungs hit their canonical targets before the profile clamp.
        $result = $this->ladder->build(new SourceProfile(3840, 2160, 'h264', null, 'aac'), $profileName);

        self::assertSame(
            $expectedHeights,
            array_map(static fn (Rendition $r): int => $r->height, $result->renditions),
        );

        foreach ($result->renditions as $rung) {
            self::assertLessThanOrEqual($maxHeight, $rung->height, 'height within profile max_resolution');
            self::assertLessThanOrEqual($maxWidth, $rung->width, 'width within profile max_resolution');
            self::assertLessThanOrEqual($maxBitrate, $rung->bandwidth(), 'BANDWIDTH within profile max_bitrate');
            $this->assertRungSane($rung);
        }
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function profileCapProvider(): iterable
    {
        yield 'generic 2160p' => ['generic', [2160, 1440, 1080, 720, 480, 360, 240]];
        yield 'mobile-low 480p' => ['mobile-low', [480, 360, 240]];
        yield 'mobile-high 720p' => ['mobile-high', [720, 480, 360, 240]];
        yield 'web 1080p' => ['web', [1080, 720, 480, 360, 240]];
        yield 'tv-4k 2160p' => ['tv-4k', [2160, 1440, 1080, 720, 480, 360, 240]];
    }

    public function testMobileLowBandwidthStaysUnderOnePointFiveMbps(): void
    {
        $result = $this->ladder->build(new SourceProfile(3840, 2160, 'h264'), 'mobile-low');

        self::assertSame([480, 360, 240], array_map(static fn (Rendition $r): int => $r->height, $result->renditions));
        // 480p is video-ceiling clamped so maxrate + audio == 1_499_999 <= 1_500_000.
        self::assertSame(1_499_999, $result->renditions[0]->bandwidth());
        self::assertLessThanOrEqual(1_500_000, $result->renditions[0]->bandwidth());
    }

    public function testUnknownProfileNameFallsBackToGeneric(): void
    {
        $source = new SourceProfile(3840, 2160, 'h264', 20_000_000, 'aac', 256_000);

        $generic = $this->ladder->build($source, 'generic');
        $unknown = $this->ladder->build($source, 'totally-made-up-profile');

        self::assertSame($generic->toArray(), $unknown->toArray(), 'unknown profile == generic, no crash');
    }

    public function testNarrowProfileDropsRungsWhoseAnamorphicWidthExceedsMaxWidth(): void
    {
        // 21:9 ultrawide on a 854-wide profile: the 480p tier would be 1138px wide
        // (> 854) so it is dropped; the highest that fits is the 360p tier at 854px.
        $result = $this->ladder->build(new SourceProfile(2560, 1080, 'h264'), 'mobile-low');

        self::assertSame([360, 240], array_map(static fn (Rendition $r): int => $r->height, $result->renditions));
        self::assertSame(854, $result->renditions[0]->width);
        foreach ($result->renditions as $rung) {
            self::assertLessThanOrEqual(854, $rung->width);
        }
    }

    // -----------------------------------------------------------------
    // #8 — Unknown source dimensions cap conservatively at 1080p
    // -----------------------------------------------------------------

    /**
     * @param non-empty-string $profileName
     * @param list<int>        $expectedHeights
     */
    #[DataProvider('unknownDimsProvider')]
    public function testUnknownDimensionsCapAtProfileButNeverAbove1080(
        string $profileName,
        array $expectedHeights,
    ): void {
        $result = $this->ladder->build(new SourceProfile(null, null, 'h264', null, 'aac'), $profileName);

        self::assertSame(
            $expectedHeights,
            array_map(static fn (Rendition $r): int => $r->height, $result->renditions),
        );
        self::assertNotContains(1440, $expectedHeights);
        self::assertNotContains(2160, $expectedHeights);

        // Copy Original is suppressed when dimensions are unknown — the transcode
        // Original falls back to the top rung's frame (still a distinct variant).
        self::assertFalse($result->original->isCopy);
        self::assertSame(sprintf('Original (%dp)', $expectedHeights[0]), $result->original->label);
        self::assertSame($expectedHeights[0], $result->original->height);
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function unknownDimsProvider(): iterable
    {
        yield 'generic caps 1080p' => ['generic', [1080, 720, 480, 360, 240]];
        yield 'mobile-low still 480p' => ['mobile-low', [480, 360, 240]];
        yield 'mobile-high still 720p' => ['mobile-high', [720, 480, 360, 240]];
        yield 'web 1080p' => ['web', [1080, 720, 480, 360, 240]];
        yield 'tv-4k capped to 1080p (not 2160)' => ['tv-4k', [1080, 720, 480, 360, 240]];
    }

    // -----------------------------------------------------------------
    // #6/#7 — "Original" descriptor (D4)
    // -----------------------------------------------------------------

    /**
     * @param non-empty-string $profileName
     * @param non-empty-string $expectedLabel
     * @param non-empty-string $expectedCodecs
     */
    #[DataProvider('originalProvider')]
    public function testOriginalDescriptorDecision(
        SourceProfile $source,
        string $profileName,
        bool $expectedIsCopy,
        string $expectedLabel,
        int $expectedWidth,
        int $expectedHeight,
        int $expectedBandwidth,
        string $expectedCodecs,
    ): void {
        $original = $this->ladder->build($source, $profileName)->original;

        self::assertTrue($original->isOriginal);
        self::assertSame($expectedIsCopy, $original->isCopy);
        self::assertSame($expectedLabel, $original->label);
        self::assertSame($expectedWidth, $original->width);
        self::assertSame($expectedHeight, $original->height);
        self::assertSame($expectedBandwidth, $original->bandwidth());
        self::assertSame($expectedCodecs, $original->codecs);
        self::assertSame('original', $original->id);
    }

    /**
     * @return iterable<string, array{SourceProfile, string, bool, string, int, int, int, string}>
     */
    public static function originalProvider(): iterable
    {
        $codec1080 = 'avc1.640029,mp4a.40.2';

        yield 'h264+aac fits generic -> copy at source' => [
            new SourceProfile(1920, 1080, 'h264', 8_000_000, 'aac', 192_000),
            'generic', true, 'Original (1080p)', 1920, 1080, 8_192_000, $codec1080,
        ];

        yield 'h264+aac fits web -> copy at source' => [
            new SourceProfile(1920, 1080, 'h264', 8_000_000, 'aac', 192_000),
            'web', true, 'Original (1080p)', 1920, 1080, 8_192_000, $codec1080,
        ];

        yield 'h264+aac unknown bitrate -> copy (canonical estimate)' => [
            new SourceProfile(1920, 1080, 'h264', null, 'aac', null),
            'generic', true, 'Original (1080p)', 1920, 1080, 5_128_000, $codec1080,
        ];

        // Known video bitrate but unknown audio bitrate: the fit check and the copy
        // bandwidth both fall back to the 128 kbps audio allowance.
        yield 'h264+aac known video, unknown audio -> copy' => [
            new SourceProfile(1920, 1080, 'h264', 8_000_000, 'aac', null),
            'generic', true, 'Original (1080p)', 1920, 1080, 8_128_000, $codec1080,
        ];

        // Non-copy sources now yield a TRANSCODE Original at source resolution +
        // source-ish bitrate (bandwidth = round(vb*1.07) + 128k, capped at profile).
        // A TRANSCODED original re-encodes to H.264, so an HEVC source's bitrate is
        // translated to its H.264 equivalent (x1.5) first — 8 Mbps HEVC needs
        // ~12 Mbps of H.264 to survive the transcode, giving
        // round(12e6*1.07)+128k = 12,968,000. Contrast the h264 row below, which
        // re-encodes same-codec (factor 1.0) and keeps the raw 8 Mbps figure.
        yield 'hevc video -> transcode original at h264-equivalent bitrate' => [
            new SourceProfile(1920, 1080, 'hevc', 8_000_000, 'aac', 192_000),
            'generic', false, 'Original (1080p)', 1920, 1080, 12_968_000, $codec1080,
        ];

        yield 'non-aac audio (ac3) -> transcode original' => [
            new SourceProfile(1920, 1080, 'h264', 8_000_000, 'ac3', 192_000),
            'generic', false, 'Original (1080p)', 1920, 1080, 8_688_000, $codec1080,
        ];

        // 12 Mbps source over web's 10 Mbps cap: copy original is still valid
        // since stream copy bypasses the profile bitrate cap (no transcode).
        yield 'h264+aac over bitrate cap on web -> copy original (bypasses cap)' => [
            new SourceProfile(1920, 1080, 'h264', 12_000_000, 'aac', 192_000),
            'web', true, 'Original (1080p)', 1920, 1080, 12_192_000, $codec1080,
        ];

        yield 'same 12Mbps source fits generic -> copy' => [
            new SourceProfile(1920, 1080, 'h264', 12_000_000, 'aac', 192_000),
            'generic', true, 'Original (1080p)', 1920, 1080, 12_192_000, $codec1080,
        ];

        // 4K source on web: the transcode Original clamps the SOURCE frame to the
        // profile cap (aspect preserved) → 1920×1080, canonical 1080p bitrate.
        yield 'over resolution cap (4K on web) -> transcode original clamped to cap' => [
            new SourceProfile(3840, 2160, 'h264', null, 'aac'),
            'web', false, 'Original (1080p)', 1920, 1080, 5_478_000, $codec1080,
        ];

        yield 'DCI-2K h264+aac fits generic -> copy at L4.2' => [
            new SourceProfile(2048, 1080, 'h264', 9_000_000, 'aac', 192_000),
            'generic', true, 'Original (1080p)', 2048, 1080, 9_192_000, 'avc1.64002A,mp4a.40.2',
        ];

        // DCI-2K over web's 1920 width cap: the SOURCE frame is scaled to fit
        // (2048×1080 → 1920×1012, aspect preserved, even-floored), keeping the
        // source's 9 Mbps → bandwidth 9,630,000 + 128,000 = 9,758,000.
        yield 'DCI-2K over width cap on web -> transcode original scaled to fit' => [
            new SourceProfile(2048, 1080, 'h264', 9_000_000, 'aac', 192_000),
            'web', false, 'Original (1012p)', 1920, 1012, 9_758_000, $codec1080,
        ];

        yield 'null dims -> copy suppressed, transcode original at top rung frame' => [
            new SourceProfile(null, null, 'h264', null, 'aac'),
            'generic', false, 'Original (1080p)', 1920, 1080, 5_478_000, $codec1080,
        ];
    }

    public function testCopyOriginalCodecLevelIsComputedFromTrueSourceDims(): void
    {
        // 2049px true width -> L5.0 (8772 MB), even though the advertised RESOLUTION
        // is floored to 2048; proves the copy level covers the real frame and never
        // under-declares because of the even-floor.
        $original = $this->ladder->build(
            new SourceProfile(2049, 1080, 'h264', 9_000_000, 'aac', 192_000),
            'generic',
        )->original;

        self::assertTrue($original->isCopy);
        self::assertSame(2048, $original->width, 'advertised width is even-floored, never overstates the frame');
        self::assertSame('avc1.640032,mp4a.40.2', $original->codecs, 'level derived from the true 2049px frame');
    }

    // -----------------------------------------------------------------
    // #10 — streamVariants() de-duplication
    // -----------------------------------------------------------------

    public function testStreamVariantsPrependsCopyOriginalOnce(): void
    {
        $result = $this->ladder->build(
            new SourceProfile(1920, 1080, 'h264', 8_000_000, 'aac', 192_000),
            'generic',
        );

        self::assertTrue($result->original->isCopy);
        $variants = $result->streamVariants();

        self::assertCount(count($result->renditions) + 1, $variants);
        self::assertSame($result->original, $variants[0]);
        // The copy Original is a distinct highest variant, not a duplicate of a rung.
        self::assertNotContains($result->original, $result->renditions);
    }

    public function testStreamVariantsPrependsNonCopyOriginalToo(): void
    {
        // A transcoding (non-copy) source ALSO gets a distinct "original" master
        // variant — a transcode at source resolution — so the client's Original
        // choice is always playable, never silently dropped.
        $result = $this->ladder->build(
            new SourceProfile(1920, 1080, 'hevc', 8_000_000, 'aac', 192_000),
            'generic',
        );

        self::assertFalse($result->original->isCopy);
        $variants = $result->streamVariants();
        self::assertCount(count($result->renditions) + 1, $variants);
        self::assertSame($result->original, $variants[0]);
        self::assertSame('original', $variants[0]->id);
        self::assertNotContains($result->original, $result->renditions);
    }

    // -----------------------------------------------------------------
    // #1 (always >=1 rung) — sub-240 and degenerate dimensions
    // -----------------------------------------------------------------

    public function testSubTwoFortySourceYieldsSingleClampedRung(): void
    {
        $result = $this->ladder->build(new SourceProfile(320, 180, 'h264'), 'generic');

        self::assertCount(1, $result->renditions);
        $only = $result->renditions[0];
        self::assertSame(320, $only->width);
        self::assertSame(180, $only->height);
        self::assertSame(400_000, $only->videoBitrate, 'lowest fallback target (400 kbps)');
        $this->assertRungSane($only);
    }

    public function testTinyDimensionsClampToMinimumTwoPixels(): void
    {
        $result = $this->ladder->build(new SourceProfile(1, 1, 'h264'), 'generic');

        self::assertCount(1, $result->renditions);
        self::assertSame(2, $result->renditions[0]->width, 'width floored to the 2px minimum');
        self::assertSame(2, $result->renditions[0]->height, 'height floored to the 2px minimum');
        $this->assertRungSane($result->renditions[0]);
    }

    public function testPathologicalWideSourceFallbackClampsToProfileWidth(): void
    {
        // 50:1 sub-240 source: the single fallback rung is width-reduced to fit the
        // 3840px generic max width (exercises the fallback width-driven clamp).
        $result = $this->ladder->build(new SourceProfile(5000, 100, 'h264'), 'generic');

        self::assertCount(1, $result->renditions);
        $only = $result->renditions[0];
        self::assertSame(3800, $only->width);
        self::assertSame(76, $only->height);
        self::assertLessThanOrEqual(3840, $only->width);
        self::assertLessThanOrEqual(5000, $only->width);
        self::assertLessThanOrEqual(100, $only->height);
        $this->assertRungSane($only);
    }

    // -----------------------------------------------------------------
    // #12 — Purity / determinism
    // -----------------------------------------------------------------

    /**
     * @param int<2, max> $width
     * @param int<2, max> $height
     */
    #[DataProvider('sourceSweepProvider')]
    public function testBuildIsDeterministicForIdenticalInput(int $width, int $height): void
    {
        $source = new SourceProfile($width, $height, 'h264', 7_000_000, 'aac', 160_000);

        $first = $this->ladder->build($source, 'web');
        $second = (new AbrLadder())->build(new SourceProfile($width, $height, 'h264', 7_000_000, 'aac', 160_000), 'web');

        self::assertSame($first->toArray(), $second->toArray(), 'identical input -> identical output');
    }

    // -----------------------------------------------------------------
    // #9 — maxrate / bufsize / bandwidth relationships
    // -----------------------------------------------------------------

    public function testMaxrateAndBufsizeDerivationOnGenericRungs(): void
    {
        $top = $this->ladder->build(new SourceProfile(1920, 1080, 'h264'), 'generic')->renditions[0];

        self::assertSame(5_000_000, $top->videoBitrate);
        self::assertSame(5_350_000, $top->maxrate(), 'maxrate = round(1.07 * videoBitrate)');
        self::assertSame(10_700_000, $top->bufsize(), 'bufsize = 2 * maxrate');
        self::assertSame($top->maxrate() * Rendition::BUFSIZE_MULTIPLIER, $top->bufsize());
    }

    public function testUncappedBandwidthEqualsMaxratePlusAudioAllowance(): void
    {
        // Generic never binds the profile max_bitrate, so BANDWIDTH is exactly
        // video maxrate + the 128 kbps AAC allowance for every rung.
        foreach ($this->ladder->build(new SourceProfile(3840, 2160, 'h264'), 'generic')->renditions as $rung) {
            self::assertSame(
                $rung->maxrate() + Rendition::AUDIO_BANDWIDTH,
                $rung->bandwidth(),
                'BANDWIDTH = maxrate + audio when uncapped',
            );
        }
    }

    // -----------------------------------------------------------------
    // resolveProfileCap fallbacks (custom QualitySelector injection)
    // -----------------------------------------------------------------

    public function testSelectorWithoutGenericFallsBackToBuiltInDefaults(): void
    {
        $selector = new class extends QualitySelector {
            public function getProfile(string $name): ?array
            {
                return null;
            }
        };

        $result = (new AbrLadder($selector))->build(new SourceProfile(3840, 2160, 'h264'), 'anything');

        // Built-in defaults are 3840x2160 @ 100 Mbps -> full generic-shaped ladder.
        self::assertSame(
            [2160, 1440, 1080, 720, 480, 360, 240],
            array_map(static fn (Rendition $r): int => $r->height, $result->renditions),
        );
    }

    public function testDegenerateZeroProfileIsCoercedToSafeDefaults(): void
    {
        $selector = new class extends QualitySelector {
            public function getProfile(string $name): array
            {
                return [
                    'max_bitrate' => 0,
                    'max_resolution' => [0, 0],
                    'direct_play' => [],
                    'transcode' => [],
                    'container' => [],
                ];
            }
        };

        $result = (new AbrLadder($selector))->build(new SourceProfile(3840, 2160, 'h264'), 'zero');

        self::assertSame(
            [2160, 1440, 1080, 720, 480, 360, 240],
            array_map(static fn (Rendition $r): int => $r->height, $result->renditions),
        );
        self::assertLessThanOrEqual(100_000_000, $result->renditions[0]->bandwidth());
    }

    public function testPartialProfileResolutionUsesDefaultHeight(): void
    {
        $selector = new class extends QualitySelector {
            public function getProfile(string $name): array
            {
                return [
                    'max_bitrate' => 8_000_000,
                    'max_resolution' => [1280], // height index missing -> default 2160
                    'direct_play' => [],
                    'transcode' => [],
                    'container' => [],
                ];
            }
        };

        $result = (new AbrLadder($selector))->build(new SourceProfile(3840, 2160, 'h264'), 'partial');

        // Height defaults to 2160 but the 1280px width cap keeps only <=720 tiers.
        self::assertSame(
            [720, 480, 360, 240],
            array_map(static fn (Rendition $r): int => $r->height, $result->renditions),
        );
        foreach ($result->renditions as $rung) {
            self::assertLessThanOrEqual(1280, $rung->width);
        }
    }

    public function testResultIsALadderResultInstance(): void
    {
        self::assertInstanceOf(
            LadderResult::class,
            $this->ladder->build(new SourceProfile(1920, 1080, 'h264'), 'generic'),
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Assert a transcode rung is internally consistent and never under-declares its
     * H.264 level for its frame.
     */
    private function assertRungSane(Rendition $rung): void
    {
        self::assertFalse($rung->isOriginal, 'ladder rungs are not the Original descriptor');
        self::assertFalse($rung->isCopy);
        self::assertSame($rung->height . 'p', $rung->id);
        self::assertGreaterThan(0, $rung->videoBitrate);
        self::assertGreaterThan(0, $rung->bandwidth());
        self::assertSame($rung->maxrate() * Rendition::BUFSIZE_MULTIPLIER, $rung->bufsize());
        self::assertSame(sprintf('%dx%d', $rung->width, $rung->height), $rung->resolution());
        self::assertSame(self::AUDIO, self::audioCodecOf($rung), 'AAC-LC audio advertised');

        $video = self::videoCodecOf($rung);
        self::assertArrayHasKey($video, self::LEVEL_MAXFS, "advertised level {$video} is a known H.264 level");
        $macroblocks = (int) (ceil($rung->width / 16.0) * ceil($rung->height / 16.0));
        self::assertLessThanOrEqual(
            self::LEVEL_MAXFS[$video],
            $macroblocks,
            sprintf(
                'no under-declaration: %dx%d = %d MB must fit level %s (MaxFS %d)',
                $rung->width,
                $rung->height,
                $macroblocks,
                $video,
                self::LEVEL_MAXFS[$video],
            ),
        );
    }

    /**
     * The ordered rung heights of a ladder result.
     *
     * @return list<int>
     */
    private static function heights(LadderResult $result): array
    {
        return array_map(static fn (Rendition $r): int => $r->height, $result->renditions);
    }

    /**
     * @param list<int> $bandwidths
     *
     * @return list<int>
     */
    private static function sortedDescending(array $bandwidths): array
    {
        rsort($bandwidths);

        return $bandwidths;
    }

    /**
     * Assert a rung list has strictly-decreasing, all-distinct BANDWIDTHs — the
     * gradient a player needs to actually climb between rungs.
     *
     * @param list<Rendition> $rungs
     */
    private static function assertDescendingDistinctBandwidths(array $rungs): void
    {
        $previous = PHP_INT_MAX;
        $seen = [];
        foreach ($rungs as $rung) {
            $bandwidth = $rung->bandwidth();
            self::assertLessThan($previous, $bandwidth, 'BANDWIDTH strictly decreasing');
            self::assertArrayNotHasKey($bandwidth, $seen, 'no two rungs share a BANDWIDTH');
            $seen[$bandwidth] = true;
            $previous = $bandwidth;
        }
    }

    private static function videoCodecOf(Rendition $rendition): string
    {
        return explode(',', $rendition->codecs)[0];
    }

    private static function audioCodecOf(Rendition $rendition): string
    {
        $parts = explode(',', $rendition->codecs);

        return $parts[1] ?? '';
    }
}
