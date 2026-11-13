<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Hub\ClaimStatusResult;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayStateStore;
use Phlix\Server\Http\Controllers\Admin\AdminHubController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the S39 relay control surface on {@see AdminHubController}.
 *
 * The relay tunnel runs in a SEPARATE forked process; these endpoints must read
 * the cross-process state files (`relay-tunnel.state.json`,
 * `hub-heartbeat.state.json`) the forks write and persist the operator
 * kill-switch (`relay-control.json`) — NOT talk to a never-started
 * container-local `RelayConsumer`, run `exec('pgrep …')`, or scrape logs.
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream and is covered by the middleware's own tests. The controller is
 * constructed with a null container + a seeded temp config dir, so the reads/
 * writes are exercised without any container wiring.
 */
final class AdminHubControllerTest extends TestCase
{
    private string $dir;

    /** @var string|false Saved PHLIX_RELAY_DISABLED env so tests can restore it. */
    private string|false $savedEnv;

    /** @var list<string> Throwaway logger dirs created by the E2W pins. */
    private array $loggerDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-adminhub-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);

        // The kill-switch env var must be neutral for the default cases; save +
        // clear it so a leaked value from the host or another test cannot skew
        // the "disabled" reporting.
        $this->savedEnv = getenv('PHLIX_RELAY_DISABLED');
        putenv('PHLIX_RELAY_DISABLED');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        if ($this->savedEnv === false) {
            putenv('PHLIX_RELAY_DISABLED');
        } else {
            putenv('PHLIX_RELAY_DISABLED=' . $this->savedEnv);
        }

        foreach ($this->loggerDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->loggerDirs = [];
        LoggerFactory::reset();

        parent::tearDown();
    }

    private function controller(): AdminHubController
    {
        // Null container: the relay control endpoints never need it — they read
        // and write files under $configDir.
        return new AdminHubController(null, $this->dir);
    }

    private function request(): Request
    {
        $request = new Request();
        $request->body = [];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A recent ISO-8601 `updatedAt`, i.e. one the S40 staleness gate accepts.
     *
     * The relay fork refreshes its state file on a fixed cadence, so a "tunnel
     * is up" fixture must be dated relative to NOW — a hard-coded calendar date
     * ages past the gate and silently stops meaning "a live fork wrote this".
     */
    private function fresh(int $secondsAgo = 0): string
    {
        return date('c', time() - $secondsAgo);
    }

    private function seedRelayState(string $json): void
    {
        file_put_contents($this->dir . '/' . RelayStateStore::RELAY_STATE_FILE, $json);
    }

    private function seedHeartbeatState(string $json): void
    {
        file_put_contents($this->dir . '/' . RelayStateStore::HEARTBEAT_STATE_FILE, $json);
    }

    // ---------------------------------------------------------------------
    // relayStatus — reads the seeded state file, not a live probe.
    // ---------------------------------------------------------------------

    public function testRelayStatusReadsSeededStateFile(): void
    {
        $updatedAt = $this->fresh();

        $this->seedRelayState(json_encode([
            'connected' => true,
            'active' => true,
            'reconnectAttempts' => 2,
            'activeSessions' => 4,
            'lastDisconnectTime' => '2026-07-23T10:00:00+00:00',
            'lastConnectError' => 'boom',
            'lastConnectErrorAt' => '2026-07-23T09:59:00+00:00',
            'updatedAt' => $updatedAt,
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller()->relayStatus($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertTrue($body['connected']);
        self::assertTrue($body['active']);
        self::assertSame(2, $body['reconnectAttempts']);
        self::assertSame(4, $body['activeSessions']);
        self::assertSame('2026-07-23T10:00:00+00:00', $body['lastDisconnectTime']);
        self::assertSame('boom', $body['lastConnectError']);
        self::assertSame('2026-07-23T09:59:00+00:00', $body['lastConnectErrorAt']);
        self::assertSame($updatedAt, $body['updatedAt']);
        self::assertFalse($body['disabled']);
        self::assertFalse($body['enrolled']);
        self::assertFalse($body['stale']);
    }

    public function testRelayStatusForcesAStaleTunnelDown(): void
    {
        // S40: the relay fork's state file survives its death, so a raw read
        // would keep answering "connected" forever after a SIGKILL.
        $this->seedRelayState(json_encode([
            'connected' => true,
            'active' => true,
            'reconnectAttempts' => 0,
            'activeSessions' => 4,
            'staleAfterSeconds' => 90,
            'updatedAt' => $this->fresh(3600),
        ], JSON_THROW_ON_ERROR));

        $body = $this->decode($this->controller()->relayStatus($this->request(), [])->body);
        self::assertFalse($body['connected']);
        self::assertFalse($body['active']);
        self::assertSame(0, $body['activeSessions']);
        self::assertTrue($body['stale']);
        self::assertStringContainsString('stale', (string) $body['lastConnectError']);
    }

    public function testRelayStatusReportsOfflineWhenStateFileMissing(): void
    {
        // No state file at all — a never-started fork must read as offline, not
        // throw and not 500.
        $response = $this->controller()->relayStatus($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['connected']);
        self::assertFalse($body['active']);
        self::assertSame(0, $body['reconnectAttempts']);
        self::assertSame(0, $body['activeSessions']);
        self::assertNull($body['updatedAt']);
    }

    public function testRelayStatusReflectsPersistedKillSwitch(): void
    {
        $this->seedRelayState(json_encode(['connected' => true], JSON_THROW_ON_ERROR));
        (new RelayStateStore($this->dir))->setRelayDisabled(true);

        $body = $this->decode($this->controller()->relayStatus($this->request(), [])->body);
        self::assertTrue($body['disabled']);
    }

    public function testRelayStatusReflectsEnvKillSwitch(): void
    {
        putenv('PHLIX_RELAY_DISABLED=1');

        $body = $this->decode($this->controller()->relayStatus($this->request(), [])->body);
        self::assertTrue($body['disabled']);
    }

    public function testRelayStatusReportsEnrolledFromEnrollmentFile(): void
    {
        file_put_contents(
            $this->dir . '/hub-enrollment.json',
            json_encode(['server_id' => 'srv-1', 'hub_base_url' => 'https://hub.example'], JSON_THROW_ON_ERROR)
        );

        $body = $this->decode($this->controller()->relayStatus($this->request(), [])->body);
        self::assertTrue($body['enrolled']);
    }

    // ---------------------------------------------------------------------
    // relayDisable / relayEnable — honest levers, not fake {success:true} no-ops.
    // ---------------------------------------------------------------------

    public function testRelayDisablePersistsKillSwitchAndStatusReflectsIt(): void
    {
        $response = $this->controller()->relayDisable($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertTrue($body['success']);
        self::assertTrue($body['disabled']);
        self::assertTrue($body['takesEffectOnReload']);

        // The flag is really persisted where the relay fork reads it.
        self::assertTrue((new RelayStateStore($this->dir))->isRelayDisabled());

        // And a subsequent status read reflects it.
        $status = $this->decode($this->controller()->relayStatus($this->request(), [])->body);
        self::assertTrue($status['disabled']);
    }

    public function testRelayEnableClearsKillSwitch(): void
    {
        (new RelayStateStore($this->dir))->setRelayDisabled(true);

        $response = $this->controller()->relayEnable($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertTrue($body['success']);
        self::assertFalse($body['disabled']);
        self::assertTrue($body['takesEffectOnReload']);

        self::assertFalse((new RelayStateStore($this->dir))->isRelayDisabled());
        $status = $this->decode($this->controller()->relayStatus($this->request(), [])->body);
        self::assertFalse($status['disabled']);
    }

    public function testRelayEnableReportsEnvOverrideStillDisabled(): void
    {
        // The endpoint can clear the file flag but cannot unset an env kill-switch
        // — it must say so honestly rather than claim the relay is enabled.
        putenv('PHLIX_RELAY_DISABLED=1');

        $body = $this->decode($this->controller()->relayEnable($this->request(), [])->body);
        self::assertTrue($body['success']);
        self::assertTrue($body['disabled']);
        self::assertStringContainsStringIgnoringCase('environment', $body['message']);
    }

    public function testRelayEnableWhenNotEnrolledSaysSo(): void
    {
        $body = $this->decode($this->controller()->relayEnable($this->request(), [])->body);
        self::assertTrue($body['success']);
        self::assertFalse($body['enrolled']);
        self::assertStringContainsStringIgnoringCase('not paired', $body['message']);
    }

    public function testRelayEnableWhenEnrolledSaysWillReconnect(): void
    {
        file_put_contents(
            $this->dir . '/hub-enrollment.json',
            json_encode(['server_id' => 'srv-1', 'hub_base_url' => 'https://hub.example'], JSON_THROW_ON_ERROR)
        );

        $body = $this->decode($this->controller()->relayEnable($this->request(), [])->body);
        self::assertTrue($body['success']);
        self::assertTrue($body['enrolled']);
        self::assertFalse($body['disabled']);
        self::assertStringContainsStringIgnoringCase('reconnect', str_replace('(re)', 're', $body['message']));
    }

    public function testRelayEnableFailsWhenControlDirUnwritable(): void
    {
        $file = $this->dir . '/iam-a-file';
        file_put_contents($file, 'x');

        $controller = new AdminHubController(null, $file . '/cannot');
        $response = $controller->relayEnable($this->request(), []);

        self::assertSame(500, $response->statusCode);
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
    }

    public function testRelayDisableFailsWhenControlDirUnwritable(): void
    {
        // configDir under an existing FILE → the atomic write cannot land; the
        // load-bearing lever must report a genuine 500, not a fake success.
        $file = $this->dir . '/iam-a-file';
        file_put_contents($file, 'x');

        $controller = new AdminHubController(null, $file . '/cannot');
        $response = $controller->relayDisable($this->request(), []);

        self::assertSame(500, $response->statusCode);
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
    }

    // ---------------------------------------------------------------------
    // relayPing — persisted latency + connection state, never a live probe.
    // ---------------------------------------------------------------------

    public function testRelayPingReturnsPersistedLatencyNotLiveProbe(): void
    {
        $this->seedRelayState(json_encode([
            'connected' => true,
            'active' => true,
            'updatedAt' => $this->fresh(),
        ], JSON_THROW_ON_ERROR));
        $this->seedHeartbeatState(json_encode([
            'lastLatencyMs' => 42,
            'lastSuccessfulHeartbeat' => '2026-07-23T10:00:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller()->relayPing($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertTrue($body['success']);
        self::assertTrue($body['connected']);
        self::assertTrue($body['active']);
        self::assertSame(42, $body['latencyMs']);
        self::assertSame('2026-07-23T10:00:00+00:00', $body['lastHeartbeatAt']);
        self::assertSame('persisted', $body['latencySource']);
        self::assertTrue(
            $body['heartbeatStale'],
            'that heartbeat snapshot carries no updatedAt, so it cannot be current'
        );
    }

    public function testRelayPingMarksAFreshHeartbeatAsNotStale(): void
    {
        $this->seedRelayState(json_encode([
            'connected' => true,
            'active' => true,
            'updatedAt' => $this->fresh(),
        ], JSON_THROW_ON_ERROR));
        $this->seedHeartbeatState(json_encode([
            'lastLatencyMs' => 42,
            'lastSuccessfulHeartbeat' => $this->fresh(30),
            'staleAfterSeconds' => 180,
            'updatedAt' => $this->fresh(30),
        ], JSON_THROW_ON_ERROR));

        $body = $this->decode($this->controller()->relayPing($this->request(), [])->body);
        self::assertSame(42, $body['latencyMs']);
        self::assertFalse($body['heartbeatStale']);
    }

    public function testRelayPingLatencyNullWhenNoHeartbeatRecorded(): void
    {
        // Connected, but no heartbeat state file yet (S40 not shipped / no beat
        // recorded) → honest null, not a fabricated timing.
        $this->seedRelayState(json_encode([
            'connected' => true,
            'active' => true,
            'updatedAt' => $this->fresh(),
        ], JSON_THROW_ON_ERROR));

        $body = $this->decode($this->controller()->relayPing($this->request(), [])->body);
        self::assertTrue($body['success']);
        self::assertNull($body['latencyMs']);
        self::assertSame('persisted', $body['latencySource']);
    }

    public function testRelayPingReturns409WhenTheStateFileIsStale(): void
    {
        // The tunnel "was" connected — but the fork stopped refreshing the file,
        // so ping must not answer success out of a frozen snapshot (S40).
        $this->seedRelayState(json_encode([
            'connected' => true,
            'active' => true,
            'staleAfterSeconds' => 90,
            'updatedAt' => $this->fresh(3600),
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller()->relayPing($this->request(), []);
        self::assertSame(409, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['connected']);
        self::assertStringContainsString('stale', (string) $body['lastConnectError']);
    }

    public function testRelayPingReturns409WhenNotConnected(): void
    {
        $this->seedRelayState(json_encode([
            'connected' => false,
            'active' => false,
            'lastConnectError' => 'handshake timeout',
            'lastConnectErrorAt' => '2026-07-23T09:00:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller()->relayPing($this->request(), []);
        self::assertSame(409, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertFalse($body['connected']);
        self::assertSame('handshake timeout', $body['lastConnectError']);
        self::assertSame('2026-07-23T09:00:00+00:00', $body['lastConnectErrorAt']);
    }

    public function testRelayPingReturns409WhenNoStateFile(): void
    {
        // No state file → not connected → 409, never a live probe / exception.
        $response = $this->controller()->relayPing($this->request(), []);
        self::assertSame(409, $response->statusCode);
        self::assertFalse($this->decode($response->body)['connected']);
    }

    // ---------------------------------------------------------------------
    // Regression guard: the relay control request path must not reintroduce a
    // blocking exec()/pgrep probe or a log-scrape in the resident event loop.
    // ---------------------------------------------------------------------

    public function testControllerHasNoBlockingExecOrLogScrapeInRequestPath(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 6) . '/src/Server/Http/Controllers/Admin/AdminHubController.php'
        );
        self::assertIsString($source);

        self::assertStringNotContainsString('exec(', $source, 'no shell exec() in the event loop');
        self::assertStringNotContainsString('pgrep', $source, 'no pgrep process probe');
        self::assertStringNotContainsString('proc_open', $source, 'no subprocess in the event loop');
        self::assertStringNotContainsString('popen(', $source, 'no popen() subprocess in the event loop');
        self::assertStringNotContainsString('passthru(', $source, 'no passthru() shell out in the event loop');
        self::assertStringNotContainsString('system(', $source, 'no system() shell out in the event loop');
        self::assertStringNotContainsString('events-', $source, 'no daily log-file scrape');
        self::assertStringNotContainsString('shell_exec', $source, 'no shell_exec in the event loop');
    }

    // ---------------------------------------------------------------------
    // INFO-SERVER-51 (E2 cluster, 2026-11-04): the raw-Throwable catch arms of
    // this controller must answer with the FIXED copy inside the byte-identical
    // 500 envelope `{success:false, message:<copy>}`, log class + cause to the
    // server-side stream, and NEVER echo $e->getMessage() onto the wire. At the
    // parent every arm below leaked the filesystem path or upstream prose of
    // the caught error into the admin JSON body (the estate R17-03 law, here
    // extended from the WS layer to HTTP; idiom = the cured
    // TranscodeController RuntimeException arm / M-SERVER-05 envelope test).
    // Zero new wire codes; status stays 500.
    // ---------------------------------------------------------------------

    /**
     * Drive an arm, assert the fixed wire copy, assert the caught exception's
     * own text (which embeds scratch paths / echoed input) appears nowhere in
     * the body, and assert it DID reach the captured log (class + message).
     *
     * @param array<string, mixed> $expectBody Decoded envelope expected verbatim.
     */
    private function assertE2WEnvelope(
        Response $response,
        string $logPath,
        string $expectContext,
        string $expectClass,
        string $leakNeedle,
        array $expectBody
    ): void {
        self::assertSame(500, $response->statusCode);
        self::assertSame($expectBody, json_decode((string) $response->body, true));
        self::assertStringNotContainsString($leakNeedle, (string) $response->body);

        $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
        self::assertStringContainsString($expectContext, $log, 'endpoint context reached the log');
        self::assertStringContainsString($expectClass, $log, 'exception class reached the log');
        self::assertStringContainsString($leakNeedle, $log, 'the cause itself is log-only, not wire');
    }

    /**
     * Point LoggerFactory at a throwaway stream handler (the estate
     * captured-logger idiom from AdminSettingsControllerErrorEnvelopeTest).
     */
    private function initCapturedLogger(): string
    {
        LoggerFactory::reset();
        $dir = sys_get_temp_dir() . '/phlix-e2w-log-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->loggerDirs[] = $dir;
        $logPath = $dir . '/app.log';
        file_put_contents(
            $dir . '/logger.php',
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($logPath, true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );
        LoggerFactory::init($dir . '/logger.php');

        return $logPath;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postBody(array $body): Request
    {
        $request = $this->request();
        $request->body = $body;

        return $request;
    }

    public function testHubPairRejectsNonHttpSchemeWith400(): void
    {
        $response = $this->controller()->hubPair(
            $this->postBody(['hubUrl' => 'file:///etc/passwd', 'serverName' => 'X']),
            []
        );

        $this->assertSame(400, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertFalse($body['success']);
        $this->assertSame('Hub URL must be an http:// or https:// URL.', $body['message']);
    }

    public function testHubCompleteRejectsNonHttpSchemeWith400(): void
    {
        foreach ([['gopher://h.test', 'https://h.test/jwks'], ['https://h.test', 'file:///x']] as [$hubUrl, $jwks]) {
            $response = $this->controller()->hubComplete(
                $this->postBody([
                    'enrollmentJwt' => 'jwt', 'hubJwksUrl' => $jwks, 'serverId' => 's', 'hubUrl' => $hubUrl,
                ]),
                []
            );

            $this->assertSame(400, $response->statusCode);
            $body = $this->decode($response->body);
            $this->assertSame(
                'Hub URL and hub JWKS URL must be http:// or https:// URLs.',
                $body['message']
            );
        }
        $this->assertFileDoesNotExist($this->dir . '/hub-enrollment.json');
    }

    /**
     * Controller whose HubClient is a mock returning $result from pollClaimStatus().
     * `$stored` flips true when storeEnrollment() is reached.
     */
    private function pollControllerReturning(ClaimStatusResult $result, bool &$stored): AdminHubController
    {
        $client = $this->getMockBuilder(HubClient::class)->disableOriginalConstructor()->getMock();
        $client->method('pollClaimStatus')->willReturn($result);
        $client->method('storeEnrollment')->willReturnCallback(function () use (&$stored): void {
            $stored = true;
        });
        $container = new class ($client) implements \Psr\Container\ContainerInterface {
            public function __construct(private object $client)
            {
            }

            public function get(string $id): mixed
            {
                return $this->client;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        return new AdminHubController($container, $this->dir);
    }

    public function testHubPollRefusesToStoreANonHttpJwksUrlFromTheHub(): void
    {
        foreach (['file:///etc/passwd', 'javascript:alert(1)', 'gopher://h.test/jwks'] as $jwks) {
            $stored = false;
            $controller = $this->pollControllerReturning(
                new ClaimStatusResult(ClaimStatusResult::STATUS_CLAIMED, 'jwt', $jwks, 'srv-1'),
                $stored
            );
            $response = $controller->hubPoll(
                $this->postBody(['claimId' => 'abc123', 'hubUrl' => 'https://hub.test']),
                []
            );

            $this->assertSame(502, $response->statusCode, $jwks);
            $this->assertFalse($this->decode($response->body)['success']);
            $this->assertFalse($stored, 'non-http JWKS URL must never reach storeEnrollment()');
        }
    }

    public function testHubPollStoresAHttpsJwksUrl(): void
    {
        $stored = false;
        $controller = $this->pollControllerReturning(
            new ClaimStatusResult(
                ClaimStatusResult::STATUS_CLAIMED,
                'jwt',
                'https://hub.test/.well-known/jwks.json',
                'srv-1'
            ),
            $stored
        );
        $response = $controller->hubPoll(
            $this->postBody(['claimId' => 'abc123', 'hubUrl' => 'https://hub.test']),
            []
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($stored);
    }

    public function testHubPairFailureAnswersFixedCopyAndLogsTheCause(): void
    {
        $logPath = $this->initCapturedLogger();
        // Corrupt key material: getOrCreateKeyPair() rejects the PEM and its
        // message quotes the ABSOLUTE key path — exactly the internal state a
        // leaking arm would have shipped to the admin client.
        file_put_contents($this->dir . '/hub-server-key.pem', 'e2w-not-a-pem');

        $response = $this->controller()->hubPair(
            $this->postBody(['hubUrl' => 'https://hub.e2w.test', 'serverName' => 'E2W']),
            []
        );

        $this->assertE2WEnvelope(
            $response,
            $logPath,
            'Admin hub endpoint failed: hub pair',
            'InvalidArgumentException',
            'hub-server-key.pem',
            ['success' => false, 'message' => 'Failed to start hub pairing.']
        );
    }

    public function testHubPollFailureAnswersFixedCopyAndLogsTheCause(): void
    {
        $logPath = $this->initCapturedLogger();

        // Malformed claimId: pollClaimStatus() parses it at its own boundary and
        // throws BEFORE any network — its message echoes the rejected token.
        $response = $this->controller()->hubPoll(
            $this->postBody(['claimId' => 'bad.id.e2w', 'hubUrl' => 'https://hub.e2w.test']),
            []
        );

        $this->assertE2WEnvelope(
            $response,
            $logPath,
            'Admin hub endpoint failed: hub poll',
            'HubClientException',
            'bad.id.e2w',
            ['success' => false, 'message' => 'Failed to poll claim status.']
        );
    }

    public function testHubCompleteFailureAnswersFixedCopyAndLogsTheCause(): void
    {
        $logPath = $this->initCapturedLogger();
        // configDir is a FILE: storeEnrollment() fails its temp-file step and
        // names the path it could not write into.
        $blockedDir = $this->dir . '/not-a-dir';
        file_put_contents($blockedDir, 'e2w');

        $response = (new AdminHubController(null, $blockedDir))->hubComplete(
            $this->postBody([
                'enrollmentJwt' => 'jwt',
                'hubJwksUrl' => 'https://hub.e2w.test/.well-known/jwks.json',
                'serverId' => 'srv-1',
                'hubUrl' => 'https://hub.e2w.test',
            ]),
            []
        );

        $this->assertE2WEnvelope(
            $response,
            $logPath,
            'Admin hub endpoint failed: hub complete',
            'RuntimeException',
            'not-a-dir',
            ['success' => false, 'message' => 'Failed to complete hub pairing.']
        );
    }

    public function testNoCatchArmEchoesExceptionTextOntoTheWire(): void
    {
        // Source tripwire for the arms no unit drive can reach deterministically
        // (hub unenroll / heartbeat, subdomain claim/release, relay enable/
        // disable/ping, port-forward enable/disable — the store is null-safe or
        // the vector is network-only). All 12 leak arms share one shape and now
        // share one exit: $this->internalFailure(). Reddening: ANY restored
        // `'message' => $e->getMessage()` or arm bypassing the helper.
        $source = file_get_contents(
            dirname(__DIR__, 6) . '/src/Server/Http/Controllers/Admin/AdminHubController.php'
        );
        self::assertIsString($source);

        // The ONLY remaining `'message' => $e->getMessage()` is the sanctioned
        // log-context line inside internalFailure() — one occurrence, server-side
        // only. A restored wire echo makes this 2+; a swallowed cause makes it 0.
        self::assertSame(1, substr_count($source, "'message' => " . '$' . "e->getMessage()"));
        self::assertSame(12, substr_count($source, 'return $this->internalFailure('));
        self::assertSame(1, substr_count($source, 'LoggerFactory::get(LogChannels::HTTP)->error('));
        self::assertStringContainsString("['exception' => get_class(\$e), 'message' => \$e->getMessage()]", $source);
    }
}
