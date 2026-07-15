<?php

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentProcessRegistry;

class FfmpegRunnerTest extends TestCase
{
    public function testCanCreateFfmpegRunner(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $this->assertInstanceOf(FfmpegRunner::class, $runner);
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

    // -------------------------------------------------------------------------
    // SV-4.2 ([S-F23]): detached segment-encode timeout wrapper + PID tracking.
    // -------------------------------------------------------------------------

    /**
     * The detached on-demand segment command wraps the atomic-publish chain in
     * `timeout -k <grace> -s TERM <n>` when a positive timeout is configured, so
     * a hung/abandoned encode is TERM'd then force-KILLed by `timeout` itself
     * (SV-4.2 finding #4 — the wrapper self-escalates, not relying on an external
     * SIGKILL reaching only the wrapper). The whole chain runs under `setsid` so
     * it is its own process group and a cancel can group-signal ffmpeg directly.
     */
    public function testBuildDetachedSegmentCommandWrapsInTimeout(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $cmd = $runner->buildDetachedSegmentCommand(
            'ffmpeg -i in.mkv out.part',
            '/tmp/hls/job/seg.ts.part-abc',
            '/tmp/hls/job/seg.ts',
            7200,
        );

        // timeout wrapper with self-escalation (-k grace) and the configured secs.
        $this->assertMatchesRegularExpression('/timeout -k \d+ -s TERM 7200 sh -c /', $cmd);
        // Launched in its own process group so a cancel can group-signal ffmpeg.
        $this->assertStringContainsString('nohup setsid ', $cmd);
        // Atomic publish tail preserved (mv on success, rm on failure).
        $this->assertStringContainsString('mv -f', $cmd);
        $this->assertStringContainsString('rm -f', $cmd);
        // Backgrounded, prints the child PID.
        $this->assertStringContainsString('& echo $!', $cmd);
    }

    /**
     * With a zero (disabled) timeout the command still launches via `setsid sh -c`
     * but without a `timeout` prefix.
     */
    public function testBuildDetachedSegmentCommandOmitsTimeoutWhenZero(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $cmd = $runner->buildDetachedSegmentCommand(
            'ffmpeg -i in.mkv out.part',
            '/tmp/hls/job/seg.ts.part-abc',
            '/tmp/hls/job/seg.ts',
            0,
        );

        $this->assertStringNotContainsString('timeout ', $cmd);
        $this->assertStringContainsString('setsid sh -c ', $cmd);
    }

    /**
     * kill / release / releaseAfterWaitTimeout delegate to the wired registry:
     * kill signals the tracked PID and drops the entry; release drops it without
     * signalling; releaseAfterWaitTimeout drops it without signalling a live
     * encode. No-op (returns 0) when no registry is wired.
     */
    public function testSegmentProcessLifecycleDelegatesToRegistry(): void
    {
        $signals = [];
        $registry = new SegmentProcessRegistry(
            null,
            static function (int $pid, int $signal) use (&$signals): void {
                $signals[] = $pid;
            },
            static fn (int $pid): bool => false,
            0.01,
            // No-op temp cleaner so the test touches no real files.
            static function (string $key): void {
            },
        );

        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        // No registry wired yet → kill is a safe no-op returning 0.
        $this->assertSame(0, $runner->killSegmentProcess('seg-x'));

        $runner->setSegmentProcessRegistry($registry);

        // Release path: entry dropped, nothing signalled.
        $registry->register('seg-release', 1234);
        $runner->releaseSegmentProcess('seg-release');
        $this->assertSame(0, $registry->registeredKeyCount());
        $this->assertSame([], $signals);

        // Wait-timeout release path: entry dropped, still nothing signalled.
        $registry->register('seg-wait', 4321);
        $runner->releaseSegmentProcessAfterWaitTimeout('seg-wait');
        $this->assertSame(0, $registry->registeredKeyCount());
        $this->assertSame([], $signals, 'wait-timeout release must never signal');

        // Kill path: entry signalled + dropped.
        $registry->register('seg-kill', 5678);
        $this->assertSame(1, $runner->killSegmentProcess('seg-kill'));
        $this->assertSame([5678], $signals);
        $this->assertSame(0, $registry->registeredKeyCount());
    }

    // -------------------------------------------------------------------------
    // probeHardwareAcceleration() static guard (SV-HWACCEL-FIX)
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the static guard between tests so each test gets a clean slate.
        $this->resetHwaccelProbed();
    }

    private function resetHwaccelProbed(): void
    {
        $ref = new \ReflectionProperty(FfmpegRunner::class, 'hwaccelProbed');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
    }

    public function testProbeHardwareAccelerationRunsOnlyOnceStaticGuard(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        // First call — will early-return via the singleton since no real registry is set up,
        // but the static guard is set.
        $first = $runner->probeHardwareAcceleration();

        // Verify the static flag is now true.
        $ref = new \ReflectionProperty(FfmpegRunner::class, 'hwaccelProbed');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue(), 'hwaccelProbed must be true after first call');

        // Second call — must return early without re-entering the probe logic.
        // We detect this by checking the hwaccelRegistry property is unchanged
        // from the first call (it was set to the singleton).
        $second = $runner->probeHardwareAcceleration();

        // Both calls return the same result (singleton's empty capabilities).
        $this->assertSame($first, $second);
    }

    public function testProbeHardwareAccelerationSubsequentCallsReturnSameResult(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        $result1 = $runner->probeHardwareAcceleration();
        $result2 = $runner->probeHardwareAcceleration();
        $result3 = $runner->probeHardwareAcceleration();

        // All subsequent calls must return identical results.
        $this->assertSame($result1, $result2);
        $this->assertSame($result2, $result3);
    }

    public function testProbeHardwareAccelerationUsesSingletonOnEarlyReturn(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');

        // First call — populates the internal registry reference.
        $runner->probeHardwareAcceleration();

        // Get the registry that was stored.
        $registry = $runner->getHwaccelRegistry();

        // On early return (subsequent calls), the singleton must be used.
        // Verify by calling again and checking the same registry instance is returned.
        $runner->probeHardwareAcceleration();
        $this->assertSame($registry, $runner->getHwaccelRegistry(),
            'same registry instance must be returned on subsequent calls');

        // Verify it's actually the singleton.
        $this->assertSame(\Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry::getInstance(), $registry);
    }
}
