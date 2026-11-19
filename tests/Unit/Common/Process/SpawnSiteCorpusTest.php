<?php

declare(strict_types=1);

namespace Phlix\LiveTv {
    /**
     * Namespace-shadow stub for the corpus capture of
     * {@see \Phlix\LiveTv\Recorder::launchDetached()}'s historical shell_exec
     * call. PHP resolves the unqualified `shell_exec()` inside the
     * Phlix\LiveTv namespace here FIRST because Recorder is autoloaded lazily,
     * only after this definition exists. While the capture flag is unset the
     * stub is a transparent pass-through, so loading this file cannot alter
     * any other test's behavior.
     */
    function shell_exec(string $command): ?string
    {
        if (isset($GLOBALS['SRVPROC_RECORDER_SPAWNS'])) {
            $GLOBALS['SRVPROC_RECORDER_SPAWNS'][] = $command;
            return "4242\n";
        }

        return \shell_exec($command);
    }
}

namespace Phlix\Tests\Unit\Common\Process {

    use PHPUnit\Framework\TestCase;
    use Phlix\Common\Process\Detached;
    use Phlix\LiveTv\Recorder;
    use Phlix\Media\Transcoding\FfmpegRunner;
    use ReflectionClass;
    use ReflectionMethod;

    /**
     * E-12 byte-identity corpus — THE behavior-disclosure guard for the
     * spawn-site delegations.
     *
     * Every golden below was captured from a PRISTINE clone of the base
     * commit (/tmp/opencode/srvproc-base @ 41ceea95) running the ORIGINAL
     * per-site string builders (capture script: refactor-exec-1119/
     * capture-goldens.php; raw JSON: gates/SRVPROC-pristine-capture.json).
     * This suite therefore passes BEFORE any migration (pinning the pristine
     * code against itself and the new substrate against the same goldens)
     * and must still pass AFTER each site delegates — any accidental grammar
     * drift, or any attempt to silently add sentinels to Recorder's spawn,
     * reddens this file at the migration commit.
     *
     * The Recorder no-timeout branch could not be captured from pristine
     * (EffectiveConfig resolves config/ffmpeg.php from the autoloader's own
     * repo, pinning the value at the 7200 file default in every capture
     * environment). It is pinned STRUCTURALLY instead: the golden build[0]
     * proves the pristine code's timeout-off arm emits `nohup sh -c '<inner>'`
     * for the sentinel shape, DetachedTest pins the same arm for the
     * sentinel-less shape, and the grammar is one sprintf in both cases.
     */
    final class SpawnSiteCorpusTest extends TestCase
    {
        private const GOLDEN_JSON = <<<'JSON'
        {
            "build": [
                "nohup sh -c 'if ffmpeg -i in.mp4 out.ts; then touch '\\''/var/jobs/abc/.complete'\\''; else touch '\\''/var/jobs/abc/.failed'\\''; fi' > '/var/jobs/abc/ffmpeg.log' 2>&1 & echo $!",
                "nohup timeout 7200 sh -c 'if ffmpeg -i in.mp4 out.ts; then touch '\\''/var/jobs/abc/.complete'\\''; else touch '\\''/var/jobs/abc/.failed'\\''; fi' > '/var/jobs/abc/ffmpeg.log' 2>&1 & echo $!",
                "nohup timeout 95000 sh -c 'if ffmpeg -y -hide_banner; then touch '\\''/tmp/ö dir \"quoted\" $dollar/.complete'\\''; rm -f /tmp/x; touch /tmp/y; else touch '\\''/tmp/ö dir \"quoted\" $dollar/.failed'\\''; fi' > '/tmp/ö dir \"quoted\" $dollar/ffmpeg.log' 2>&1 & echo $!",
                "nohup timeout 1 sh -c 'if cmd; then touch '\\''/out/dir/.complete'\\''; a; b; else touch '\\''/out/dir/.failed'\\''; fi' > '/out/dir/ffmpeg.log' 2>&1 & echo $!",
                "nohup timeout 3661 sh -c 'if if-echoing cmd \"with quotes\" and '\\''single'\\''; then touch '\\''/out/.complete'\\''; x; else touch '\\''/out/.failed'\\''; fi' > '/out/ffmpeg.log' 2>&1 & echo $!",
                "nohup timeout 100000 sh -c 'if ffmpeg -progress '\\''x'\\'' -i $HOME/f.mp4; then touch '\\''/tmp/semi;colon dir/.complete'\\''; echo done; else touch '\\''/tmp/semi;colon dir/.failed'\\''; fi' > '/tmp/semi;colon dir/ffmpeg.log' 2>&1 & echo $!"
            ],
            "recorder_launch_line": "nohup timeout 7200 sh -c 'ffmpeg -i tcp://tuner record.ts' > '/var/log/phlix/rec-1.log' 2>&1 & echo $!",
            "probe": { "self": true, "zero": false, "negative": false, "huge": false }
        }
        JSON;

