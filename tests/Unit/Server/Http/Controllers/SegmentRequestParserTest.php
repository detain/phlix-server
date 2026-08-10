<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Controllers\SegmentRequestParser;

/**
 * S310 — the shared filename → `ensureSegment()` router, tested directly.
 *
 * The controller tests prove each arm is REACHED from its own route; these prove
 * the arms themselves, including the two properties no controller test can see:
 *
 *  - the extension set is a real gate in BOTH directions (a `.ts`-only caller
 *    must not match `.m4s`, and vice versa), and
 *  - the init arms are conditional on `m4s` being routable at all, because there
 *    is no such thing as an MPEG-TS init.
 *
 * ⚠ The producer side of this contract is private
 * ({@see \Phlix\Media\Transcoding\TranscodeManager}'s `segmentFileName()` /
 * `initSegmentFileName()`), so it cannot be called from here. The names below
 * were transcribed from those two methods and the round trip is asserted for
 * real — over real ffmpeg output — in
 * {@see \Phlix\Tests\Integration\Media\Transcoding\HlsFmp4OnDemandServeTest},
 * which drives the parser with names read out of the PLAYLISTS the producer
 * wrote rather than names a test author typed.
 */
final class SegmentRequestParserTest extends TestCase
{
    /**
     * Every fMP4 shape a client can ask for, and the exact arguments that
     * produce it.
     *
     * @return array<string, array{0:string, 1:string|null, 2:string|null, 3:int}>
     */
    public static function fmp4Shapes(): array
    {
        return [
            'video segment'   => ['seg-v1080p-00042.m4s', '1080p', null, 42],
            'video segment 0' => ['seg-v240p-00000.m4s', '240p', null, 0],
            'original rung'   => ['seg-voriginal-00007.m4s', 'original', null, 7],
            'audio segment'   => ['seg-a1-00003.m4s', null, 'a1', 3],
            'legacy segment'  => ['seg-00012.m4s', null, null, 12],
            'video init'      => ['init-v720p.m4s', '720p', null, 0],
            'audio init'      => ['init-a0.m4s', null, 'a0', 0],
            'legacy init'     => ['init.m4s', null, null, 0],
        ];
    }

    /**
     * Every MPEG-TS shape. There is deliberately no init row: an MPEG-TS segment
     * carries its own headers.
     *
     * @return array<string, array{0:string, 1:string|null, 2:string|null, 3:int}>
     */
    public static function mpegtsShapes(): array
    {
        return [
            'video segment'  => ['seg-v1080p-00042.ts', '1080p', null, 42],
            'original rung'  => ['seg-voriginal-00007.ts', 'original', null, 7],
            'audio segment'  => ['seg-a1-00003.ts', null, 'a1', 3],
            'legacy segment' => ['seg-00005.ts', null, null, 5],
        ];
    }

    /**
     * @dataProvider fmp4Shapes
     */
    public function testTheHlsExtensionSetParsesEveryFmp4Shape(
        string $file,
        ?string $variant,
        ?string $audioId,
        int $index
    ): void {
        $this->assertSame(
            ['variant' => $variant, 'audioId' => $audioId, 'index' => $index],
            SegmentRequestParser::parse($file, SegmentRequestParser::HLS_EXTENSIONS)
        );
    }

    /**
     * @dataProvider mpegtsShapes
     */
    public function testTheHlsExtensionSetStillParsesEveryMpegTsShape(
        string $file,
        ?string $variant,
        ?string $audioId,
        int $index
    ): void {
        $this->assertSame(
            ['variant' => $variant, 'audioId' => $audioId, 'index' => $index],
            SegmentRequestParser::parse($file, SegmentRequestParser::HLS_EXTENSIONS)
        );
    }

    /**
     * @dataProvider fmp4Shapes
     */
    public function testTheDashExtensionSetParsesEveryFmp4Shape(
        string $file,
        ?string $variant,
        ?string $audioId,
        int $index
    ): void {
        $this->assertSame(
            ['variant' => $variant, 'audioId' => $audioId, 'index' => $index],
            SegmentRequestParser::parse($file, SegmentRequestParser::DASH_EXTENSIONS)
        );
    }

