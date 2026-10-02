<?php

/**
 * Phlix media server component: AirPlay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\AirPlay;

use InvalidArgumentException;
use Phlix\Casting\CastingSessionRecord;
use Phlix\Casting\CastingSessionStoreInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;

/**
 * Manages AirPlay sessions for streaming audio to AirPlay 2 devices.
 *
 * Coordinates device discovery, session creation, and lifecycle management.
 * Maintains a map of active sessions per device ID.
 *
 * ## Device-M1: cross-worker sessions
 *
 * The audit asked whether RAOP could honestly re-attach: it can. RaopClient
 * opens a FRESH `fsockopen` per command and closes it again, and an
 * AirPlaySession holds no live socket and NO poll timer at all — the session
 * object is pure metadata over a per-call transport. So there is no 409
 * "stale_worker" refusal posture needed here and no orphan-timer vector to
 * police: a second worker rebuilds the identical control surface from
 * host/raopPort and re-issues whatever the request asks for (pause = FLUSH,
 * resume/stop = their own RAOP requests). With the shared store attached,
 * starts register, control ops verify ownership, and local misses re-attach
 * from the row; without one the manager is byte-identical to its pre-Device-M1
 * self.
 *
 * @since 0.12.0
 */
class AirPlayManager
{
    /** @var AirPlayDiscovery Discovery service */
    private AirPlayDiscovery $discovery;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var CastingSessionStoreInterface|null Shared cross-worker register (null = worker-local only) */
    private ?CastingSessionStoreInterface $store;

    /** @var array<string, AirPlaySession> Active sessions by device ID */
    private array $sessions = [];

    /** @var array<string, string> Device ID => owning user id, mirror of the store row */
    private array $owners = [];

    /**
     * @param AirPlayDiscovery  $discovery  Discovery service
     * @param StructuredLogger|null $logger Optional logger
     * @param CastingSessionStoreInterface|null $store Optional shared casting-session
     *        register (Device-M1); trailing-optional so existing constructions stay
     *        legal and a store-less manager keeps its exact worker-local semantics
     */
    public function __construct(
        AirPlayDiscovery $discovery,
        ?StructuredLogger $logger = null,
        ?CastingSessionStoreInterface $store = null,
    ) {
        $this->discovery = $discovery;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->store = $store;
    }

    /**
     * The shared MEDIA-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a `sys_get_temp_dir()/phlix_airplay_manager_<uniqid>`
     * directory on every construction and pointed a private `StructuredLogger`
     * at a log file inside it — a per-instance leak that survived for the life
     * of the worker. `LoggerFactory::get()` returns one cached instance per
     * channel, so the whole family shares a single logger.
     *
     * @return StructuredLogger The shared MEDIA channel logger, routed by
     *         `config/logger.php` to `.logs/app.log` and `.logs/error.log` —
     *         an install-dir destination that creates no directory.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        return LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Discover AirPlay devices on the network.
     *
     * @return AirPlayDevice[] Array of discovered devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(): array
    {
        $this->logger->debug('AirPlayManager: Discovering devices');

        $devices = $this->discovery->discoverDevices();

        $this->logger->info('AirPlayManager: Discovered devices', [
            'count' => count($devices),
        ]);

        return $devices;
    }

    /**
     * Start an AirPlay session for audio streaming.
     *
     * Creates a new session and starts streaming to the device. An existing
     * session for the device — local or fleet-visible — is stopped first (the
     * pre-Device-M1 REPLACE semantics, now cross-worker-visible).
     *
     * NOTE: like its pre-Device-M1 self this method does NOT wrap
     * `startStream()` — a RAOP transport throw still propagates to the
     * controller (behaviour preserved deliberately; changing it is out of
     * scope here).
     *
     * @param string $deviceId    Target device ID
     * @param string $audioUrl   Audio stream URL
     * @param string $contentType MIME type (default: 'audio/mp4')
     * @param int    $duration   Content duration in seconds (0 if unknown)
     * @param string|null $userId Authenticated owner (required once a store is active)
     *
     * @return AirPlaySession|null New session, or null if device not found
     *
     * @since 0.12.0
     */
    public function startSession(
        string $deviceId,
        string $audioUrl,
        string $contentType = 'audio/mp4',
        int $duration = 0,
        ?string $userId = null,
    ): ?AirPlaySession {
        // Fail-closed identity gate — see PlayToManager for the reasoning.
        if ($this->store !== null && ($userId === null || $userId === '')) {
            $this->logger->error('AirPlay start refused: no authenticated owner for the shared store', [
                'device_id' => $deviceId,
            ]);
            return null;
        }

        // Find the device
        $devices = $this->discovery->discoverDevices();
        $device = null;
        foreach ($devices as $d) {
            if ($d->deviceId === $deviceId) {
                $device = $d;
                break;
            }
        }

        if ($device === null) {
            $this->logger->warning('AirPlayManager: Device not found', [
                'device_id' => $deviceId,
            ]);
            return null;
        }

        // Check for existing session
        if (isset($this->sessions[$deviceId])) {
            $this->logger->info('AirPlayManager: Stopping existing session', [
                'device_id' => $deviceId,
            ]);
            $this->stopSession($deviceId, $userId);
        }

        // Generate session ID
        $sessionId = $this->generateUuid();

        // Create RAOP client and session
        $raopClient = new RaopClient($device->host, $device->raopPort, $this->logger);
        $session = new AirPlaySession(
            $sessionId,
            $device,
            $raopClient,
            $this->logger,
        );

        // Start streaming
        $session->startStream($audioUrl, $contentType, $duration);

        // Device-M1: displace + register (fail-closed on an unwritable row —
        // roll the device back first so a failed start never leaves audio
        // streaming with nobody registered to control it).
        if ($this->store !== null) {
            $this->store->deleteByDevice(CastingSessionStoreInterface::TYPE_AIRPLAY, $deviceId);

            $registered = $this->store->insert(
                $sessionId,
                CastingSessionStoreInterface::TYPE_AIRPLAY,
                $deviceId,
                (string)$userId,
                [
                    'device' => [
                        'deviceId' => $device->deviceId,
                        'name' => $device->name,
                        'host' => $device->host,
                        'port' => $device->port,
                        'raopPort' => $device->raopPort,
                        'model' => $device->model,
                        'supportsVideo' => $device->supportsVideo,
                    ],
                    'media_url' => $audioUrl,
                    'content_type' => $contentType,
                ]
            );

            if (!$registered) {
                $this->logger->error('Failed to register AirPlay session in shared store', [
                    'session_id' => $sessionId,
                    'device_id' => $deviceId,
                ]);
                $this->stopQuietly($session);
                return null;
            }
        }

        // Store session
        $this->sessions[$deviceId] = $session;
        $this->owners[$deviceId] = (string)$userId;

        $this->logger->info('AirPlayManager: Session started', [
            'session_id' => $sessionId,
            'device_id' => $deviceId,
        ]);

        return $session;
    }

