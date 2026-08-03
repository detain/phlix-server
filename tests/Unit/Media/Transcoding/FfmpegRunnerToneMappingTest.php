<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use ReflectionMethod;
use ReflectionProperty;

/**
 * SV-1.4 / SV-1.5: directly exercises the private tone-map filter-string
 * builders on {@see FfmpegRunner} via reflection.
 *
 * Prior coverage (in {@see FfmpegRunnerHwaccelTest}) only ever stubbed
 * {@see FfmpegRunner::getToneMappingProfile()} wholesale, so neither
 * {@see FfmpegRunner::buildZscaleToneMapFilter()} nor
 * {@see FfmpegRunner::buildLibplaceboToneMapFilter()} was ever actually
 * invoked by a test. These tests close that gap and pin the exact filter
 * strings, both of which were verified against a real, on-box ffmpeg
 * (6.1.1, built with --enable-libplacebo / --enable-libzimg) as part of the
 * SV-1.4/SV-1.5 fix:
 *
 *  - zscale graph: `ffmpeg -f lavfi -i testsrc,setparams=... -vf
 *    "zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,
 *    tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format=yuv420p"
 *    -f null -` exits 0.
 *  - libplacebo graph: the OLD graph (`peak=`/`input_color_space=`/etc.)
 *    failed immediately with `Error applying option 'peak' to filter
 *    'libplacebo': Option not found` (confirmed live on this box — those
 *    options do not exist on the real filter, per `ffmpeg -h
 *    filter=libplacebo`). The NEW graph
 *    (`tonemapping=hable:colorspace=bt709:color_primaries=bt709:
 *    color_trc=bt709:range=tv,format=yuv420p`) was run against a synthetic
 *    BT.2020/PQ-tagged source with an explicit Vulkan device
 *    (`-init_hw_device vulkan=vk -filter_hw_device vk` — required on this
 *    sandbox because it has no real GPU, only a software `llvmpipe` Vulkan
 *    device that libplacebo's OWN auto-probe excludes via
 *    `!params->allow_software`; a real GPU box would auto-probe
 *    successfully with no extra flags) and exited 0, producing
 *    `yuv420p(tv, bt709, progressive)` output — a genuine successful
 *    tone-map, not merely a parseable command line.
 *
 */
final class FfmpegRunnerToneMappingTest extends TestCase
{
    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    /**
     * @param array<string, mixed> $colorMeta
     */
    private function invokeZscale(FfmpegRunner $runner, array $colorMeta = []): string
    {
        $method = new ReflectionMethod(FfmpegRunner::class, 'buildZscaleToneMapFilter');
        $method->setAccessible(true);
        /** @var string $result */
        $result = $method->invoke($runner, $colorMeta);
        return $result;
    }

    /**
     * @param array<string, mixed> $colorMeta
     */
    private function invokeLibplacebo(FfmpegRunner $runner, array $colorMeta = []): string
    {
        $method = new ReflectionMethod(FfmpegRunner::class, 'buildLibplaceboToneMapFilter');
        $method->setAccessible(true);
        /** @var string $result */
        $result = $method->invoke($runner, $colorMeta);
        return $result;
    }

    /**
     * Forces the cached libplacebo-detection result so the test is
     * deterministic and never depends on the box's actual ffmpeg build.
     */
    private function forceLibplaceboDetected(FfmpegRunner $runner, bool $available): void
    {
        $prop = new ReflectionProperty(FfmpegRunner::class, 'hasLibplacebo');
        $prop->setAccessible(true);
        $prop->setValue($runner, $available);
    }

    /**
     * SV-1.4: pins the exact zscale+tonemap graph. This is the literal
     * string verified (see class docblock) to actually tone-map HDR→SDR
     * against real ffmpeg rather than merely hard-clipping via a bare
     * colorspace conversion.
     */
    public function testBuildZscaleToneMapFilterEmitsCanonicalGraph(): void
    {
        $filter = $this->invokeZscale($this->runner());

        $this->assertSame(
            'zscale=t=linear:npl=100,format=gbrpf32le,'
                . 'zscale=p=bt709,tonemap=hable:desat=0,'
                . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p',
            $filter
        );
    }

