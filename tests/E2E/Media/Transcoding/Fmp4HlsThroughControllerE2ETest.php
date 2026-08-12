<?php

declare(strict_types=1);

namespace Phlix\Tests\E2E\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Request as HttpRequest;
use Phlix\Server\WebPortal\Controllers\SharedUiController;
use Phlix\Tests\Support\Browser\BrowserProbeEnvironment;
use Phlix\Tests\Support\Browser\StubJobRowConnection;

/**
 * S315 — hls.js, in a real headless Chrome, playing an fMP4 presentation whose
 * every byte was served by the REAL `/hls/{job_id}/{file}` route.
 *
 * ## The gap this closes, stated precisely
 *
 * S60's acceptance criterion is cross-client verification of the fMP4 path. Two
 * halves of it already exist and NEITHER subsumes the other:
 *
 *  - {@see Fmp4HlsPlaybackE2ETest} — **real browser, FAKE server.** Its own header
 *    says so: "The bytes are served by the probe's own static HTTP server, not by
 *    `HlsController`." It proves the playlists and segments are PLAYABLE.
 *  - {@see \Phlix\Tests\Integration\Media\Transcoding\HlsFmp4OnDemandServeTest} —
 *    **real controller, ffmpeg's HLS demuxer for a client.** It proves the bytes are
 *    REACHABLE. No browser, no socket.
 *
 * This file is the diagonal: a real player, over a real TCP socket, against
 * `HlsController::serveFile()` reached through the real {@see \Phlix\Server\Http\Router}
 * running inside a real Workerman HTTP worker
 * (`tests/Support/Browser/hls-controller-server.php`). Nothing about the serve path
 * is simulated — the segments do not exist when Chrome starts, and the controller
 * has to produce every one of them while the player waits.
 *
 * ## What makes a pass here mean something
 *
 *  1. **The denominator is zero.** The job directory is asserted to hold NOT ONE
 *     `.m4s` before Chrome launches, and to hold them afterwards. A 200 therefore
 *     cannot mean "the file was already there", and the static-fallthrough arm of
 *     `serveFile()` (anything `SegmentRequestParser::parse()` returns null for)
 *     cannot be what answered.
 *  2. **Two independent request censuses.** The probe records what the BROWSER
 *     fetched; the server records what the CONTROLLER answered, with its status,
 *     content type and whether the body was file-backed. They are produced by
 *     different processes in different languages and are compared.
 *  3. **A negative control.** The same fixture with `#EXT-X-MAP` deleted must FAIL.
 *     Without it, a probe that quietly stopped loading anything would report "no
 *     fatal errors" and read as a pass.
 *  4. **A concurrency control, in both directions.** `ensureSegment()` blocks in
 *     `usleep()` outside a coroutine, so a single-worker test server SERIALISES
 *     hls.js's requests and the resulting stall reads as "the controller is broken"
 *     when it is the harness. {@see testTheControllerBackedServerIsGenuinelyConcurrent}
 *     measures overlapping in-flight requests against a one-worker control that must
 *     measure ZERO.
 *  5. **A leak guard.** The harness's first working version left a five-process
 *     server running after every case and every test still passed
 *     ({@see testTheHarnessServerShutsDownCleanlyAndLeaksNoListener}).
 *  6. **A CSP control (S60).** The page is served under the SPA's real
 *     `Content-Security-Policy`, and
 *     {@see testRemovingBlobFromMediaSrcBlocksPlaybackUnderTheSamePolicy} shows
 *     that policy BITING — because a page served with a permissive policy and a
 *     page served with none are indistinguishable, and both play.
 *
 * ## ⚠ What S60 changed in this file
 *
 * S315 wrote it against a master where the shipped default was `mpegts`, selected
 * fMP4 by an EXPLICIT `transcoding.segment_format` override read through the real
 * {@see EncodeSettings::segmentFormat()} (settable over the admin API since S313),
 * and carried a scope guard asserting the default had not moved. **S60 is the step
 * that legitimately moves it.** Three things follow:
 *
 *  - the scope guard is re-pointed, not removed —
 *    {@see testTheShippedDefaultIsFmp4} pins the new default with equal strength
 *    (and keeps an explicit-`mpegts` control, which is the documented rollback).
 *    Its NAME is registered in {@see BrowserProbeEnvironment::REQUIRED_CASES_BY_CLASS},
 *    which is updated in the same commit;
 *  - the explicit override is KEPT rather than dropped in favour of the new
 *    default. A fixture that stopped saying what it wanted would stop being able
 *    to fail if the default moved back;
 *  - CSP is now asserted here, which S315 deliberately declined to do (its page
 *    carried no policy at all, so an assertion would have been a claim with no
 *    measurement behind it).
 *
 * ⚠ The `markTestSkipped()` guards below are the same load-bearing ones S57 carries,
 * for the same reason (a developer box with no browser must still be able to run the
 * suite) and with the same danger (a skipped test reads as a pass). CI supplies every
 * prerequisite via `scripts/ci-browser-e2e-prereqs.php`, and
 * `scripts/assert-browser-e2e-ran.php` asserts BY NAME that the cases below executed
 * with a non-zero assertion count — see
 * {@see BrowserProbeEnvironment::REQUIRED_CASES_BY_CLASS}.
 *
 * @phpstan-type ServerHandle array{id: int, proc: resource, pid: int, port: int,
 *                                  log: string, out: string, cmd: string}
 * @phpstan-type ServedRequest array{pid: int, name: string, status: int, contentType: ?string,
 *                                   fileBacked: bool, bytes: int, startNs: int, endNs: int}
 */
