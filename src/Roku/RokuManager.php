<?php

/**
 * Phlix media server component: Roku.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Roku;

use InvalidArgumentException;
use Phlix\Casting\CastingSessionRecord;
use Phlix\Casting\CastingSessionStoreInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Phlix\Session\PlaybackController;

/**
 * Manages Roku sessions.
 *
 * Provides a facade for discovering devices and managing active
 * "send to Roku" sessions. Maps device IDs to active RokuSession instances.
 *
 * ## Device-M1: cross-worker sessions
 *
 * The map is worker-local and the HTTP pool runs multiple resident workers; a
 * cast started on worker A used to 404 on worker B while A's poll timer kept
 * firing unrechable. With the optional shared store every start registers a
 * row, control ops verify ownership, and a local miss re-attaches by rebuilding
 * the stateless ECP HTTP client from the stored host/port — Roku ECP commands
 * are stateless HTTP POSTs, so re-attach is total: nothing is lost except the
 * old worker's timer, which self-evicts via its next store touch. The stored
 * state is device addressing + the media URL only: the ECP client holds no
 * credentials by design.
 *
 * @since 0.12.0
 */
class RokuManager
{
    /** @var RokuDiscovery Device discovery service */
    private RokuDiscovery $discovery;

    /** @var PlaybackController Phlix playback controller */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var CastingSessionStoreInterface|null Shared cross-worker register (null = worker-local only) */
    private ?CastingSessionStoreInterface $store;

    /** @var array<string, RokuSession> Active sessions keyed by device ID */
    private array $sessions = [];

    /** @var array<string, string> Device ID => owning user id, mirror of the store row */
    private array $owners = [];

    /**
     * @param RokuDiscovery $discovery Device discovery service
     * @param PlaybackController $playbackController Phlix playback controller
     * @param StructuredLogger|null $logger Optional logger instance
     * @param CastingSessionStoreInterface|null $store Optional shared casting-session
     *        register (Device-M1); trailing-optional so existing constructions stay
     *        legal and a store-less manager keeps its exact worker-local semantics
     *
     * @since 0.12.0
     */
    public function __construct(
        RokuDiscovery $discovery,
        PlaybackController $playbackController,
        ?StructuredLogger $logger = null,
        ?CastingSessionStoreInterface $store = null
    ) {
        $this->discovery = $discovery;
        $this->playbackController = $playbackController;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->store = $store;
    }

