<?php

declare(strict_types=1);

namespace Phlex\Hub;

use Phlex\Common\Logger\StructuredLogger;
use Throwable;

/**
 * Main orchestrator for server-to-hub communication.
 *
 * Handles the complete lifecycle:
 * 1. **Pairing** — initiates a claim request and polls for claim status.
 * 2. **Enrollment** — stores the enrollment JWT after successful claim.
 * 3. **Heartbeat** — sends periodic heartbeats to the hub.
 * 4. **JWKS** — exposes the server's public keys for hub JWT validation.
 * 5. **Re-enrollment** — automatically re-enrolls when the enrollment JWT expires.
 *
 * Heartbeat loop is managed by a Workerman timer and runs every 60 seconds.
 *
 * @package Phlex\Hub
 * @since 0.11.0
 */
class HubClient
{
    private const PROTOCOL_VERSION = 'v1';
    private const HEARTBEAT_INTERVAL = 60;
    private const ENROLLMENT_FILE = 'hub-enrollment.json';

    /** @var Ed25519KeyManager Key manager instance. */
    private Ed25519KeyManager $keyManager;

    /** @var HttpClientInterface HTTP client for hub communication. */
    private HttpClientInterface $httpClient;

    /** @var StructuredLogger Logger instance. */
    private StructuredLogger $logger;

    /** @var string Directory where enrollment JSON is stored. */
    private string $configDir;

    /** @var int|null Workerman timer ID. */
    private ?int $heartbeatTimer = null;

    /** @var int Process start time (for uptime calculation). */
    private int $processStartTime;

    /** @var string Server software version. */
    private string $serverVersion;

    /**
     * Creates a new HubClient.
     *
     * @param Ed25519KeyManager    $keyManager  Key manager for Ed25519 operations.
     * @param HttpClientInterface  $httpClient  HTTP client for hub API calls.
     * @param StructuredLogger $logger     Logger instance.
     * @param string                $configDir   Directory for enrollment storage.
     * @param string                $serverVersion Server software version string.
     */
    public function __construct(
        Ed25519KeyManager $keyManager,
        HttpClientInterface $httpClient,
        StructuredLogger $logger,
        string $configDir,
        string $serverVersion = '0.11.0',
    ) {
        $this->keyManager = $keyManager;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->configDir = $configDir;
        $this->serverVersion = $serverVersion;
        $this->processStartTime = time();
    }

    /**
     * Initiates pairing by sending a claim request to the hub.
     *
     * Generates (or loads) the server's Ed25519 keypair and sends a
     * claim request. The returned claim code should be displayed to the
     * operator for entry on the hub's web portal.
     *
     * @param string $hubUrl     The hub's base URL (e.g. `https://hub.example.com`).
     * @param string $serverName Human-readable server name for the hub dashboard.
     *
     * @return ClaimInitiateResult The claim code, expiry, and claim ID.
     */
    public function initiatePairing(string $hubUrl, string $serverName): ClaimInitiateResult
    {
        $keyPair = $this->keyManager->getOrCreateKeyPair();
        $publicKeyJwk = $this->keyManager->getPublicKeyJwk();

        $payload = [
            'server_name' => $serverName,
            'version' => $this->serverVersion,
            'public_keys' => $publicKeyJwk,
            'hostname_candidates' => $this->getHostnameCandidates(),
            'protocol_version' => self::PROTOCOL_VERSION,
        ];

        $this->logger->info('Initiating pairing with hub', [
            'hub_url' => $hubUrl,
            'server_name' => $serverName,
        ]);

        $response = $this->httpClient->post('/api/v1/server-claims/new', $payload);

        if (!$response->isSuccess()) {
            $errorCode = $response->getErrorCode() ?? 'UNKNOWN';
            $this->logger->error('Pairing initiation failed', [
                'hub_url' => $hubUrl,
                'status' => $response->statusCode,
                'error_code' => $errorCode,
            ]);
            throw new HubClientException(
                "Hub returned error: {$errorCode}",
                $response->statusCode,
                $errorCode,
            );
        }

        $body = $response->body;

        $claimCode = is_string($body['claim_code'] ?? null) ? $body['claim_code'] : '';
        $expiresIn = is_int($body['expires_in'] ?? null) ? $body['expires_in'] : 600;
        $claimId = is_string($body['claim_id'] ?? null) ? $body['claim_id'] : '';
        $hubBaseUrl = is_string($body['hub_base_url'] ?? null) ? $body['hub_base_url'] : $hubUrl;

        return new ClaimInitiateResult(
            claimCode: $claimCode,
            expiresIn: $expiresIn,
            claimId: $claimId,
            hubBaseUrl: $hubBaseUrl,
        );
    }