final class Fmp4HlsThroughControllerE2ETest extends TestCase
{
    private const FFMPEG = BrowserProbeEnvironment::FFMPEG;
    private const FFPROBE = BrowserProbeEnvironment::FFPROBE;

    /** Source clip length, in seconds. 12 s at 3 s ⇒ segments 0..3. */
    private const DURATION = 12;

    /** Segment length, and therefore the boundary playback must cross. */
    private const SEG_SECONDS = 3;

    /** Play past the FIRST BOUNDARY: one segment playing proves far less. */
    private const PLAY_TO_SECONDS = 4;

    /**
     * The segment wait ceiling handed to the server's transcoder — the same value
     * S310's integration test uses, so a refusal means the producer decided rather
     * than that this harness ran out of patience.
     */
    private const SEGMENT_WAIT_MS = 90_000;

    /** Accepting processes in the controller-backed server. Mirrors `$httpWorker->count`. */
    private const SERVER_WORKERS = 4;

    /**
     * Wall clock for the whole probe. Generous because every segment is encoded
     * WHILE THE PLAYER WAITS — unlike S57, which pre-produces all four up front.
     */
    private const PROBE_TIMEOUT_MS = 120_000;

    private string $root;
    private string $node;
    private string $chrome;
    private string $hlsjs;

    /**
     * Servers still running, keyed by id so {@see stopServer()} can remove itself
     * from the teardown list and be safe to call twice.
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
        $node = BrowserProbeEnvironment::node();
        if ($node === null) {
            $this->markTestSkipped('node (>= 22, for the built-in WebSocket client) not available');
        }
        $this->node = $node;

        $chrome = BrowserProbeEnvironment::chrome();
        if ($chrome === null) {
            $this->markTestSkipped('no Chrome/Chromium binary — the hls.js check needs a real browser');
        }
        $this->chrome = $chrome;

        $hlsjs = BrowserProbeEnvironment::hlsJs();
        if ($hlsjs === null) {
            $this->markTestSkipped(
                'web-ui/node_modules/hls.js is not installed (php scripts/ci-browser-e2e-prereqs.php)'
            );
        }
        $this->hlsjs = $hlsjs;

        $this->root = sys_get_temp_dir() . '/phlix_s315_e2e_' . uniqid();
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
    }

    // ─────────────────────────────────────────────────────────────────
    // the acceptance criterion
    // ─────────────────────────────────────────────────────────────────

    /**
     * ⚠ THE CASE S315 EXISTS FOR.
     *
     * Real hls.js. Real Chrome. Real socket. Real `HlsController::serveFile()`. No
     * segment on disk when it starts.
     */
    public function testHlsJsPlaysAnFmp4PresentationServedEntirelyByTheRealController(): void
    {
        $jobId = 's315-fmp4';
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

        // ⚠ S60 — the page is served under the SPA's REAL Content-Security-Policy.
        //
        // S315 ran this case with no policy at all and said so: nothing was
        // edited, every URL was same-origin, and asserting CSP would have been a
        // claim with no measurement behind it. The header below is not a
        // reconstruction — it is read off a real `SharedUiController::shell()`
        // response, i.e. the exact bytes the SPA that plays this content serves,
        // built by `SecurityHeaders::contentSecurityPolicy()`. Under it Chrome
        // has to permit: `/__hls.js` (`script-src 'self'`), the playlist and
        // segment fetches (`connect-src 'self'`), hls.js's `blob:` transmux
        // worker (`worker-src`) and the MSE `blob:` object URL on the `<video>`
        // element (`media-src`). The nonce is taken from the SPA's HTML BODY, not
        // from its header, so a header/body mismatch shows up as the inline
        // script never running rather than being papered over.
        //
        // That the policy is genuinely ENFORCED (and not merely present) is the
        // job of {@see testRemovingBlobFromMediaSrcBlocksPlaybackUnderTheSamePolicy()}.
        $spa = $this->spaContentSecurityPolicy();

        $server = $this->startServer($jobId, self::SERVER_WORKERS);
        $probe = $this->probe($dir, $server, $spa['csp'], $spa['nonce']);

        // The probe really ran in controller-backed mode. A silent fallback to
        // reading the directory would pass every assertion below while proving the
        // opposite of what this file claims.
        $this->assertSame(
            $this->upstreamOf($server, $jobId),
            $probe['upstream'] ?? null,
            'the probe did not run in --upstream mode, so these bytes did NOT come from the controller'
        );
        // …and it really ran under the policy, for the same reason.
        $this->assertSame(
            $spa['csp'],
            $probe['csp'] ?? null,
            'the probe did not serve the SPA policy, so nothing below is evidence about CSP'
        );
        $this->assertSame([], $probe['cspViolations'] ?? null, 'the SPA CSP refused something');

        $this->assertTrue(
            $probe['ok'] === true,
            'hls.js did not play the controller-served fMP4 playlist: ' . json_encode($probe, JSON_PRETTY_PRINT)
        );
        $this->assertSame([], $this->fatalErrors($probe), 'hls.js raised a fatal error');

        // The browser's own census: EXT-X-MAP honoured, once, successfully…
        $this->assertSame(
            [['name' => 'init-v360p.m4s', 'status' => 200]],
            $this->requestsMatching($probe, '/^init-/'),
            'the player must have fetched exactly this variant\'s init segment, once, successfully'
        );
        // …and the boundary crossed: two distinct media fragments, in order.
        $this->assertSame(
            [
                ['name' => 'seg-v360p-00000.m4s', 'status' => 200],
                ['name' => 'seg-v360p-00001.m4s', 'status' => 200],
            ],
            array_slice($this->requestsMatching($probe, '/^seg-/'), 0, 2)
        );

        // Pixels really came out the other end.
        $this->assertGreaterThanOrEqual(self::PLAY_TO_SECONDS, $probe['currentTime']);
        $this->assertGreaterThan(0, $probe['decodedFrames'], 'no video frame was decoded');
        $this->assertSame(640, $probe['videoWidth']);
        $this->assertSame(360, $probe['videoHeight']);

        // The SERVER's census, produced independently of the browser's.
        $served = $this->serverLog($server);
        $this->assertNotSame([], $served, 'the controller answered nothing at all');

        foreach (['master.m3u8', 'media_v360p.m3u8', 'init-v360p.m4s', 'seg-v360p-00000.m4s'] as $name) {
            $entry = $this->firstServed($served, $name);
            $this->assertNotNull($entry, "the controller never saw a request for {$name}");
            $this->assertSame(200, $entry['status'], "the controller refused {$name}");
            $this->assertGreaterThan(0, $entry['bytes'], "{$name} was served with an empty body");
        }

        // The content types are the controller's, not the probe's MIME table: in
        // --upstream mode the probe passes the upstream header through verbatim.
        $this->assertSame('video/mp4', $this->firstServed($served, 'init-v360p.m4s')['contentType']);
        $this->assertSame('video/mp4', $this->firstServed($served, 'seg-v360p-00000.m4s')['contentType']);
        $this->assertSame(
            'application/vnd.apple.mpegurl',
            $this->firstServed($served, 'master.m3u8')['contentType']
        );
        $this->assertTrue(
            $this->firstServed($served, 'seg-v360p-00000.m4s')['fileBacked'],
            'segments must leave through Workerman\'s native withFile() sender, as they do in production'
        );

        // Denominator #2 — the on-demand claim, closed. The segments exist NOW and
        // did not before, so the producer ran because of these requests.
        $after = $this->m4sIn($dir);
        $this->assertContains('init-v360p.m4s', $after);
        $this->assertContains('seg-v360p-00000.m4s', $after);
        $this->assertContains('seg-v360p-00001.m4s', $after);

        // Every request the controller answered, with its status — the denominator in
        // full rather than a count. A census that only says "8" cannot distinguish a
        // presentation that played from one that 404'd its way to a stall.
        $this->report(sprintf(
            'played %.2fs, %d frames, %dx%d | .m4s on disk: 0 before, %d after | '
            . 'browser requests: %d | controller: %s',
            $probe['currentTime'],
            $probe['decodedFrames'],
            $probe['videoWidth'],
            $probe['videoHeight'],
            count($after),
            count($probe['requests']),
            implode(' ', array_map(
                static fn (array $e): string => "{$e['name']}:{$e['status']}",
                $served
            ))
        ));

        // Nothing the controller refused. hls.js retries a failed fragment, so a
        // presentation can reach the play target with a 404 in its history — which
        // would make "it played" true and "the serve path works" false.
        $this->assertSame(
            [],
            array_values(array_filter($served, static fn (array $e): bool => $e['status'] !== 200)),
            'the controller refused a request during a run that nonetheless played'
        );
    }