    /**
     * The shared MEDIA-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a `sys_get_temp_dir()/phlix_roku_manager_<uniqid>`
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
     * Discover Roku devices on the network.
     *
     * @return RokuDevice[] Array of discovered devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(): array
    {
        $this->logger->info('Discovering Roku devices');

        $devices = $this->discovery->discoverDevices();

        $this->logger->info('Discovered {count} Roku devices', [
            'count' => count($devices),
        ]);

        return $devices;
    }

    /**
     * Start a "send to Roku" session for a media item.
     *
     * Creates a new RokuSession, discovers the device, creates ECP client,
     * and launches media playback. An existing session for the device — local
     * or fleet-visible — is displaced (the pre-Device-M1 local REPLACE
     * semantics, now cross-worker).
     *
     * @param string $deviceId Target device ID
     * @param string $mediaUrl Media URL to send
     * @param string $mimeType MIME content type
     * @param string $title Media title for display
     * @param string $thumbnail Thumbnail URL
     * @param string|null $userId Authenticated owner (required once a store is active)
     *
     * @return RokuSession|null New session or null on failure
     *
     * @since 0.12.0
     */
    public function startSession(
        string $deviceId,
        string $mediaUrl,
        string $mimeType,
        string $title,
        string $thumbnail,
        ?string $userId = null
    ): ?RokuSession {
        // Fail-closed identity gate — see PlayToManager for the reasoning.
        if ($this->store !== null && ($userId === null || $userId === '')) {
            $this->logger->error('Roku start refused: no authenticated owner for the shared store', [
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
            $this->logger->error('Device not found', ['device_id' => $deviceId]);
            return null;
        }

        // Stop existing session for this device
        if (isset($this->sessions[$deviceId])) {
            $this->stopSession($deviceId, $userId);
        }

        // Generate session ID
        $sessionId = $this->generateUuid();

        // Create ECP client for this device
        $client = new RokuEcpClient($device->host, $device->port, $this->logger);

        // Create session
        $session = new RokuSession(
            $sessionId,
            $device,
            $client,
            $this->playbackController,
            $this->logger
        );

        // Play media
        try {
            $session->playMedia($mediaUrl, $mimeType, $title, $thumbnail);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to play media on device', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Device-M1: displace any row this device still carries (another
        // worker's live session — its poll notices the replacement via touch)
        // and register; an unwritable row means an invisible-only session, so
        // the start fails closed after stopping what we just launched.
        if ($this->store !== null) {
            $this->store->deleteByDevice(CastingSessionStoreInterface::TYPE_ROKU, $deviceId);

            $registered = $this->store->insert(
                $sessionId,
                CastingSessionStoreInterface::TYPE_ROKU,
                $deviceId,
                (string)$userId,
                [
                    'device' => [
                        'deviceId' => $device->deviceId,
                        'name' => $device->name,
                        'host' => $device->host,
                        'port' => $device->port,
                        'model' => $device->model,
                        'softwareVersion' => $device->softwareVersion,
                    ],
                    'media_url' => $mediaUrl,
                ]
            );

            if (!$registered) {
                $this->logger->error('Failed to register Roku session in shared store', [
                    'session_id' => $sessionId,
                    'device_id' => $deviceId,
                ]);
                $this->stopQuietly($session);
                return null;
            }
        }

        // Store session (with orphan-discipline seams wired).
        $this->wireLocal($deviceId, $session, $userId);

        $this->logger->info('Roku session started', [
            'session_id' => $sessionId,
            'device_id' => $deviceId,
            'media_url' => $mediaUrl,
        ]);

        return $session;
    }

    /**
     * Get the active session for a device.
     *
     * Local map first; re-attach from the shared row on a miss (see class
     * docblock). Foreign-owner, corrupt-row and no-row all answer null — the
     * existing 404 wire, unchanged.
     *
     * @param string $deviceId Device ID
     * @param string|null $userId Requesting authenticated user (ownership-checked
     *        whenever a store is active)
     *
     * @return RokuSession|null Active session or null if none
     *
     * @since 0.12.0
     */
    public function getSession(string $deviceId, ?string $userId = null): ?RokuSession
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
            $session->abandon();
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
                $this->logger->warning('Error stopping session', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Evict + delete the shared row through the self-destruct seam.
            $session->abandon();

            $this->logger->info('Roku session stopped', [
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

        // Cross-worker stop: best-effort device Stop (ECP 'Back') through a
        // rebuilt client, then drop the row; the owning worker self-evicts on
        // its next touch.
        try {
            $this->sessionFromRecord($record)->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('Cross-worker Roku stop: device stop command failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->deleteQuietly($record->sessionId);

        $this->logger->info('Roku session stopped (cross-worker)', [
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Get all active sessions.
     *
     * Worker-local view only, as before (see the class docblock).
     *
     * @return RokuSession[] Array of active sessions
     *
     * @since 0.12.0
     */
    public function getActiveSessions(): array
    {
        return array_values($this->sessions);
    }

    /**
     * Publish a session into the worker-local map with orphan discipline wired.
     */
    private function wireLocal(string $deviceId, RokuSession $session, ?string $userId): void
    {
        $this->sessions[$deviceId] = $session;
        $this->owners[$deviceId] = (string)$userId;

        $session->setSelfDestructHandler(function (RokuSession $dead) use ($deviceId): void {
            if (($this->sessions[$deviceId] ?? null) === $dead) {
                unset($this->sessions[$deviceId], $this->owners[$deviceId]);
            }

            $this->deleteQuietly($dead->getSessionId());
        });

        if ($this->store !== null) {
            $session->setTouchHandler(fn (): bool => $this->touchQuietly($session->getSessionId()));
        }
    }

    /**
     * Re-attach a fleet-visible session onto THIS worker.
     */
    private function reattach(string $deviceId, ?string $userId): ?RokuSession
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
            $this->logger->error('Roku re-attach refused: stored session state unusable', [
                'device_id' => $deviceId,
                'session_id' => $record->sessionId,
                'error' => $e->getMessage(),
            ]);
            $this->deleteQuietly($record->sessionId);
            return null;
        }

        $this->wireLocal($deviceId, $session, $record->userId);
        $session->resumePolling();

        $this->logger->info('Roku session re-attached from shared store', [
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
    private function sessionFromRecord(CastingSessionRecord $record): RokuSession
    {
        $deviceRaw = $record->state['device'] ?? null;
        $mediaUrl = $record->state['media_url'] ?? null;

        if (!is_array($deviceRaw) || !is_string($mediaUrl)) {
            throw new InvalidArgumentException('stored Roku session has no device/media context');
        }

        $deviceId = $deviceRaw['deviceId'] ?? null;
        $host = $deviceRaw['host'] ?? null;
        $port = $deviceRaw['port'] ?? null;

        if (!is_string($deviceId) || $deviceId === '' || !is_string($host) || !is_int($port)) {
            throw new InvalidArgumentException('stored Roku device addressing is incomplete');
        }

        $name = is_string($deviceRaw['name'] ?? null) ? (string)$deviceRaw['name'] : '';
        $model = is_string($deviceRaw['model'] ?? null) ? (string)$deviceRaw['model'] : '';
        $softwareVersion = is_string($deviceRaw['softwareVersion'] ?? null)
            ? (string)$deviceRaw['softwareVersion']
            : '';

        $device = new RokuDevice($deviceId, $name, $host, $port, $model, $softwareVersion);
        $client = new RokuEcpClient($host, $port, $this->logger);

        $session = new RokuSession(
            $record->sessionId,
            $device,
            $client,
            $this->playbackController,
            $this->logger
        );

        $session->restoreMediaContext($mediaUrl);

        return $session;
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
            return $this->store->find(CastingSessionStoreInterface::TYPE_ROKU, $deviceId);
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
    private function stopQuietly(RokuSession $session): void
    {
        try {
            $session->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('Roku rollback stop failed', [
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
        $this->logger->warning('Roku session control refused: requesting user does not own the session', [
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
