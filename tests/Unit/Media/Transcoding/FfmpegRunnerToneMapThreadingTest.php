<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * SV-1.1(b): the HDR tone-map filter STRING is resolved ONCE at job-creation
 * time (TranscodeManager::computeSegmentParams) and threaded through
 * `segment_params` as `tone_map_filter`. {@see FfmpegRunner::buildSegmentCommand()}
 * and {@see FfmpegRunner::buildHwaccelSegmentCommand()} must then use that
 * threaded string DIRECTLY — with ZERO per-segment
 * probe()/needsToneMapping()/getToneMappingProfile() re-derivation (which is what
 * caused the per-segment ffprobe storm S-F4 / SV-1.1 targets) — and fall back to
 * the legacy per-segment re-derive ONLY when the threaded string is absent
 * (pre-SV-1.1(b) persisted params / un-rescanned items).
 *
 */
final class FfmpegRunnerToneMapThreadingTest extends TestCase
{
    /**
     * The canonical zscale HDR→SDR graph SV-1.4 pins. Used here as the threaded
     * `tone_map_filter` value so the assertion doubles as a byte-identity check:
     * the exact string handed in must appear verbatim in the built `-vf` graph.
     */
    private const CANON_TONE_MAP =
        'zscale=t=linear:npl=100,format=gbrpf32le,'
        . 'zscale=p=bt709,tonemap=hable:desat=0,'
        . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p';

    private const FALLBACK_TONE_MAP = 'FALLBACK_DERIVED_TONEMAP_FILTER';

    protected function setUp(): void
    {
        parent::setUp();
        HwaccelRegistry::reset();
    }

    protected function tearDown(): void
    {
        HwaccelRegistry::reset();
        parent::tearDown();
    }

    private function spyRunner(bool $needsToneMappingReturn = false): ToneMapThreadingSpyRunner
    {
        return new ToneMapThreadingSpyRunner(self::FALLBACK_TONE_MAP, $needsToneMappingReturn);
    }

    private function assertNoReDerive(ToneMapThreadingSpyRunner $runner): void
    {
        $this->assertSame(0, $runner->probeCalls, 'threaded path must not probe()');
        $this->assertSame(0, $runner->needsToneMappingCalls, 'threaded path must not call needsToneMapping()');
        $this->assertSame(0, $runner->getToneMappingProfileCalls, 'threaded path must not call getToneMappingProfile()');
    }

    private function hdrCapableNvenc(): HwaccelCapability
    {
        // require_hdr_tone_map=true routes getEncoder() to an encoder advertising
        // HDR tone-map support, so seed one.
        return new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'h264_cuvid',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['baseline', 'main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 100000000,
        );
    }

    /**
     * @param array<string, HwaccelCapability> $capabilities
     */
    private function seedRegistry(array $capabilities): HwaccelRegistry
    {
        HwaccelRegistry::reset();
        $registry = HwaccelRegistry::getInstance();
        $ref = new \ReflectionObject($registry);

        $capProp = $ref->getProperty('capabilities');
        $capProp->setAccessible(true);
        $capProp->setValue($registry, $capabilities);

        $initProp = $ref->getProperty('initialized');
        $initProp->setAccessible(true);
        $initProp->setValue($registry, true);

        return $registry;
    }

    // ---- buildSegmentCommand (software) -----------------------------------