    /**
     * THE CONTROL THAT MAKES THE ABOVE MEAN SOMETHING.
     *
     * Same job, same server, same probe — with the single `#EXT-X-MAP` line deleted
     * from the media playlist the controller serves. hls.js must FAIL. Without this,
     * the pass above would be evidence that the player tolerates whatever it is
     * given, not that this presentation is correct; and a probe broken in some
     * unrelated way would report "no fatal errors" from a page that loaded nothing.
     */
    public function testRemovingTheExtXMapBreaksPlaybackThroughTheController(): void
    {
        $jobId = 's315-nomap';
        $dir = $this->buildJob($jobId);

        $media = "{$dir}/media_v360p.m3u8";
        $before = (string) file_get_contents($media);
        $after = (string) preg_replace('/^#EXT-X-MAP:.*\R/m', '', $before);
        $this->assertNotSame($before, $after, 'the fixture must actually have carried an EXT-X-MAP to remove');
        file_put_contents($media, $after);

        $server = $this->startServer($jobId, self::SERVER_WORKERS);
        $probe = $this->probe($dir, $server);

        $this->assertSame($this->upstreamOf($server, $jobId), $probe['upstream'] ?? null);
        $this->assertFalse(
            $probe['ok'] === true,
            'hls.js played a controller-served fMP4 playlist with NO EXT-X-MAP: '
            . json_encode($probe, JSON_PRETTY_PRINT)
        );
        $this->assertNotSame([], $probe['errors'], 'the failure must be a reported hls.js error, not silence');

        // …and the failure is not "the harness served nothing". The controller
        // answered the playlists; it is the CONTENT that is broken.
        $served = $this->serverLog($server);
        $this->assertNotNull($this->firstServed($served, 'master.m3u8'));
        $this->assertSame(200, $this->firstServed($served, 'master.m3u8')['status']);
        $this->assertNotNull(
            $this->firstServed($served, 'media_v360p.m3u8'),
            'the control must have got as far as the media playlist, or it fails for the wrong reason'
        );
        $this->assertStringNotContainsString(
            'EXT-X-MAP',
            (string) file_get_contents($media),
            'the mutation must still be in place at the end of the case'
        );

        $this->report(sprintf(
            'negative control: ok=%s, %d hls.js errors, %d controller requests',
            $probe['ok'] === true ? 'true' : 'false',
            count($probe['errors']),
            count($served)
        ));
    }

