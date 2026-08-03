<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\HttpClientInterface;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayStateStore;
use Phlix\Server\Http\Controllers\Admin\HealthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit coverage for the S40 Network Health surface on {@see HealthController}.
 *
 * The relay tunnel + hub heartbeat run in SEPARATE forked processes with no
 * shared memory, so these HTTP-worker endpoints must read the cross-process
 * state files those forks write (`relay-tunnel.state.json`,
 * `hub-heartbeat.state.json`) via {@see RelayStateStore} — NOT a never-started,
 * container-local `RelayConsumer`/`HubClient` copy. And crucially, `/health/network`
 * must NOT fire an outbound heartbeat POST on every poll (the core S40 bug).
 *
 * The controller is constructed with a null container + seeded temp config dir
 * (mirroring {@see AdminHubControllerTest}); the "no outbound POST" test uses a
 * container returning a HubClient whose HTTP client asserts it is never invoked.
 */
final class HealthControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-health-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function controller(?ContainerInterface $container = null): HealthController
    {
        return new HealthController($container, $this->dir);
    }

    private function request(): Request
    {
        return new Request();
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

    private function seedEnrollment(?int $exp = null): void
    {
        $jwt = $exp !== null ? $this->fakeJwt($exp) : 'jwt-token';
        file_put_contents(
            $this->dir . '/hub-enrollment.json',
            json_encode([
                'enrollment_jwt' => $jwt,
                'hub_jwks_url' => 'https://hub.example.com/.well-known/jwks.json',
                'server_id' => 'srv-1',
                'hub_base_url' => 'https://hub.example.com',
                'enrolled_at' => time(),
            ], JSON_THROW_ON_ERROR)
        );
    }

    /** Builds an (unsigned) JWT whose payload carries an `exp` claim. */
    private function fakeJwt(int $exp): string
    {
        $enc = static fn (array $a): string => rtrim(
            strtr(base64_encode((string) json_encode($a)), '+/', '-_'),
            '='
        );

        return $enc(['alg' => 'none']) . '.' . $enc(['exp' => $exp]) . '.sig';
    }

    // ---------------------------------------------------------------------
    // relayHealth — reads seeded cross-process state, not a live object.
    // ---------------------------------------------------------------------

    public function testRelayHealthReadsSeededStateFiles(): void
    {
        $this->seedRelayState(json_encode([
            'connected' => true,
            'active' => true,
            'reconnectAttempts' => 3,
            'activeSessions' => 5,
            'lastDisconnectTime' => '2026-07-23T10:00:00+00:00',
            'lastConnectError' => 'boom-socket',
            'lastConnectErrorAt' => '2026-07-23T09:59:00+00:00',
            'updatedAt' => '2026-07-23T10:01:00+00:00',
        ], JSON_THROW_ON_ERROR));
        $this->seedHeartbeatState(json_encode([
            'lastHeartbeatAttempt' => '2026-07-23T10:02:00+00:00',
            'lastSuccessfulHeartbeat' => '2026-07-23T10:02:00+00:00',
            'consecutiveFailures' => 0,
            'lastLatencyMs' => 42,
            'updatedAt' => '2026-07-23T10:02:00+00:00',
        ], JSON_THROW_ON_ERROR));
        $futureExp = time() + 86400;
        $this->seedEnrollment($futureExp);

        $response = $this->controller()->relayHealth($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);

        self::assertIsArray($body['relay']);
        self::assertTrue($body['relay']['connected']);
        self::assertTrue($body['relay']['active']);
        self::assertSame(3, $body['relay']['reconnectAttempts']);
        self::assertSame(5, $body['relay']['activeSessions']);
        self::assertSame('2026-07-23T10:00:00+00:00', $body['relay']['lastDisconnectTime']);
        self::assertSame('boom-socket', $body['relay']['lastConnectError']);
        self::assertSame('2026-07-23T09:59:00+00:00', $body['relay']['lastConnectErrorAt']);

        self::assertIsArray($body['hub']);
        self::assertSame('2026-07-23T10:02:00+00:00', $body['hub']['lastSuccessfulHeartbeat']);
        self::assertSame(0, $body['hub']['consecutiveFailures']);
        self::assertSame(42, $body['hub']['lastLatencyMs']);
        self::assertTrue($body['hub']['isEnrolled']);
        self::assertNotNull($body['hub']['enrollmentExpiresAt']);
        // getEnrollmentExpiry() builds a UTC DateTimeImmutable from the `@epoch`
        // form, so the ISO-8601 string carries a +00:00 offset.
        self::assertSame(
            (new \DateTimeImmutable('@' . $futureExp))->format('c'),
            $body['hub']['enrollmentExpiresAt']
        );
    }

    public function testRelayHealthReportsOfflineWhenStateFilesMissing(): void
    {
        // No state files and no enrollment — a never-started fork must read as
        // offline / not-enrolled, and must not throw or 500.
        $response = $this->controller()->relayHealth($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['relay']['connected']);
        self::assertFalse($body['relay']['active']);
        self::assertSame(0, $body['relay']['reconnectAttempts']);
        self::assertSame(0, $body['relay']['activeSessions']);
        self::assertNull($body['relay']['lastDisconnectTime']);
        self::assertNull($body['relay']['lastConnectError']);

        self::assertNull($body['hub']['lastSuccessfulHeartbeat']);
        self::assertSame(0, $body['hub']['consecutiveFailures']);
        self::assertNull($body['hub']['lastLatencyMs']);
        self::assertFalse($body['hub']['isEnrolled']);
        self::assertNull($body['hub']['enrollmentExpiresAt']);
    }

    // ---------------------------------------------------------------------
    // networkHealth — cheap read of persisted heartbeat state (NO POST).
    // ---------------------------------------------------------------------

    public function testNetworkHealthOfflineWhenNotEnrolled(): void
    {
        $response = $this->controller()->networkHealth($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertNull($body['latencyMs']);
        self::assertSame('offline', $body['status']);
        self::assertSame('Not enrolled in hub', $body['error']);
    }

    public function testNetworkHealthHealthyFromPersistedState(): void
    {
        $this->seedEnrollment();
        $this->seedHeartbeatState(json_encode([
            'lastSuccessfulHeartbeat' => '2026-07-23T10:02:00+00:00',
            'consecutiveFailures' => 0,
            'lastLatencyMs' => 42,
            'updatedAt' => '2026-07-23T10:02:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $body = $this->decode($this->controller()->networkHealth($this->request(), [])->body);
        self::assertSame(42, $body['latencyMs']);
        self::assertSame('healthy', $body['status']);
        self::assertSame('2026-07-23T10:02:00+00:00', $body['measuredAt']);
        self::assertArrayNotHasKey('error', $body);
    }

    public function testNetworkHealthDegradedFromPersistedState(): void
    {
        $this->seedEnrollment();
        $this->seedHeartbeatState(json_encode([
            'lastSuccessfulHeartbeat' => '2026-07-23T10:02:00+00:00',
            'consecutiveFailures' => 0,
            'lastLatencyMs' => 250,
            'updatedAt' => '2026-07-23T10:02:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $body = $this->decode($this->controller()->networkHealth($this->request(), [])->body);
        self::assertSame(250, $body['latencyMs']);
        self::assertSame('degraded', $body['status']);
    }

    public function testNetworkHealthOfflineWhenLatencyExceeds500(): void
    {
        $this->seedEnrollment();
        $this->seedHeartbeatState(json_encode([
            'lastSuccessfulHeartbeat' => '2026-07-23T10:02:00+00:00',
            'consecutiveFailures' => 0,
            'lastLatencyMs' => 750,
            'updatedAt' => '2026-07-23T10:02:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $body = $this->decode($this->controller()->networkHealth($this->request(), [])->body);
        self::assertSame(750, $body['latencyMs']);
        self::assertSame('offline', $body['status']);
    }

    public function testNetworkHealthOfflineWhenHeartbeatFailing(): void
    {
        $this->seedEnrollment();
        $this->seedHeartbeatState(json_encode([
            'lastSuccessfulHeartbeat' => '2026-07-23T10:00:00+00:00',
            'consecutiveFailures' => 3,
            'lastLatencyMs' => null,
            'updatedAt' => '2026-07-23T10:05:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $body = $this->decode($this->controller()->networkHealth($this->request(), [])->body);
        self::assertSame('offline', $body['status']);
        self::assertSame('Hub heartbeat failing', $body['error']);
    }

    public function testNetworkHealthOfflineWhenNoSuccessfulHeartbeatYet(): void
    {
        // Enrolled, but the heartbeat fork has not yet recorded a success.
        $this->seedEnrollment();

        $body = $this->decode($this->controller()->networkHealth($this->request(), [])->body);
        self::assertNull($body['latencyMs']);
        self::assertSame('offline', $body['status']);
        self::assertSame('No successful heartbeat recorded yet', $body['error']);
    }

    public function testNetworkHealthFallsBackWhenContainerCannotResolveHubClient(): void
    {
        // Container present but unable to resolve HubClient → getHubClient() must
        // fall back to a file-reading instance rather than 500. With no
        // enrollment seeded, networkHealth reports offline/not-enrolled.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('no binding'));
        $container->method('has')->willReturn(false);

        $response = $this->controller($container)->networkHealth($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertSame('offline', $body['status']);
        self::assertSame('Not enrolled in hub', $body['error']);
    }

    /**
     * Core S40 landmine: `/health/network` used to POST a REAL heartbeat to the
     * hub on every call. It must now be a cheap read — the hub HTTP client is
     * NEVER invoked.
     */
    public function testNetworkHealthMakesNoOutboundHeartbeatPost(): void
    {
        $this->seedEnrollment();
        $this->seedHeartbeatState(json_encode([
            'lastSuccessfulHeartbeat' => '2026-07-23T10:02:00+00:00',
            'consecutiveFailures' => 0,
            'lastLatencyMs' => 30,
            'updatedAt' => '2026-07-23T10:02:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('post');
        $httpClient->expects($this->never())->method('get');
        $httpClient->expects($this->never())->method('delete');

        $hubClient = new HubClient(
            new Ed25519KeyManager($this->dir . '/key.pem'),
            $httpClient,
            new StructuredLogger('hub', []),
            $this->dir,
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($hubClient): mixed {
                if ($id === HubClient::class) {
                    return $hubClient;
                }
                throw new \RuntimeException('unexpected container get: ' . $id);
            }
        );
        $container->method('has')->willReturn(true);

        $response = $this->controller($container)->networkHealth($this->request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertSame(30, $body['latencyMs']);
        self::assertSame('healthy', $body['status']);
        // The mock's never()-expectations are verified at teardown: no POST fired.
    }
}
