<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Hub\HubApplication;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayStateStore;
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
     * @return Response JSON {paired, serverId, hubUrl, enrolledAt, lastHeartbeat,
     *   consecutiveFailures, enrollmentExpiresAt, isEnrolled}.
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

            $heartbeatStatus = $this->getHeartbeatStatus();

            return (new Response())->json([
                'paired' => true,
                'serverId' => $enrollment['server_id'] ?? null,
                'hubUrl' => $enrollment['hub_base_url'] ?? null,
                'enrolledAt' => isset($enrollment['enrolled_at']) && is_int($enrollment['enrolled_at'])
                    ? date('c', $enrollment['enrolled_at'])
                    : null,
                'lastHeartbeat' => $heartbeatStatus['lastSuccessfulHeartbeat'],
                'consecutiveFailures' => $heartbeatStatus['consecutiveFailures'],
                'enrollmentExpiresAt' => $heartbeatStatus['enrollmentExpiresAt'],
                'isEnrolled' => $heartbeatStatus['isEnrolled'],
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
                // Persist the enrollment server-side NOW. The hub deletes the
                // one-time claim the instant it returns the JWT, so relying on a
                // separate client `/complete` call is lossy (a missing field or
                // closed tab strands the pairing — the claim is already gone and
                // cannot be re-polled). Storing here makes the poll atomic and
                // robust for any client; `/complete` remains for back-compat and
                // is now idempotent.
                $enrollmentJwt = $result->enrollmentJwt ?? '';
                $resultJwksUrl = $result->hubJwksUrl ?? '';
                $resultServerId = $result->serverId ?? '';
                $hubClient->storeEnrollment($enrollmentJwt, $resultJwksUrl, $resultServerId, $hubUrl);

                return (new Response())->json([
                    'success' => true,
                    'paired' => true,
                    'token' => $enrollmentJwt,
                    'hubJwksUrl' => $resultJwksUrl,
                    'serverId' => $resultServerId,
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

            // Idempotent: hubPoll() now stores the enrollment as soon as the
            // claim is consumed, so by the time a (possibly older) client calls
            // /complete the server may already be enrolled. Older clients also
            // send an empty hubJwksUrl (the poll response historically omitted
            // it). In either case, if we are already enrolled, report success
            // rather than 400 on the missing field.
            if ($hubClient->loadEnrollment() !== null) {
                return (new Response())->json(['success' => true]);
            }

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
     * The real tunnel runs in a SEPARATE forked process (`phlix-relay-tunnel`),
     * which is the sole writer of `relay-tunnel.state.json` (S38). This HTTP
     * worker reads that file — the honest, cross-process live status — instead
     * of the never-started container-local relay consumer copy, and without the
     * blocking process-probe / log-scrape the previous implementation ran in the
     * event loop (S39).
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { connected, active, reconnectAttempts, activeSessions,
     *   lastDisconnectTime, lastConnectError, lastConnectErrorAt, disabled,
     *   enrolled, updatedAt, endpoint, establishedAt }.
     */
    public function relayStatus(Request $request, array $params): Response
    {
        try {
            $state = $this->getRelayStateStore()->readRelayState();

            // `disabled` reflects what will actually take effect on reload: the
            // persisted operator kill-switch OR the env var. `enrolled` and the
            // kill-switch are the REAL levers the UI reframe surfaces.
            $disabled = $this->relayEnvDisabled() || $this->getRelayStateStore()->isRelayDisabled();
            $enrolled = $this->getHubEnrollment() !== null;

            $updatedAt = isset($state['updatedAt']) && is_string($state['updatedAt'])
                ? $state['updatedAt'] : null;

            return (new Response())->json([
                'connected' => ($state['connected'] ?? false) === true,
                'active' => ($state['active'] ?? false) === true,
                'reconnectAttempts' => isset($state['reconnectAttempts']) && is_int($state['reconnectAttempts'])
                    ? $state['reconnectAttempts'] : 0,
                'activeSessions' => isset($state['activeSessions']) && is_int($state['activeSessions'])
                    ? $state['activeSessions'] : 0,
                'lastDisconnectTime' => isset($state['lastDisconnectTime']) && is_string($state['lastDisconnectTime'])
                    ? $state['lastDisconnectTime'] : null,
                'lastConnectError' => isset($state['lastConnectError']) && is_string($state['lastConnectError'])
                    ? $state['lastConnectError'] : null,
                'lastConnectErrorAt' => isset($state['lastConnectErrorAt']) && is_string($state['lastConnectErrorAt'])
                    ? $state['lastConnectErrorAt'] : null,
                'disabled' => $disabled,
                'enrolled' => $enrolled,
                // When the relay fork last wrote state (staleness signal); null
                // if the fork has never run / never written.
                'updatedAt' => $updatedAt,
                // Back-compat keys retained for the current UI; the state file
                // does not carry them.
                'endpoint' => null,
                'establishedAt' => $updatedAt,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => 'Failed to load relay status.',
            ]);
        }
    }

    /**
     * Enables the relay tunnel (clears the persisted operator kill-switch).
     *
     * POST /api/v1/admin/remote/relay/enable
     *
     * HONEST lever (S39): the real tunnel runs in a separate fork with no live
     * control channel, so "Enable" clears the persisted `relay-control.json`
     * kill-switch that the relay fork reads at boot. It takes effect on the next
     * server reload — reported via `takesEffectOnReload`. This is NOT a fake
     * `{success:true}` no-op: it persists a real state change and returns the
     * resolved levers. If `PHLIX_RELAY_DISABLED` is set in the environment it
     * still wins (this endpoint cannot unset an env var), so `disabled` may stay
     * `true` even after a successful enable.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success, disabled, enrolled, takesEffectOnReload, message }.
     */
    public function relayEnable(Request $request, array $params): Response
    {
        try {
            if (!$this->getRelayStateStore()->setRelayDisabled(false)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Failed to persist relay enable state.',
                ]);
            }

            $envDisabled = $this->relayEnvDisabled();
            $enrolled = $this->getHubEnrollment() !== null;

            if ($envDisabled) {
                $message = 'Relay kill-switch cleared, but PHLIX_RELAY_DISABLED is set in the '
                    . 'environment; the tunnel stays disabled until that is removed and the '
                    . 'server reloads.';
            } elseif (!$enrolled) {
                $message = 'Relay enabled, but this server is not paired with a hub; pair with a '
                    . 'hub for the tunnel to connect on the next reload.';
            } else {
                $message = 'Relay enabled; the tunnel will (re)connect on the next server reload.';
            }

            return (new Response())->json([
                'success' => true,
                'disabled' => $envDisabled,
                'enrolled' => $enrolled,
                'takesEffectOnReload' => true,
                'message' => $message,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Disables the relay tunnel (persists the operator kill-switch).
     *
     * POST /api/v1/admin/remote/relay/disable
     *
     * HONEST lever (S39): persists `disabled:true` to `relay-control.json`, which
     * the relay fork honors at boot IN ADDITION to `PHLIX_RELAY_DISABLED`. It
     * does NOT tear down the already-running fork in-process (cross-process, no
     * live channel), so it takes effect on the next server reload — reported via
     * `takesEffectOnReload`. Stops the previous fake `{success:true}` no-op.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success, disabled, enrolled, takesEffectOnReload, message }.
     */
    public function relayDisable(Request $request, array $params): Response
    {
        try {
            if (!$this->getRelayStateStore()->setRelayDisabled(true)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'message' => 'Failed to persist relay disable state.',
                ]);
            }

            return (new Response())->json([
                'success' => true,
                'disabled' => true,
                'enrolled' => $this->getHubEnrollment() !== null,
                'takesEffectOnReload' => true,
                'message' => 'Relay disabled; the tunnel will disconnect on the next server reload.',
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pings the relay tunnel (reports persisted connection state + latency).
     *
     * POST /api/v1/admin/remote/relay/ping
     *
     * HONEST report (S39): the old implementation timed `isConnected()` on the
     * never-started container-local `RelayConsumer` — a meaningless boolean-getter
     * duration, not a network round-trip. This reads the relay fork's persisted
     * connection state (`relay-tunnel.state.json`) and the last persisted hub
     * round-trip latency (`hub-heartbeat.state.json`, written by the heartbeat
     * fork in S40). `latencyMs` is `null` until a heartbeat has been recorded —
     * honest "no measurement yet" rather than a fabricated timing.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON { success, connected, active, latencyMs,
     *   lastHeartbeatAt, latencySource } or 409 when the tunnel is not connected.
     */
    public function relayPing(Request $request, array $params): Response
    {
        try {
            $store = $this->getRelayStateStore();
            $relayState = $store->readRelayState();

            $connected = ($relayState['connected'] ?? false) === true;
            $active = ($relayState['active'] ?? false) === true;

            if (!$connected) {
                return (new Response())->status(409)->json([
                    'success' => false,
                    'connected' => false,
                    'active' => $active,
                    'message' => 'Relay not connected.',
                    'lastConnectError' =>
                        isset($relayState['lastConnectError']) && is_string($relayState['lastConnectError'])
                            ? $relayState['lastConnectError'] : null,
                    'lastConnectErrorAt' =>
                        isset($relayState['lastConnectErrorAt']) && is_string($relayState['lastConnectErrorAt'])
                            ? $relayState['lastConnectErrorAt'] : null,
                ]);
            }

            $heartbeat = $store->readHeartbeatState();
            $latencyMs = isset($heartbeat['lastLatencyMs']) && is_int($heartbeat['lastLatencyMs'])
                ? $heartbeat['lastLatencyMs'] : null;
            $lastHeartbeatAt =
                isset($heartbeat['lastSuccessfulHeartbeat']) && is_string($heartbeat['lastSuccessfulHeartbeat'])
                    ? $heartbeat['lastSuccessfulHeartbeat'] : null;

            return (new Response())->json([
                'success' => true,
                'connected' => true,
                'active' => $active,
                'latencyMs' => $latencyMs,
                'lastHeartbeatAt' => $lastHeartbeatAt,
                // Signals the value is the last persisted measurement, not a
                // live probe fired by this request.
                'latencySource' => 'persisted',
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
     * Returns a RelayStateStore bound to this controller's config dir.
     *
     * The relay tunnel + heartbeat forks write their live state to single-writer
     * JSON files under `$configDir`; this HTTP-worker controller reads them (and
     * writes the operator kill-switch to `relay-control.json`). Constructed from
     * `$this->configDir` for the same reason {@see getHubEnrollment()} reads the
     * enrollment file directly — so seeded-config unit tests need no container.
     *
     * @return RelayStateStore The state store for the relay control surface.
     */
    private function getRelayStateStore(): RelayStateStore
    {
        return new RelayStateStore($this->configDir);
    }

    /**
     * Whether the `PHLIX_RELAY_DISABLED` env kill-switch is set.
     *
     * The env var is an operator lever this endpoint cannot unset; it wins over
     * the persisted `relay-control.json` flag when reporting effective state.
     *
     * @return bool `true` when the env var disables the relay tunnel.
     */
    private function relayEnvDisabled(): bool
    {
        $value = getenv('PHLIX_RELAY_DISABLED');
        if ($value === false) {
            return false;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Returns a HubApplication instance from the container or null.
     *
     * @return HubApplication|null The HubApplication instance or null.
     */
    private function getHubApplication(): ?HubApplication
    {
        if ($this->container === null) {
            return null;
        }

        try {
            /** @var HubApplication */
            return $this->container->get(HubApplication::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns the heartbeat status from HubApplication.
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
        $hubApp = $this->getHubApplication();
        if ($hubApp !== null) {
            return $hubApp->getHeartbeatStatus();
        }

        // Fallback: construct HubClient directly to get status
        $hubClient = $this->getHubClient();
        return $hubClient->getStatus();
    }
}
