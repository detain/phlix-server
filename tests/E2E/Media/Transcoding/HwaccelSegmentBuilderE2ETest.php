<?php

declare(strict_types=1);

namespace Phlix\Tests\E2E\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Config\HwAccelConfig;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Tests\Support\Browser\StubJobRowConnection;

/**
 * S354 — the hwaccel segment-builder branch executed end-to-end, with a REAL
 * hardware encode, through the REAL `/hls/{job_id}/{file}` route.
 *
 * ## The gap this closes
 *
 * S56/S60 shipped `FfmpegRunner::buildHwaccelSegmentCommand()` with its CMAF branch
 * verified only at the command-string level (no GPU to run it on). This file is the
 * S315 twin for the hardware path: the same real-Worker server
 * (`tests/Support/Browser/hls-controller-server.php`), the same real
 * `HlsController::serveFile()` route, the same on-demand production — with the
 * runner wired to the REAL merged hardware-acceleration config
 * ({@see HwAccelConfig::get()}, the same array production threads via setConfig),
 * so `startSegmentEncode()` takes the hwaccel branch and the segments are produced
 * by the REAL builder, not by a hand-written command.
 *
 * ## What a pass here means
 *
 *  1. **The builder is the real one.** Nothing in this file constructs a segment
 *     command by hand: the encode that lands on disk is produced by
 *     `buildHwaccelSegmentCommand()` inside the server process, and the command
 *     string it would emit is captured in-process from the same builder for
 *     inspection.
 *  2. **The encoder identity is proven at the STREAM level, not assumed.**
 *     ffprobe reads the produced fragment: the hardware branch resolves to
 *     h264_nvenc on this box and its output carries the NVENC signature
 *     (profile Main / level 3.0 / yuv420p), which is measurably DIFFERENT from the
 *     software-fallback branch's output (profile High / level 4.1 — the
 *     `browserSafeVideoFlags()` arm). A software encode reading as a pass is the
 *     exact failure mode this step exists to kill, so the two branches are told
 *     apart by their outputs, not by the command text.
 *  3. **The software-fallback branch is exercised as the control** — the same
 *     builder, with the per-process registry seeded to a software-only capability
 *     (the "VAAPI present but its encoder is a non-functional stub" scenario the
 *     `browserSafeVideoFlags()` arm exists for). Its output is compared against
 *     the hardware arm.
 *  4. **The segments serve through the REAL route with 200s and correct bytes**
 *     (the S315 server's per-request census), and the presentation passes the HLS
 *     demuxer's readback (init++fragment decoded through the playlist the
 *     controller serves).
 *  5. **The guard names its skip.** CI runners have no GPU, so this proof cannot
 *     run there — a named KNOWN LIMIT, recorded in this header. Where /dev/dri is
 *     absent the cases skip BY NAME (visible in the skipped-test name set via
 *     `scripts/skipped-test-names.sh`); where /dev/dri exists but no real hardware
 *     encoder resolves, they FAIL loudly instead. The decision logic is a pure
 *     static function asserted deterministically by
 *     {@see testTheProofGuardNamesItsSkipAndItsFailuresDeterministically}, so the
 *     guard cannot be deleted or weakened without a red.
 *
 * ## KNOWN LIMIT — h264_vaapi cannot init on this box (measured 2026-08-25)
 *
 * The step's title names h264_vaapi; the box's ffmpeg carries the encoder and
 * /dev/dri/renderD128 exists. Measured: `ffmpeg -vaapi_device /dev/dri/renderD128`
 * fails with `No VA display found for device /dev/dri/renderD128` — the NVIDIA
 * GPUs on this box have no VAAPI driver (no vainfo binary, no vaapi backend for
 * the NVIDIA vendor), while h264_nvenc encodes for real. The hwaccel builder is
 * vendor-agnostic: it emits whatever encoder the registry resolves (here
 * h264_nvenc, vendor_priority nvenc=0 before vaapi=1). The proof therefore runs
 * the REAL builder with the REAL hardware encoder this box actually has, and the
 * h264_vaapi init failure is the measured, named KNOWN LIMIT (see the step's
 * report). CI cannot run ANY of this proof — no GPU on runners — which is why the
 * local GPU run is the record.
 *
 * @phpstan-type ServerHandle array{id: int, proc: resource, pid: int, port: int,
 *                                  workers: int, log: string, out: string, cmd: string}
 * @phpstan-type Fetched array{name: string, status: int, contentType: ?string, bytes: int, body: string}
 */