    /**
     * ⚠ THE HARNESS CONTROL, IN BOTH DIRECTIONS.
     *
     * `TranscodeManager::ensureSegment()` polls for its encode with a blocking
     * `usleep()` whenever it is not inside a Swoole coroutine. A test server with one
     * accepting process therefore serialises every request, and hls.js's requests
     * pile up behind each other: the player stalls, and the stall reads as "the
     * controller is broken" when it is the harness. Any red measured on a serialising
     * server would be a false one.
     *
     * So concurrency is MEASURED, not assumed, and measured against a control that
     * must produce the opposite answer:
     *
     *   - {@see self::SERVER_WORKERS} workers ⇒ at least one pair of requests
     *     genuinely in flight at the same instant, answered by ≥2 distinct pids;
     *   - ONE worker, same fixture, same four requests ⇒ ZERO overlapping pairs and
     *     exactly one pid.
     *
     * The overlap arithmetic is the same in both, so "we found overlaps" cannot be a
     * detector that always says yes. Timestamps are `hrtime()`, which is monotonic
     * AND drawn from a clock shared across processes on Linux — `microtime()` would
     * be comparable but not monotonic, and a per-process clock would make the
     * cross-pid comparison meaningless.
     *
     * FOUR requests against FOUR workers, not three: Workerman's children share one
     * listen socket and the kernel decides which wakes, so the distribution is not
     * round-robin. The only arrangement that produces zero overlaps on a concurrent
     * server is ALL requests landing in ONE worker, and a fourth request makes that
     * arrangement much rarer without changing what is being claimed. (Measured across
     * five runs at three requests: 3, 3, 3, 2, 1 overlapping pairs — never 0, but the
     * margin was thinner than it needed to be.)
     */
    public function testTheControllerBackedServerIsGenuinelyConcurrent(): void
    {
        // 12 s at 3 s ⇒ indices 0..3, and every one is a distinct `-ss` encode: only
        // index 0's encode publishes the init, so no request here is a cache hit.
        $files = [
            'seg-v360p-00000.m4s',
            'seg-v360p-00001.m4s',
            'seg-v360p-00002.m4s',
            'seg-v360p-00003.m4s',
        ];
        $expected = array_fill(0, count($files), 200);

        // ── the measurement ──────────────────────────────────────────
        $parallelJob = 's315-parallel';
        $parallelDir = $this->buildJob($parallelJob);
        $this->assertSame([], $this->m4sIn($parallelDir), 'every request below must be real encode work');

        $parallel = $this->startServer($parallelJob, self::SERVER_WORKERS);
        $parallelStatuses = $this->fetchInParallel($parallel, $parallelJob, $files);
        $this->assertSame($expected, $parallelStatuses, 'the concurrent fetches did not all succeed');

        $parallelLog = $this->serverLog($parallel);
        $this->assertCount(count($files), $parallelLog, 'denominator: every request was measured, once');
        foreach ($parallelLog as $entry) {
            $this->assertGreaterThan(
                0,
                $entry['endNs'] - $entry['startNs'],
                'a zero-length interval cannot overlap anything — this measurement would be vacuous'
            );
        }

        $overlaps = $this->overlappingPairs($parallelLog);
        $pids = $this->distinctPids($parallelLog);

        // ── the control ──────────────────────────────────────────────
        $serialJob = 's315-serial';
        $serialDir = $this->buildJob($serialJob);
        $this->assertSame([], $this->m4sIn($serialDir));

        $serial = $this->startServer($serialJob, 1);
        $serialStatuses = $this->fetchInParallel($serial, $serialJob, $files);
        $this->assertSame($expected, $serialStatuses, 'the one-worker control did not serve the same set');

        $serialLog = $this->serverLog($serial);
        $this->assertCount(count($files), $serialLog);
        $serialOverlaps = $this->overlappingPairs($serialLog);
        $serialPids = $this->distinctPids($serialLog);

        $maxPairs = count($files) * (count($files) - 1) / 2;
        $this->report(sprintf(
            'concurrency: %d workers ⇒ %d/%d overlapping pairs across %d pids; '
            . '1 worker ⇒ %d/%d overlapping pairs across %d pid(s); durations(ms) %s vs %s',
            self::SERVER_WORKERS,
            $overlaps,
            $maxPairs,
            $pids,
            $serialOverlaps,
            $maxPairs,
            $serialPids,
            $this->durationsMs($parallelLog),
            $this->durationsMs($serialLog)
        ));

        $this->assertSame(
            0,
            $serialOverlaps,
            'the ONE-worker control overlapped, so the overlap arithmetic is not measuring concurrency'
        );
        $this->assertSame(1, $serialPids, 'the one-worker control used more than one process');

        $this->assertGreaterThanOrEqual(
            1,
            $overlaps,
            'the multi-worker server served every request one after another, so it is NOT concurrent — '
            . 'any playback stall measured against it would be the harness, not HlsController'
        );
        $this->assertGreaterThanOrEqual(2, $pids, 'only one process ever answered');
    }

