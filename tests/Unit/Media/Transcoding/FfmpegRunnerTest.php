<?php

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

class FfmpegRunnerTest extends TestCase
{
    public function testCanCreateFfmpegRunner(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $this->assertInstanceOf(FfmpegRunner::class, $runner);
    }

    public function testBuildTranscodeCommand(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $params = [
            'video_codec' => 'libx264',
            'preset' => 'medium',
            'crf' => 23,
            'width' => 1920,
            'height' => 1080,
            'audio_codec' => 'aac',
            'audio_bitrate' => '192k',
            'container' => 'mp4',
        ];

        $cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', $params);

        $this->assertStringContainsString('libx264', $cmd);
        $this->assertStringContainsString('aac', $cmd);
        $this->assertStringContainsString('/input.mkv', $cmd);
        $this->assertStringContainsString('/output.mp4', $cmd);
    }

    public function testIsAvailableReturnsFalseForNonexistentBinary(): void
    {
        $runner = new FfmpegRunner('/nonexistent/ffmpeg', '/nonexistent/ffprobe', '/tmp');

        $this->assertFalse($runner->isAvailable());
    }

    public function testGetTranscodeDirReturnsConfiguredPath(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/var/transcodes');

        $this->assertSame('/var/transcodes', $runner->getTranscodeDir());
    }

    public function testBuildTranscodeCommandIgnoresNonScalarParams(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        // Mixed/garbage values (objects, arrays, true) should not corrupt the command line.
        $params = [
            'video_codec' => 'libx264',
            'preset' => new \stdClass(),
            'crf' => ['bogus'],
            'width' => true,
            'height' => null,
            'audio_codec' => 'aac',
            'audio_bitrate' => 192,           // numeric int — should serialize
            'audio_sample_rate' => '44100',   // numeric string — should coerce
        ];

        $cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', $params);

        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringContainsString('-preset medium', $cmd);   // fallback default
        $this->assertStringContainsString('-crf 23', $cmd);          // fallback default
        $this->assertStringContainsString('-b:a 192', $cmd);
        $this->assertStringContainsString('-ar 44100', $cmd);
        $this->assertStringNotContainsString('-vf', $cmd);            // width/height not valid ints
    }

