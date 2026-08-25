<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use PHPUnit\Framework\TestCase;

/**
 * S295 — the wire-byte proof, on a REAL Workerman worker.
 *
 * The boundary suite ({@see \Phlix\Tests\Unit\Server\Core\ApplicationHeadOnlyBoundaryTest})
 * executes the real encoders; the S113 suite
 * ({@see \Phlix\Tests\Unit\Server\Workerman\HttpHandlerHeadNoBodyTest}) executes the
 * real `HttpHandler`; NEITHER executes a real socket. S295's acceptance criterion is
 * the diagonal: a `HEAD` refused by the only GLOBAL middleware
 * ({@see \Phlix\Server\Http\Middleware\AccessScheduleMiddleware}) must carry NO body
 * and the REAL `Content-Length`, proved against a live Workerman worker, beside a
 * desyncing control (a `GET` that carries a body).
 *
 * The worker under test is `tests/Support/Browser/s295-head-body-server.php` — the
 * same HTTP driver `start.php` runs, over the real {@see \Phlix\Server\Core\Application}
 * constructor (including the S295 chain-return seam
 * `Application::flagHeadShortCircuitReply()`), the real middleware over the real
 * {@see \Phlix\Access\AccessScheduleService}, and the same encoder
 * `HttpHandler`'s matched-route branch sends at `HttpHandler.php:267`. What is NOT
 * real is deliberate and documented in that file: the database (one JSON schedule
 * row replayed by {@see \Phlix\Tests\Support\Browser\StubScheduleConnection} — a
 * PHPUnit mock cannot cross `proc_open()`) and the authentication context (the
 * harness publishes the per-request context keys `AuthMiddleware` would set, because
 * the S295 seam is the middleware's REFUSAL, not the auth that precedes it).
 *
 * The schedule row is generated HERE, per run, from the CURRENT clock: the window is
 * "today, 00:00:00 → 23:59:59", so the schedule check answers "blocked" for whatever
 * second the worker happens to serve — no hand-written time that could silently fall
 * outside the window, and no dependency on the `AccessScheduleService` clock being
 * controllable.
 *
 * ## Why the desync test is the load-bearing one
 *
 * A `HEAD` that ships a body is recoverable per RFC 9110 §9.3.2 (one
 * self-consistent `Content-Length`), which is exactly why the pre-S295 shape was
 * documented rather than alarmed: a client that reads only the header block and then
 * REUSES the connection drifts by the leaked bytes and misparses every later reply.
 * {@see testAHeaderOnlyClientReusingTheConnectionStaysInSync()} is that client, on a
 * real socket: it reads the HEAD's header block, stops, sends a `GET` on the SAME
 * connection, and requires the next reply to begin at a status line. Deleting the
 * S295 seam flag (mutating `Application.php:114` back to `return $result;`) reddens
 * this test: the GET's head then begins with the leaked JSON body, exactly as
 * measured on 2026-08-25 (`HEAD head bytes: 102` → next head starts
 * `{\n    "error": "AccessScheduled"...`).
 *
 * @package Phlix
 */
final class AccessScheduleHeadNoBodyWireTest extends TestCase
{
    /** The one refusal this proof needs: the blocked-schedule-window branch. */
    private const ERROR = ['error' => 'AccessScheduled', 'message' => 'Access denied during scheduled window'];

    private const PROFILE_ID = '11111111-2222-3333-4444-555555555555';

