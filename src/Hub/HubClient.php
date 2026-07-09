<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Network\PortForwardService;
use Phlix\Shared\Hub\ClaimRequest;
use Phlix\Shared\Hub\HeartbeatDto;
use Phlix\Shared\Hub\LibraryRef;
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
 * @package Phlix\Hub
 * @since 0.11.0
 */
class HubClient
{
    private const PROTOCOL_VERSION = 'v1';
    private const HEARTBEAT_INTERVAL = 60;
    private const ENROLLMENT_FILE = 'hub-enrollment.json';

    /** @var int Enrollment JWT lifetime in seconds (7 days). */
    private const ENROLLMENT_TTL = 604800;

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

    /** @var \DateTimeImmutable|null Last heartbeat attempt timestamp. */
    private ?\DateTimeImmutable $lastHeartbeatAttempt = null;

    /** @var \DateTimeImmutable|null Last successful heartbeat timestamp. */
    private ?\DateTimeImmutable $lastSuccessfulHeartbeat = null;

    /** @var int Number of consecutive heartbeat failures. */
    private int $consecutiveFailures = 0;

    /** @var int Process start time (for uptime calculation). */
    private int $processStartTime;

    /** @var string Server software version. */
    private string $serverVersion;

    /** @var PortForwardService|null Port forward service for hostname discovery. */
    private ?PortForwardService $portForwardService = null;

    /** @var string Configured public base URL (scheme+host); '' when no domain is set. */
    private string $publicUrl = '';

    /** @var int Renewal threshold in seconds (extracted from config in R5). */
    private int $renewalThreshold;

    /**
     * @var (\Closure(): list<array{library_id: string, library_name: string}>)|null
     *      Lazily returns this server's libraries to advertise in each heartbeat
     *      so the hub can cache them (server_libraries) and show them to the owner.
     *      Null leaves the heartbeat's `libraries` empty (backward compatible).
     */
    private ?\Closure $librariesProvider = null;

