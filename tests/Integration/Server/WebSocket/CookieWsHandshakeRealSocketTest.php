<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Server\WebSocket;

use Phlix\Auth\JwtHandler;
use Phlix\Server\Http\Controllers\AuthController;
use PHPUnit\Framework\TestCase;

/**
 * CP-008 (COOKIE-DESIGN.md §7): the WS auth-carrier law proved over REAL
 * SOCKETS — "two-real-socket :8097: cookie-carrier accept, no-marker echo,
 * foreign-Origin refuse" is the design sentence; the full carrier precedence
 * (bearer > admitted-cookie > query) plus the CP-007 gate dispositions and
 * the CP-009 Sec-Fetch belt ride the same live harness.
 *
 * ## What this adds over the mock cohorts (named overlaps)
 *
 * {@see \Phlix\Tests\Unit\Server\WebSocket\CookieWsHandshakeTest} pins the
 * resolver verdicts and the hook decisions (close() called / headers[]
 * appended) over mock connections; {@see \Phlix\Tests\Unit\Server\WebSocket\WsQueryTokenGateTest}
 * pins the gate telemetry at the same level. This file pins the CONSEQUENCE
 * on the wire: the composed 101 bytes actually carry (or omit) the
 * `Sec-WebSocket-Protocol: bearer` marker line, refused handshakes receive
 * ZERO response bytes (never a 401-shaped reply), and the redaction proof
 * runs against the log FILE the server process itself writes — not a mock
 * logger's recorded calls. The venue idiom is the S295 real-worker family
 * (proc_open + `exec` + pidfile + worker roll-call), adapted to the
 * websocket protocol; a PHPUnit mock cannot cross the process boundary, so
 * the AuthManager liveness seam stays null here — the HTTP-parity matrix is
 * owned by {@see \Phlix\Tests\Unit\Server\WebSocket\WsHandshakeLivenessParityTest}.
 *
 * Every leg uses a 127.0.0.1 literal (venue law) on an owned ephemeral port
 * and tears the process group down in tearDown.
 */
final class CookieWsHandshakeRealSocketTest extends TestCase
{
    private const SECRET = 'cp008-real-socket-handshake-secret';

    /** The marker line the 101 may or may not carry — the echo law's bytes. */
    private const MARKER_LINE = "Sec-WebSocket-Protocol: bearer\r\n";

    /** RFC 6455 guid for the accept verification. */
    private const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var list<array<string, mixed>> live servers, torn down in tearDown */
    private array $servers = [];

    private string $artifactDir = '';

    private int $serverSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artifactDir = sys_get_temp_dir() . '/phlix-cp008-ws-' . getmypid() . '-' . bin2hex(random_bytes(6));
        mkdir($this->artifactDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $this->stopServer($server);
        }
        $this->servers = [];

        foreach (glob($this->artifactDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->artifactDir);

        parent::tearDown();
    }

    // ── §7 named rows: cookie-carrier accept, no-marker echo, foreign-Origin refuse

