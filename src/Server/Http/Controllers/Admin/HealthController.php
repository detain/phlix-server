<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Hub\HubClient;
use Phlix\Hub\RelayStateStore;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Admin JSON API for network health monitoring (P3B-S7).
 *
 * Provides relay tunnel status, hub heartbeat status, and network latency
 * metrics for the server admin panel and UI health indicators.
 *
 * Route group prefix: /api/v1/health
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 2.3
 */
final class HealthController
{
    /** @var ContainerInterface|null PSR-11 container. */
    private ?ContainerInterface $container;

    /** @var string Config directory for JSON state files. */
    private string $configDir;

    /**
     * @param ContainerInterface|null $container PSR-11 container (optional for testing).
     * @param string                  $configDir Config directory for JSON state files.
     */
    public function __construct(?ContainerInterface $container = null, string $configDir = '')
    {
        $this->container = $container;
        // Use the same config directory source as HubServicesProvider: when configDir
        // is empty, derive it from this file's location (5 levels up = phlix-server/).
        // This must match hub.php's config_dir (which is __DIR__) so that the fallback
        // HubClient in getHubClient() finds hub-enrollment.json in the same location
        // as the container-wired HubClient.
        $this->configDir = $configDir !== '' ? $configDir : dirname(__DIR__, 5) . '/config';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relay health (P3B-S7.1)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns comprehensive relay health status.
     *
     * GET /api/v1/health/relay
     *
     * The relay tunnel and hub heartbeat run in SEPARATE forked processes
     * (`phlix-relay-tunnel`, `phlix-hub-heartbeat`) with no shared memory, so
     * this HTTP-worker endpoint reads the cross-process state files those forks
     * write (`relay-tunnel.state.json`, `hub-heartbeat.state.json`) via
     * {@see RelayStateStore} — NOT a never-started, container-local
     * `RelayConsumer`/`HubClient` copy (which always reported offline/0/null).
     * Enrollment presence/expiry stay cheap file reads via {@see HubClient}.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON {
     *     relay: {
     *         connected: bool,
     *         active: bool,
     *         reconnectAttempts: int,
     *         lastDisconnectTime: string|null,
     *         activeSessions: int,
     *         lastConnectError: string|null,
     *         lastConnectErrorAt: string|null
     *     },
     *     hub: {
     *         lastSuccessfulHeartbeat: string|null,
     *         consecutiveFailures: int,
     *         lastLatencyMs: int|null,
     *         isEnrolled: bool,
     *         enrollmentExpiresAt: string|null
     *     }
     * }.
     */
    public function relayHealth(Request $request, array $params): Response
    {
        try {
            $store = $this->getStateStore();
            $relayState = $store->readRelayState();
            $heartbeatState = $store->readHeartbeatState();
            $hubClient = $this->getHubClient();

            $relay = [
                'connected' => (bool) ($relayState['connected'] ?? false),
                'active' => (bool) ($relayState['active'] ?? false),
                'reconnectAttempts' => $this->intOrZero($relayState['reconnectAttempts'] ?? null),
                'lastDisconnectTime' => $this->nullableString($relayState['lastDisconnectTime'] ?? null),
                'activeSessions' => $this->intOrZero($relayState['activeSessions'] ?? null),
                'lastConnectError' => $this->nullableString($relayState['lastConnectError'] ?? null),
                'lastConnectErrorAt' => $this->nullableString($relayState['lastConnectErrorAt'] ?? null),
            ];

            $lastSuccess = $this->nullableString($heartbeatState['lastSuccessfulHeartbeat'] ?? null);

            return (new Response())->json([
                'relay' => $relay,
                'hub' => [
                    'lastSuccessfulHeartbeat' => $lastSuccess,
                    'consecutiveFailures' => $this->intOrZero($heartbeatState['consecutiveFailures'] ?? null),
                    'lastLatencyMs' => $this->nullableInt($heartbeatState['lastLatencyMs'] ?? null),
                    'isEnrolled' => $hubClient->loadEnrollment() !== null,
                    'enrollmentExpiresAt' => $hubClient->getEnrollmentExpiry()?->format('c'),
                ],
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => 'Failed to load relay health status.',
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Network health / latency (P3B-S7.2)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Reports hub network health from PERSISTED heartbeat state (cheap probe).
     *
     * GET /api/v1/health/network
     *
     * This endpoint is polled continuously by the admin UI's network-health
     * indicator. It MUST NOT fire an outbound heartbeat: the previous
     * implementation POSTed a REAL `/api/v1/servers/{id}/heartbeat` on every
     * call, mutating hub-side state and hammering the hub as the poller ran.
     * Instead it reads the latency/health snapshot the `phlix-hub-heartbeat`
     * fork already persists each tick to `hub-heartbeat.state.json`
     * ({@see HubClient::performHeartbeatTick()}). No blocking I/O, no side
     * effects — enrollment presence is a cheap file read, everything else a
     * best-effort read of the state store.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON {
     *     latencyMs: int|null,
     *     status: 'healthy'|'degraded'|'offline',
     *     measuredAt: string,
     *     error?: string
     * }.
     *
     * Status is 'healthy' when the last heartbeat's latency < 100ms, 'degraded'
     * at 100-500ms, and 'offline' when not enrolled, no heartbeat has yet
     * succeeded, the heartbeat is currently failing, or latency > 500ms.
     */
    public function networkHealth(Request $request, array $params): Response
    {
        try {
            $hubClient = $this->getHubClient();
            $enrollment = $hubClient->loadEnrollment();

            if ($enrollment === null) {
                return (new Response())->json([
                    'latencyMs' => null,
                    'status' => 'offline',
                    'measuredAt' => date('c'),
                    'error' => 'Not enrolled in hub',
                ]);
            }

            $heartbeatState = $this->getStateStore()->readHeartbeatState();

            $latencyMs = $this->nullableInt($heartbeatState['lastLatencyMs'] ?? null);
            $lastSuccess = $this->nullableString($heartbeatState['lastSuccessfulHeartbeat'] ?? null);
            $consecutiveFailures = $this->intOrZero($heartbeatState['consecutiveFailures'] ?? null);
            $measuredAt = $this->nullableString($heartbeatState['updatedAt'] ?? null) ?? date('c');

            // Offline when the fork has never recorded a successful heartbeat,
            // the heartbeat is currently failing, or there is no latency sample.
            if ($lastSuccess === null || $consecutiveFailures > 0 || $latencyMs === null) {
                return (new Response())->json([
                    'latencyMs' => $latencyMs,
                    'status' => 'offline',
                    'measuredAt' => $measuredAt,
                    'error' => $lastSuccess === null
                        ? 'No successful heartbeat recorded yet'
                        : 'Hub heartbeat failing',
                ]);
            }

            $status = match (true) {
                $latencyMs < 100 => 'healthy',
                $latencyMs <= 500 => 'degraded',
                default => 'offline',
            };

            return (new Response())->json([
                'latencyMs' => $latencyMs,
                'status' => $status,
                'measuredAt' => $measuredAt,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => 'Failed to measure network health.',
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns a HubClient instance from the container or a new instance.
     *
     * @return HubClient The HubClient instance.
     */
    private function getHubClient(): HubClient
    {
        if ($this->container !== null) {
            try {
                /** @var HubClient */
                return $this->container->get(HubClient::class);
            } catch (Throwable) {
                // Fall through to manual construction
            }
        }

        // Manual fallback (testing / minimal scenario)
        return new HubClient(
            new \Phlix\Hub\Ed25519KeyManager($this->configDir . '/hub-server-key.pem'),
            new \Phlix\Hub\HttpClient('https://hub.example.com'),
            new \Phlix\Common\Logger\StructuredLogger('hub', []),
            $this->configDir,
        );
    }

    /**
     * Returns the cross-process relay/heartbeat state store.
     *
     * Bound to this controller's config dir (the same directory the relay +
     * heartbeat forks write their single-writer state files to, and where
     * `hub-enrollment.json` lives). Constructed directly — like
     * {@see AdminHubController} — so seeded-config unit tests need no container.
     *
     * @return RelayStateStore The state store the forks write and this endpoint reads.
     */
    private function getStateStore(): RelayStateStore
    {
        return new RelayStateStore($this->configDir);
    }

    /**
     * Coerces a state-file value to a non-empty string, or null.
     *
     * @param mixed $value Raw value read from the (untyped) state JSON.
     *
     * @return string|null The string when non-empty, otherwise null.
     */
    private function nullableString(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * Coerces a state-file value to an int, or null when absent/non-numeric.
     *
     * @param mixed $value Raw value read from the (untyped) state JSON.
     *
     * @return int|null The int value, or null when the value is missing/null.
     */
    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Coerces a state-file value to an int, defaulting to 0 when non-numeric.
     *
     * @param mixed $value Raw value read from the (untyped) state JSON.
     *
     * @return int The int value, or 0 when the value is missing/non-numeric.
     */
    private function intOrZero(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
