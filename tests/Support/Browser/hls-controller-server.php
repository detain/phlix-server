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
 * ## Usage
 *
 *   php tests/Support/Browser/hls-controller-server.php \
 *       --root=<segment dir> --job=<job id> --row=<row.json> --port=<n> \
 *       --log=<requests.jsonl> --pid=<pidfile> [--workers=4] [--max-wait-ms=90000] start
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

if ($port <= 0) {
    fwrite(STDERR, "S315 hls-controller-server: --port must be a positive integer\n");
    exit(2);
}

// A fresh log per run, created in the MASTER so a reader cannot mistake "the file is
// not there yet" for "no request was served".
file_put_contents($logFile, '');

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
    $segmentSeconds
): void {
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

$worker->onMessage = static function (TcpConnection $connection, WorkermanRequest $wr) use (&$state): void {
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
    $response = $router->dispatch($request);
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