    public function testAdmittedCookieHandshakeCompletesOverRealSocketWithoutTheBearerMarker(): void
    {
        $server = $this->startServer();
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/', [
            'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $token,
            $this->sameSiteOriginLine($server),
        ]);

        $this->assertUpgrade($wire, 'cookie carrier');
        $this->assertStringNotContainsString(
            self::MARKER_LINE,
            $wire,
            'the accepted COOKIE carrier must not echo the bearer marker (§2.3: the '
            . "marker means 'you authenticated via subprotocol'):\n" . $wire
        );
        // CP-006 credential-hygiene law on the wire: the 101 never carries
        // the token back.
        $this->assertStringNotContainsString($token, $wire);
    }

    public function testCookieCarrierWithBareBearerOfferStillEchoesNoMarkerOverRealSocket(): void
    {
        // The offered-gate makes an echo PROTOCOL-correct, never CUSTODY-true:
        // a client that lists `bearer` in its offer but authenticates through
        // the ADMITTED COOKIE JAR (offer carries no token entry, so the
        // resolver never sees a bearer) must still get NO marker. This is the
        // real-wire conviction of the §7 echo-bearer-on-cookie mutation — the
        // no-offer jar leg above rides the offer's absence and cannot see it.
        $server = $this->startServer();
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/', [
            'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $token,
            'Sec-WebSocket-Protocol: bearer',
            $this->sameSiteOriginLine($server),
        ]);

        $this->assertUpgrade($wire, 'cookie carrier with bare bearer offer');
        $this->assertStringNotContainsString(
            self::MARKER_LINE,
            $wire,
            "a bare bearer OFFER must not flip the accepted cookie carrier into an echoed bearer:\n" . $wire
        );
    }

    public function testForeignOriginCookieHandshakeReceivesZeroBytesOverRealSocket(): void
    {
        $server = $this->startServer();
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/', [
            'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $token,
            'Origin: http://evil.example',
        ]);

        // Design §2.3 refuse posture: nothing lands after the hook closes the
        // connection pre-101 — no 401, no 101, no bytes. (A refusal that grew
        // a reply shape would be a new wire contract; this pin keeps it out.)
        $this->assertSame('', $wire, 'a foreign-Origin cookie upgrade must die silently on the socket');
    }

    public function testCrossSiteSecFetchBeltRefusesCookieHandshakeOverRealSocket(): void
    {
        // CP-009 interplay on the real handshake: a SAME-SITE Origin does not
        // rescue a cross-site belt signal.
        $server = $this->startServer();
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/', [
            'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $token,
            $this->sameSiteOriginLine($server),
            'Sec-Fetch-Site: cross-site',
        ]);

        $this->assertSame('', $wire, 'the belt must head the cookie admission over the real socket');
    }

    public function testCookieHandshakeWithoutOriginContextFailsClosedOverRealSocket(): void
    {
        $server = $this->startServer();
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/', [
            'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $token,
        ]);

        $this->assertSame('', $wire, 'jar with no Origin AND no Referer fails closed, never open');
    }

    public function testRefererOnlySameSiteCookieHandshakeCompletesWithoutMarkerOverRealSocket(): void
    {
        $server = $this->startServer();
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/', [
            'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $token,
            'Referer: http://127.0.0.1:' . $server['port'] . '/app',
        ]);

        $this->assertUpgrade($wire, 'referer-only cookie carrier');
        $this->assertStringNotContainsString(self::MARKER_LINE, $wire);
    }

    // ── bearer precedence legs (real wire) ───────────────────────────────────

    public function testBearerSubprotocolHandshakeCompletesOverRealSocketWithTheMarkerEcho(): void
    {
        $server = $this->startServer();
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/', [
            'Sec-WebSocket-Protocol: bearer,' . $token,
        ]);

        $this->assertUpgrade($wire, 'bearer carrier');
        $this->assertStringContainsString(
            self::MARKER_LINE,
            $wire,
            "the offered-and-accepted bearer carrier must echo the marker:\n" . $wire
        );
        // The echo is a MARKER, never the credential (BEARER_SUBPROTOCOL_ECHO).
        $this->assertStringNotContainsString($token, $wire);
    }

    public function testValidBearerHeadsOverDisagreeingCookieOverRealSocket(): void
    {
        // bearer > admitted-cookie: valid bearer + a jar for a DIFFERENT
        // signature (garbage the resolver must never consult) still upgrades
        // on the bearer leg — proven by the marker echo (cookie acceptance
        // would suppress it).
        $server = $this->startServer();
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/', [
            'Sec-WebSocket-Protocol: bearer,' . $token,
            'Cookie: ' . AuthController::SESSION_COOKIE . '=not-even-a-jwt',
            $this->sameSiteOriginLine($server),
        ]);

        $this->assertUpgrade($wire, 'bearer over cookie');
        $this->assertStringContainsString(self::MARKER_LINE, $wire);
    }

    public function testInvalidBearerWithValidCookieRefusesWithoutFallingBackOverRealSocket(): void
    {
        // The converse leg of "bearer heads": precedence is not a fallback
        // chain — once the bearer leg is chosen, its validity alone decides.
        $server = $this->startServer();

        $wire = $this->handshake($server, '/', [
            'Sec-WebSocket-Protocol: bearer,' . 'this-token-does-not-verify',
            'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $this->accessToken(),
            $this->sameSiteOriginLine($server),
        ]);

        $this->assertSame('', $wire, 'the cookie carrier must never rescue a refused bearer credential');
    }

    public function testRefreshTokenAsBearerCredentialRefusedOverRealSocket(): void
    {
        // R17-02 type law survives the real socket: only ACCESS tokens open
        // the WS rail.
        $server = $this->startServer();
        $jwt = $this->jwt();

        $wire = $this->handshake($server, '/', [
            'Sec-WebSocket-Protocol: bearer,' . $jwt->createRefreshToken('u-ws-real'),
        ]);

        $this->assertSame('', $wire, 'a signing-valid refresh token must not authenticate :8097');
    }

    // ── query carrier + CP-007 gate legs (real wire + real log file) ─────────

    public function testQueryCarrierGateAbsentRefusesPreUpgradeOnRealSocket(): void
    {
        // CP-024 PHASE B on the real wire: gate-absent IS the shipped
        // posture, and the shipped posture is now deny — the query handshake
        // receives ZERO response bytes and the deny telemetry lands in the
        // log file the child process writes itself. REDDENED at the parent
        // (this leg pinned the PHASE A default-allow upgrade + telemetry).
        $server = $this->startServer('absent');
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/syncplay?token=' . urlencode($token), []);

        $this->assertSame('', $wire, 'the deny default must kill the query handshake before the 101');

        $log = $this->serverLog($server);
        $this->assertStringContainsString('legacy ?token= query carrier refused (PHLIX_WS_QUERY_TOKEN=deny)', $log);
        $this->assertStringContainsString('token=[redacted]', $log);
        $this->assertStringNotContainsString(
            $token,
            $log,
            'the server-written log file must never hold the raw query credential'
        );
    }

    public function testQueryCarrierGateAllowCompletesAndLogsRedactedTelemetryOnRealSocket(): void
    {
        $server = $this->startServer('allow');
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/syncplay?token=' . urlencode($token), []);

        $this->assertUpgrade($wire, 'query carrier under explicit allow');
        $log = $this->serverLog($server);
        $this->assertStringContainsString('used the legacy ?token= query carrier (PHLIX_WS_QUERY_TOKEN=allow)', $log);
        $this->assertStringNotContainsString($token, $log);
    }

    public function testQueryCarrierGateDenyRefusesPreUpgradeOnRealSocket(): void
    {
        $server = $this->startServer('deny');
        $token = $this->accessToken();

        $wire = $this->handshake($server, '/syncplay?token=' . urlencode($token), []);

        $this->assertSame('', $wire, 'PHLIX_WS_QUERY_TOKEN=deny must kill the query handshake before the 101');

        $log = $this->serverLog($server);
        $this->assertStringContainsString('legacy ?token= query carrier refused (PHLIX_WS_QUERY_TOKEN=deny)', $log);
        $this->assertStringContainsString('token=[redacted]', $log);
        $this->assertStringNotContainsString($token, $log);
    }

    public function testBearerQueryMismatchRefusesAndRedactsTheLogOnRealSocket(): void
    {
        $server = $this->startServer('allow');
        $bearerToken = $this->accessToken();
        $queryToken = $this->jwt()->createAccessToken('u-other');

        $wire = $this->handshake($server, '/syncplay?token=' . urlencode($queryToken), [
            'Sec-WebSocket-Protocol: bearer,' . $bearerToken,
        ]);

        $this->assertSame('', $wire, 'disagreeing bearer/query credentials must be refused outright');

        $log = $this->serverLog($server);
        $this->assertStringContainsString('bearer subprotocol token disagrees with ?token= query', $log);
        $this->assertStringContainsString('token=[redacted]', $log);
        // BOTH credentials stay out of the log — the URI redaction covers the
        // query one; the bearer one never enters the log line at all.
        $this->assertStringNotContainsString($bearerToken, $log);
        $this->assertStringNotContainsString($queryToken, $log);
    }

    public function testNoCredentialHandshakeRefusedOverRealSocket(): void
    {
        $server = $this->startServer();

        $wire = $this->handshake($server, '/', []);

        $this->assertSame('', $wire, 'requireAuth posture: an uncredentialled upgrade receives zero bytes');
    }

    public function testBearerMarkerEchoGateUnchangedForBearerLegUnderDenyPolicyOnRealSocket(): void
    {
        // CP-007 non-hijack law on the wire: turning the gate to deny must not
        // disturb the bearer leg's shape (101 + marker echo, byte-ident to the
        // allow-gate server).
        $allow = $this->handshakeOn($this->startServer('allow'));
        $deny = $this->handshakeOn($this->startServer('deny'));

        $this->assertSame(
            $this->normalizeWire($allow),
            $this->normalizeWire($deny),
            'the bearer 101 must be gate-policy-invariant on the real wire'
        );
        $this->assertStringContainsString(self::MARKER_LINE, $deny);
    }

    // ── harness ──────────────────────────────────────────────────────────────

    private function jwt(): JwtHandler
    {
        return new JwtHandler(self::SECRET, 'HS256', 3600, 604800);
    }

    private function accessToken(): string
    {
        return $this->jwt()->createAccessToken('u-ws-real');
    }

    /** @param array<string, mixed> $server */
    private function sameSiteOriginLine(array $server): string
    {
        return 'Origin: http://127.0.0.1:' . $server['port'];
    }

    /**
     * Boot the support listener with the given gate posture ('absent' omits
     * the config key — the shipped default).
     */
    /** @return array<string, mixed> */
    private function startServer(string $gate = 'absent'): array
    {
        $script = dirname(__DIR__, 3) . '/Support/ws-cookie-handshake-server.php';
        $this->assertFileExists($script);

        $id = ++$this->serverSeq;
        $port = $this->freePort();
        $roll = "{$this->artifactDir}/roll-{$id}.txt";
        $log = "{$this->artifactDir}/app-{$id}.log";
        $out = "{$this->artifactDir}/server-{$id}.out";

        // ⚠ `exec ` is load-bearing (S315/S295 lesson): proc_open runs the
        // command under /bin/sh -c, which does not optimise the fork away;
        // without exec, proc_terminate() would signal the shell while the
        // Workerman master keeps serving the port.
        $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            $script,
            "--port={$port}",
            '--secret=' . self::SECRET,
            "--gate={$gate}",
            "--roll={$roll}",
            "--log={$log}",
            "--pid={$this->artifactDir}/server-{$id}.pid",
            'start',
        ]));

        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
            $pipes
        );
        $this->assertIsResource($proc, 'proc_open() refused to start the CP-008 handshake server');
        $status = proc_get_status($proc);

        $server = [
            'id' => $id,
            'proc' => $proc,
            'pid' => (int) $status['pid'],
            'port' => $port,
            'roll' => $roll,
            'log' => $log,
            'out' => $out,
        ];
        $this->servers[] = $server;

        $deadline = microtime(true) + 25.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if ($status['running'] === false) {
                $this->fail(
                    "the CP-008 handshake server died while becoming ready.\nserver output:\n"
                    . (is_file($out) ? (string) file_get_contents($out) : '(none)')
                );
            }
            // Roll-call line appears only AFTER onStart() ran in the forked
            // worker — the master's listen backlog alone proves nothing about
            // the armed gate (same lesson as S315).
            if (count($this->rollPids($server)) >= 1) {
                return $server;
            }
            usleep(100_000);
        }

        $this->stopServer($server);
        $this->fail('the CP-008 handshake server never announced a started worker.');
    }

    /** @param array<string, mixed> $server @return list<int> */
    private function rollPids(array $server): array
    {
        $pids = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($server['roll'])) ?: [] as $line) {
            if (trim($line) !== '') {
                $pids[] = (int) trim($line);
            }
        }

        return $pids;
    }

    /** @param array<string, mixed> $server */
    private function serverLog(array $server): string
    {
        // The worker writes through a Monolog stream handler; give it a beat
        // to flush after the socket leg completes (bounded poll, never sleep
        // -and-hope: the assertion strings below fail loudly if it stays empty).
        $deadline = microtime(true) + 2.0;
        $contents = '';
        while (microtime(true) < $deadline) {
            $contents = (string) @file_get_contents($server['log']);
            if ($contents !== '') {
                return $contents;
            }
            usleep(50_000);
        }

        return $contents;
    }

    /**
     * Send a REAL websocket upgrade over a REAL TCP socket and return every
     * byte the server answered with ('' = closed before any reply).
     *
     * @param array<string, mixed> $server
     * @param list<string> $extraHeaderLines
     */
    private function handshake(array $server, string $path, array $extraHeaderLines): string
    {
        $keyBytes = random_bytes(16);
        $key = base64_encode($keyBytes);

        $raw = "GET {$path} HTTP/1.1\r\n"
            . 'Host: 127.0.0.1:' . $server['port'] . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n";
        foreach ($extraHeaderLines as $line) {
            $raw .= "{$line}\r\n";
        }
        $raw .= "\r\n";

        $sock = @stream_socket_client(
            'tcp://127.0.0.1:' . $server['port'],
            $errno,
            $errstr,
            5.0
        );
        $this->assertIsResource($sock, "cannot open the client socket to the test server: {$errstr}");
        stream_set_timeout($sock, 5);

        try {
            $written = @fwrite($sock, $raw);
            $this->assertIsInt($written);
            $this->assertSame(strlen($raw), $written, 'the whole handshake must reach the kernel');

            $wire = '';
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $chunk = fread($sock, 8192);
                if ($chunk === false || $chunk === '') {
                    // EOF (refusal) or timeout — either way no more bytes come.
                    break;
                }
                $wire .= $chunk;
                if (str_contains($wire, "\r\n\r\n")) {
                    // Header block complete (101s never carry a body).
                    break;
                }
            }

            // Validate the accept math whenever a 101 arrived: this is the
            // REAL protocol completing, not a canned fixture.
            if (str_starts_with($wire, 'HTTP/1.1 101')) {
                $expectedAccept = base64_encode(sha1($key . self::WS_GUID, true));
                $this->assertStringContainsString(
                    'Sec-WebSocket-Accept: ' . $expectedAccept . "\r\n",
                    $wire,
                    "a 101 must carry the RFC 6455 accept of OUR key:\n" . $wire
                );
            }

            return $wire;
        } finally {
            fclose($sock);
        }
    }

    /**
     * Fixed bearer leg used by the gate-invariance comparison.
     *
     * @param array<string, mixed> $server
     */
    private function handshakeOn(array $server): string
    {
        return $this->handshake($server, '/', [
            'Sec-WebSocket-Protocol: bearer,' . $this->accessToken(),
        ]);
    }

    /**
     * The HANDSHAKE BLOCK only (status line + headers, up to the blank line),
     * with the per-connection accept stripped (two boots, two keys). Post-101
     * data frames are live-connection payload, not handshake shape — reading
     * them into the comparison would race server-side timing (the 'connected'
     * event frame) and could not prove anything about the gate.
     */
    private function normalizeWire(string $wire): string
    {
        [$head] = explode("\r\n\r\n", $wire, 2);

        return (string) preg_replace('/Sec-WebSocket-Accept: [^\r]+\r\n/', '', (string) $head);
    }

    /**
     * A completed WS upgrade as seen on the real socket: the vendor's exact
     * 101 status line, the Upgrade limb, and a non-empty derived accept.
     */
    private function assertUpgrade(string $wire, string $site): void
    {
        $this->assertStringStartsWith(
            'HTTP/1.1 101 Switching Protocol',
            $wire,
            "{$site}: the handshake must complete with a real 101:\n" . $wire
        );
        $this->assertStringContainsStringIgnoringCase('Upgrade: websocket', $wire, $site);
        $this->assertMatchesRegularExpression('/Sec-WebSocket-Accept: \S+/', $wire, $site);
    }

    /** @param array<string, mixed> $server */
    private function stopServer(array $server): void
    {
        if (!is_resource($server['proc'])) {
            return;
        }
        $status = proc_get_status($server['proc']);
        if ($status['running'] === true) {
            proc_terminate($server['proc'], SIGTERM);
            $deadline = microtime(true) + 10.0;
            while (microtime(true) < $deadline && proc_get_status($server['proc'])['running'] === true) {
                usleep(100_000);
            }
            if (proc_get_status($server['proc'])['running'] === true) {
                proc_terminate($server['proc'], SIGKILL);
                usleep(500_000);
            }
        }
        proc_close($server['proc']);
        $this->servers = array_values(array_filter(
            $this->servers,
            static fn (array $s): bool => $s['id'] !== $server['id']
        ));
    }

    private function freePort(): int
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($listener, 'could not reserve an ephemeral port: ' . $errstr);
        $name = (string) stream_socket_get_name($listener, false);
        fclose($listener);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port, 'ephemeral port reservation');

        return $port;
    }
}