    /**
     * Get the active session for a device.
     *
     * Local map first; re-attach from the shared row on a miss. AirPlay objects
     * carry no timer, so the store touch here doubles as the row's only
     * liveness stamp between control ops — and a gone row simply means the
     * next re-attach reads whatever row (if any) the device now has.
     *
     * @param string $deviceId Device ID
     * @param string|null $userId Requesting authenticated user (ownership-checked
     *        whenever a store is active)
     *
     * @return AirPlaySession|null Active session or null if none
     *
     * @since 0.12.0
     */
    public function getSession(string $deviceId, ?string $userId = null): ?AirPlaySession
    {
        $session = $this->sessions[$deviceId] ?? null;

        if ($session === null) {
            return $this->reattach($deviceId, $userId);
        }

        if ($this->store === null) {
            return $session;
        }

        if (!$this->ownerMatches($deviceId, $userId)) {
            return null;
        }

        if (!$this->touchQuietly($session->getSessionId())) {
            // Row replaced or swept: evict the local metadata object (no device
            // command — this path must never interrupt live streaming) and
            // consult the current row.
            $this->evictLocal($deviceId, $session);
            return $this->reattach($deviceId, $userId);
        }

        return $session;
    }

    /**
     * Stop and remove a session.
     *
     * @param string $deviceId Device ID
     * @param string|null $userId Requesting authenticated user (ownership-checked
     *        whenever a store is active)
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stopSession(string $deviceId, ?string $userId = null): void
    {
        $session = $this->sessions[$deviceId] ?? null;

        if ($session !== null) {
            if ($this->store !== null && !$this->ownerMatches($deviceId, $userId)) {
                return;
            }

            try {
                $session->stop();
            } catch (\Throwable $e) {
                $this->logger->warning('AirPlayManager: Error stopping session', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->evictLocal($deviceId, $session);

            $this->logger->info('AirPlayManager: Session stopped', [
                'device_id' => $deviceId,
            ]);

            return;
        }

        if ($this->store === null) {
            return;
        }

        $record = $this->findQuietly($deviceId);

        if ($record === null) {
            return;
        }

        if ($record->userId !== (string)$userId) {
            $this->logCrossUserRefusal('stop', $deviceId, $userId);
            return;
        }

        // Cross-worker stop: best-effort device stop (FLUSH + teardown sequence)
        // through a rebuilt client, then drop the row.
        try {
            $this->sessionFromRecord($record)->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('Cross-worker AirPlay stop: device stop command failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->deleteQuietly($record->sessionId);

        $this->logger->info('AirPlayManager: Session stopped (cross-worker)', [
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Get all active sessions.
     *
     * Worker-local view only, as before: sessions living in other workers are
     * visible to any control request through the shared store anyway.
     *
     * @return array<string, AirPlaySession> Active sessions keyed by device ID
     *
     * @since 0.12.0
     */
    public function getActiveSessions(): array
    {
        return $this->sessions;
    }

