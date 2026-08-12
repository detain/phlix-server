<?php

/**
 * S315 — a REAL HTTP server whose ONLY route is `HlsController::serveFile()`.
 *
 * ## Why this file exists
 *
 * S57's browser gate ({@see \Phlix\Tests\E2E\Media\Transcoding\Fmp4HlsPlaybackE2ETest})
 * has a real hls.js in a real headless Chrome and a FAKE server: the probe's own
 * static `node:http` handler reads the job directory off disk. Its header says so.
 * S310 ({@see \Phlix\Tests\Integration\Media\Transcoding\HlsFmp4OnDemandServeTest})
 * has the real controller and a fake client: it calls `serveFile()` in-process and
 * hands the bytes to ffmpeg's HLS demuxer. Neither subsumes the other, and S60's
 * acceptance criterion is the diagonal both of them miss — a real player, over a
 * real socket, against the real serve path.
 *
 * This script is that serve path, standing up on a port.
 *
 * ## What is real here, and what is not
 *
 * REAL: {@see Worker} (the same HTTP driver `start.php` runs), {@see Request::fromWorkerman()}
 * (the daemon's request constructor, not `fromGlobals()`), the real {@see Router}
 * carrying the same `'/hls/{job_id}/{file}'` pattern `Application::loadStreamingRoutes()`
 * registers, the real {@see HlsController} over the real {@see TranscodeManager} over
 * the real {@see FfmpegRunner}, and {@see Response::toWorkermanResponse()} — so a
 * segment leaves through Workerman's native `withFile()` sender exactly as it does in
 * production, rather than through the CGI `send()` fallback that HLS never uses there.
 *
 * NOT REAL, deliberately: the database (one JSON row replayed by
 * {@see StubJobRowConnection} — a PHPUnit mock cannot cross `proc_open()`), and the
 * route's middleware. Production wraps this route in `SignedUrlMiddleware` (+
 * `StreamLimitMiddleware`); both are omitted because this step is about whether a
 * BROWSER can play what the controller produces, and neither S310 nor S57 covered
 * auth either. Signed-URL behaviour has its own tests. Nothing here should be read as
 * evidence about it.
 *
 * ## Concurrency — the trap this file is shaped around
 *
 * `TranscodeManager::ensureSegment()` polls for the encode to publish, and OUTSIDE a
 * Swoole coroutine that poll is a blocking `usleep()` (`TranscodeManager.php`, the
 * `SEGMENT_POLL_INTERVAL_MS` loop). A single-process test server therefore SERIALISES
 * every in-flight request, and the resulting player stall looks exactly like "the
 * controller is broken" when it is the harness. So this server forks
 * `--workers=<n>` accepting processes, mirroring `start.php`'s `$httpWorker->count`.
 *
 * That is a claim, so it is measured rather than asserted: every request appends one
 * JSON line to `--log=` carrying its pid and its `hrtime()` start/end (monotonic, and
 * on Linux comparable ACROSS processes, unlike `microtime()`), and the test reads that
 * log to prove two requests were genuinely in flight at the same instant.
 *
 * The same log is the "the bytes came from the controller" evidence: the probe's
 * browser-side request list and this server-side list are produced independently and
 * the test compares them.
 *
 * ## S317 — why that measurement needed a BARRIER, and what was added for it
 *
 * S315 measured concurrency by firing four segment requests at once with `curl_multi`
 * and counting overlapping `[startNs, endNs]` windows. That is a SAMPLE of a race, and
 * it reddened master (run `31456728212`: `4 workers ⇒ 0/6 overlapping pairs across 1
 * pids`) and reproduces on a dev box roughly once in five runs. The cause is not slow
 * responses — the failing local reproduction measured 600-710 ms per request, longer
 * than several passing ones. It is that Workerman's children share ONE listen socket,
 * so which child `accept()`s is the kernel's choice: when a single child accepted all
 * four connections it then served them one at a time out of its own event loop, and the
 * four-worker arm measured identically to the one-worker control.
 *
 * Nothing the test can do to the *content* of a request fixes that, because the
 * distribution is decided at accept time. So the test now decides it, using two
 * additions here:
 *
 *  * **`/__hold/<name>`** — a request that blocks in a `usleep()` poll until
 *    `--hold-release=<file>` exists (or `--hold-max-ms` elapses, which answers 504 so a
 *    lost release is a loud failure and not a quiet pass). The blocking shape is
 *    deliberately the same one `ensureSegment()` has outside a coroutine: a worker
 *    inside it cannot run its event loop and therefore CANNOT accept another
 *    connection.
 *  * **the in-flight marker file** (`<log>.inflight`) — one JSON line appended the
 *    instant a request is dispatched, i.e. BEFORE it is answered. The completed-request
 *    log cannot say "a request has begun", only "a request finished", so it can never
 *    be the thing a barrier waits on.
 *
 * With those, the test opens one request, waits for its marker, and only THEN opens the
 * next — so the next connection cannot have been accepted by the blocked worker, and
 * the earlier request is still in flight by construction rather than by luck. See
 * `Fmp4HlsThroughControllerE2ETest::testTheControllerBackedServerIsGenuinelyConcurrent()`.
 *
 * ⚠ `/__hold` is a HARNESS endpoint and is not routed: nothing in `src/` grows a hold
 * path from this. It is logged like any other request (unlike `/__ready`, which must
 * stay out of the census) precisely because its window is half of the measurement.
 *
 * ## Usage
 *
 *   php tests/Support/Browser/hls-controller-server.php \
 *       --root=<segment dir> --job=<job id> --row=<row.json> --port=<n> \
 *       --log=<requests.jsonl> --pid=<pidfile> [--workers=4] [--max-wait-ms=90000] \
 *       [--hold-release=<file>] [--hold-max-ms=30000] start
 *
 * The trailing `start` is Workerman's own command word ({@see Worker::runAll()} exits
 * with a usage banner without it). It is passed by the caller rather than injected
 * here so the command line in a failure message is one that can be pasted and re-run.
 *
 * @package Phlix
 */

