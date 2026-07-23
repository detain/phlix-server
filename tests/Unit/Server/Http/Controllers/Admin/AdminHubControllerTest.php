<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Hub\RelayStateStore;
use Phlix\Server\Http\Controllers\Admin\AdminHubController;
use Phlix\Server\Http\Request;
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
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminHubController
 */
final class AdminHubControllerTest extends TestCase
{
    private string $dir;

    /** @var string|false Saved PHLIX_RELAY_DISABLED env so tests can restore it. */
    private string|false $savedEnv;

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
        $this->seedRelayState(json_encode([
            'connected' => true,
            'active' => true,
            'reconnectAttempts' => 2,
            'activeSessions' => 4,
            'lastDisconnectTime' => '2026-07-23T10:00:00+00:00',
            'lastConnectError' => 'boom',
            'lastConnectErrorAt' => '2026-07-23T09:59:00+00:00',
            'updatedAt' => '2026-07-23T10:01:00+00:00',
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
        self::assertSame('2026-07-23T10:01:00+00:00', $body['updatedAt']);
        self::assertFalse($body['disabled']);
        self::assertFalse($body['enrolled']);
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
        $this->seedRelayState(json_encode(['connected' => true, 'active' => true], JSON_THROW_ON_ERROR));
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
    }

    public function testRelayPingLatencyNullWhenNoHeartbeatRecorded(): void
    {
        // Connected, but no heartbeat state file yet (S40 not shipped / no beat
        // recorded) → honest null, not a fabricated timing.
        $this->seedRelayState(json_encode(['connected' => true, 'active' => true], JSON_THROW_ON_ERROR));

        $body = $this->decode($this->controller()->relayPing($this->request(), [])->body);
        self::assertTrue($body['success']);
        self::assertNull($body['latencyMs']);
        self::assertSame('persisted', $body['latencySource']);
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
}