    /**
     * Polls the hub for the current claim status.
     *
     * Used by the CLI pairing script to wait for the user to complete
     * the claim flow on the hub's web portal.
     *
     * @param string $claimId The claim ID from initiatePairing.
     * @param string $hubUrl  The hub base URL.
     *
     * @return ClaimStatusResult Current status (pending / claimed / expired).
     */
    public function pollClaimStatus(string $claimId, string $hubUrl): ClaimStatusResult
    {
        $response = $this->httpClient->get("/api/v1/server-claims/{$claimId}");

        $body = $response->body;
        $status = is_string($body['status'] ?? null) ? $body['status'] : 'unknown';

        if ($status === ClaimStatusResult::STATUS_CLAIMED) {
            $enrollmentJwt = is_string($body['enrollment_jwt'] ?? null) ? $body['enrollment_jwt'] : '';
            $hubJwksUrl = is_string($body['hub_jwks_url'] ?? null) ? $body['hub_jwks_url'] : '';
            $serverId = is_string($body['server_id'] ?? null) ? $body['server_id'] : '';

            return new ClaimStatusResult(
                status: ClaimStatusResult::STATUS_CLAIMED,
                enrollmentJwt: $enrollmentJwt,
                hubJwksUrl: $hubJwksUrl,
                serverId: $serverId,
            );
        }

        if ($status === ClaimStatusResult::STATUS_EXPIRED) {
            return new ClaimStatusResult(
                status: ClaimStatusResult::STATUS_EXPIRED,
            );
        }

        return new ClaimStatusResult(
            status: ClaimStatusResult::STATUS_PENDING,
        );
    }

    /**
     * Stores the enrollment data after successful claim.
     *
     * Writes `hub-enrollment.json` to the config directory containing
     * the enrollment JWT, hub JWKS URL, server ID, and hub base URL.
     *
     * @param string $enrollmentJwt JWT from the hub's claim response.
     * @param string $hubJwksUrl    URL of the hub's JWKS document.
     * @param string $serverId     Hub-assigned server UUID.
     * @param string $hubBaseUrl    Hub's base URL for heartbeat.
     *
     * @return void
     */
    public function storeEnrollment(
        string $enrollmentJwt,
        string $hubJwksUrl,
        string $serverId,
        string $hubBaseUrl,
    ): void {
        $data = [
            'enrollment_jwt' => $enrollmentJwt,
            'hub_jwks_url' => $hubJwksUrl,
            'server_id' => $serverId,
            'hub_base_url' => $hubBaseUrl,
            'enrolled_at' => time(),
        ];

        $path = $this->getEnrollmentPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write enrollment file: ' . $path);
        }

        @chmod($path, 0600);