declare(strict_types=1);

use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Tests\Support\Browser\BrowserProbeEnvironment;
use Phlix\Tests\Support\Browser\StubJobRowConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;
use Workerman\Worker;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/**
 * Reads `--name=value` out of `$argv`. Absent and empty are the same thing: a
 * required option that arrived empty must abort, never default to something that
 * quietly serves the wrong directory.
 */
$option = static function (string $name, ?string $default = null) use ($argv): string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            $value = substr($arg, strlen($name) + 3);
            if ($value !== '') {
                return $value;
            }
        }
    }
    if ($default === null) {
        fwrite(STDERR, "S315 hls-controller-server: --{$name}= is required\n");
        exit(2);
    }

    return $default;
};

$root = $option('root');
$jobId = $option('job');
$rowFile = $option('row');
$port = (int) $option('port');
$logFile = $option('log');
$pidFile = $option('pid');
$workers = max(1, (int) $option('workers', '4'));
$maxWaitMs = max(1000, (int) $option('max-wait-ms', '90000'));
$segmentSeconds = max(1, (int) $option('segment-seconds', '6'));

// S317. The file whose APPEARANCE releases every in-flight `/__hold/<name>` request,
// and the ceiling past which a hold gives up and answers 504. The default is derived
// from the log path so the two files travel together; the test passes it explicitly.
$holdRelease = $option('hold-release', $logFile . '.release');
$holdMaxMs = max(1000, (int) $option('hold-max-ms', '30000'));

// S317. Appended to the instant a request is DISPATCHED. The request log below is
// written after the response, so it can only ever say "a request finished" — which is
// useless to a barrier that has to wait for "a request has begun".
$inFlightFile = $logFile . '.inflight';

if ($port <= 0) {
    fwrite(STDERR, "S315 hls-controller-server: --port must be a positive integer\n");
    exit(2);
}

// A fresh log per run, created in the MASTER so a reader cannot mistake "the file is
// not there yet" for "no request was served". Same for the in-flight marker file; and
// a release file left behind by an earlier run would disarm every hold, so it goes.
file_put_contents($logFile, '');
file_put_contents($inFlightFile, '');
file_put_contents($logFile . '.workers', '');
if (is_file($holdRelease)) {
    unlink($holdRelease);
}

Worker::$pidFile = $pidFile;
// Workerman writes its own log beside the start file by default, which would drop a
// stray `workerman.log` into tests/Support/Browser/ on every run.
Worker::$logFile = $logFile . '.workerman';

$worker = new Worker("http://127.0.0.1:{$port}");
$worker->count = $workers;
$worker->name = "s315-hls-controller:{$jobId}";

/**
 * Per-worker state, populated AFTER the fork. Captured by reference rather than
 * parked in a static or a global: each child gets its own copy, and nothing
 * request-scoped is ever written to it (the CARDINAL rule for resident memory —
 * this holds the router and the log path, both fixed for the process's lifetime).
 *
 * @var array{router: ?Router, log: string} $state
 */
$state = ['router' => null, 'log' => $logFile];