    /**
     * ⚠ WAS THE SCOPE GUARD; IS NOW THE PIN.
     *
     * Until S60 this case was `testTheShippedDefaultIsStillMpegTs` and existed to
     * stop an earlier step flipping the default by accident: fMP4 is reached above
     * by an EXPLICIT `transcoding.segment_format` override read through the real
     * settings path, and "the test passes" and "the shipped behaviour changed"
     * would otherwise be indistinguishable from the outside.
     *
     * S60 is the step that legitimately flips it, so the guard is re-pointed
     * rather than removed, with equal strength: the default, the config literal
     * that mirrors it, and the empty fingerprint that made the
     * `JOB_KEY_VERSION` bump necessary. ⚠ It is registered BY NAME in
     * {@see BrowserProbeEnvironment::REQUIRED_CASES_BY_CLASS}, so the rename is
     * mirrored there — `scripts/assert-browser-e2e-ran.php` iterates that map and
     * a stale entry is a CI red, correctly.
     */
    public function testTheShippedDefaultIsFmp4(): void
    {
        $this->assertSame(
            EncodeSettings::FORMAT_FMP4,
            EncodeSettings::DEFAULT_SEGMENT_FORMAT,
            'S60 flipped this; reverting it must also revert TranscodeManager::JOB_KEY_VERSION to v9'
        );
        $this->assertSame(EncodeSettings::FORMAT_FMP4, (new EncodeSettings())->segmentFormat());
        $this->assertSame(
            '',
            (new EncodeSettings())->fingerprint(),
            'the fingerprint is empty at the shipped default WHATEVER that default is — which is '
            . 'precisely why the flip could not express itself there and needed the JOB_KEY_VERSION bump'
        );

        // The config file is the other half of the same default and must agree:
        // it is what SettingsRepository::getDefault() reports as the effective
        // value when there is no override row.
        $config = require dirname(__DIR__, 4) . '/config/transcoding.php';
        $this->assertIsArray($config);
        $this->assertSame(EncodeSettings::FORMAT_FMP4, $config['segment_format'] ?? null);

        // The control, now pointing the other way: an EXPLICIT mpegts override —
        // the documented rollback — must still resolve to MPEG-TS, so the
        // assertions above measure "the default is fmp4" rather than "everything
        // is fmp4 now".
        $this->assertSame(EncodeSettings::FORMAT_MPEGTS, $this->mpegtsSettings()->segmentFormat());
        $this->assertSame(EncodeSettings::FORMAT_FMP4, $this->fmp4Settings()->segmentFormat());
    }

    /**
     * ⚠ THE CSP CONTROL — the case that makes the `csp` assertions in
     * {@see testHlsJsPlaysAnFmp4PresentationServedEntirelyByTheRealController()}
     * mean something.
     *
     * S315 deliberately did NOT assert CSP, and gave the right reason: it edited
     * nothing, every URL was same-origin, and an assertion would have been "a
     * claim with no measurement behind it". Serving the real header solves half
     * of that — but only half. A page served WITH a policy that happens to permit
     * everything is indistinguishable from a page served with no policy at all,
     * and both play. So the policy has to be shown to BITE.
     *
     * The mutation is one token: `blob:` removed from `media-src`, nothing else
     * touched. That is the exact directive hls.js needs — it attaches an MSE
     * `blob:` object URL to the `<video>` element — and it is one of the two
     * directives `SecurityHeaders::contentSecurityPolicy()` carries `blob:` on.
     * Under the mutated policy playback must FAIL and the browser must report a
     * `media-src` violation by name.
     *
     * Cheap by design: it fails as soon as the blob URL is refused, so it does
     * not pay for four seconds of real-time playback or for four on-demand
     * encodes.
     */
    public function testRemovingBlobFromMediaSrcBlocksPlaybackUnderTheSamePolicy(): void
    {
        $shipped = $this->spaContentSecurityPolicy();
        $mutated = str_replace("media-src 'self' blob:", "media-src 'self'", $shipped['csp']);
        $this->assertNotSame(
            $shipped['csp'],
            $mutated,
            'the mutation did not apply — SecurityHeaders no longer spells media-src the expected way, '
            . 'so this control would have re-run the positive case and passed for the wrong reason'
        );

        $jobId = 's60-csp-control';
        $dir = $this->buildJob($jobId);
        $server = $this->startServer($jobId, self::SERVER_WORKERS);
        $probe = $this->probe($dir, $server, $mutated, $shipped['nonce']);

        $this->assertSame($mutated, $probe['csp'] ?? null, 'the mutated policy was not the one served');

        $this->report(sprintf(
            'CSP control: ok=%s, %d violations %s, %d hls.js errors',
            $probe['ok'] === true ? 'true' : 'false',
            count($probe['cspViolations'] ?? []),
            json_encode(array_values(array_unique(array_map(
                static fn (array $v): string => (string) ($v['effectiveDirective'] ?? '?'),
                $probe['cspViolations'] ?? []
            )))),
            count($probe['errors'])
        ));

        $this->assertFalse(
            $probe['ok'] === true,
            'hls.js played with blob: removed from media-src, so the CSP header the harness serves is '
            . 'NOT being enforced and the positive case proves nothing about CSP: '
            . json_encode($probe, JSON_PRETTY_PRINT)
        );
        $this->assertContains(
            'media-src',
            array_map(
                static fn (array $v): string => (string) ($v['effectiveDirective'] ?? ''),
                $probe['cspViolations'] ?? []
            ),
            'the failure must be the media-src refusal specifically, not some unrelated breakage'
        );

        // …and the failure is not "the harness served nothing": the controller
        // still answered the playlists, exactly as in the positive case.
        $served = $this->serverLog($server);
        $this->assertNotNull($this->firstServed($served, 'master.m3u8'));
        $this->assertSame(200, $this->firstServed($served, 'master.m3u8')['status']);
    }