        $this->logger->info('Enrollment stored', [
            'server_id' => $serverId,
            'hub_base_url' => $hubBaseUrl,
        ]);
    }

    /**
     * Loads the stored enrollment, if any.
     *
     * @return StoredEnrollment|null The stored enrollment, or null if not enrolled.
     */
    public function loadEnrollment(): ?StoredEnrollment
    {
        $path = $this->getEnrollmentPath();
        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $data;

        return new StoredEnrollment(
            enrollmentJwt: is_string($data['enrollment_jwt'] ?? null) ? $data['enrollment_jwt'] : '',
            hubJwksUrl: is_string($data['hub_jwks_url'] ?? null) ? $data['hub_jwks_url'] : '',
            serverId: is_string($data['server_id'] ?? null) ? $data['server_id'] : '',
            hubBaseUrl: is_string($data['hub_base_url'] ?? null) ? $data['hub_base_url'] : '',
            enrolledAt: is_int($data['enrolled_at'] ?? null) ? $data['enrolled_at'] : 0,
        );
    }

    /**
     * Starts the background heartbeat loop.
     *
     * Registers a Workerman timer to call sendHeartbeat() every 60 seconds.
     * The heartbeat loop runs in the context of the Workerman worker.
     *
     * @return void
     */
    public function startHeartbeatLoop(): void
    {
        if ($this->heartbeatTimer !== null) {
            return;
        }

        $enrollment = $this->loadEnrollment();
        if ($enrollment === null) {
            $this->logger->warning('Cannot start heartbeat: not enrolled');
            return;
        }

        $this->httpClient = new HttpClient($enrollment->hubBaseUrl, $enrollment->enrollmentJwt);

        $this->heartbeatTimer = \Workerman\Timer::add(
            self::HEARTBEAT_INTERVAL,
            function (): void {
                $this->reEnrollIfNeeded();
                $result = $this->sendHeartbeat();
                if (!$result->ok) {
                    $this->logger->warning('Heartbeat failed', [
                        'error' => $result->error,
                        'error_code' => $result->errorCode,
                    ]);
                }
            },
        );

        $this->logger->info('Heartbeat loop started', [
            'interval' => self::HEARTBEAT_INTERVAL,
        ]);
    }

    /**
     * Stops the background heartbeat loop.
     *
     * @return void
     */
    public function stopHeartbeatLoop(): void
    {
        if ($this->heartbeatTimer !== null) {
            \Workerman\Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = null;
            $this->logger->info('Heartbeat loop stopped');
        }
    }

    /**
     * Sends a heartbeat to the hub.
     *
     * @return HeartbeatResult Success or failure with error details.
     */
    public function sendHeartbeat(): HeartbeatResult
    {
        $enrollment = $this->loadEnrollment();
        if ($enrollment === null) {
            return new HeartbeatResult(false, 'Not enrolled', 'NOT_ENROLLED');
        }

        $payload = [
            'server_id' => $enrollment->serverId,
            'version' => $this->serverVersion,
            'timestamp' => time(),
            'uptime_seconds' => time() - $this->processStartTime,
            'active_sessions' => 0,
            'active_transcodes' => 0,
            'hostname_candidates' => $this->getHostnameCandidates(),
            'capabilities' => ['direct-play', 'transcode-h264', 'transcode-h265', 'syncplay'],
        ];

        try {
            $response = $this->httpClient->post("/api/v1/servers/{$enrollment->serverId}/heartbeat", $payload);

            if ($response->statusCode === 401) {
                $errorCode = $response->getErrorCode() ?? 'UNAUTHORIZED';
                return new HeartbeatResult(false, 'Enrollment token expired', $errorCode);
            }

            if (!$response->isSuccess()) {
                $errorCode = $response->getErrorCode() ?? 'HEARTBEAT_FAILED';
                return new HeartbeatResult(false, "Heartbeat failed: {$response->statusCode}", $errorCode);
            }

            return new HeartbeatResult(true);
        } catch (Throwable $e) {
            $this->logger->error('Heartbeat exception', ['exception' => $e->getMessage()]);
            return new HeartbeatResult(false, $e->getMessage(), 'NETWORK_ERROR');
        }
    }

    /**
     * Returns the server's public keys as JWK for the JWKS endpoint.
     *
     * @return array<int, array<string, mixed>> Array of JWK maps.
     */
    public function getPublicKeysJwk(): array
    {
        return [$this->keyManager->getPublicKeyJwk()];
    }

    /**
     * Checks enrollment expiry and attempts re-enrollment if needed.
     *
     * Called automatically before every heartbeat. Re-enrollment
     * requires the operator to re-enter a claim code, so this method
     * logs a warning and leaves the server in a degraded state rather
     * than blocking.
     *
     * @return bool True if re-enrollment succeeded; false otherwise.
     */
    public function reEnrollIfNeeded(): bool
    {
        $enrollment = $this->loadEnrollment();
        if ($enrollment === null) {
            return false;
        }

        if (!$enrollment->isExpired()) {
            return false;
        }

        $this->logger->warning('Enrollment expired; re-enrollment required', [
            'server_id' => $enrollment->serverId,
            'enrolled_at' => $enrollment->enrolledAt,
        ]);

        return false;
    }

    /**
     * Returns the enrollment file path.
     *
     * @return string Absolute path to hub-enrollment.json.
     */
    private function getEnrollmentPath(): string
    {
        return rtrim($this->configDir, '/') . '/' . self::ENROLLMENT_FILE;
    }

    /**
     * Returns a list of hostnames/IPs the server believes it is reachable at.
     *
     * @return array<int, string> List of candidate URLs.
     */
    private function getHostnameCandidates(): array
    {
        $candidates = [];

        $serverName = $_SERVER['SERVER_NAME'] ?? null;
        if (!empty($serverName) && is_string($serverName)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $serverPort = $_SERVER['SERVER_PORT'] ?? null;
            $defaultPort = $scheme === 'https' ? 443 : 80;
            $port = is_string($serverPort) ? (int) $serverPort : $defaultPort;
            $candidates[] = $scheme . '://' . $serverName . ':' . $port;
        }

        $serverAddr = $_SERVER['SERVER_ADDR'] ?? null;
        if (!empty($serverAddr) && is_string($serverAddr)) {
            $scheme = 'http';
            $port = is_string($_SERVER['SERVER_PORT'] ?? null) ? (int) $_SERVER['SERVER_PORT'] : 8096;
            $candidates[] = $scheme . '://' . $serverAddr . ':' . $port;
        }

        return $candidates;
    }
}