    /**
     * DASH must NOT claim an MPEG-TS name: `.ts` is HlsController's artefact and
     * an MPEG-TS job has no manifest referencing it. This is the property S59
     * shipped and S310 must not lose in the extraction.
     *
     * @dataProvider mpegtsShapes
     */
    public function testTheDashExtensionSetRefusesEveryMpegTsShape(string $file): void
    {
        $this->assertNull(SegmentRequestParser::parse($file, SegmentRequestParser::DASH_EXTENSIONS));
    }

    /**
     * The mirror of the case above, and the one that cannot be reached from
     * either controller: a caller routing MPEG-TS only must not claim an fMP4
     * name. Nothing in `src/` passes this extension set today — it is asserted
     * so the extension parameter is a real gate rather than decoration that
     * happens to be right for the two live call sites.
     *
     * @dataProvider fmp4Shapes
     */
    public function testAnMpegTsOnlyCallerRefusesEveryFmp4Shape(string $file): void
    {
        $this->assertNull(SegmentRequestParser::parse($file, [SegmentRequestParser::EXT_MPEGTS]));
    }

    /**
     * An init has no MPEG-TS counterpart, so the init arms must be gated on
     * `m4s` being routable — not merely hardcoded to `.m4s` and always tried.
     * Without the gate a `.ts`-only caller would answer `init.m4s` by launching
     * an encode that can never publish that name.
     */
    public function testTheInitArmsAreGatedOnFmp4BeingRoutable(): void
    {
        foreach (['init.m4s', 'init-v720p.m4s', 'init-a0.m4s'] as $file) {
            $this->assertNull(
                SegmentRequestParser::parse($file, [SegmentRequestParser::EXT_MPEGTS]),
                "{$file} must not be an on-demand artefact for an MPEG-TS-only caller"
            );
            $this->assertNotNull(
                SegmentRequestParser::parse($file, [SegmentRequestParser::EXT_FMP4]),
                "{$file} must be one for an fMP4 caller — positive control"
            );
        }
    }

    /**
     * An empty extension set routes nothing. The guard exists so the pattern is
     * never built with an empty alternation (`(?:)`), which matches the empty
     * string and would turn `seg-00001.` into a valid request.
     */
    public function testAnEmptyExtensionSetRoutesNothing(): void
    {
        foreach (['seg-v1080p-00042.m4s', 'seg-00001.ts', 'init.m4s', 'seg-00001.'] as $file) {
            $this->assertNull(SegmentRequestParser::parse($file, []), "{$file} with no extensions");
        }
        // Positive control: the same names DO parse when an extension is offered.
        $this->assertNotNull(
            SegmentRequestParser::parse('seg-v1080p-00042.m4s', SegmentRequestParser::HLS_EXTENSIONS)
        );
    }

    /**
     * An unknown extension is dropped by the intersection rather than
     * interpolated into the pattern, so a caller cannot widen the router by
     * asking for one — and cannot inject regex metacharacters through it.
     */
    public function testUnknownExtensionsAreDiscardedNotInterpolated(): void
    {
        $this->assertNull(SegmentRequestParser::parse('seg-00001.mp4', ['mp4']));
        $this->assertNull(SegmentRequestParser::parse('seg-00001.ts', ['.*']));
        $this->assertNull(SegmentRequestParser::parse('seg-00001.x', ['.*']));
        // ... while a known extension travelling alongside an unknown one works.
        $this->assertSame(
            ['variant' => null, 'audioId' => null, 'index' => 1],
            SegmentRequestParser::parse('seg-00001.ts', ['mp4', SegmentRequestParser::EXT_MPEGTS])
        );
    }