    /**
     * SV-1.1(b) core: a threaded `tone_map_filter` (with require_hdr_tone_map)
     * is emitted verbatim into `-vf` and NO probe/needsToneMapping/
     * getToneMappingProfile is invoked.
     */
    public function testSoftwareSegmentUsesThreadedFilterWithoutReDeriving(): void
    {
        $runner = $this->spyRunner();

        $cmd = $runner->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'require_hdr_tone_map' => true,
            'tone_map_filter' => self::CANON_TONE_MAP,
        ]);

        $this->assertStringContainsString('-vf "' . self::CANON_TONE_MAP . '"', $cmd);
        $this->assertStringNotContainsString(self::FALLBACK_TONE_MAP, $cmd);
        $this->assertNoReDerive($runner);
    }

    /**
     * Mutation sense: with NO threaded string, the software path re-derives via
     * getToneMappingProfile() (legacy fallback). require_hdr_tone_map short-
     * circuits the `||`, so needsToneMapping() is not consulted.
     */
    public function testSoftwareSegmentFallsBackToReDeriveWhenFilterAbsent(): void
    {
        $runner = $this->spyRunner();

        $cmd = $runner->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'require_hdr_tone_map' => true,
            // no tone_map_filter
        ]);

        $this->assertStringContainsString('-vf "' . self::FALLBACK_TONE_MAP . '"', $cmd);
        $this->assertSame(1, $runner->getToneMappingProfileCalls, 'fallback must re-derive exactly once');
        $this->assertSame(0, $runner->needsToneMappingCalls, 'require_hdr_tone_map short-circuits needsToneMapping()');
    }

    /**
     * Legacy fallback via needsToneMapping(): when require_hdr_tone_map is NOT
     * set and no filter is threaded, an HDR input is still detected through the
     * memoised needsToneMapping() probe and re-derived.
     */
    public function testSoftwareSegmentFallsBackViaNeedsToneMappingWhenNoFlag(): void
    {
        $runner = $this->spyRunner(needsToneMappingReturn: true);

        $cmd = $runner->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            // no require_hdr_tone_map, no tone_map_filter
        ]);

        $this->assertStringContainsString('-vf "' . self::FALLBACK_TONE_MAP . '"', $cmd);
        $this->assertSame(1, $runner->needsToneMappingCalls);
        $this->assertSame(1, $runner->getToneMappingProfileCalls);
    }

    /**
     * The threaded string is gated on require_hdr_tone_map: a `tone_map_filter`
     * present WITHOUT the flag is ignored (defensive — the flag is the contract),
     * and with needsToneMapping()=false no tone-map is emitted at all.
     */
    public function testSoftwareSegmentIgnoresThreadedFilterWithoutFlag(): void
    {
        $runner = $this->spyRunner(needsToneMappingReturn: false);

        $cmd = $runner->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'tone_map_filter' => self::CANON_TONE_MAP,
            // no require_hdr_tone_map
        ]);

        $this->assertStringNotContainsString(self::CANON_TONE_MAP, $cmd);
        $this->assertStringNotContainsString(self::FALLBACK_TONE_MAP, $cmd);
        $this->assertSame(0, $runner->getToneMappingProfileCalls);
    }

    /**
     * SV-1.6 ordering preserved: the threaded tone-map filter comes BEFORE the
     * scale filter in the `-vf` chain.
     */
    public function testSoftwareSegmentThreadedFilterPrecedesScale(): void
    {
        $runner = $this->spyRunner();

        $cmd = $runner->buildSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'require_hdr_tone_map' => true,
            'tone_map_filter' => self::CANON_TONE_MAP,
            'width' => 1280,
            'height' => 720,
        ]);

        $tonePos = strpos($cmd, self::CANON_TONE_MAP);
        $scalePos = strpos($cmd, 'scale=1280:720');
        $this->assertIsInt($tonePos);
        $this->assertIsInt($scalePos);
        $this->assertLessThan($scalePos, $tonePos, 'tone-map must precede scale in the -vf chain');
        $this->assertNoReDerive($runner);
    }

    // ---- buildHwaccelSegmentCommand ---------------------------------------

    /**
     * SV-1.1(b) core (hwaccel): a threaded `tone_map_filter` is emitted verbatim
     * and NO probe/needsToneMapping/getToneMappingProfile is invoked.
     */
    public function testHwaccelSegmentUsesThreadedFilterWithoutReDeriving(): void
    {
        $registry = $this->seedRegistry(['nvenc' => $this->hdrCapableNvenc()]);
        $runner = $this->spyRunner();
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);
        // Zero the counters AFTER hardware probing so only the build call is measured.
        $runner->resetCounters();

        $cmd = $runner->buildHwaccelSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'require_hdr_tone_map' => true,
            'tone_map_filter' => self::CANON_TONE_MAP,
        ]);

        $this->assertNotNull($cmd);
        $this->assertStringContainsString(self::CANON_TONE_MAP, $cmd);
        $this->assertStringNotContainsString(self::FALLBACK_TONE_MAP, $cmd);
        $this->assertNoReDerive($runner);
    }

    /**
     * Mutation sense (hwaccel): with NO threaded string the hwaccel path re-
     * derives via getToneMappingProfile() (legacy fallback).
     */
    public function testHwaccelSegmentFallsBackToReDeriveWhenFilterAbsent(): void
    {
        $registry = $this->seedRegistry(['nvenc' => $this->hdrCapableNvenc()]);
        $runner = $this->spyRunner();
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);
        $runner->resetCounters();

        $cmd = $runner->buildHwaccelSegmentCommand('/in.mkv', '/out/seg-00000.ts', 0.0, 6.0, [
            'video_codec' => 'libx264',
            'require_hdr_tone_map' => true,
            // no tone_map_filter
        ]);

        $this->assertNotNull($cmd);
        $this->assertStringContainsString(self::FALLBACK_TONE_MAP, $cmd);
        $this->assertSame(1, $runner->getToneMappingProfileCalls, 'fallback must re-derive exactly once');
    }

    // ---- ABR rendition params (SV-1.1(b′)) --------------------------------

    /**
     * SV-1.1(b′): the REAL per-rendition params
     * ({@see TranscodeManager::segmentParamsForRendition()}) for a transcode rung,
     * with the tone-map flag+filter merged in (as
     * {@see TranscodeManager::applyToneMap()} threads them from the job's base
     * segment_params), drive the HWACCEL builder to emit the threaded string
     * VERBATIM (tone-map before scale per SV-1.6) with ZERO
     * probe()/needsToneMapping()/getToneMappingProfile() re-derivation — matching
     * the single-variant path. Together with the software builder proof in
     * {@see \Phlix\Tests\Unit\Media\Transcoding\TranscodeManagerTest::testMultiVariantRenditionToneMapParamsBuildWithoutReDeriving()}
     * this covers BOTH ABR segment builders.
     */
    public function testAbrRenditionHwaccelSegmentUsesThreadedFilterWithoutReDeriving(): void
    {
        // Genuine 480p transcode-rung encode contract (libx264 + rung scale/VBV).
        $forRendition = new ReflectionMethod(TranscodeManager::class, 'segmentParamsForRendition');
        $forRendition->setAccessible(true);
        /** @var array<string, mixed> $segParams */
        $segParams = $forRendition->invoke(self::bareTranscodeManagerForRendition(), [
            'is_copy' => false,
            'video_bitrate' => 1400000,
            'codecs' => 'avc1.64001f,mp4a.40.2',
            'width' => 854,
            'height' => 480,
        ]);
        $this->assertSame('libx264', $segParams['video_codec']);
        // Merge exactly what applyToneMap() threads in from base segment_params.
        $segParams['require_hdr_tone_map'] = true;
        $segParams['tone_map_filter'] = self::CANON_TONE_MAP;

        $registry = $this->seedRegistry(['nvenc' => $this->hdrCapableNvenc()]);
        $runner = $this->spyRunner();
        $runner->setConfig(['enabled' => true, 'prefer_hardware' => true]);
        $runner->probeHardwareAcceleration($registry);
        $runner->resetCounters();

        $cmd = $runner->buildHwaccelSegmentCommand('/in.mkv', '/out/seg-v480p-00002.ts', 12.0, 6.0, $segParams);

        $this->assertNotNull($cmd);
        $this->assertStringContainsString(self::CANON_TONE_MAP, $cmd);
        $this->assertStringNotContainsString(self::FALLBACK_TONE_MAP, $cmd);
        // SV-1.6 ordering: tone-map precedes the rung scale in the filter chain.
        $tonePos = strpos($cmd, self::CANON_TONE_MAP);
        $scalePos = strpos($cmd, 'scale=854:480');
        $this->assertIsInt($tonePos);
        $this->assertIsInt($scalePos);
        $this->assertLessThan($scalePos, $tonePos, 'tone-map must precede scale');
        $this->assertNoReDerive($runner);
    }

    /**
     * A bare TranscodeManager for reflecting into `segmentParamsForRendition()`,
     * which became an instance method when the ABR rungs started reading the
     * effective `transcoding.*` encode settings. Constructed without the ctor so
     * no database is needed; the encode settings then default to the shipped
     * literals, which is what these assertions expect.
     */
    private static function bareTranscodeManagerForRendition(): TranscodeManager
    {
        $ref = new \ReflectionClass(TranscodeManager::class);
        $manager = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('encodeSettings');
        $prop->setAccessible(true);
        $prop->setValue($manager, new \Phlix\Media\Transcoding\EncodeSettings());

        return $manager;
    }
}