    public function testBuildTranscodeCommandHonoursValidWidthHeight(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', [
            'video_codec' => 'libx265',
            'width' => 1280,
            'height' => 720,
        ]);

        $this->assertStringContainsString('libx265', $cmd);
        $this->assertStringContainsString('scale=1280:720', $cmd);
    }

    public function testGetVersionReturnsNullWhenBinaryMissing(): void
    {
        $runner = new FfmpegRunner('/nonexistent/ffmpeg', '/nonexistent/ffprobe', '/tmp');

        $this->assertNull($runner->getVersion());
    }

    /**
     * S6: probe() runs its ffprobe via FfmpegRunner::runProbeCommand(). Under
     * phpunit CLI there is no Swoole coroutine context (getCid() === -1), so the
     * non-blocking coroutine branch is skipped and the blocking shell_exec()
     * fallback is exercised — which is exactly the fallback S6 relies on off the
     * event loop. This proves the fallback captures ffprobe stdout and probe()
     * parses it into the normalized {streams, format} shape.
     */
    public function testProbeParsesFfprobeJsonViaShellExecFallback(): void
    {
        $fakeProbe = $this->createFakeProbe(
            '{"streams":[{"codec_type":"video","width":1920,"height":1080}],'
            . '"format":{"duration":"42.000000","format_name":"matroska"}}'
        );

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', $fakeProbe, '/tmp');
        $result = $runner->probe('/whatever.mkv');

        $this->assertIsArray($result);
        $this->assertSame(1920, $result['streams'][0]['width'] ?? null);
        $this->assertSame(1080, $result['streams'][0]['height'] ?? null);
        $this->assertSame('42.000000', $result['format']['duration'] ?? null);
    }

    /**
     * S6: the shell_exec() fallback's null-on-failure contract — when the ffprobe
     * binary does not exist the child produces no stdout (its error goes to the
     * command's `2>/dev/null`), so runProbeCommand() yields an empty string and
     * probe() must return null rather than a malformed array.
     */
    public function testProbeReturnsNullWhenFfprobeBinaryMissing(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/nonexistent/ffprobe', '/tmp');

        $this->assertNull($runner->probe('/whatever.mkv'));
    }

    /**
     * S6: the fallback captures stdout verbatim; when that stdout is not valid
     * JSON (a non-JSON-emitting binary) probe() must still fail closed to null.
     */
    public function testProbeReturnsNullWhenFfprobeOutputIsNotJson(): void
    {
        $fakeProbe = $this->createFakeProbe('this is not json');

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', $fakeProbe, '/tmp');

        $this->assertNull($runner->probe('/whatever.mkv'));
    }

    /**
     * S6 CORE MECHANISM: inside a real Swoole coroutine, probe() must drive its
     * ffprobe through the non-blocking `\Swoole\Coroutine\System::exec()` branch
     * (getCid() > 0) — NOT the blocking shell_exec() fallback. This is the exact
     * path that runs under the production Workerman/Swoole worker on play-start,
     * and the whole point of S6 (it yields to the event loop instead of stalling
     * the worker for the duration of ffprobe). The other probe tests only ever hit
     * the fallback because phpunit CLI has no coroutine context (getCid() === -1);
     * this one explicitly enters a coroutine so the async branch is exercised and
     * its stdout capture + normalization is asserted.
     */
    public function testProbeUsesCoroutineExecBranchInsideACoroutine(): void
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('ext-swoole not loaded; coroutine branch not exercisable');
        }

        // Quieten Swoole's per-op TRACE logging so the coroutine run does not spam
        // the test output; harmless process-global setting under phpunit CLI.
        \Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR, 'trace_flags' => 0]);

        $fakeProbe = $this->createFakeProbe(
            '{"streams":[{"codec_type":"video","width":1280,"height":720}],'
            . '"format":{"duration":"7.500000","format_name":"matroska"}}'
        );

        $result = null;
        $cid = null;
        \Swoole\Coroutine\run(function () use ($fakeProbe, &$result, &$cid): void {
            // Prove we really are on the coroutine (getCid() > 0) branch, not the
            // shell_exec() fallback, so this test cannot silently degrade.
            $cid = \Swoole\Coroutine::getCid();
            $runner = new FfmpegRunner('/usr/bin/ffmpeg', $fakeProbe, '/tmp');
            $result = $runner->probe('/whatever.mkv');
        });

        $this->assertIsInt($cid);
        $this->assertGreaterThan(0, $cid, 'test must run inside a coroutine (getCid() > 0)');
        $this->assertIsArray($result);
        $this->assertSame(1280, $result['streams'][0]['width'] ?? null);
        $this->assertSame(720, $result['streams'][0]['height'] ?? null);
        $this->assertSame('7.500000', $result['format']['duration'] ?? null);
    }

    /**
     * Writes an executable shell stub that ignores its args and prints $stdout,
     * standing in for the ffprobe binary so the shell_exec() fallback can be
     * exercised deterministically. Registered for teardown-time cleanup.
     */
    private function createFakeProbe(string $stdout): string
    {
        $dir = sys_get_temp_dir() . '/phlix_probe_test_' . bin2hex(random_bytes(4));
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->fail('Could not create temp dir for fake ffprobe');
        }
        $bin = $dir . '/fake-ffprobe';
        file_put_contents(
            $bin,
            "#!/bin/sh\ncat <<'PHLIX_EOF'\n" . $stdout . "\nPHLIX_EOF\n"
        );
        chmod($bin, 0755);

        $this->fakeProbePaths[] = $bin;

        return $bin;
    }

    /** @var array<int, string> Absolute paths of fake-ffprobe stubs to clean up. */
    private array $fakeProbePaths = [];

    protected function tearDown(): void
    {
        foreach ($this->fakeProbePaths as $bin) {
            if (is_file($bin)) {
                @unlink($bin);
            }
            $dir = dirname($bin);
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->fakeProbePaths = [];

        parent::tearDown();
    }
}