    /**
     * Names that are NOT on-demand artefacts, for either caller. These are the
     * files the controllers must keep serving statically — a router that
     * over-matched would start an encode for a playlist.
     *
     * @return array<string, array{0:string}>
     */
    public static function nonArtefacts(): array
    {
        return [
            'master playlist'     => ['master.m3u8'],
            'media playlist'      => ['media_v1080p.m3u8'],
            'audio playlist'      => ['media_a0.m3u8'],
            'dash manifest'       => ['manifest.mpd'],
            'subtitle sidecar'    => ['sub-0.vtt'],
            'legacy chunk'        => ['chunk-0-00001.m4s'],
            'legacy init-N'       => ['init-0.m4s'],
            'uppercase variant'   => ['seg-vABC-00001.m4s'],
            'empty variant'       => ['seg-v-00001.m4s'],
            'empty audio id'      => ['seg-a-00001.m4s'],
            'missing index'       => ['seg-v1080p.m4s'],
            'index too long'      => ['seg-v1080p-0123456789.m4s'],
            'no extension'        => ['seg-v1080p-00001'],
            'init with index'     => ['init-v1080p-00001.m4s'],
            'bare init no ext'    => ['init'],
            'suffixed init'       => ['init.m4s.bak'],
        ];
    }

    /**
     * @dataProvider nonArtefacts
     */
    public function testANonArtefactNameRoutesNowhereForEitherCaller(string $file): void
    {
        $this->assertNull(
            SegmentRequestParser::parse($file, SegmentRequestParser::HLS_EXTENSIONS),
            "{$file} must fall through to the static serve on the HLS route"
        );
        $this->assertNull(
            SegmentRequestParser::parse($file, SegmentRequestParser::DASH_EXTENSIONS),
            "{$file} must fall through to the static serve on the DASH route"
        );
    }

    /**
     * ⚠ `init-0.m4s` deserves its own case, not just a row above: it is a REAL
     * legacy filename this server still serves statically (see
     * `HlsControllerTest::testServesInitSegment`), and it sits one character
     * away from the new `init-a{A}.m4s` arm. If the audio-init pattern were
     * written `init-([a-z0-9]+)\.m4s` — dropping the literal `a` and taking the
     * whole token as the group — it would swallow this name and 404 a file that
     * exists on disk.
     */
    public function testTheLegacyInitNameIsNotSwallowedByTheAudioInitArm(): void
    {
        $this->assertNull(SegmentRequestParser::parse('init-0.m4s', SegmentRequestParser::HLS_EXTENSIONS));
        // Positive control: the audio init one character away DOES parse.
        $this->assertSame(
            ['variant' => null, 'audioId' => 'a0', 'index' => 0],
            SegmentRequestParser::parse('init-a0.m4s', SegmentRequestParser::HLS_EXTENSIONS)
        );
    }

    /**
     * The audio id keeps its `a`. `ensureSegment()`'s `$audioId` is the audio
     * GROUP id (`a0`, `a1`) — the token the producer interpolates whole — so a
     * parser returning the bare digit would resolve to no track at all. The
     * assertion is on the STRING, because `'a1'` and `'1'` are both truthy and
     * a laxer assertion could not tell them apart.
     */
    public function testTheAudioIdRetainsItsGroupPrefix(): void
    {
        $parsed = SegmentRequestParser::parse('seg-a1-00003.m4s', SegmentRequestParser::HLS_EXTENSIONS);
        $this->assertNotNull($parsed);
        $this->assertSame('a1', $parsed['audioId']);
        $this->assertNull($parsed['variant'], 'an audio segment has no video rendition');
    }

    /**
     * A video rendition id, by contrast, does NOT keep its `v` — the `v` is a
     * filename discriminator, not part of the rendition id in the ABR ladder.
     * The two arms are one character apart in the pattern and opposite in this
     * respect, which is exactly the kind of asymmetry a copy-paste loses.
     */
    public function testTheVariantIdDropsItsFilenameDiscriminator(): void
    {
        $parsed = SegmentRequestParser::parse('seg-v1080p-00042.m4s', SegmentRequestParser::HLS_EXTENSIONS);
        $this->assertNotNull($parsed);
        $this->assertSame('1080p', $parsed['variant']);
        $this->assertNull($parsed['audioId'], 'a video segment has no audio group');
    }

    /**
     * Leading zeros in the index are stripped to a real int (the producer writes
     * `%05d`), and the index is the SEGMENT number, not the rendition.
     */
    public function testTheIndexIsParsedAsAnIntegerNotAZeroPaddedString(): void
    {
        $parsed = SegmentRequestParser::parse('seg-v240p-00042.m4s', SegmentRequestParser::HLS_EXTENSIONS);
        $this->assertNotNull($parsed);
        $this->assertSame(42, $parsed['index']);
    }
}
