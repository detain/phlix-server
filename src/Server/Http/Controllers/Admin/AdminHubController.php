<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Hub\HubClient;
use Phlix\Hub\RelayApplication;
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\SubdomainClient;
use Phlix\Network\PortForwardService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Admin JSON API for remote access management (hub pairing, subdomain, relay tunnel, port-forward).
 *
 * All 16 endpoints are gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Core\Application::loadHubAdminRoutes()});
 * non-admin callers receive a JSON 401/403 from the middleware. This controller assumes
 * it only runs for authenticated admins.
 *
 * Route group prefix: /api/v1/admin/remote
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 2.3
 */
final class AdminHubController
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
    // Hub pairing (6 endpoints)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns current hub enrollment status.
     *
     * GET /api/v1/admin/remote/hub/status
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { paired, serverId, hubUrl, enrolledAt, lastHeartbeat }.
     */
    public function hubStatus(Request $request, array $params): Response
    {
        try {
            $enrollment = $this->getHubEnrollment();
            if ($enrollment === null) {
                return (new Response())->json([
                    'paired' => false,
                ]);
            }

            return (new Response())->json([
                'paired' => true,
                'serverId' => $enrollment['server_id'] ?? null,
                'hubUrl' => $enrollment['hub_base_url'] ?? null,
                'enrolledAt' => isset($enrollment['enrolled_at']) && is_int($enrollment['enrolled_at'])
                    ? date('c', $enrollment['enrolled_at'])
                    : null,
                'lastHeartbeat' => null, // Not persisted by HubClient
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => 'Failed to load hub status.',
            ]);
        }
    }

    /**
     * Initiates hub pairing.
     *
     * POST /api/v1/admin/remote/hub/pair
     *
     * @param Request              $request The HTTP request.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, serverId, hubUrl } or error.
     */
    public function hubPair(Request $request, array $params): Response
    {
        try {
            $hubClient = $this->getHubClient();
            $body = $request->body;
            $hubUrl = is_string($body['hubUrl'] ?? null) ? $body['hubUrl'] : '';
            $serverName = is_string($body['serverName'] ?? null) ? $body['serverName'] : 'Phlix Server';

            if ($hubUrl === '') {
                // Try to get hub URL from existing config
                $enrollment = $this->getHubEnrollment();
                $configHubUrl = $enrollment['hub_base_url'] ?? null;
                if ($enrollment !== null && is_string($configHubUrl) && $configHubUrl !== '') {
                    $hubUrl = $configHubUrl;
                }
            }

            if ($hubUrl === '') {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'message' => 'hubUrl is required.',
                ]);
            }

            $result = $hubClient->initiatePairing($hubUrl, $serverName);

            return (new Response())->json([
                'success' => true,
                'serverId' => '', // Not available until claim is complete
                'hubUrl' => $hubUrl,
                'claimCode' => $result->claimCode,
                'claimId' => $result->claimId,
                'expiresIn' => $result->expiresIn,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Polls for claim completion.
     *
     * POST /api/v1/admin/remote/hub/poll
     *
     * @param Request              $request The HTTP request.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, token } or { success: false, message }.
     */
    public function hubPoll(Request $request, array $params): Response
    {
        try {
            $hubClient = $this->getHubClient();
            $body = $request->body;
            $claimId = is_string($body['claimId'] ?? null) ? $body['claimId'] : '';
            $hubUrl = is_string($body['hubUrl'] ?? null) ? $body['hubUrl'] : '';

            if ($claimId === '') {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'message' => 'claimId is required.',
                ]);
            }

            if ($hubUrl === '') {
                $enrollment = $this->getHubEnrollment();
                $configHubUrl = $enrollment['hub_base_url'] ?? null;
                if (is_string($configHubUrl) && $configHubUrl !== '') {
                    $hubUrl = $configHubUrl;
                }
            }

            if ($hubUrl === '') {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'message' => 'hubUrl is required.',
                ]);
            }

            $result = $hubClient->pollClaimStatus($claimId, $hubUrl);

            if ($result->status === 'claimed') {
                return (new Response())->json([
                    'success' => true,
                    'token' => $result->enrollmentJwt,
                    'serverId' => $result->serverId,
                ]);
            }

            if ($result->status === 'expired') {
                return (new Response())->json([
                    'success' => false,
                    'message' => 'Claim has expired.',
                ]);
            }

            return (new Response())->json([
                'success' => false,
                'message' => 'Claim is still pending.',
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Completes hub pairing by storing enrollment.
     *
     * POST /api/v1/admin/remote/hub/complete
     *
     * @param Request              $request The HTTP request.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true }.
     */
    public function hubComplete(Request $request, array $params): Response
    {
        try {
            $hubClient = $this->getHubClient();
            $body = $request->body;
            $enrollmentJwt = is_string($body['enrollmentJwt'] ?? null) ? $body['enrollmentJwt'] : '';
            $hubJwksUrl = is_string($body['hubJwksUrl'] ?? null) ? $body['hubJwksUrl'] : '';
            $serverId = is_string($body['serverId'] ?? null) ? $body['serverId'] : '';
            $hubUrl = is_string($body['hubUrl'] ?? null) ? $body['hubUrl'] : '';

            if ($enrollmentJwt === '' || $hubJwksUrl === '' || $serverId === '' || $hubUrl === '') {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'message' => 'enrollmentJwt, hubJwksUrl, serverId, and hubUrl are required.',
                ]);
            }

            $hubClient->storeEnrollment($enrollmentJwt, $hubJwksUrl, $serverId, $hubUrl);

            return (new Response())->json([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Unenrolls from the hub.
     *
     * POST /api/v1/admin/remote/hub/unenroll
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true }.
     */
    public function hubUnenroll(Request $request, array $params): Response
    {
        try {
            $enrollmentPath = $this->configDir . '/hub-enrollment.json';
            if (file_exists($enrollmentPath)) {
                @unlink($enrollmentPath);
            }

            return (new Response())->json([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sends a hub heartbeat.
     *
     * POST /api/v1/admin/remote/hub/heartbeat
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, receivedAt }.
     */
    public function hubHeartbeat(Request $request, array $params): Response
    {
        try {
            $hubClient = $this->getHubClient();
            $result = $hubClient->sendHeartbeat();

            if (!$result->ok) {
                return (new Response())->status(409)->json([
                    'success' => false,
                    'message' => $result->error ?? 'Heartbeat failed.',
                ]);
            }

            return (new Response())->json([
                'success' => true,
                'receivedAt' => date('c'),
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Subdomain (3 endpoints)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns current subdomain status.
     *
     * GET /api/v1/admin/remote/subdomain/status
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { claimed, subdomain, fqdn, certPath, keyPath }.
     */
    public function subdomainStatus(Request $request, array $params): Response
    {
        try {
            $config = $this->getSubdomainConfig();
            if ($config === null) {
                return (new Response())->json([
                    'claimed' => false,
                ]);
            }

            return (new Response())->json([
                'claimed' => true,
                'subdomain' => $config['subdomain'] ?? null,
                'fqdn' => $config['fqdn'] ?? null,
                'certPath' => $config['cert_path'] ?? null,
                'keyPath' => $config['key_path'] ?? null,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => 'Failed to load subdomain status.',
            ]);
        }
    }

    /**
     * Claims a subdomain.
     *
     * POST /api/v1/admin/remote/subdomain/claim
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, subdomain, fqdn } or 409 if already claimed.
     */
    public function subdomainClaim(Request $request, array $params): Response
    {
        try {
            // Check if already claimed
            $existingConfig = $this->getSubdomainConfig();
            if ($existingConfig !== null) {
                return (new Response())->status(409)->json([
                    'success' => false,
                    'message' => 'Subdomain already claimed.',
                ]);
            }

            $subdomainClient = $this->getSubdomainClient();
            $result = $subdomainClient->claimSubdomain();

            if ($result === null) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Failed to claim subdomain.',
                ]);
            }

            return (new Response())->json([
                'success' => true,
                'subdomain' => $result->subdomain,
                'fqdn' => $result->fqdn,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Releases the subdomain.
     *
     * POST /api/v1/admin/remote/subdomain/release
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true } or 409 if not claimed.
     */
    public function subdomainRelease(Request $request, array $params): Response
    {
        try {
            // Check if not claimed
            $existingConfig = $this->getSubdomainConfig();
            if ($existingConfig === null) {
                return (new Response())->status(409)->json([
                    'success' => false,
                    'message' => 'Subdomain not claimed.',
                ]);
            }

            $subdomainClient = $this->getSubdomainClient();
            $released = $subdomainClient->releaseSubdomain();

            if (!$released) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Failed to release subdomain.',
                ]);
            }

            return (new Response())->json([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relay tunnel (4 endpoints)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns current relay tunnel status.
     *
     * GET /api/v1/admin/remote/relay/status
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { connected, active, endpoint, establishedAt }.
     */
    public function relayStatus(Request $request, array $params): Response
    {
        try {
            $relayApp = $this->getRelayApplication();
            $relayConsumer = $this->getRelayConsumer();

            $running = $relayApp !== null && $relayApp->isRunning();
            $connected = $relayConsumer !== null && $relayConsumer->isConnected();
            $active = $relayConsumer !== null && $relayConsumer->isActive();

            return (new Response())->json([
                'connected' => $running && $connected,
                'active' => $running && $active,
                'endpoint' => null, // Not exposed by RelayConsumer
                'establishedAt' => null, // Not tracked
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => 'Failed to load relay status.',
            ]);
        }
    }

    /**
     * Enables the relay tunnel.
     *
     * POST /api/v1/admin/remote/relay/enable
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true }.
     */
    public function relayEnable(Request $request, array $params): Response
    {
        try {
            $relayApp = $this->getRelayApplication();
            if ($relayApp === null) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Relay not configured.',
                ]);
            }

            if (!$relayApp->isRunning()) {
                $relayApp->start();
            }

            return (new Response())->json([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Disables the relay tunnel.
     *
     * POST /api/v1/admin/remote/relay/disable
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true }.
     */
    public function relayDisable(Request $request, array $params): Response
    {
        try {
            $relayApp = $this->getRelayApplication();
            if ($relayApp === null) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Relay not configured.',
                ]);
            }

            if ($relayApp->isRunning()) {
                $relayApp->stop();
            }

            return (new Response())->json([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pings the relay tunnel.
     *
     * POST /api/v1/admin/remote/relay/ping
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true, latencyMs }.
     */
    public function relayPing(Request $request, array $params): Response
    {
        try {
            $relayConsumer = $this->getRelayConsumer();

            if ($relayConsumer === null) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Relay not configured.',
                ]);
            }

            // Measure time for a round-trip through the relay
            $start = hrtime(true);
            $connected = $relayConsumer->isConnected();
            $end = hrtime(true);

            if (!$connected) {
                return (new Response())->status(409)->json([
                    'success' => false,
                    'message' => 'Relay not connected.',
                ]);
            }

            $latencyNs = $end - $start;
            $latencyMs = (int) ($latencyNs / 1_000_000);

            return (new Response())->json([
                'success' => true,
                'latencyMs' => $latencyMs,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Port forward (4 endpoints)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns current port-forward status.
     *
     * GET /api/v1/admin/remote/portforward/status
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { enabled, method, externalIp, externalPort, hostname }.
     */
    public function portForwardStatus(Request $request, array $params): Response
    {
        try {
            $service = new PortForwardService(null, null, null, null, 32400, true, $this->configDir);
            $status = $service->getStatus();

            return (new Response())->json([
                'enabled' => $status['enabled'],
                'method' => $status['method'],
                'externalIp' => $status['external_ip'],
                'externalPort' => $status['port'],
                'hostname' => $status['endpoint'],
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => 'Failed to load port-forward status.',
            ]);
        }
    }

    /**
     * Enables port forwarding.
     *
     * POST /api/v1/admin/remote/portforward/enable
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true }.
     */
    public function portForwardEnable(Request $request, array $params): Response
    {
        try {
            $service = new PortForwardService(null, null, null, null, 32400, true, $this->configDir);
            $result = $service->autoConfigure();

            if (!$result['success']) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Failed to enable port forwarding: ' . ($result['method'] ?? 'unknown error'),
                ]);
            }

            return (new Response())->json([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Disables port forwarding.
     *
     * POST /api/v1/admin/remote/portforward/disable
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success: true }.
     */
    public function portForwardDisable(Request $request, array $params): Response
    {
        try {
            $service = new PortForwardService(null, null, null, null, 32400, true, $this->configDir);
            $disabled = $service->disable();

            if ($disabled === false) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Failed to disable port forwarding.',
                ]);
            }

            return (new Response())->json([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Returns port-forward hostname candidates.
     *
     * GET /api/v1/admin/remote/portforward/candidates
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { candidates: [{ hostname, externalIp, port }] }.
     */
    public function portForwardCandidates(Request $request, array $params): Response
    {
        try {
            $service = new PortForwardService(null, null, null, null, 32400, true, $this->configDir);
            $candidates = $service->discoverHostnameCandidates();

            $formatted = array_map(function (array $candidate): array {
                // Extract hostname/IP and port from URL like "http://192.168.1.100:32400"
                $url = $candidate['url'];
                $parsed = parse_url($url);
                $host = $parsed['host'] ?? '';
                $port = $parsed['port'] ?? 32400;

                return [
                    'hostname' => $candidate['url'],
                    'externalIp' => $host,
                    'port' => $port,
                ];
            }, $candidates);

            return (new Response())->json([
                'candidates' => $formatted,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => 'Failed to load candidates.',
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns the hub enrollment data from the JSON config file.
     *
     * @return array<string, mixed>|null The enrollment data or null if not enrolled.
     */
    private function getHubEnrollment(): ?array
    {
        $path = rtrim($this->configDir, '/') . '/hub-enrollment.json';
        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Returns the subdomain config data from the JSON config file.
     *
     * @return array<string, mixed>|null The subdomain config or null if not claimed.
     */
    private function getSubdomainConfig(): ?array
    {
        $path = rtrim($this->configDir, '/') . '/hub-subdomain.json';
        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

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
     * Returns a SubdomainClient instance from the container or a new instance.
     *
     * @return SubdomainClient The SubdomainClient instance.
     */
    private function getSubdomainClient(): SubdomainClient
    {
        if ($this->container !== null) {
            try {
                /** @var SubdomainClient */
                return $this->container->get(SubdomainClient::class);
            } catch (Throwable) {
                // Fall through to manual construction
            }
        }

        // Manual fallback (testing / minimal scenario)
        $hubClient = $this->getHubClient();
        $enrollment = $hubClient->loadEnrollment();
        $serverId = $enrollment !== null ? $enrollment->serverId : '';

        return new SubdomainClient(
            $hubClient,
            $serverId,
            new \Phlix\Common\Logger\StructuredLogger('hub', []),
            $this->configDir,
        );
    }

    /**
     * Returns a RelayApplication instance from the container or null.
     *
     * @return RelayApplication|null The RelayApplication instance or null.
     */
    private function getRelayApplication(): ?RelayApplication
    {
        if ($this->container === null) {
            return null;
        }

        try {
            /** @var RelayApplication */
            return $this->container->get(RelayApplication::class);
        } catch (Throwable) {
            return null;
        }
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
}