    /**
     * SV-1.4: the graph does not vary with the probed color metadata (the
     * zscale path is a fixed BT.2020→BT.709 SDR conversion regardless of the
     * specific source tags), confirming callers cannot accidentally regress
     * this into option interpolation without the test catching the change.
     */
    public function testBuildZscaleToneMapFilterIgnoresColorMetaContent(): void
    {
        $runner = $this->runner();

        $withHlgMeta = $this->invokeZscale($runner, [
            'color_transfer' => 'arib-std-b67',
            'color_primaries' => 'bt2020',
            'color_space' => 'bt2020nc',
        ]);
        $withEmptyMeta = $this->invokeZscale($runner, []);

        $this->assertSame($withHlgMeta, $withEmptyMeta);
    }

    /**
     * SV-1.5: when ffmpeg lacks libplacebo, falls back to the exact same
     * zscale graph as SV-1.4 (no partial/broken libplacebo fragment leaks
     * through).
     */
    public function testBuildLibplaceboToneMapFilterFallsBackToZscaleWhenUnavailable(): void
    {
        $runner = $this->runner();
        $this->forceLibplaceboDetected($runner, false);

        $filter = $this->invokeLibplacebo($runner, [
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'color_space' => 'bt2020nc',
        ]);

        $this->assertSame($this->invokeZscale($runner), $filter);
    }

    /**
     * SV-1.5 (the core fix): when libplacebo IS available, emits the
     * corrected filter graph using ONLY option names that genuinely exist on
     * the real `libplacebo` ffmpeg filter (verified via `ffmpeg -h
     * filter=libplacebo` and by actually running this exact string through
     * ffmpeg — see class docblock). Critically, this must NOT regress to the
     * old, non-existent `peak=`/`input_color_space=`/`input_primaries=`/
     * `input_trc=`/`output_color_space=`/`output_primaries=`/`output_trc=`
     * options, which fail immediately with "Option not found".
     */
    public function testBuildLibplaceboToneMapFilterEmitsRealFfmpegOptionsWhenAvailable(): void
    {
        $runner = $this->runner();
        $this->forceLibplaceboDetected($runner, true);

        $filter = $this->invokeLibplacebo($runner, [
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'color_space' => 'bt2020nc',
        ]);

        $this->assertSame(
            'libplacebo=tonemapping=hable:colorspace=bt709:'
                . 'color_primaries=bt709:color_trc=bt709:range=tv,format=yuv420p',
            $filter
        );

        // The non-existent options from the pre-fix graph must never reappear.
        $this->assertStringNotContainsString('peak=', $filter);
        $this->assertStringNotContainsString('input_color_space=', $filter);
        $this->assertStringNotContainsString('input_primaries=', $filter);
        $this->assertStringNotContainsString('input_trc=', $filter);
        $this->assertStringNotContainsString('output_color_space=', $filter);
        $this->assertStringNotContainsString('output_primaries=', $filter);
        $this->assertStringNotContainsString('output_trc=', $filter);

        // The tone-mapping curve/mode is preserved.
        $this->assertStringContainsString('tonemapping=hable', $filter);
    }