    /**
     * ⚠ THE LEAK GUARD, and it caught a real one.
     *
     * The first working version of this harness left a five-process Workerman server
     * running after EVERY case, forever, and every test still passed. `proc_open()`
     * runs its command under `/bin/sh -c`; this shell did not exec away, so
     * `proc_terminate()` killed the SHELL and the master it had started was
     * reparented to init and went on serving. The only symptom was a line in `ps`,
     * which no assertion reads.
     *
     * So the shutdown is asserted, on two independent facts: the master's EXIT CODE
     * (0 means Workerman stopped its own workers; a SIGKILL escalation would not
     * produce it) and the LISTENING PORT (still accepting means a worker outlived the
     * master, which is exactly the leak shape above).
     *
     * Cheap on purpose: no encode, no browser. It measures the harness, not the
     * controller.
     */
    public function testTheHarnessServerShutsDownCleanlyAndLeaksNoListener(): void
    {
        $jobId = 's315-shutdown';
        $this->buildJob($jobId);
        $server = $this->startServer($jobId, self::SERVER_WORKERS);

        // The positive control: it really was listening before we stopped it, so
        // "the port is closed" is a change of state rather than a permanent truth.
        $socket = @stream_socket_client("tcp://127.0.0.1:{$server['port']}", $errno, $errstr, 2.0);
        $this->assertIsResource($socket, "the server was not listening to begin with: {$errstr}");
        fclose($socket);

        $result = $this->stopServer($server);

        $this->report(sprintf(
            'shutdown: exit=%d portClosed=%s',
            $result['exitCode'],
            $result['portClosed'] ? 'true' : 'false'
        ));

        $this->assertSame(
            0,
            $result['exitCode'],
            'the Workerman master did not exit cleanly on SIGTERM. A non-zero code here means the '
            . 'harness had to escalate, and an escalation is what orphans the worker processes.'
        );
        $this->assertTrue(
            $result['portClosed'],
            'the port still accepts after the master exited — a worker process was orphaned and is '
            . 'still serving. See the `exec ` note in startServer().'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // building a real job
    // ─────────────────────────────────────────────────────────────────

    /**
     * Writes the playlists through the real `writeVodPlaylists()` (reached via
     * `ensurePlaylistRegenerated()`) and produces NOT ONE segment — that is the
     * controller's job here, and the whole point.
     *
     * The fixture is deliberately identical in shape to {@see Fmp4HlsPlaybackE2ETest}'s
     * (one 360p rung, 12 s, 3 s segments, ultrafast/crf30), so the only difference
     * between that file's result and this one's is WHO SERVES THE BYTES.
     *
     * @return string the job directory
     */
    private function buildJob(string $jobId): string
    {
        $clip = $this->makeClip();
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
                'preset' => 'ultrafast',
                'crf' => 30,
                'audio_codec' => 'aac',
                // Read from the REAL settings reader rather than typed as a literal:
                // this is the value a job created with `transcoding.segment_format`
                // set to `fmp4` over the admin API (S313) persists — see
                // TranscodeManager's "S56: stamp the container the job is being
                // CREATED with" block.
                'segment_format' => $this->fmp4Settings()->segmentFormat(),
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

    /**
     * The real {@see EncodeSettings}, reading a `transcoding.segment_format` override
     * of `fmp4` through the real {@see SettingsRepository} seam — i.e. exactly what
     * an operator gets by PUTting the key S313 added to the schema. No default is
     * touched anywhere.
     */
    private function fmp4Settings(): EncodeSettings
    {
        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === EncodeSettings::SEGMENT_FORMAT_KEY
                ? EncodeSettings::FORMAT_FMP4
                : null
        );

        return new EncodeSettings($repository);
    }

    /**
     * The real {@see EncodeSettings} reading an EXPLICIT `mpegts` override — the
     * documented S60 rollback (`PUT /api/v1/admin/settings`). Used only as the
     * control in {@see testTheShippedDefaultIsFmp4()}.
     */
    private function mpegtsSettings(): EncodeSettings
    {
        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === EncodeSettings::SEGMENT_FORMAT_KEY
                ? EncodeSettings::FORMAT_MPEGTS
                : null
        );

        return new EncodeSettings($repository);
    }

    /**
     * The SPA's OWN `Content-Security-Policy` header and the nonce on its inline
     * bootstrap `<script>`, taken from a real {@see SharedUiController::shell()}
     * response over a minimal Vite-manifest fixture.
     *
     * Read off the response rather than rebuilt from
     * {@see \Phlix\Server\Http\Middleware\SecurityHeaders::contentSecurityPolicy()}
     * directly, because it is the SPA shell that is load-bearing here: `shell()`
     * mints a per-request nonce, stamps it on the inline block AND passes it to
     * the builder, and it is that pairing the browser has to accept. The nonce is
     * extracted from the BODY, so the two sides of the pairing arrive here from
     * different places and a mismatch cannot cancel out.
     *
     * @return array{csp: string, nonce: string}
     */
    private function spaContentSecurityPolicy(): array
    {
        $root = "{$this->root}/spa";
        $appDir = "{$root}/assets/app/.vite";
        mkdir($appDir, 0755, true);
        file_put_contents(
            "{$appDir}/manifest.json",
            (string) json_encode(['index.html' => ['file' => 'assets/index-s60.js', 'isEntry' => true]])
        );
        file_put_contents(
            "{$root}/assets/app/index.html",
            '<!DOCTYPE html><html><head><title>Phlix</title></head><body></body></html>'
        );

        $response = (new SharedUiController($root))->shell(new HttpRequest());
        $this->assertSame(200, $response->statusCode, 'the SPA shell fixture did not render');

        $csp = $response->headers['Content-Security-Policy'] ?? '';
        $this->assertIsString($csp);
        $this->assertNotSame('', $csp, 'the SPA shell served no CSP header');

        // The directives this whole exercise is about, asserted on the SPA's own
        // response before the browser is ever asked. ⚠ ASSERT, NEVER EDIT — S60
        // changes no CSP, and these three are what make browser HLS possible at
        // all (hls.js drives an MSE `blob:` object URL and a `blob:`-sourced
        // transmux Web Worker, and fetches every playlist/segment same-origin).
        $this->assertStringContainsString("connect-src 'self';", $csp);
        $this->assertStringContainsString("media-src 'self' blob:;", $csp);
        $this->assertStringContainsString("worker-src 'self' blob:;", $csp);

        $this->assertSame(
            1,
            preg_match('/<script nonce="([^"]+)">window\.__PHLIX__ = /', $response->body, $m),
            'the SPA shell did not stamp a nonce on its inline bootstrap script'
        );
        $nonce = (string) $m[1];
        $this->assertStringContainsString(
            "'nonce-{$nonce}'",
            $csp,
            'the SPA shell\'s header and body nonces disagree'
        );

        return ['csp' => $csp, 'nonce' => $nonce];
    }

    private function makeClip(): string
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

    // ─────────────────────────────────────────────────────────────────
    // the controller-backed server
    // ─────────────────────────────────────────────────────────────────

    /**
     * Starts `tests/Support/Browser/hls-controller-server.php` and waits until its
     * FORKED WORKERS are accepting — not merely until the master's listen backlog
     * exists, which a bare TCP connect would satisfy while proving nothing.
     *
     * @return ServerHandle
     */
    private function startServer(string $jobId, int $workers): array
    {
        $script = dirname(__DIR__, 3) . '/Support/Browser/hls-controller-server.php';
        $this->assertFileExists($script);

        $slug = "{$jobId}-w{$workers}";
        $log = "{$this->root}/{$slug}-requests.jsonl";
        $out = "{$this->root}/{$slug}-server.out";

        $lastError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = $this->freePort();
            // ⚠ `exec ` is load-bearing, and its absence is invisible until you look
            // at `ps`. proc_open() runs the command under `/bin/sh -c`, and this
            // shell does NOT optimise the fork away (measured: `ps` showed a live
            // `sh -c '…php…'` parent). proc_terminate() then signals the SHELL,
            // which dies while the Workerman master it started is reparented to
            // init and keeps serving — a leaked 5-process server per case, on every
            // run, with the test still green. `exec` makes php REPLACE the shell, so
            // the pid proc_open reports is the master's.
            $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [
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
                // Workerman's own command word. Passed here rather than injected by
                // the script so the command in a failure message can be re-run.
                'start',
            ]));

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
     * Stops a server and reports HOW it stopped.
     *
     * The exit code is returned rather than discarded because it is the difference
     * between "Workerman shut its workers down" (0) and "the harness had to SIGKILL
     * something" — and because the `exec ` note in {@see startServer()} describes a
     * failure mode whose only symptom was a stray line in `ps`.
     *
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
            // SIGTERM: Workerman's master stops every child (and SIGKILLs one that
            // is still blocked in a segment poll after its stop timeout).
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

