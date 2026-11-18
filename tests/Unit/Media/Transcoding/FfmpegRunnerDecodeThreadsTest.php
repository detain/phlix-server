<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * perf #5: the software decoder of a segment encode is thread-capped by an
 * INPUT-side `-threads N` (placed between the seek and `-i`), independent of the
 * output-side encoder cap. Pins the placement, the 0 / negative = disabled contract,
 * the env override, and that a stream copy (which decodes nothing) gets no flag.
 */
final class FfmpegRunnerDecodeThreadsTest extends TestCase
{
    private const ENV = 'PHLIX_SEGMENT_DECODE_THREADS';

    private string|false $envBefore = false;

    protected function setUp(): void
    {
        $this->envBefore = getenv(self::ENV);
        putenv(self::ENV);
    }

    protected function tearDown(): void
    {
        if ($this->envBefore === false) {
            putenv(self::ENV);
        } else {
            putenv(self::ENV . '=' . $this->envBefore);
        }
    }

    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function build(FfmpegRunner $runner, array $params): string
    {
        return $runner->buildSegmentCommand('/media/in.mkv', '/tmp/out.ts', 1800.0, 6.0, $params);
    }

    /**
     * The value of the INPUT-side `-threads` (the one between `-ss` and `-i`), or null.
     */
    private function inputThreads(string $cmd): ?string
    {
        return preg_match('/ -ss \S+ -threads (\d+) -i /', $cmd, $m) === 1 ? $m[1] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function softwareRung(): array
    {
        return [
            'video_codec' => 'libx264',
            'preset' => 'veryfast',
            'crf' => 23,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
        ];
    }

    public function testExplicitDecodeCapSitsBetweenTheSeekAndTheInput(): void
    {
        $runner = $this->runner();
        $runner->setSegmentEncodeThreads(4);
        $runner->setSegmentDecodeThreads(2);

        $cmd = $this->build($runner, $this->softwareRung());

        self::assertSame('2', $this->inputThreads($cmd), 'input-side decode cap before -i');
        // The output-side encoder cap is unchanged and still follows the input.
        self::assertSame(2, substr_count($cmd, '-threads'), 'one input-side and one output-side flag');
        self::assertGreaterThan(
            (int) strpos($cmd, ' -i '),
            (int) strpos($cmd, '-threads 4'),
            'the encoder cap stays on the output side',
        );
    }

    public function testZeroDisablesTheDecodeFlagAndLeavesTheEncoderCapAlone(): void
    {
        $runner = $this->runner();
        $runner->setSegmentEncodeThreads(4);
        $runner->setSegmentDecodeThreads(0);

        $cmd = $this->build($runner, $this->softwareRung());

        self::assertNull($this->inputThreads($cmd));
        self::assertStringContainsString('-threads 4', $cmd);
    }

    public function testNegativeValueDisablesTheDecodeFlag(): void
    {
        $runner = $this->runner();
        $runner->setSegmentEncodeThreads(0);
        $runner->setSegmentDecodeThreads(-1);

        $cmd = $this->build($runner, $this->softwareRung());

        self::assertNull($this->inputThreads($cmd));
        self::assertStringNotContainsString('-threads', $cmd);
    }

    public function testStreamCopyDecodesNothingAndTakesNoDecodeFlag(): void
    {
        $runner = $this->runner();
        $runner->setSegmentEncodeThreads(4);
        $runner->setSegmentDecodeThreads(4);

        $cmd = $this->build($runner, ['video_codec' => 'copy', 'audio_codec' => 'copy']);

        self::assertStringContainsString(' -c:v copy', $cmd);
        self::assertNull($this->inputThreads($cmd));
        self::assertStringNotContainsString('-threads', $cmd);
    }

    public function testUnwiredRunnerDefaultsToOffAndHonoursTheEnvOverride(): void
    {
        // Default is 0 (off): no input-side flag, identical to the pre-#5 command.
        self::assertNull($this->inputThreads($this->build($this->runner(), $this->softwareRung())));

        putenv(self::ENV . '=3');
        self::assertSame('3', $this->inputThreads($this->build($this->runner(), $this->softwareRung())));

        // "0" from the environment survives as a real value (disable), not as unset.
        putenv(self::ENV . '=0');
        self::assertNull($this->inputThreads($this->build($this->runner(), $this->softwareRung())));
    }

    public function testNonNumericEnvValueReadsLikeEveryOtherIntegerKnob(): void
    {
        // The knob uses EnvConfig::integer, master's (int)-cast parser: "abc" is 0, which
        // is off (the default), and "4x" reads as 4, the same as PHLIX_SEGMENT_ENCODE_THREADS.
        putenv(self::ENV . '=abc');
        self::assertNull($this->inputThreads($this->build($this->runner(), $this->softwareRung())));

        putenv(self::ENV . '=4x');
        self::assertSame('4', $this->inputThreads($this->build($this->runner(), $this->softwareRung())));
    }

    public function testWiredValueBeatsTheEnvironment(): void
    {
        putenv(self::ENV . '=3');
        $runner = $this->runner();
        $runner->setSegmentDecodeThreads(1);

        self::assertSame('1', $this->inputThreads($this->build($runner, $this->softwareRung())));
    }
}