final class HwaccelSegmentBuilderE2ETest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';

    private const FFPROBE = '/usr/bin/ffprobe';

    /** Source clip length, in seconds. 12 s at 3 s ⇒ segments 0..3. */
    private const DURATION = 12;

    /** Segment length, the S315 fixture's. */
    private const SEG_SECONDS = 3;

    /** The segment wait ceiling handed to the server's transcoder (S310's value). */
    private const SEGMENT_WAIT_MS = 90_000;

    /** Accepting processes in the controller-backed server. */
    private const SERVER_WORKERS = 2;

    private string $root;

    /**
     * Servers still running, keyed by id so stopServer() can remove itself.
     *
     * @var array<int, ServerHandle>
     */
    private array $servers = [];

    private int $serverSeq = 0;

    protected function setUp(): void
    {
        if (!is_executable(self::FFMPEG) || !is_executable(self::FFPROBE)) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        // The in-process command capture drives the REAL HwaccelRegistry singleton;
        // reset it so this class's probe/seed never leaks into another test class.
        HwaccelRegistry::reset();
        $this->root = sys_get_temp_dir() . '/phlix_s354_hwaccel_e2e_' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $this->stopServer($server);
        }
        $this->servers = [];

        if (isset($this->root) && is_dir($this->root)) {
            $this->rrmdir($this->root);
        }
        HwaccelRegistry::reset();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────
    // the guard (AC d) — pure logic, asserted deterministically
    // ─────────────────────────────────────────────────────────────────

    /**
     * The whole proof gate in one pure decision.
     *
     * @return array{run: bool, skipReason: ?string, failReason: ?string}
     */
    public static function hwaccelProofDecision(bool $hasDri, ?string $hwEncoderVendor): array
    {
        if (!$hasDri) {
            return [
                'run' => false,
                'skipReason' => 'S354 hwaccel proof skipped BY NAME: /dev/dri is absent — no GPU on this machine. '
                    . 'CI runners cannot run this proof (KNOWN LIMIT: the local GPU run is the record).',
                'failReason' => null,
            ];
        }
        if ($hwEncoderVendor === null) {
            return [
                'run' => false,
                'skipReason' => null,
                'failReason' => 'S354 hwaccel proof FAILED: /dev/dri exists but the registry resolved NO hardware '
                    . 'encoder for h264 — the proof must not silently pass as a software encode.',
            ];
        }
        if ($hwEncoderVendor === 'software') {
            return [
                'run' => false,
                'skipReason' => null,
                'failReason' => 'S354 hwaccel proof FAILED: /dev/dri exists but the registry resolved only the '
                    . 'software vendor — a non-functional GPU stub must not read as a pass.',
            ];
        }

        return ['run' => true, 'skipReason' => null, 'failReason' => null];
    }

    /**
     * The guard in BOTH directions, deterministically — no GPU needed, so this case
     * RUNS on CI while the two encode cases skip there by name. A guard nobody has
     * watched fail is not a guard anybody knows works; the S57/S305 failure class
     * this file exists to prevent is the SILENT skip, so the skip is named and the
     * fail paths are loud.
     */
    public function testTheProofGuardNamesItsSkipAndItsFailuresDeterministically(): void
    {
        // Absent GPU → skip BY NAME, never silently.
        $skip = self::hwaccelProofDecision(false, null);
        $this->assertFalse($skip['run']);
        $this->assertNull($skip['failReason']);
        $this->assertNotNull($skip['skipReason']);
        $this->assertStringContainsString('/dev/dri', (string) $skip['skipReason']);
        $this->assertStringContainsString('KNOWN LIMIT', (string) $skip['skipReason']);

        // GPU present but nothing resolved → fail loudly.
        $noEncoder = self::hwaccelProofDecision(true, null);
        $this->assertFalse($noEncoder['run']);
        $this->assertNull($noEncoder['skipReason']);
        $this->assertNotNull($noEncoder['failReason']);
        $this->assertStringContainsString('FAILED', (string) $noEncoder['failReason']);

        // GPU present but only software resolved (a broken hw stub) → fail loudly.
        $stub = self::hwaccelProofDecision(true, 'software');
        $this->assertFalse($stub['run']);
        $this->assertNull($stub['skipReason']);
        $this->assertNotNull($stub['failReason']);

        // A real hardware vendor → run. This is the GUARD's contract — generic
        // across vendors, because the guard's only job is "is there a real
        // hardware encoder". The encode case's BODY below is nvenc-tuned (its
        // command assertions and its stream signature Main/level-30 are this
        // box's measured nvenc output): on a vaapi/qsv-only box the guard still
        // lets the proof run and the nvenc-tuned assertions red LOUDLY — a
        // named KNOWN LIMIT (a future vaapi box must re-tune the body, not
        // read the red as a regression; h264_vaapi itself cannot init on this
        // box — see the class header).
        foreach (['nvenc', 'vaapi', 'qsv'] as $vendor) {
            $run = self::hwaccelProofDecision(true, $vendor);
            $this->assertTrue($run['run'], "vendor {$vendor} must run the proof");
            $this->assertNull($run['skipReason']);
            $this->assertNull($run['failReason']);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // the acceptance criterion (a)+(b): real hardware encode, real route
    // ─────────────────────────────────────────────────────────────────

    /**
     * ⚠ THE CASE S354 EXISTS FOR.
     *
     * Real `buildHwaccelSegmentCommand()` (inside the server process, driven by the
     * REAL merged HwAccelConfig), real h264_nvenc hardware encode, real
     * `HlsController::serveFile()` route, ffprobe stream-level identity, demuxer
     * readback. No segment exists when the first request arrives.
     */
    public function testHwaccelBuilderServesPlayableFmp4SegmentsFromARealHardwareEncode(): void
    {
        $this->applyProofGuard();

        $jobId = 's354-hwaccel';
        $dir = $this->buildJob($jobId);

        // Denominator #1 — the premise. Playlists, and NOT ONE segment.
        $this->assertFileExists("{$dir}/master.m3u8");
        $this->assertStringContainsString(
            '#EXT-X-MAP:URI="init-v360p.m4s"',
            (string) file_get_contents("{$dir}/media_v360p.m3u8"),
            'the fixture must be an fMP4 job — an mpegts one carries no EXT-X-MAP'
        );
        $this->assertSame(
            [],
            $this->m4sIn($dir),
            'the AC is "produced on demand" — a 200 must not be able to mean "the file was already there"'
        );

        // The REAL builder's command for THIS box, captured in-process through the
        // same HwAccelConfig + real registry the server process uses. Grepping this
        // string proves nothing by itself (S345 rule 1) — the stream-level ffprobe
        // assertions below are the proof; this is the command half the AC asks for.
        $clip = $this->clipPath();
        $command = $this->hwaccelBuilderCommand($clip, "{$dir}/seg-v360p-00000.m4s");
        $this->assertStringContainsString('-c:v h264_nvenc', $command, 'the builder must resolve the real hardware encoder');
        $this->assertStringContainsString('-preset:v p4', $command, 'nvenc preset must be the shipped p4');
        $this->assertStringContainsString('-hls_segment_type fmp4', $command, 'the CMAF branch must be selected');
        $this->assertStringNotContainsString(
            '-pix_fmt yuv420p',
            $command,
            'browserSafeVideoFlags() must NOT be applied to a REAL hardware vendor'
        );
        $this->report('hwaccel builder command: ' . $command);

        $server = $this->startServer($jobId, self::SERVER_WORKERS, '--hwaccel=1');

        // The client's walk: playlists first, then init, then every fragment.
        foreach (['master.m3u8', 'media_v360p.m3u8'] as $name) {
            $entry = $this->fetch($server, $jobId, $name);
            $this->assertSame(200, $entry['status'], "the route refused {$name}");
            $this->assertGreaterThan(0, $entry['bytes'], "{$name} was served with an empty body");
        }
        $fragments = [];
        foreach (['init-v360p.m4s', 'seg-v360p-00000.m4s', 'seg-v360p-00001.m4s', 'seg-v360p-00002.m4s', 'seg-v360p-00003.m4s'] as $name) {
            $entry = $this->fetch($server, $jobId, $name);
            $this->assertSame(200, $entry['status'], "the route refused {$name}");
            $this->assertGreaterThan(0, $entry['bytes'], "{$name} was served with an empty body");
            $this->assertSame('video/mp4', $entry['contentType'], "{$name} left with the wrong content type");
            $fragments[$name] = $entry;
        }

        // Denominator #2 — produced on demand, because of these requests.
        $after = $this->m4sIn($dir);
        foreach (array_keys($fragments) as $name) {
            $this->assertContains($name, $after, "the route 200'd {$name} but the producer never wrote it");
        }

        // Stream-level encoder identity: NVENC's signature, NOT the software arm's.
        // S56's standard: a CMAF fragment is NOT self-contained — it must be read
        // as init++fragment (the same join S310's serve-bytes readback performs;
        // a bare fragment is expected to NOT probe as a stream).
        $init = "{$dir}/init-v360p.m4s";
        $video = $this->ffprobeStreams("{$dir}/seg-v360p-00000.m4s", $init)['video'];
        $this->assertSame('h264', $video['codec_name']);
        $this->assertSame('Main', $video['profile'], 'the hardware branch must not carry the software arm\'s High profile');
        $this->assertSame(30, $video['level'], 'the hardware branch must not carry the software arm\'s 4.1 level');
        $this->assertSame('yuv420p', $video['pix_fmt']);
        $this->assertSame('aac', $this->ffprobeStreams("{$dir}/seg-v360p-00000.m4s", $init)['audio']['codec_name']);

        // Playability: the HLS demuxer decodes init++fragments through the REAL
        // playlist the controller serves.
        $this->assertDemuxerReads("{$dir}/media_v360p.m3u8");

        // The controller's own census: everything it answered, and it refused nothing.
        $served = $this->serverLog($server);
        $this->assertNotSame([], $served, 'the controller answered nothing at all');
        $this->assertSame(
            [],
            array_values(array_filter($served, static fn (array $e): bool => $e['status'] !== 200)),
            'the controller refused a request during a run that otherwise succeeded'
        );
        // The bytes left through Workerman's native withFile() sender, as in
        // production — the server's census says so; a client cannot see it.
        foreach (array_keys($fragments) as $name) {
            $entry = $this->firstServed($served, $name);
            $this->assertNotNull($entry, "the controller never saw a request for {$name}");
            $this->assertTrue(
                $entry['fileBacked'],
                "{$name} did not leave through Workerman's withFile() sender"
            );
        }

        $this->report(sprintf(
            'hwaccel E2E: %d .m4s on disk (0 before), %d controller requests, all 200; '
            . 'video %s/%s/%s level %s; fragment bytes %d+%d',
            count($after),
            count($served),
            $video['codec_name'],
            $video['profile'],
            $video['pix_fmt'],
            $video['level'],
            $fragments['init-v360p.m4s']['bytes'],
            $fragments['seg-v360p-00000.m4s']['bytes']
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    // the control (c): the SOFTWARE-fallback branch of the same builder
    // ─────────────────────────────────────────────────────────────────

    /**
     * The control: the hwaccel builder with the registry resolving ONLY the
     * software vendor — the exact "GPU probed but its encoder is a non-functional
     * stub" scenario `browserSafeVideoFlags()` exists for (FfmpegRunner's own
     * docblock, buildHwaccelSegmentCommand). The encode is REAL libx264 produced
     * by the REAL builder; its stream signature (High/4.1) is measurably distinct
     * from the hardware arm (Main/3.0), so the two arms cannot be confused.
     */
    public function testHwaccelSoftwareFallbackBranchServesPlayableSegments(): void
    {
        $this->applyProofGuard();

        $jobId = 's354-hwaccel-software';
        $dir = $this->buildJob($jobId);
        $this->assertSame([], $this->m4sIn($dir));

        // In-process capture with the registry seeded to software-only — the same
        // seed the control server applies after its fork.
        $this->seedSoftwareRegistry();
        $clip = $this->clipPath();
        $command = $this->hwaccelBuilderCommand($clip, "{$dir}/seg-v360p-00000.m4s");
        $this->assertStringContainsString('-c:v libx264', $command, 'the software-fallback arm must emit libx264');
        $this->assertStringContainsString(
            '-pix_fmt yuv420p -profile:v high -level 4.1',
            $command,
            'browserSafeVideoFlags() must be applied when the resolved vendor is software'
        );
        $this->assertStringContainsString('-hls_segment_type fmp4', $command);
        $this->report('software-fallback builder command: ' . $command);

        $server = $this->startServer($jobId, self::SERVER_WORKERS, '--hwaccel=1', '--hwaccel-seed=software');

        foreach (['master.m3u8', 'media_v360p.m3u8'] as $name) {
            $entry = $this->fetch($server, $jobId, $name);
            $this->assertSame(200, $entry['status'], "the route refused {$name}");
        }
        $fragments = [];
        foreach (['init-v360p.m4s', 'seg-v360p-00000.m4s', 'seg-v360p-00001.m4s', 'seg-v360p-00002.m4s', 'seg-v360p-00003.m4s'] as $name) {
            $entry = $this->fetch($server, $jobId, $name);
            $this->assertSame(200, $entry['status'], "the route refused {$name}");
            $this->assertGreaterThan(0, $entry['bytes'], "{$name} was served with an empty body");
            $fragments[$name] = $entry;
        }

        $init = "{$dir}/init-v360p.m4s";
        $video = $this->ffprobeStreams("{$dir}/seg-v360p-00000.m4s", $init)['video'];
        $this->assertSame('h264', $video['codec_name']);
        $this->assertSame('High', $video['profile'], 'the software arm must carry browserSafeVideoFlags\' High profile');
        $this->assertSame(41, $video['level'], 'the software arm must carry browserSafeVideoFlags\' 4.1 level');
        $this->assertSame('yuv420p', $video['pix_fmt']);
        $this->assertSame('aac', $this->ffprobeStreams("{$dir}/seg-v360p-00000.m4s", $init)['audio']['codec_name']);

        $this->assertDemuxerReads("{$dir}/media_v360p.m3u8");

        $served = $this->serverLog($server);
        $this->assertSame(
            [],
            array_values(array_filter($served, static fn (array $e): bool => $e['status'] !== 200)),
            'the controller refused a request during the control run'
        );
        foreach (array_keys($fragments) as $name) {
            $entry = $this->firstServed($served, $name);
            $this->assertNotNull($entry, "the controller never saw a request for {$name}");
            $this->assertTrue($entry['fileBacked'], "{$name} did not leave through Workerman's withFile() sender");
        }

        $this->report(sprintf(
            'software-fallback control: %d .m4s on disk (0 before), %d controller requests, all 200; '
            . 'video %s/%s/%s level %s',
            count($this->m4sIn($dir)),
            count($served),
            $video['codec_name'],
            $video['profile'],
            $video['pix_fmt'],
            $video['level']
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    // the guard wiring
    // ─────────────────────────────────────────────────────────────────

    private function applyProofGuard(): void
    {
        // /dev/dri checked FIRST, without probing: a GPU-less runner must not
        // pay for the full vendor probe (several ffmpeg invocations) before it
        // skips by name.
        $hasDri = is_dir('/dev/dri');
        $decision = $hasDri
            ? self::hwaccelProofDecision(true, $this->resolvedHwEncoderVendor())
            : self::hwaccelProofDecision(false, null);
        if ($decision['skipReason'] !== null) {
            $this->markTestSkipped($decision['skipReason']);
        }
        if ($decision['failReason'] !== null) {
            $this->fail($decision['failReason']);
        }
    }

    /**
     * What the REAL registry resolves for h264 on this machine (a real probe:
     * nvenc on this box, software-only on a GPU-less one).
     */
    private function resolvedHwEncoderVendor(): ?string
    {
        $capability = HwaccelRegistry::getInstance()->getEncoder('h264');

        return $capability?->vendor;
    }

    // ─────────────────────────────────────────────────────────────────
    // building a real job (the S315 fixture shape)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Writes the playlists through the real `writeVodPlaylists()` (reached via
     * `ensurePlaylistRegenerated()`) and produces NOT ONE segment — that is the
     * controller's job here, exactly as in the S315 twin.
     *
     * @return string the job directory
     */
    private function buildJob(string $jobId): string
    {
        $clip = $this->clipPath();
        // `ensurePlaylistRegenerated()` resolves the directory as
        // `{segmentDir}/{jobId}` and ignores the row's `hls_dir`, so the job id IS
        // the directory name here.
        $dir = "{$this->root}/{$jobId}";
        mkdir($dir, 0755, true);

        $row = [
            'id' => $jobId,
            'hls_dir' => $dir,
            'input_path' => $clip,
            'status' => 'completed',
            'duration_seconds' => self::DURATION,
            'segment_seconds' => self::SEG_SECONDS,
            'segment_params' => json_encode([
                'video_codec' => 'libx264',
                'preset' => 'veryfast',
                'crf' => 30,
                'audio_codec' => 'aac',
                'segment_format' => EncodeSettings::FORMAT_FMP4,
            ]),
            'variants' => json_encode(['renditions' => [[
                'id' => '360p',
                'width' => 640,
                'height' => 360,
                'bandwidth' => 800000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'video_codec' => 'libx264',
                'audio_codec' => 'aac',
                'is_copy' => false,
            ]]]),
        ];

        file_put_contents($this->rowFileFor($jobId), (string) json_encode($row));

        $manager = new TranscodeManager(
            new StubJobRowConnection($row),
            new FfmpegRunner(self::FFMPEG, self::FFPROBE, $this->root),
            $this->root,
            null,
            self::SEG_SECONDS,
            null,
            null,
            null,
            null,
            null,
            null,
            self::SEGMENT_WAIT_MS
        );

        $this->assertTrue($manager->ensurePlaylistRegenerated($jobId), 'playlists were not written');

        return $dir;
    }

    private function clipPath(): string
    {
        $path = "{$this->root}/source.mp4";
        if (is_file($path)) {
            return $path;
        }
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i testsrc=duration=%d:size=640x360:rate=24 '
            . '-f lavfi -i sine=frequency=440:duration=%d '
            . '-c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a aac -shortest %s 2>&1',
            escapeshellarg(self::FFMPEG),
            self::DURATION,
            self::DURATION,
            escapeshellarg($path)
        );
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, 'failed to generate the source clip: ' . implode("\n", $output));

        return $path;
    }

    private function rowFileFor(string $jobId): string
    {
        return "{$this->root}/{$jobId}-row.json";
    }

    /**
     * The REAL builder, in-process: same config source (HwAccelConfig::get()) and
     * same registry the server process uses. `$params` mirrors what
     * `TranscodeManager::segmentParamsForRendition()` hands the builder.
     */
    private function hwaccelBuilderCommand(string $inputPath, string $outFile): string
    {
        $runner = new FfmpegRunner(self::FFMPEG, self::FFPROBE, $this->root);
        $runner->setConfig(HwAccelConfig::get());
        // Wire the registry the same way production does at startup. This is NOT
        // optional: FfmpegRunner::$hwaccelProbed is a PROCESS-WIDE static that
        // earlier test classes in this PHPUnit process already flipped, so
        // buildHwaccelSegmentCommand() would skip the wiring on a fresh runner and
        // resolve a null registry. probeHardwareAcceleration() is idempotent: it
        // wires the singleton (real or seeded) regardless of the static flag.
        $runner->probeHardwareAcceleration();
        $command = $runner->buildHwaccelSegmentCommand($inputPath, $outFile, 0.0, (float) self::SEG_SECONDS, [
            'video_codec' => 'libx264',
            'preset' => EncodeSettings::DEFAULT_PRESET,
            'crf' => 30,
            'pix_fmt' => 'yuv420p',
            'profile' => 'high',
            'level' => '4.1',
            'width' => 640,
            'height' => 360,
            'maxrate' => 0,
            'bufsize' => 0,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_channels' => 2,
            'segment_format' => EncodeSettings::FORMAT_FMP4,
            'init_file' => dirname($outFile) . '/init-v360p.m4s',
        ]);
        $this->assertIsString(
            $command,
            'buildHwaccelSegmentCommand() returned null — the hwaccel branch is not reachable with this config'
        );

        return $command;
    }

    /**
     * Seeds the singleton registry with a software-only capability — the exact
     * reflection shape the unit tests use (FfmpegRunnerSegmentFormatTest::seedNvenc)
     * and the server's `--hwaccel-seed=software` applies after its fork.
     */
    private function seedSoftwareRegistry(): void
    {
        HwaccelRegistry::reset();
        $registry = HwaccelRegistry::getInstance();
        $ref = new \ReflectionObject($registry);
        $capabilities = $ref->getProperty('capabilities');
        $capabilities->setAccessible(true);
        $capabilities->setValue($registry, [
            'software' => new HwaccelCapability(
                vendor: 'software',
                encoder: 'libx264',
                decoder: 'libx264',
                supports_hdr_tone_mapping: false,
                supported_codecs: ['h264', 'hevc'],
                supported_profiles: ['baseline', 'main', 'high'],
                max_resolution_w: 7680,
                max_resolution_h: 4320,
                max_bitrate: 100000000,
            ),
        ]);
        $initialized = $ref->getProperty('initialized');
        $initialized->setAccessible(true);
        $initialized->setValue($registry, true);
    }

    // ─────────────────────────────────────────────────────────────────
    // the controller-backed server (S315's harness, minus the browser)
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return ServerHandle
     */
    private function startServer(string $jobId, int $workers, string ...$extraArgs): array
    {
        $script = dirname(__DIR__, 3) . '/Support/Browser/hls-controller-server.php';
        $this->assertFileExists($script);

        $slug = "{$jobId}-w{$workers}";
        $log = "{$this->root}/{$slug}-requests.jsonl";
        $out = "{$this->root}/{$slug}-server.out";

        $lastError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = $this->freePort();
            // ⚠ `exec ` is load-bearing (S315): proc_open() runs the command under
            // /bin/sh -c, which does not exec away; without `exec` proc_terminate()
            // would kill the SHELL and orphan the Workerman master.
            $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', array_merge([
                PHP_BINARY,
                $script,
                "--root={$this->root}",
                "--job={$jobId}",
                "--row={$this->rowFileFor($jobId)}",
                "--port={$port}",
                "--log={$log}",
                "--pid={$this->root}/{$slug}.pid",
                "--workers={$workers}",
                '--segment-seconds=' . self::SEG_SECONDS,
                '--max-wait-ms=' . self::SEGMENT_WAIT_MS,
                'start',
            ], $extraArgs)));

            $proc = proc_open(
                $cmd,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
                $pipes
            );
            $this->assertIsResource($proc, 'proc_open() refused to start the controller-backed server');
            $status = proc_get_status($proc);
            $server = [
                'id' => ++$this->serverSeq,
                'proc' => $proc,
                'pid' => (int) $status['pid'],
                'port' => $port,
                'workers' => $workers,
                'log' => $log,
                'out' => $out,
                'cmd' => $cmd,
            ];
            $this->servers[$server['id']] = $server;

            if ($this->waitForReady($server)) {
                return $server;
            }

            $lastError = "port {$port}: " . (is_file($out) ? (string) file_get_contents($out) : '(no output)');
            $this->stopServer($server);
        }

        $this->fail("the controller-backed server never became ready.\n{$lastError}");
    }

    /**
     * @param ServerHandle $server
     */
    private function waitForReady(array $server): bool
    {
        $deadline = microtime(true) + 25.0;
        $context = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true]]);
        while (microtime(true) < $deadline) {
            $status = proc_get_status($server['proc']);
            if ($status['running'] === false) {
                return false;
            }
            $body = @file_get_contents("http://127.0.0.1:{$server['port']}/__ready", false, $context);
            if ($body === 'ready') {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    /**
     * @param ServerHandle $server
     *
     * @return array{exitCode: int, portClosed: bool}
     */
    private function stopServer(array $server): array
    {
        unset($this->servers[$server['id']]);

        $exitCode = -1;
        $status = proc_get_status($server['proc']);
        if ($status['running'] === true) {
            proc_terminate($server['proc'], SIGTERM);
            $deadline = microtime(true) + 20.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($server['proc']);
                if ($status['running'] === false) {
                    break;
                }
                usleep(100_000);
            }
            if ($status['running'] === true) {
                proc_terminate($server['proc'], SIGKILL);
                usleep(500_000);
                $status = proc_get_status($server['proc']);
            }
        }
        if ($status['running'] === false) {
            $exitCode = (int) $status['exitcode'];
        }
        proc_close($server['proc']);

        $portClosed = false;
        for ($i = 0; $i < 20; $i++) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$server['port']}", $errno, $errstr, 0.5);
            if ($socket === false) {
                $portClosed = true;
                break;
            }
            fclose($socket);
            usleep(250_000);
        }

        return ['exitCode' => $exitCode, 'portClosed' => $portClosed];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($socket, "could not reserve a port: {$errstr} ({$errno})");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    /**
     * A client's fetch through the REAL route, with the status line and headers.
     *
     * ONE attempt, deliberately NO retry: a transient first-launch failure
     * (e.g. a GPU-init hiccup) leaves a non-200 entry in the server census,
     * and the census assertions below reject ANY non-200 — a retry that then
     * succeeded would still red the run, because the census is the record of
     * what the controller actually answered. A transient failure therefore
     * fails the case LOUDLY, which is the fail-fast contract: better a red
     * re-run than a pass that hides a 404 from the evidence.
     *
     * @param ServerHandle $server
     *
     * @return Fetched
     */
    private function fetch(array $server, string $jobId, string $name): array
    {
        $url = "http://127.0.0.1:{$server['port']}/hls/{$jobId}/{$name}";

        return $this->httpGet($url, $name);
    }

    /**
     * @return Fetched
     */
    private function httpGet(string $url, string $name): array
    {
        // ⚠ cURL, NOT the PHP http stream wrapper. Measured against this server
        // (2026-08-25): the response carries `Connection: keep-alive` with a
        // complete Content-Length body, and file_get_contents() with an http
        // context STALLS until its socket timeout despite the body being complete
        // (8 s stall at an 8 s timeout, body length correct all along); cURL reads
        // the identical response in ~6 ms. The stall would have read as a server
        // hang — the S315 harness's own client used cURL_multi for the same reason.
        $ch = curl_init($url);
        $this->assertNotFalse($ch, 'curl_init() failed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        $this->assertIsString($raw, "no response body for {$name}");
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $contentType = null;
        if (preg_match('/^Content-Type:\s*(\S+)/mi', substr($raw, 0, $headerSize), $m) === 1) {
            $contentType = trim($m[1]);
        }
        $this->assertGreaterThan(0, $status, "no HTTP status line for {$name}");

        return [
            'name' => $name,
            'status' => $status,
            'contentType' => $contentType,
            'bytes' => strlen($raw) - $headerSize,
            'body' => substr($raw, $headerSize),
        ];
    }

    /**
     * @param ServerHandle $server
     *
     * @return list<array{pid: int, name: string, status: int, contentType: ?string,
     *                    fileBacked: bool, bytes: int, startNs: int, endNs: int}>
     */
    private function serverLog(array $server): array
    {
        $this->assertFileExists($server['log'], 'the server never created its request log');

        /** @var list<array{pid: int, name: string, status: int, contentType: ?string,
         *      fileBacked: bool, bytes: int, startNs: int, endNs: int}> $entries */
        $entries = $this->readJsonLines($server['log']);

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonLines(string $path): array
    {
        $entries = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($path)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "unparseable line in {$path}: {$line}");
            /** @var array<string, mixed> $decoded */
            $entries[] = $decoded;
        }

        return $entries;
    }

    /**
     * The first census entry the controller recorded for a file name.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, mixed>|null
     */
    private function firstServed(array $entries, string $name): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function m4sIn(string $dir): array
    {
        $names = array_map('basename', glob("{$dir}/*.m4s") ?: []);
        sort($names);

        return $names;
    }

    /**
     * ffprobe's streams of a file, keyed by codec_type.
     *
     * S56's readback standard: a CMAF media segment is a bare fragment and is
     * NOT self-contained — it must be joined onto its init before it can be
     * probed as a stream (the same join S310 performs; ffprobe on the bare
     * fragment alone reports "trun track id unknown, no tfhd was found").
     *
     * @param string      $path     The media segment (or any self-contained file).
     * @param string|null $initPath The variant's init segment, when probing a fragment.
     *
     * @return array<string, array<string, mixed>>
     */
    private function ffprobeStreams(string $path, ?string $initPath = null): array
    {
        $probePath = $path;
        if ($initPath !== null) {
            $init = @file_get_contents($initPath);
            $this->assertIsString($init, "init segment not readable: {$initPath}");
            $fragment = @file_get_contents($path);
            $this->assertIsString($fragment, "fragment not readable: {$path}");
            $this->assertNotSame('', $init, 'the init segment must not be empty');
            $this->assertNotSame('', $fragment, 'the fragment must not be empty');
            $probePath = $this->root . '/joined-' . uniqid() . '.mp4';
            file_put_contents($probePath, $init . $fragment);
        }
        $cmd = sprintf(
            '%s -v error -show_entries stream=codec_type,codec_name,profile,level,pix_fmt -of json %s 2>&1',
            escapeshellarg(self::FFPROBE),
            escapeshellarg($probePath)
        );
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, 'ffprobe failed on ' . $probePath . ': ' . implode("\n", $output));
        $json = json_decode(implode("\n", $output), true);
        $this->assertIsArray($json, 'ffprobe output did not parse as JSON on ' . $probePath);

        $byType = [];
        foreach ($json['streams'] ?? [] as $stream) {
            $this->assertIsArray($stream);
            $byType[(string) ($stream['codec_type'] ?? '?')] = $stream;
        }
        $this->assertArrayHasKey('video', $byType, 'no video stream in ' . $path);

        return $byType;
    }

    /**
     * The S56 standard: the presentation must READ BACK — the HLS demuxer decodes
     * init++fragments through the real playlist.
     */
    private function assertDemuxerReads(string $playlist): void
    {
        $cmd = sprintf(
            '%s -v error -i %s -f null - 2>&1',
            escapeshellarg(self::FFMPEG),
            escapeshellarg($playlist)
        );
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, 'the HLS demuxer could not read the presentation: ' . implode("\n", $output));
    }

    private function report(string $line): void
    {
        fwrite(STDERR, "\n[S354] {$line}\n");
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