        // A port that still accepts after the master is gone means a worker outlived
        // it — the leak shape described in startServer(). Measured, not assumed.
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

    /**
     * An ephemeral port the OS just handed out. There is an unavoidable race between
     * releasing it and the server binding it, which is why {@see startServer()}
     * retries rather than failing on the first refusal.
     */
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
     * @param ServerHandle $server
     */
    private function upstreamOf(array $server, string $jobId): string
    {
        return "http://127.0.0.1:{$server['port']}/hls/{$jobId}";
    }

    /**
     * The controller's OWN census of what it answered — written by the server
     * process, one JSON object per request, entirely independently of anything the
     * browser or the probe reports.
     *
     * @param ServerHandle $server
     *
     * @return list<array{pid: int, name: string, status: int, contentType: ?string,
     *                    fileBacked: bool, bytes: int, startNs: int, endNs: int}>
     */
    private function serverLog(array $server): array
    {
        $this->assertFileExists($server['log'], 'the server never created its request log');

        $entries = [];
        foreach (preg_split('/\R/', (string) file_get_contents($server['log'])) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "unparseable line in the server request log: {$line}");
            /** @var array{pid: int, name: string, status: int, contentType: ?string,
             *      fileBacked: bool, bytes: int, startNs: int, endNs: int} $decoded */
            $entries[] = $decoded;
        }