    /**
     * SV-1.1(a): the persisted-columns entry point
     * {@see FfmpegRunner::resolveToneMapFilterFromColorMeta()} produces the SAME
     * filter STRING as the probe entry point
     * {@see FfmpegRunner::resolveToneMapFilterFromProbe()} for equivalent color
     * metadata — because the latter now just runs extractColorMetadata() and
     * delegates to the former. This byte-identity is what lets sub-step (a)
     * source the tone-map filter from the scan columns without changing the
     * emitted `-vf` graph vs the live-probe path.
     */
    public function testResolveToneMapFilterFromColorMetaMatchesProbeEntryPoint(): void
    {
        $runner = $this->runner();

        // HDR10 color metadata (extractColorMetadata()-shaped).
        $hdrColorMeta = [
            'color_space' => 'bt2020nc',
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ];
        // The probe ffprobe would return for the SAME source.
        $hdrProbe = [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'color_space' => 'bt2020nc',
                    'color_transfer' => 'smpte2084',
                    'color_primaries' => 'bt2020',
                ],
            ],
        ];

        $fromColorMeta = $runner->resolveToneMapFilterFromColorMeta($hdrColorMeta, 'libx264');
        $fromProbe = $runner->resolveToneMapFilterFromProbe($hdrProbe, 'libx264');

        // HDR → the canonical zscale graph, and the two entry points agree.
        $this->assertSame(
            'zscale=t=linear:npl=100,format=gbrpf32le,'
                . 'zscale=p=bt709,tonemap=hable:desat=0,'
                . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p',
            $fromColorMeta
        );
        $this->assertSame($fromProbe, $fromColorMeta);
    }

    /**
     * SV-1.1(a): SDR color metadata (bt709 transfer) is not HDR, so the
     * column-sourced resolver returns null — identical to feeding
     * resolveToneMapFilterFromProbe() an SDR probe — and null color metadata
     * (unpopulated columns) also returns null.
     */
    public function testResolveToneMapFilterFromColorMetaReturnsNullForSdrAndNull(): void
    {
        $runner = $this->runner();

        $this->assertNull($runner->resolveToneMapFilterFromColorMeta([
            'color_space' => 'bt709',
            'color_transfer' => 'bt709',
            'color_primaries' => 'bt709',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ], 'libx264'));

        $this->assertNull($runner->resolveToneMapFilterFromColorMeta(null, 'libx264'));
    }

    /**
     * SV-1.1(a): the config-driven branches of the column-sourced resolver
     * (extracted verbatim from resolveToneMapFilterFromProbe, so this pins the
     * shared decision logic). HDR metadata still yields NO tone-map filter when
     * (a) prefer_hdr_output is on AND the final codec can carry HDR (the output
     * stays HDR10), or (b) tone-mapping is disabled; and it routes to the
     * libplacebo builder when that mode is selected.
     */
    public function testResolveToneMapFilterFromColorMetaHonoursConfigBranches(): void
    {
        $hdr = [
            'color_space' => 'bt2020nc',
            'color_transfer' => 'smpte2084',
            'color_primaries' => 'bt2020',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ];

        // (a) prefer_hdr_output + an HDR-capable codec → keep HDR, no tone-map.
        $preferHdr = $this->runner();
        $preferHdr->setConfig(['prefer_hdr_output' => true]);
        $this->assertNull($preferHdr->resolveToneMapFilterFromColorMeta($hdr, 'libx265'));
        // ...but a non-HDR-capable codec (libx264) still tone-maps to SDR.
        $this->assertNotNull($preferHdr->resolveToneMapFilterFromColorMeta($hdr, 'libx264'));

        // (b) tone-mapping explicitly disabled → null even for HDR.
        $noneMode = $this->runner();
        $noneMode->setConfig(['tone_mapping_mode' => 'none']);
        $this->assertNull($noneMode->resolveToneMapFilterFromColorMeta($hdr, 'libx264'));

        // (c) libplacebo mode routes to the libplacebo builder (forced-available).
        $libplacebo = $this->runner();
        $libplacebo->setConfig(['tone_mapping_mode' => 'libplacebo']);
        $this->forceLibplaceboDetected($libplacebo, true);
        $this->assertSame(
            'libplacebo=tonemapping=hable:colorspace=bt709:'
                . 'color_primaries=bt709:color_trc=bt709:range=tv,format=yuv420p',
            $libplacebo->resolveToneMapFilterFromColorMeta($hdr, 'libx264')
        );
    }
}