        protected function tearDown(): void
        {
            unset($GLOBALS['SRVPROC_RECORDER_SPAWNS']);
        }

        /**
         * @return array{build: array<int,string>, recorder_launch_line: string, probe: array<string,bool>}
         */
        private function goldens(): array
        {
            /** @var array{build: array<int,string>, recorder_launch_line: string, probe: array<string,bool>} $decoded */
            $decoded = json_decode(self::GOLDEN_JSON, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        }

        /**
         * The exact six argument shapes fed to the pristine builder, in the
         * exact order recorded in the goldens (capture-goldens.php).
         *
         * @return array<int, array{0: string, 1: string, 2: array<mixed>, 3: int}>
         */
        private function shapes(): array
        {
            return [
                ['ffmpeg -i in.mp4 out.ts', '/var/jobs/abc', [], 0],
                ['ffmpeg -i in.mp4 out.ts', '/var/jobs/abc', [], 7200],
                [
                    'ffmpeg -y -hide_banner',
                    '/tmp/ö dir "quoted" $dollar',
                    ['rm -f /tmp/x', '', null, 42, 'touch /tmp/y'],
                    95000,
                ],
                ['cmd', '/out/dir', ['a; b'], 1],
                ["if-echoing cmd \"with quotes\" and 'single'", '/out', ['x'], 3661],
                ["ffmpeg -progress 'x' -i \$HOME/f.mp4", '/tmp/semi;colon dir', ['echo done'], 100000],
            ];
        }

        public function testFfmpegBuildDetachedCommandBytesMatchPristineCapture(): void
        {
            /** @var FfmpegRunner $runner */
            $runner = (new ReflectionClass(FfmpegRunner::class))->newInstanceWithoutConstructor();
            $goldens = $this->goldens()['build'];

            foreach ($this->shapes() as $i => [$command, $outDir, $trailing, $timeout]) {
                self::assertSame(
                    $goldens[$i],
                    $runner->buildDetachedCommand($command, $outDir, $trailing, $timeout),
                    "buildDetachedCommand drift on shape #{$i}"
                );
            }
        }

        public function testDetachedGrammarReproducesEveryPristineFfmpegShape(): void
        {
            $goldens = $this->goldens()['build'];

            foreach ($this->shapes() as $i => [$command, $outDir, $trailing, $timeout]) {
                self::assertSame(
                    $goldens[$i],
                    Detached::buildLaunch($command, $outDir . '/ffmpeg.log', $timeout, $outDir, $trailing),
                    "Detached grammar drift on shape #{$i}"
                );
            }
        }

        public function testRecorderLaunchDetachedBytesMatchPristineCapture(): void
        {
            /** @var Recorder $recorder */
            $recorder = (new ReflectionClass(Recorder::class))->newInstanceWithoutConstructor();
            $launch = new ReflectionMethod(Recorder::class, 'launchDetached');
            $launch->setAccessible(true);

            $GLOBALS['SRVPROC_RECORDER_SPAWNS'] = [];
            $pid = $launch->invoke(
                $recorder,
                'ffmpeg -i tcp://tuner record.ts',
                '/var/log/phlix/rec-1.log'
            );

            self::assertSame(4242, $pid);
            self::assertSame(
                [$this->goldens()['recorder_launch_line']],
                $GLOBALS['SRVPROC_RECORDER_SPAWNS']
            );
        }

        public function testRecorderSentinellessDisclosureLawIsBytePinned(): void
        {
            // Recorder's spawn must never grow a sentinel footprint: the
            // pristine golden has neither marker file, and neither may the
            // grammar that now backs it.
            $golden = $this->goldens()['recorder_launch_line'];
            self::assertStringNotContainsString('.complete', $golden);
            self::assertStringNotContainsString('.failed', $golden);

            $line = Detached::buildLaunch(
                'ffmpeg -i tcp://tuner record.ts',
                '/var/log/phlix/rec-1.log',
                7200,
                null
            );
            self::assertSame($golden, $line);
        }

        public function testProcessAliveProbeMatchesPristineTruthTable(): void
        {
            /** @var FfmpegRunner $runner */
            $runner = (new ReflectionClass(FfmpegRunner::class))->newInstanceWithoutConstructor();
            $probe = $this->goldens()['probe'];

            self::assertSame($probe['self'], $runner->isProcessRunning(getmypid()));
            self::assertSame($probe['zero'], $runner->isProcessRunning(0));
            self::assertSame($probe['negative'], $runner->isProcessRunning(-7));
            self::assertSame($probe['huge'], $runner->isProcessRunning(999999));

            // And the substrate agrees with the site on every arm.
            self::assertSame(Detached::isAlive(getmypid()), $runner->isProcessRunning(getmypid()));
            self::assertSame(Detached::isAlive(0), $runner->isProcessRunning(0));
            self::assertSame(Detached::isAlive(-7), $runner->isProcessRunning(-7));
            self::assertSame(Detached::isAlive(999999), $runner->isProcessRunning(999999));
        }
    }
}