    /** The JSON entity — computed, never transcribed (S105/S113 rule). */
    private function entity(): string
    {
        return (string) json_encode(self::ERROR, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @var array<int, array<string, mixed>> Servers still running, keyed by id.
     */
    private array $servers = [];

    private int $serverSeq = 0;

    private string $artifactDir;

    protected function setUp(): void
    {
        $this->artifactDir = sys_get_temp_dir() . '/s295-' . getmypid() . '-' . uniqid();
        mkdir($this->artifactDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $id => $server) {
            $this->stopServer($server);
        }
        $this->servers = [];
    }

    // ── the AC: HEAD refused by the global middleware on a LIVE worker ─────────

    /**
     * The headline wire-byte proof, one connection per request, each closed by
     * `Connection: close` so the socket EOF is the end of the reply:
     *
     *  * the CONTROL (`GET`) carries its whole 403 JSON body and the matching
     *    `Content-Length` — the fix must never suppress bodies generally;
     *  * the `HEAD` carries NO body (zero bytes after the terminator) and the REAL
     *    `Content-Length` — the entity size the equivalent `GET` returned.
     *
     * The server-side request log corroborates both from the other side of the
     * socket: `headOnly` is true for the HEAD and false for the GET, and the HEAD's
     * encoder body is empty (`bodyBytes: 0`).
     */
    public function testAHeadRefusedByTheGlobalMiddlewareOnALiveWorkerCarriesNoBodyAndTheRealContentLength(): void
    {
        $server = $this->startServer();

        $expectedEntity = $this->entity();

        // ── control: a GET in the same blocked window still carries its body ──
        $getWire = $this->request($server['port'], "GET /api/v1/anything HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        self::assertStringStartsWith("HTTP/1.1 403 Forbidden\r\n", $getWire, "premise: the GET is refused:\n" . $getWire);
        self::assertStringContainsString('Content-Length: ' . strlen($expectedEntity) . "\r\n", $getWire);
        self::assertSame($expectedEntity, $this->bodyBytes($getWire), 'the GET control must still ship the refusal body');

        // ── the HEAD refused by the same branch, on the same worker ──
        $headWire = $this->request($server['port'], "HEAD /api/v1/anything HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        self::assertStringStartsWith("HTTP/1.1 403 Forbidden\r\n", $headWire);
        self::assertSame(
            1,
            substr_count($headWire, 'Content-Length:'),
            "exactly ONE Content-Length on the wire (RFC 9110 §8.6):\n" . $headWire,
        );
        self::assertStringContainsString('Content-Length: ' . strlen($expectedEntity) . "\r\n", $headWire);
        self::assertSame(
            '',
            $this->bodyBytes($headWire),
            'a HEAD refused by the global middleware must carry NO body — the bytes below are the '
            . "leaked 403 envelope:\n" . $headWire,
        );
        self::assertStringNotContainsString('Access denied during scheduled window', $headWire);

        // ── the server-side corroboration: the flag is set, the entity is intact ──
        // (the wire assertions above are the "no body on the socket" proof; the log
        // measures the Response OBJECT, whose entity asHeadReply() deliberately keeps
        // — BodylessResponse suppresses it at the encoder, so entityBytes stays 90.)
        $log = $this->requestLog($server);
        self::assertSame('GET', $log[0]['method']);
        self::assertFalse($log[0]['headOnly'], 'a GET short-circuit is never flagged head-only');
        self::assertSame(strlen($expectedEntity), $log[0]['entityBytes'], 'the GET control shipped its body');
        self::assertSame('HEAD', $log[1]['method']);
        self::assertTrue($log[1]['headOnly'], 'the chain-return seam must flag the HEAD reply head-only');
        self::assertSame(strlen($expectedEntity), $log[1]['entityBytes'], 'the entity is kept on the Response object');
        self::assertSame((string) strlen($expectedEntity), $log[1]['contentLength'], 'the real Content-Length');
    }

    /**
     * The desync consequence, on ONE keep-alive connection: a header-block-only
     * client reads the refused HEAD's head, stops, and sends a GET over the SAME
     * connection. If the HEAD had leaked its body, the GET's reply would begin with
     * the leaked JSON and the client's read offset would be wrong for every reply
     * that follows. Post-S295 the next reply begins at its status line — measured
     * pre-fix on 2026-08-25 it began with `{\n    "error": "AccessScheduled"...`.
     */
    public function testAHeaderOnlyClientReusingTheConnectionStaysInSyncAfterTheRefusedHead(): void
    {
        $server = $this->startServer();
        $expectedEntity = $this->entity();

        $sock = $this->connect($server['port']);
        try {
            // 1) HEAD — read ONLY the header block, like curl -I / python http.client.
            fwrite($sock, "HEAD /api/v1/anything HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");
            $head = $this->readHeadBlock($sock);
            self::assertStringStartsWith("HTTP/1.1 403 Forbidden\r\n", $head);
            self::assertSame(1, substr_count($head, 'Content-Length:'), "exactly one length:\n" . $head);
            self::assertStringContainsString('Content-Length: ' . strlen($expectedEntity) . "\r\n", $head);
            self::assertStringNotContainsString('Access denied during scheduled window', $head);

            // 2) GET on the SAME connection — the read offset must not have drifted.
            fwrite($sock, "GET /api/v1/anything HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");
            $getHead = $this->readHeadBlock($sock);
            self::assertStringStartsWith(
                "HTTP/1.1 403 Forbidden\r\n",
                $getHead,
                "the connection desynced: the GET's reply must begin at a status line, not at the "
                . "leaked HEAD body. First bytes: " . substr($getHead, 0, 80),
            );
            self::assertSame(1, substr_count($getHead, 'Content-Length:'), "exactly one length:\n" . $getHead);
            preg_match('/Content-Length: (\d+)\r\n/i', $getHead, $m);
            self::assertArrayHasKey(1, $m, "the GET head must declare its length:\n" . $getHead);
            $getBody = $this->readExact($sock, (int) $m[1]);
            self::assertSame($expectedEntity, $getBody, 'the GET still carries its whole body, in sync');
        } finally {
            fclose($sock);
        }
    }

    // ── the live-worker boot (S315's hls-controller-server pattern) ────────────

    /**
     * Starts `tests/Support/Browser/s295-head-body-server.php` with a schedule row
     * that blocks "now" (today, 00:00:00 → 23:59:59) and waits until its forked
     * worker is accepting — not merely until the master's listen backlog exists.
     *
     * @return array<string, mixed>
     */
    private function startServer(): array
    {
        $script = dirname(__DIR__, 3) . '/Support/Browser/s295-head-body-server.php';
        self::assertFileExists($script);

        $rowFile = $this->artifactDir . '/schedule-row.json';
        $dayAbbrev = strtolower(substr(date('D'), 0, 3));
        file_put_contents(
            $rowFile,
            (string) json_encode([
                'id' => 1,
                'profile_id' => self::PROFILE_ID,
                'name' => 'S295 blocked window',
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
                'days_of_week' => $dayAbbrev,
                'is_active' => true,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $lastError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = $this->freePort();
            $log = $this->artifactDir . "/requests-{$attempt}.jsonl";
            $out = $this->artifactDir . "/server-{$attempt}.out";

            // ⚠ `exec ` is load-bearing (S315): proc_open() runs the command under
            // `/bin/sh -c`, which does NOT optimise the fork away; proc_terminate()
            // would then signal the shell while the Workerman master keeps serving.
            // `exec` makes php REPLACE the shell, so the pid proc_open reports is
            // the master's.
            $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                $script,
                "--row={$rowFile}",
                "--profile=" . self::PROFILE_ID,
                "--port={$port}",
                "--log={$log}",
                "--pid={$this->artifactDir}/server-{$attempt}.pid",
                '--workers=1',
                'start',
            ]));

            $proc = proc_open(
                $cmd,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
                $pipes
            );
            self::assertIsResource($proc, 'proc_open() refused to start the S295 head-body server');
            $status = proc_get_status($proc);
            $server = [
                'id' => ++$this->serverSeq,
                'proc' => $proc,
                'pid' => (int) $status['pid'],
                'port' => $port,
                'log' => $log,
                'roll' => $log . '.workers',
                'out' => $out,
            ];
            $this->servers[$server['id']] = $server;

            if ($this->waitForReady($server)) {
                return $server;
            }

            $lastError = "port {$port}: " . (is_file($out) ? (string) file_get_contents($out) : '(no output)');
            $this->stopServer($server);
        }

        self::fail("the S295 head-body server never became ready.\n{$lastError}");
    }

    /**
     * Ready means the forked worker answers `/__ready` AND has announced itself in
     * the roll call (onWorkerStart runs before the first accept, so the roll file
     * proves the Application — including the S295 seam — is built).
     *
     * @param array<string, mixed> $server
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
            if ($body === 'ready' && count($this->workerRoll($server)) >= 1) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return list<int>
     */
    private function workerRoll(array $server): array
    {
        $pids = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($server['roll'])) ?: [] as $line) {
            if (trim($line) !== '') {
                $pids[] = (int) trim($line);
            }
        }

        return array_values(array_unique($pids));
    }

    /**
     * The per-request JSON log the server wrote, newest last.
     *
     * @param array<string, mixed> $server
     *
     * @return list<array{pid: int, method: string, path: string, status: int, headOnly: bool, contentType: ?string, contentLength: ?string, entityBytes: int}>
     */
    private function requestLog(array $server): array
    {
        $lines = preg_split('/\R/', (string) @file_get_contents($server['log'])) ?: [];
        $entries = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            /** @var array{pid: int, method: string, path: string, status: int, headOnly: bool, contentType: ?string, contentLength: ?string, entityBytes: int} $entry */
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $server
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

    /**
     * An ephemeral port the OS just handed out. There is an unavoidable race between
     * releasing it and the server binding it, which is why {@see startServer()}
     * retries rather than failing on the first refusal.
     */
    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($socket, "could not reserve a port: {$errstr} ({$errno})");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port);

        return $port;
    }