    /**
     * Creates a new HubClient.
     *
     * @param Ed25519KeyManager    $keyManager  Key manager for Ed25519 operations.
     * @param HttpClientInterface  $httpClient  HTTP client for hub API calls.
     * @param StructuredLogger $logger     Logger instance.
     * @param string                $configDir   Directory for enrollment storage.
     * @param string                $serverVersion Server software version string.
     * @param PortForwardService|null $portForwardService Port forward service for hostname discovery.
     * @param string                $publicUrl   Configured public base URL (scheme+host) advertised
     *                                            to the hub as a hostname candidate; '' when unset.
     * @param (\Closure(): list<array{library_id: string, library_name: string}>)|null $librariesProvider
     *                                            Returns the libraries to advertise in heartbeats; null = none.
     * @param int                   $renewalThreshold Seconds before expiry to trigger proactive
     *                                            enrollment renewal (R5: extracted from config).
     */
    public function __construct(
        Ed25519KeyManager $keyManager,
        HttpClientInterface $httpClient,
        StructuredLogger $logger,
        string $configDir,
        string $serverVersion = '0.11.0',
        ?PortForwardService $portForwardService = null,
        string $publicUrl = '',
        ?\Closure $librariesProvider = null,
        int $renewalThreshold = 518400,
    ) {
        $this->keyManager = $keyManager;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->configDir = $configDir;
        $this->serverVersion = $serverVersion;
        $this->portForwardService = $portForwardService;
        $this->publicUrl = $publicUrl;
        $this->librariesProvider = $librariesProvider;
        $this->processStartTime = time();
        $this->renewalThreshold = $renewalThreshold;
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

        // Build the wire payload from the shared ClaimRequest DTO so the
        // server emits exactly the camelCase keys the hub parses with
        // ClaimRequest::fromPayload(). A hand-rolled snake_case array here
        // (server_name/public_keys/…) makes the hub reject the request with
        // 400 "Bad Request" ("serverName is required").
        $payload = (new ClaimRequest(
            serverName: $serverName,
            version: $this->serverVersion,
            publicKeysJwk: $publicKeyJwk,
            hostnameCandidates: array_values($this->getHostnameCandidates()),
            protocolVersion: self::PROTOCOL_VERSION,
        ))->toPayload();

        $this->logger->info('Initiating pairing with hub', [
            'hub_url' => $hubUrl,
            'server_name' => $serverName,
        ]);

        // Target the operator-supplied hub explicitly: the injected client is an
        // empty-base placeholder (PHLIX_HUB_URL is usually unset), so a bare path
        // would hit cURL with "No host part in the URL". Pass the absolute URL.
        $response = $this->httpClient->post(
            rtrim($hubUrl, '/') . '/api/v1/server-claims/new',
            $payload,
        );

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

        // The hub replies with the shared ClaimResponse wire format
        // (camelCase: claimCode/expiresIn/claimId/hubBaseUrl); read those
        // keys, not snake_case, or the claim code comes back empty.
        $claimCode = is_string($body['claimCode'] ?? null) ? $body['claimCode'] : '';
        $expiresIn = is_int($body['expiresIn'] ?? null) ? $body['expiresIn'] : 600;
        $claimId = is_string($body['claimId'] ?? null) ? $body['claimId'] : '';
        $hubBaseUrl = is_string($body['hubBaseUrl'] ?? null) ? $body['hubBaseUrl'] : $hubUrl;

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
        // Absolute URL — the injected client has an empty placeholder base.
        $response = $this->httpClient->get(
            rtrim($hubUrl, '/') . "/api/v1/server-claims/{$claimId}",
        );

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
     * Returns the enrollment expiry date if the enrollment JWT can be decoded.
     *
     * @return \DateTimeImmutable|null The expiry DateTime or null if not enrolled or JWT invalid.
     */
    public function getEnrollmentExpiry(): ?\DateTimeImmutable
    {
        $enrollment = $this->loadEnrollment();
        if ($enrollment === null) {
            return null;
        }

        $parts = explode('.', $enrollment->enrollmentJwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $parts[1];
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }

        $exp = is_int($data['exp'] ?? null) ? $data['exp'] : 0;
        if ($exp === 0) {
            return null;
        }

        return new \DateTimeImmutable('@' . $exp);
    }

    /**
     * Checks whether the enrollment JWT expires within the next 24 hours.
     *
     * Decodes the JWT payload (no signature validation — we trust the file)
     * and returns true when the 'exp' claim is less than 24 hours away.
     *
     * @return bool True if renewal is needed within 24 hours.
     */
    public function needsReEnrollment(): bool
    {
        $expiry = $this->getEnrollmentExpiry();
        if ($expiry === null) {
            return false;
        }

        $now = new \DateTimeImmutable();
        $threshold = $now->getTimestamp() + 86400;

        return $expiry->getTimestamp() < $threshold;
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
                $this->lastHeartbeatAttempt = new \DateTimeImmutable();
                $this->reEnrollIfNeeded();
                $result = $this->sendHeartbeat();
                if ($result->ok) {
                    $this->lastSuccessfulHeartbeat = $this->lastHeartbeatAttempt;
                    $this->consecutiveFailures = 0;
                } else {
                    ++$this->consecutiveFailures;
                    $this->logger->warning('Heartbeat failed', [
                        'error' => $result->error,
                        'error_code' => $result->errorCode,
                        'http_status' => $result->errorCode,
                        'consecutive_failures' => $this->consecutiveFailures,
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
     * Get the HTTP client for hub communication.
     *
     * If not enrolled, returns the client without authentication.
     *
     * @return HttpClientInterface The HTTP client.
     */
    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
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

        // Ensure the HTTP client targets the hub with the enrollment token.
        // The injected $this->httpClient is an empty-base placeholder; the
        // heartbeat LOOP swaps it for an enrollment-scoped client, but a DIRECT
        // call — e.g. the admin "Send heartbeat" button, which runs in the HTTP
        // worker where the loop never ran — would otherwise POST to the
        // base-less relative path below and fail with cURL "No host part in the
        // URL". Rebuild from the freshly-loaded enrollment (also picks up a
        // token renewed by reEnrollIfNeeded()). Only when the client is a real
        // HttpClient — a test-injected HttpClientInterface mock is left as-is.
        if ($this->httpClient instanceof HttpClient) {
            $this->httpClient = new HttpClient($enrollment->hubBaseUrl, $enrollment->enrollmentJwt);
        }

        // Build from the shared HeartbeatDto so the wire payload uses the
        // camelCase keys the hub parses with HeartbeatDto::fromPayload();
        // a snake_case array here is rejected with 400 "Bad Request"
        // ("serverId is required").
        $payload = (new HeartbeatDto(
            serverId: $enrollment->serverId,
            version: $this->serverVersion,
            timestamp: time(),
            uptimeSeconds: time() - $this->processStartTime,
            activeSessions: 0,
            activeTranscodes: 0,
            hostnameCandidates: array_values($this->getHostnameCandidates()),
            libraries: $this->collectLibraries(),
        ))->toPayload();

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
     * Collects this server's libraries for the heartbeat payload (best-effort).
     *
     * The hub caches these in server_libraries so the owner's dashboard can list
     * them. A failure here must never break the heartbeat, so any error degrades
     * to an empty list.
     *
     * @return list<LibraryRef>
     */
    private function collectLibraries(): array
    {
        if ($this->librariesProvider === null) {
            return [];
        }
        try {
            return array_map(
                fn(array $item): LibraryRef => LibraryRef::fromPayload($item),
                ($this->librariesProvider)()
            );
        } catch (Throwable $e) {
            $this->logger->warning('Failed to collect libraries for heartbeat', ['exception' => $e->getMessage()]);
            return [];
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
     * Proactively renews the enrollment JWT before it expires.
     *
     * Called automatically before every heartbeat. The enrollment JWT has a
     * 7-day lifetime; once the enrollment is at least 6 days old (and thus
     * within ~1 day of expiry) this method renews it against the hub's
     * `POST /api/v1/servers/{serverId}/renew` endpoint. The renew call is
     * authenticated by the CURRENT (still-valid) JWT, which the active
     * {@see HttpClient} sends as a Bearer token, so renewal must succeed
     * BEFORE full expiry.
     *
     * If the enrollment is already fully expired the current JWT can no
     * longer authenticate a renew, so a one-time operator re-pair is
     * required; this method only logs a warning in that case.
     *
     * @return bool True if the enrollment was renewed; false otherwise.
     */
    public function reEnrollIfNeeded(): bool
    {
        $enrollment = $this->loadEnrollment();
        if ($enrollment === null) {
            return false;
        }

        // Proactively warn if enrollment is about to expire (within 24h).
        if ($this->needsReEnrollment()) {
            $expiry = $this->getEnrollmentExpiry();
            $this->logger->warning('Enrollment expiring soon; proactive renewal needed', [
                'server_id' => $enrollment->serverId,
                'expires_at' => $expiry?->format('c'),
            ]);
        }

        $age = time() - $enrollment->enrolledAt;

        // Already fully expired: the JWT can no longer authenticate the
        // renew call, so an operator re-pair is required.
        if ($age >= self::ENROLLMENT_TTL) {
            $this->logger->warning('Enrollment expired; re-enrollment required', [
                'server_id' => $enrollment->serverId,
                'enrolled_at' => $enrollment->enrolledAt,
            ]);

            return false;
        }

        // Not yet within the renewal window: nothing to do.
        if ($age < $this->renewalThreshold) {
            return false;
        }

        // Within ~1 day of expiry: renew while the current JWT is still valid.
        try {
            $response = $this->httpClient->post(
                "/api/v1/servers/{$enrollment->serverId}/renew",
                [],
            );

            if (!$response->isSuccess()) {
                $this->logger->warning('Enrollment renewal failed', [
                    'server_id' => $enrollment->serverId,
                    'status' => $response->statusCode,
                    'error_code' => $response->getErrorCode() ?? 'UNKNOWN',
                ]);

                return false;
            }

            $body = $response->body;
            // Mirror the claim flow's enrollment_jwt parsing (snake_case).
            $newJwt = is_string($body['enrollment_jwt'] ?? null) ? $body['enrollment_jwt'] : '';
            if ($newJwt === '') {
                $this->logger->warning('Enrollment renewal response missing enrollment_jwt', [
                    'server_id' => $enrollment->serverId,
                ]);

                return false;
            }

            // Persist the fresh enrollment via the same path storeEnrollment()
            // uses; storeEnrollment() resets enrolled_at to time().
            $this->storeEnrollment(
                $newJwt,
                $enrollment->hubJwksUrl,
                $enrollment->serverId,
                $enrollment->hubBaseUrl,
            );

            // Rebuild the HTTP client with the renewed JWT (mirrors the
            // heartbeat loop's construction in startHeartbeatLoop()).
            $this->httpClient = new HttpClient($enrollment->hubBaseUrl, $newJwt);

            $this->logger->info('Enrollment renewed', [
                'server_id' => $enrollment->serverId,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Enrollment renewal exception', [
                'server_id' => $enrollment->serverId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Returns the current heartbeat status for admin visibility.
     *
     * @return array{
     *     lastHeartbeatAttempt: string|null,
     *     lastSuccessfulHeartbeat: string|null,
     *     consecutiveFailures: int,
     *     enrollmentExpiresAt: string|null,
     *     isEnrolled: bool
     * }
     */
    public function getStatus(): array
    {
        return [
            'lastHeartbeatAttempt' => $this->lastHeartbeatAttempt?->format('c'),
            'lastSuccessfulHeartbeat' => $this->lastSuccessfulHeartbeat?->format('c'),
            'consecutiveFailures' => $this->consecutiveFailures,
            'enrollmentExpiresAt' => $this->getEnrollmentExpiry()?->format('c'),
            'isEnrolled' => $this->loadEnrollment() !== null,
        ];
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

        // The configured public URL (from PHLIX_DOMAIN via config/hub.php) is the
        // most reliable candidate: under the Workerman daemon the $_SERVER vars
        // below are empty, so without this the hub records no reachable hostname.
        if ($this->publicUrl !== '') {
            $candidates[] = $this->publicUrl;
        }

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

        if ($this->portForwardService !== null) {
            $pfCandidates = $this->portForwardService->discoverHostnameCandidates();
            foreach ($pfCandidates as $candidate) {
                $candidates[] = $candidate['url'];
            }
        }

        return array_values(array_unique($candidates));
    }
}