    /**
     * Re-attach a fleet-visible session onto THIS worker.
     */
    private function reattach(string $deviceId, ?string $userId): ?AirPlaySession
    {
        if ($this->store === null || $userId === null || $userId === '') {
            return null;
        }

        $record = $this->findQuietly($deviceId);

        if ($record === null) {
            return null;
        }

        if ($record->userId !== $userId) {
            $this->logCrossUserRefusal('access', $deviceId, $userId);
            return null;
        }

        try {
            $session = $this->sessionFromRecord($record);
        } catch (\Throwable $e) {
            $this->logger->error('AirPlay re-attach refused: stored session state unusable', [
                'device_id' => $deviceId,
                'session_id' => $record->sessionId,
                'error' => $e->getMessage(),
            ]);
            $this->deleteQuietly($record->sessionId);
            return null;
        }

        $this->sessions[$deviceId] = $session;
        $this->owners[$deviceId] = $record->userId;

        $this->logger->info('AirPlayManager: Session re-attached from shared store', [
            'session_id' => $record->sessionId,
            'device_id' => $deviceId,
        ]);

        return $session;
    }

    /**
     * Rebuild a control session from a stored row (pure parse, no device I/O).
     *
     * @throws InvalidArgumentException when a required state field is missing
     */
    private function sessionFromRecord(CastingSessionRecord $record): AirPlaySession
    {
        $deviceRaw = $record->state['device'] ?? null;
        $mediaUrl = $record->state['media_url'] ?? null;
        $contentType = $record->state['content_type'] ?? null;

        if (!is_array($deviceRaw) || !is_string($mediaUrl) || !is_string($contentType)) {
            throw new InvalidArgumentException('stored AirPlay session has no device/stream context');
        }

        $deviceId = $deviceRaw['deviceId'] ?? null;
        $host = $deviceRaw['host'] ?? null;
        $port = $deviceRaw['port'] ?? null;
        $raopPort = $deviceRaw['raopPort'] ?? null;

        if (
            !is_string($deviceId) || $deviceId === ''
            || !is_string($host) || $host === ''
            || !is_int($port) || !is_int($raopPort)
        ) {
            throw new InvalidArgumentException('stored AirPlay device addressing is incomplete');
        }

        $name = is_string($deviceRaw['name'] ?? null) ? (string)$deviceRaw['name'] : '';
        $model = is_string($deviceRaw['model'] ?? null) ? (string)$deviceRaw['model'] : '';
        $supportsVideo = (bool)($deviceRaw['supportsVideo'] ?? false);

        $device = new AirPlayDevice($deviceId, $name, $host, $port, $raopPort, $model, $supportsVideo);
        $raopClient = new RaopClient($host, $raopPort, $this->logger);

        $session = new AirPlaySession(
            $record->sessionId,
            $device,
            $raopClient,
            $this->logger,
        );

        $session->restoreStreamContext($mediaUrl, $contentType);

        return $session;
    }

    /**
     * Drop the local map/owner entries for a session (identity-guarded).
     */
    private function evictLocal(string $deviceId, AirPlaySession $session): void
    {
        if (($this->sessions[$deviceId] ?? null) === $session) {
            unset($this->sessions[$deviceId], $this->owners[$deviceId]);
        }

        $this->deleteQuietly($session->getSessionId());
    }

    /**
     * Local owner mirror check (defense-in-depth in front of the store touch).
     */
    private function ownerMatches(string $deviceId, ?string $userId): bool
    {
        return $userId !== null && $userId !== '' && ($this->owners[$deviceId] ?? null) === $userId;
    }

    /**
     * Store touch with the manager's degradation policy (see PlayToManager).
     */
    private function touchQuietly(string $sessionId): bool
    {
        if ($this->store === null) {
            return true;
        }

        try {
            return $this->store->touch($sessionId);
        } catch (\Throwable $e) {
            $this->logger->warning('Casting store touch failed, serving local session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Store lookup answering "no session" on DB failure (fail-closed = 404).
     */
    private function findQuietly(string $deviceId): ?CastingSessionRecord
    {
        if ($this->store === null) {
            return null;
        }

        try {
            return $this->store->find(CastingSessionStoreInterface::TYPE_AIRPLAY, $deviceId);
        } catch (\Throwable $e) {
            $this->logger->warning('Casting store lookup failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Row delete that never throws (see PlayToManager).
     */
    private function deleteQuietly(string $sessionId): void
    {
        if ($this->store === null) {
            return;
        }

        try {
            $this->store->delete($sessionId);
        } catch (\Throwable $e) {
            $this->logger->warning('Casting store delete failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort device stop used on the registration-failure rollback.
     */
    private function stopQuietly(AirPlaySession $session): void
    {
        try {
            $session->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('AirPlay rollback stop failed', [
                'session_id' => $session->getSessionId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uniform refusal log for cross-user control attempts (wire stays the 404).
     */
    private function logCrossUserRefusal(string $op, string $deviceId, ?string $userId): void
    {
        $this->logger->warning('AirPlay session control refused: requesting user does not own the session', [
            'operation' => $op,
            'device_id' => $deviceId,
            'requesting_user_id' => $userId,
        ]);
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string UUID
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
