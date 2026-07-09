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
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\SubdomainClient;
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
    public function __construct(?ContainerInterface $container = null, string $configDir = 'config')
    {
        $this->container = $container;
        $this->configDir = $configDir;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relay health (P3B-S7.1)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns comprehensive relay health status.
     *
     * GET /api/v1/health/relay
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
     *         activeSessions: int
     *     },
     *     hub: {
     *         lastSuccessfulHeartbeat: string|null,
     *         consecutiveFailures: int,
     *         isEnrolled: bool,
     *         enrollmentExpiresAt: string|null
     *     }
     * }.
     */
    public function relayHealth(Request $request, array $params): Response
    {
        try {
            $relayConsumer = $this->getRelayConsumer();
            $heartbeatStatus = $this->getHeartbeatStatus();

            if ($relayConsumer !== null) {
                $relay = [
                    'connected' => $relayConsumer->isConnected(),
                    'active' => $relayConsumer->isActive(),
                    'reconnectAttempts' => $relayConsumer->getReconnectAttempts(),
                    'lastDisconnectTime' => $relayConsumer->getLastDisconnectTime(),
                    'activeSessions' => $relayConsumer->getActiveSessionCount(),
                ];
            } else {
                $relay = [
                    'connected' => false,
                    'active' => false,
                    'reconnectAttempts' => 0,
                    'lastDisconnectTime' => null,
                    'activeSessions' => 0,
                ];
            }

            return (new Response())->json([
                'relay' => $relay,
                'hub' => [
                    'lastSuccessfulHeartbeat' => $heartbeatStatus['lastSuccessfulHeartbeat'],
                    'consecutiveFailures' => $heartbeatStatus['consecutiveFailures'],
                    'isEnrolled' => $heartbeatStatus['isEnrolled'],
                    'enrollmentExpiresAt' => $heartbeatStatus['enrollmentExpiresAt'],
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
     * Measures network latency to the hub heartbeat endpoint.
     *
     * GET /api/v1/health/network
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON {
     *     latencyMs: int,
     *     status: 'healthy'|'degraded'|'offline',
     *     measuredAt: string
     * }.
     *
     * Status is 'healthy' when latency < 100ms, 'degraded' when 100-500ms,
     * and 'offline' when the request fails or latency > 500ms.
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

            // Measure round-trip time to the hub heartbeat endpoint
            $start = hrtime(true);

            // Create a temporary HTTP client pointed at the hub
            $tempClient = new \Phlix\Hub\HttpClient($enrollment->hubBaseUrl, $enrollment->enrollmentJwt);

            try {
                $payload = [
                    'serverId' => $enrollment->serverId,
                    'timestamp' => time(),
                ];
                $response = $tempClient->post("/api/v1/servers/{$enrollment->serverId}/heartbeat", $payload);
                $end = hrtime(true);

                if (!$response->isSuccess()) {
                    return (new Response())->json([
                        'latencyMs' => null,
                        'status' => 'offline',
                        'measuredAt' => date('c'),
                        'error' => 'Heartbeat failed: ' . $response->getErrorCode(),
                    ]);
                }

                $latencyNs = $end - $start;
                $latencyMs = (int) (($latencyNs / 1_000_000) + 0.5); // Round to nearest ms

                // Determine status based on latency thresholds
                $status = match (true) {
                    $latencyMs < 100 => 'healthy',
                    $latencyMs <= 500 => 'degraded',
                    default => 'offline',
                };

                return (new Response())->json([
                    'latencyMs' => $latencyMs,
                    'status' => $status,
                    'measuredAt' => date('c'),
                ]);
            } catch (Throwable $e) {
                return (new Response())->json([
                    'latencyMs' => null,
                    'status' => 'offline',
                    'measuredAt' => date('c'),
                    'error' => $e->getMessage(),
                ]);
            }
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
     * Returns a RelayConsumer instance from the container or null.
     *
     * @return RelayConsumer|null The RelayConsumer instance or null.
     */
    private function getRelayConsumer(): ?RelayConsumer
    {
        if ($this->container === null) {
            return null;
        }

        try {
            /** @var RelayConsumer */
            return $this->container->get(RelayConsumer::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns the heartbeat status from HubClient.
     *
     * @return array{
     *     lastHeartbeatAttempt: string|null,
     *     lastSuccessfulHeartbeat: string|null,
     *     consecutiveFailures: int,
     *     enrollmentExpiresAt: string|null,
     *     isEnrolled: bool
     * }
     */
    private function getHeartbeatStatus(): array
    {
        $hubClient = $this->getHubClient();
        return $hubClient->getStatus();
    }
}