        return $entries;
    }

    /**
     * @param list<array{pid: int, name: string, status: int, contentType: ?string,
     *                   fileBacked: bool, bytes: int, startNs: int, endNs: int}> $entries
     *
     * @return array{pid: int, name: string, status: int, contentType: ?string,
     *               fileBacked: bool, bytes: int, startNs: int, endNs: int}|null
     */
    private function firstServed(array $entries, string $name): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Three GETs issued at the same instant on three connections.
     *
     * @param ServerHandle $server
     * @param list<string> $files
     *
     * @return list<int> the HTTP status of each, in the order requested
     */
    private function fetchInParallel(array $server, string $jobId, array $files): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($files as $file) {
            $handle = curl_init($this->upstreamOf($server, $jobId) . '/' . $file);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_TIMEOUT, (int) ceil(self::SEGMENT_WAIT_MS / 1000) + 30);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }

        $running = null;
        do {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.1);
            }
        } while ($running > 0);

        $statuses = [];
        foreach ($handles as $handle) {
            $statuses[] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        return $statuses;
    }

    /**
     * Pairs of requests whose `[startNs, endNs]` windows intersect — i.e. that were
     * genuinely in flight at the same instant.
     *
     * @param list<array{startNs: int, endNs: int}> $entries
     */
    private function overlappingPairs(array $entries): int
    {
        $pairs = 0;
        $count = count($entries);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (
                    $entries[$i]['startNs'] < $entries[$j]['endNs']
                    && $entries[$j]['startNs'] < $entries[$i]['endNs']
                ) {
                    $pairs++;
                }
            }
        }

        return $pairs;
    }

    /**
     * @param list<array{pid: int}> $entries
     */
    private function distinctPids(array $entries): int
    {
        return count(array_unique(array_column($entries, 'pid')));
    }

    /**
     * @param list<array{startNs: int, endNs: int}> $entries
     */
    private function durationsMs(array $entries): string
    {
        return '[' . implode(', ', array_map(
            static fn (array $e): string => sprintf('%.0f', ($e['endNs'] - $e['startNs']) / 1_000_000),
            $entries
        )) . ']';
    }

    // ─────────────────────────────────────────────────────────────────
    // the browser
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param ServerHandle $server
     * @param string|null  $csp   S60: the Content-Security-Policy to serve the page
     *                            under, VERBATIM. Null = no policy at all (the
     *                            pre-S60 behaviour), in which case nothing about
     *                            CSP may be concluded from the result.
     * @param string|null  $nonce The nonce to stamp on the page's own inline
     *                            script. Required whenever `$csp` carries a
     *                            `'nonce-…'` source, or the page's script does not
     *                            run and the failure has nothing to do with media.
     *
     * @return array{
     *     ok: bool, reason: ?string, errors: list<array<string, mixed>>, upstream: ?string,
     *     csp: ?string, scriptNonce: ?string,
     *     cspViolations: list<array{effectiveDirective: ?string, blockedURI: ?string}>,
     *     initSegments: list<string>, fragments: list<string>, levels: list<array<string, mixed>>,
     *     requests: list<array{name: string, status: int, bytes: int}>,
     *     currentTime: float, decodedFrames: int, videoWidth: int, videoHeight: int
     * }
     */
    private function probe(string $dir, array $server, ?string $csp = null, ?string $nonce = null): array
    {
        $script = dirname(__DIR__, 3) . '/Support/Browser/hls-playback-probe.mjs';
        $this->assertFileExists($script);

        $jobId = basename($dir);
        $args = [
            $this->node,
            $script,
            '--dir',
            $dir,
            '--upstream',
            $this->upstreamOf($server, $jobId),
            '--hlsjs',
            $this->hlsjs,
            '--chrome',
            $this->chrome,
            '--playlist',
            'master.m3u8',
            '--seconds',
            (string) self::PLAY_TO_SECONDS,
            '--timeout',
            (string) self::PROBE_TIMEOUT_MS,
        ];
        if ($csp !== null) {
            $args[] = '--csp';
            $args[] = $csp;
        }
        if ($nonce !== null) {
            $args[] = '--script-nonce';
            $args[] = $nonce;
        }
        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        exec($cmd, $output, $code);
        $text = implode("\n", $output);
        $this->assertSame(0, $code, "the browser probe could not run:\n{$text}");

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded, "the probe did not emit JSON:\n{$text}");
        // A probe that never reached the page would report `hlsSupported: null` and
        // every list empty — which must not read as a pass anywhere above.
        $this->assertTrue(
            $decoded['hlsSupported'] === true,
            "hls.js reported MSE unsupported in this browser build:\n{$text}"
        );

        /** @var array{ok: bool, reason: ?string, errors: list<array<string, mixed>>, upstream: ?string,
         *      csp: ?string, scriptNonce: ?string,
         *      cspViolations: list<array{effectiveDirective: ?string, blockedURI: ?string}>,
         *      initSegments: list<string>, fragments: list<string>, levels: list<array<string, mixed>>,
         *      requests: list<array{name: string, status: int, bytes: int}>, currentTime: float,
         *      decodedFrames: int, videoWidth: int, videoHeight: int} $decoded */
        return $decoded;
    }

    /**
     * The probe server's request log, filtered by filename and reduced to
     * `{name, status}` so a comparison reads legibly on failure.
     *
     * @param array{requests: list<array{name: string, status: int, bytes: int}>} $probe
     *
     * @return list<array{name: string, status: int}>
     */
    private function requestsMatching(array $probe, string $pattern): array
    {
        $out = [];
        foreach ($probe['requests'] as $request) {
            if (preg_match($pattern, $request['name']) === 1) {
                $out[] = ['name' => $request['name'], 'status' => $request['status']];
            }
        }

        return $out;
    }

    /**
     * @param array{errors: list<array<string, mixed>>} $probe
     *
     * @return list<array<string, mixed>>
     */
    private function fatalErrors(array $probe): array
    {
        return array_values(array_filter(
            $probe['errors'],
            static fn (array $e): bool => ($e['fatal'] ?? false) === true
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    // environment
    // ─────────────────────────────────────────────────────────────────

    /** @return list<string> Every `.m4s` currently in a job dir, sorted. */
    private function m4sIn(string $dir): array
    {
        $found = array_map('basename', glob("{$dir}/*.m4s") ?: []);
        sort($found);

        return array_values($found);
    }

    /**
     * The denominators, on STDERR.
     *
     * STDERR rather than `echo` because `phpunit.xml` sets
     * `beStrictAboutOutputDuringTests="true"` with `failOnRisky="true"`: output to
     * php://output would fail the run. These figures exist because a probe that
     * quietly stopped loading anything reports "no fatal errors" and reads as a
     * pass — the counts are how a reader tells the two apart without re-running.
     */
    private function report(string $line): void
    {
        fwrite(STDERR, "\n[S315] {$line}\n");
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