    // ── raw-socket helpers ─────────────────────────────────────────────────────

    /** @return resource */
    private function connect(int $port)
    {
        $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 3.0);
        self::assertIsResource($sock, "could not connect to the S295 server on port {$port}: {$errstr} ({$errno})");
        stream_set_timeout($sock, 5);

        return $sock;
    }

    /**
     * Send one request over a fresh connection and read the reply to EOF
     * (`Connection: close`). Returns the full wire bytes (head + body).
     */
    private function request(int $port, string $raw): string
    {
        $sock = $this->connect($port);
        try {
            fwrite($sock, $raw);
            $wire = '';
            while (!feof($sock)) {
                $chunk = fread($sock, 8192);
                if ($chunk === false) {
                    break;
                }
                if ($chunk === '') {
                    $meta = stream_get_meta_data($sock);
                    self::assertFalse($meta['timed_out'], "timed out reading the reply on port {$port}");
                    break;
                }
                $wire .= $chunk;
            }
            self::assertStringContainsString("\r\n\r\n", $wire, "the reply never terminated its head:\n" . $wire);

            return $wire;
        } finally {
            fclose($sock);
        }
    }

    /**
     * The bytes trailing the head — what a header-only client leaves buffered.
     */
    private function bodyBytes(string $wire): string
    {
        $parts = explode("\r\n\r\n", $wire, 2);
        self::assertArrayHasKey(1, $parts, "the head was never terminated. Encoded bytes were:\n" . $wire);

        return $parts[1];
    }

    /**
     * Read exactly up to and including the head terminator — byte by byte, because
     * a header-only client must not consume anything past it. Fails loudly if the
     * terminator never arrives.
     *
     * @param resource $sock
     */
    private function readHeadBlock($sock): string
    {
        $buf = '';
        while (strlen($buf) < 65536) {
            $chunk = fread($sock, 1);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($sock);
                self::fail(
                    'the head never terminated (EOF/' . ($meta['timed_out'] ? 'timeout' : 'closed')
                    . ') after ' . strlen($buf) . " bytes:\n" . $buf,
                );
            }
            $buf .= $chunk;
            if (str_ends_with($buf, "\r\n\r\n")) {
                return $buf;
            }
        }

        self::fail('the head never terminated; read ' . strlen($buf) . ' bytes without a CRLFCRLF');
    }

    /**
     * Read exactly `$n` more bytes — the entity the Content-Length header promises.
     *
     * @param resource $sock
     */
    private function readExact($sock, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($sock);
                self::fail(
                    "the body ended early (" . strlen($buf) . "/{$n} bytes; "
                    . ($meta['timed_out'] ? 'timeout' : 'closed') . "):\n" . $buf,
                );
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