$worker->onWorkerStart = static function () use (
    &$state,
    $root,
    $rowFile,
    $maxWaitMs,
    $segmentSeconds,
    $logFile
): void {
    // S317 — every child announces itself. `/__ready` proves ONE worker is accepting,
    // which is all the browser cases need; the barrier needs to know that ALL of them
    // are, because it hands one connection to each in turn and a child that had not
    // forked yet would leave a connection in the backlog forever. Roll call rather than
    // repeated `/__ready` probes, because which child answers a probe is the same
    // kernel choice the barrier exists to stop depending on.
    file_put_contents($logFile . '.workers', getmypid() . "\n", FILE_APPEND | LOCK_EX);

    // Built per worker, after the fork — the same rule `start.php` follows for the
    // container, and the reason ffmpeg process bookkeeping stays per-process.
    $manager = new TranscodeManager(
        StubJobRowConnection::fromJsonFile($rowFile),
        new FfmpegRunner(BrowserProbeEnvironment::FFMPEG, BrowserProbeEnvironment::FFPROBE, $root),
        $root,
        null,
        $segmentSeconds,
        null,
        null,
        null,
        null,
        null,
        null,
        $maxWaitMs
    );

    // Constructed exactly as `Application::getHlsController()` does.
    $controller = new HlsController(
        new HlsStreamer($root, 'http://127.0.0.1', new QualitySelector()),
        $manager
    );

    // The REAL router, carrying the REAL pattern. Registering the handler by hand
    // here would be re-deriving `{job_id}`/`{file}` extraction from the same
    // assumption the controller was written from; going through Router::dispatch()
    // means the path-parameter mapping under test is the shipped one.
    $router = new Router();
    $router->get('/hls/{job_id}/playlist', [$controller, 'getPlaylist']);
    $router->get('/hls/{job_id}/{file}', [$controller, 'serveFile']);

    $state['router'] = $router;
};

$worker->onMessage = static function (
    TcpConnection $connection,
    WorkermanRequest $wr
) use (
    &$state,
    $inFlightFile,
    $holdRelease,
    $holdMaxMs
): void {
    $router = $state['router'];
    $logFile = $state['log'];
    if (!$router instanceof Router) {
        // Unreachable in practice (onWorkerStart runs before the first accept), and
        // a 500 rather than a silent empty 200: an empty body arrives at hls.js as
        // "no EXTM3U delimiter", i.e. a playlist-format failure for a harness fault.
        $connection->send(new WorkermanResponse(500, ['Content-Type' => 'text/plain'], 'router not built'));
        return;
    }

    // The ONLY path that does not reach the router. It exists so the test can wait
    // for the forked workers to be accepting before it launches Chrome; a bare TCP
    // connect would succeed against the master's listen backlog and prove nothing.
    // It is not logged, so it cannot inflate the request census the test reads.
    if ($wr->path() === '/__ready') {
        $connection->send(new WorkermanResponse(200, ['Content-Type' => 'text/plain'], 'ready'));
        return;
    }

    // hrtime(true): monotonic (immune to NTP steps) AND, on Linux, drawn from a
    // clock shared by every process — which is what lets the test detect an overlap
    // between two requests served by two different workers.
    $start = hrtime(true);
    $request = Request::fromWorkerman($wr, $connection);

    // S317 — the barrier's first half. Written BEFORE the request is answered, so a
    // reader can tell "this worker is inside this request right now" from "this worker
    // finished this request". LOCK_EX because every child appends to the same file.
    file_put_contents(
        $inFlightFile,
        json_encode([
            'pid' => getmypid(),
            'name' => basename($request->path),
            'path' => $request->path,
            'startNs' => $start,
        ], JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );

    if (str_starts_with($request->path, '/__hold/')) {
        // S317 — the barrier's second half, and the ONLY thing here that is not the
        // real serve path. It blocks the way `TranscodeManager::ensureSegment()` blocks
        // outside a coroutine (a plain `usleep()` poll), which means this worker's event
        // loop stops: it cannot accept another connection while it is in here. That is
        // what lets the test place the NEXT connection on a DIFFERENT worker by
        // construction instead of hoping the kernel spreads four simultaneous accepts.
        //
        // The ceiling answers 504 rather than 200 so a release file that never arrives
        // reds the case with its own status instead of passing quietly after a stall.
        $deadlineNs = $start + ($holdMaxMs * 1_000_000);
        $released = false;
        while (hrtime(true) < $deadlineNs) {
            clearstatcache(true, $holdRelease);
            if (is_file($holdRelease)) {
                $released = true;
                break;
            }
            usleep(5_000);
        }
        $response = (new Response())->text($released ? 'released' : 'hold timed out', $released ? 200 : 504);
    } else {
        $response = $router->dispatch($request);
    }

    $end = hrtime(true);

    $bytes = $response->filePath !== null
        ? ($response->fileLength > 0
            ? $response->fileLength
            : max(0, (int) @filesize($response->filePath) - $response->fileOffset))
        : strlen($response->body);

    $line = json_encode([
        'pid' => getmypid(),
        'method' => $request->method,
        'path' => $request->path,
        'name' => basename($request->path),
        'status' => $response->statusCode,
        'contentType' => $response->headers['Content-Type'] ?? null,
        'cacheControl' => $response->headers['Cache-Control'] ?? null,
        'fileBacked' => $response->filePath !== null,
        'bytes' => $bytes,
        'startNs' => $start,
        'endNs' => $end,
    ], JSON_UNESCAPED_SLASHES);

    file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);

    // Production's wire encoder, including Workerman's native withFile() sender for
    // every segment — not the CGI Response::send() fallback, which HLS never uses.
    $connection->send($response->toWorkermanResponse());
};

Worker::runAll();
