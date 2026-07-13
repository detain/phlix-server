<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use ReflectionMethod;

/**
 * SV-1.4: directly exercises {@see FfmpegRunner::buildZscaleToneMapFilter()}
 * via reflection.
 *
 * Prior coverage (in {@see FfmpegRunnerHwaccelTest}) only ever stubbed
 * {@see FfmpegRunner::getToneMappingProfile()} wholesale, so this private
 * builder was never actually invoked by a test even though the audit
 * confirmed its output is the correct HDR→SDR tone-map graph. This test
 * closes that gap and pins the exact filter string, which was verified
 * against a real, on-box ffmpeg (6.1.1, built with --enable-libzimg) as part
 * of the SV-1.4/SV-1.5 pass:
 *
 *  - zscale graph: `ffmpeg -f lavfi -i testsrc,setparams=... -vf
 *    "zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,
 *    tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format=yuv420p"
 *    -f null -` exits 0 (a real, successful HDR→SDR tone-map, not merely a
 *    parseable command line).
 *
 * @covers \Phlix\Media\Transcoding\FfmpegRunner
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
}
