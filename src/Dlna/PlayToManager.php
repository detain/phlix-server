<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

use InvalidArgumentException;
use Phlix\Casting\CastingSessionRecord;
use Phlix\Casting\CastingSessionStoreInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Phlix\Session\PlaybackController;
use Throwable;

/**
 * Manages multiple "play to" sessions with DLNA renderers.
 *
 * Maintains a map of renderer ID to active PlayToSession, providing
 * a facade for discovering renderers and managing play-to sessions.
 * Acts as a factory for PlayToSession instances, creating the
 * RendererControlClient and wiring everything together.
 *
 * ## Device-M1: cross-worker sessions
 *
 * The HTTP pool runs multiple resident workers and this map is process-local,
 * so a start landing on worker A used to be invisible to worker B, which
 * answered a spurious 404 while worker A's poll timer kept firing for a
 * session nobody could reach. With an optional {@see CastingSessionStoreInterface}
 * (migration 111) every start registers a shared row, every control op verifies
 * the requesting user owns it, and a local miss RE-ATTACHES by rebuilding the
 * stateless SOAP client from the row's stored AVTransport URL — DLNA was
 * already fully re-attachable, which is exactly why the row stores the endpoint
 * and nothing else. The map stays the hot cache of live objects (timers cannot
 * cross a process boundary); the row is the truth. Without a store the manager
 * behaves byte-identically to its pre-Device-M1 self — the container-less test
 * venue never sets one.
 *
 * @since 0.12.0
 */
class PlayToManager
{
    /** @var RendererDiscovery SSDP renderer discovery service */
    private RendererDiscovery $rendererDiscovery;

    /** @var PlaybackController Phlix playback controller */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var CastingSessionStoreInterface|null Shared cross-worker register (null = worker-local only) */
    private ?CastingSessionStoreInterface $store;

    /** @var array<string, PlayToSession> Active sessions keyed by renderer ID */
    private array $sessions = [];

    /** @var array<string, string> Renderer ID => owning user id, mirror of the store row */
    private array $owners = [];

    /**
     * @param RendererDiscovery $rendererDiscovery SSDP renderer discovery service
     * @param PlaybackController $playbackController Phlix playback controller
     * @param StructuredLogger|null $logger Optional logger instance
     * @param CastingSessionStoreInterface|null $store Optional shared casting-session
     *        register (Device-M1); trailing-optional so existing constructions stay
     *        legal and a store-less manager keeps its exact worker-local semantics
     *
     * @since 0.12.0
     */
    public function __construct(
        RendererDiscovery $rendererDiscovery,
        PlaybackController $playbackController,
        ?StructuredLogger $logger = null,
        ?CastingSessionStoreInterface $store = null
    ) {
        $this->rendererDiscovery = $rendererDiscovery;
        $this->playbackController = $playbackController;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->store = $store;
    }

    /**
     * The shared DLNA-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a
     * `sys_get_temp_dir()/phlix_dlna_play_to_manager_<uniqid>` directory on
     * every construction and pointed a private `StructuredLogger` at a log
     * file inside it — a per-instance leak that survived for the life of the
     * worker. `LoggerFactory::get()` returns one cached instance per channel,
     * so the whole family shares a single logger.
     *
     * @return StructuredLogger The shared DLNA channel logger, routed by
     *         `config/logger.php` to `.logs/app.log` and `.logs/error.log` —
     *         an install-dir destination that creates no directory.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        return LoggerFactory::get(LogChannels::DLNA);
    }

    /**
     * Discover available renderers on the network.
     *
     * @return array<int, array<string, mixed>> Array of renderer descriptors
     *
     * @since 0.12.0
     */
    public function discoverRenderers(): array
    {
        $this->logger->info('Discovering renderers');

        $renderers = $this->rendererDiscovery->discoverRenderers();

        $this->logger->info('Renderer discovery complete', [
            'count' => count($renderers),
        ]);

        return $renderers;
    }

    /**
     * Start a "play to" session for a media item.
     *
     * Creates a new RendererControlClient, sets the media URI on the renderer,
     * and starts playback. If a session already exists for this renderer it is
     * stopped first — REPLACE semantics, now cross-worker-visible: a live
     * session registered by ANOTHER worker (or a previous cast by anyone) is
     * displaced the same way the old local-map stop displaced its own.
     *
     * @param string $rendererId Renderer identifier (UDN)
     * @param string $mediaItemId Media item ID
     * @param string $uri Media URI (HLS stream URL)
     * @param string $metadata DIDL-Lite metadata
     * @param string|null $userId Authenticated owner (required once a store is active —
     *        the wire stays 500-on-null exactly like every other start failure)
     *
     * @return PlayToSession|null New session or null on failure
     *
     * @since 0.12.0
     */
    public function startSession(
        string $rendererId,
        string $mediaItemId,
        string $uri,
        string $metadata = '',
        ?string $userId = null
    ): ?PlayToSession {
        $this->logger->info('Starting play-to session', [
            'renderer_id' => $rendererId,
            'media_item_id' => $mediaItemId,
            'uri' => $uri,
        ]);

        // Fail-closed identity gate: with the shared register active there is no
        // anonymous casting — the row needs an owner and defense-in-depth says
        // the check belongs in the manager, not only in the middleware chain.
        if ($this->store !== null && ($userId === null || $userId === '')) {
            $this->logger->error('Play-to start refused: no authenticated owner for the shared store', [
                'renderer_id' => $rendererId,
            ]);
            return null;
        }

        // Stop existing session if any (local object; its destroy() deletes the row
        // through the self-destruct handler when a store is wired).
        if (isset($this->sessions[$rendererId])) {
            $this->stopSession($rendererId, $userId);
        }

        // Create session ID
        $sessionId = $this->generateUuid();

        // Get renderer info - we need the AVTransport URL
        $renderers = $this->discoverRenderers();
        $rendererInfo = null;
        foreach ($renderers as $renderer) {
            if (($renderer['udn'] ?? '') === $rendererId) {
                $rendererInfo = $renderer;
                break;
            }
        }

        if ($rendererInfo === null) {
            $this->logger->error('Renderer not found', ['renderer_id' => $rendererId]);
            return null;
        }

        $avTransportUrlRaw = $rendererInfo['av_transport_url'] ?? null;
        $avTransportUrl = is_string($avTransportUrlRaw) ? $avTransportUrlRaw : '';
        if ($avTransportUrl === '') {
            $this->logger->error('Renderer has no AVTransport URL', ['renderer_id' => $rendererId]);
            return null;
        }

        // Create the renderer control client
        $client = new RendererControlClient($avTransportUrl, $this->logger);

        // Create the play-to session
        $friendlyNameRaw = $rendererInfo['friendly_name'] ?? null;
        $friendlyName = is_string($friendlyNameRaw) ? $friendlyNameRaw : 'Unknown Renderer';
        $session = new PlayToSession(
            $sessionId,
            $rendererId,
            $friendlyName,
            $client,
            $this->playbackController,
            $this->logger
        );

        // Set media and start playing
        $session->setMediaItem($mediaItemId, $uri, $metadata);
        $session->play();

        // Device-M1: register the session fleet-wide BEFORE publishing it to the
        // map, and displace any row the device still carries from another
        // worker. A row that cannot be written means a session only THIS worker
        // could ever control — the fail-closed answer is to not start it at all.
        if ($this->store !== null) {
            $this->store->deleteByDevice(CastingSessionStoreInterface::TYPE_PLAYTO, $rendererId);

            $registered = $this->store->insert(
                $sessionId,
                CastingSessionStoreInterface::TYPE_PLAYTO,
                $rendererId,
                (string)$userId,
                [
                    'av_transport_url' => $avTransportUrl,
                    'friendly_name' => $friendlyName,
                    'media_item_id' => $mediaItemId,
                    'uri' => $uri,
                ]
            );

            if (!$registered) {
                $this->logger->error('Failed to register play-to session in shared store', [
                    'session_id' => $sessionId,
                    'renderer_id' => $rendererId,
                ]);
                $session->destroy();
                return null;
            }
        }

        // Store the session (and wire its orphan-discipline seams).
        $this->wireLocal($rendererId, $session, $userId);

        $this->logger->info('Play-to session started', [
            'session_id' => $sessionId,
            'renderer_id' => $rendererId,
        ]);

        return $session;
    }

    /**
     * Get the active session for a renderer.
     *
     * Local map first (hot cache: the live object with its poll timer). On a
     * miss with a shared store attached, re-attach from the row: same owner +
     * reconstructible endpoint ⇒ a fresh session object is built here and every
     * subsequent control op rides it normally. A row owned by another user, a
     * corrupt row, or no row at all all answer `null` — the exact 404 wire
     * behaviour of the pre-Device-M1 manager, never a new failure shape.
     *
     * @param string $rendererId Renderer identifier (UDN)
     * @param string|null $userId Requesting authenticated user (ownership-checked
     *        whenever a store is active; ignored in store-less worker-local mode)
     *
     * @return PlayToSession|null Active session or null if none
     *
     * @since 0.12.0
     */
    public function getSession(string $rendererId, ?string $userId = null): ?PlayToSession
    {
        $session = $this->sessions[$rendererId] ?? null;

        if ($session === null) {
            return $this->reattach($rendererId, $userId);
        }

        if ($this->store === null) {
            return $session;
        }

        if (!$this->ownerMatches($rendererId, $userId)) {
            return null;
        }

        if (!$this->touchQuietly($session->getSessionId())) {
            // Evict my stale object first; the row may live under a NEWER
            // session id (another worker replaced it) for the SAME user, in
            // which case the re-attach below serves it transparently.
            $session->abandon();
            return $this->reattach($rendererId, $userId);
        }

        return $session;
    }

    /**
     * Stop and remove a session.
     *
     * @param string $rendererId Renderer identifier (UDN)
     * @param string|null $userId Requesting authenticated user (ownership-checked
     *        whenever a store is active)
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stopSession(string $rendererId, ?string $userId = null): void
    {
        $session = $this->sessions[$rendererId] ?? null;

        if ($session !== null) {
            if ($this->store !== null && !$this->ownerMatches($rendererId, $userId)) {
                return;
            }

            // destroy() sends the device Stop and fires the self-destruct handler,
            // which evicts the map/owner entries and deletes the shared row.
            $session->destroy();

            $this->logger->info('Play-to session stopped', [
                'renderer_id' => $rendererId,
            ]);

            return;
        }

        if ($this->store === null) {
            return;
        }

        $record = $this->findQuietly($rendererId);

        if ($record === null) {
            return;
        }

        if ($record->userId !== (string)$userId) {
            $this->logCrossUserRefusal('stop', $rendererId, $userId);
            return;
        }

        // Cross-worker stop: best-effort device Stop through a rebuilt client so
        // the renderer actually halts now, then drop the row. The worker that
        // owns the live object notices on its next poll touch (bounded by the
        // store's touch window) and self-evicts — never leaving a phantom.
        try {
            $this->sessionFromRecord($record)->stop();
        } catch (Throwable $e) {
            $this->logger->warning('Cross-worker play-to stop: device Stop command failed', [
                'renderer_id' => $rendererId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->deleteQuietly($record->sessionId);

        $this->logger->info('Play-to session stopped (cross-worker)', [
            'renderer_id' => $rendererId,
        ]);
    }

    /**
     * Get all active sessions.
     *
     * Worker-local view only, as before: sessions living in other workers have
     * their own objects with their own timers, and there is nothing useful to
     * say about them from here without pretending to control them.
     *
     * @return PlayToSession[] Array of active sessions
     *
     * @since 0.12.0
     */
    public function getActiveSessions(): array
    {
        return array_values($this->sessions);
    }

    /**
     * Stop all active sessions.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stopAllSessions(): void
    {
        foreach (array_keys($this->sessions) as $rendererId) {
            $this->stopSession($rendererId, $this->owners[$rendererId] ?? null);
        }
    }

    /**
     * Publish a session into the worker-local map with orphan discipline wired.
     *
     * The self-destruct handler is the single eviction point for BOTH the
     * manager-driven stop and the session's own timer-driven teardown — closing
     * the pre-Device-M1 hole where a strike-teardown left a dead object parked
     * in the map forever. The touch handler answers "does my row still exist?"
     * from the poll loop, bounded by the store's own throttle window.
     */
    private function wireLocal(string $rendererId, PlayToSession $session, ?string $userId): void
    {
        $this->sessions[$rendererId] = $session;
        $this->owners[$rendererId] = (string)$userId;

        $session->setSelfDestructHandler(function (PlayToSession $dead) use ($rendererId): void {
            if (($this->sessions[$rendererId] ?? null) === $dead) {
                unset($this->sessions[$rendererId], $this->owners[$rendererId]);
            }

            $this->deleteQuietly($dead->getSessionId());
        });

        if ($this->store !== null) {
            $session->setTouchHandler(fn (): bool => $this->touchQuietly($session->getSessionId()));
        }
    }

    /**
     * Re-attach a fleet-visible session onto THIS worker.
     *
     * Never throws: every failure mode (no store, no identity, no row,
     * foreign row, unparseable row) answers null = the existing 404 wire.
     */
    private function reattach(string $rendererId, ?string $userId): ?PlayToSession
    {
        if ($this->store === null || $userId === null || $userId === '') {
            return null;
        }

        $record = $this->findQuietly($rendererId);

        if ($record === null) {
            return null;
        }

        if ($record->userId !== $userId) {
            $this->logCrossUserRefusal('access', $rendererId, $userId);
            return null;
        }

        try {
            $session = $this->sessionFromRecord($record);
        } catch (Throwable $e) {
            // Deterministically unusable state — a hand-mangled row, a stored URL
            // the LanEndpointGuard now rejects. No worker could ever re-attach
            // this, so collect it and answer the honest 404.
            $this->logger->error('Play-to re-attach refused: stored session state unusable', [
                'renderer_id' => $rendererId,
                'session_id' => $record->sessionId,
                'error' => $e->getMessage(),
            ]);
            $this->deleteQuietly($record->sessionId);
            return null;
        }

        $this->wireLocal($rendererId, $session, $record->userId);
        $session->resumePolling();

        $this->logger->info('Play-to session re-attached from shared store', [
            'session_id' => $record->sessionId,
            'renderer_id' => $rendererId,
        ]);

        return $session;
    }

    /**
     * Rebuild a control session from a stored row (pure parse, no device I/O).
     *
     * @throws InvalidArgumentException when a required state field is missing
     *         or the stored endpoint fails the client's LAN validation
     */
    private function sessionFromRecord(CastingSessionRecord $record): PlayToSession
    {
        $avTransportUrl = $record->state['av_transport_url'] ?? null;
        $friendlyName = $record->state['friendly_name'] ?? null;
        $mediaItemId = $record->state['media_item_id'] ?? null;
        $uri = $record->state['uri'] ?? null;

        if (!is_string($avTransportUrl) || $avTransportUrl === '') {
            throw new InvalidArgumentException('stored session has no av_transport_url');
        }

        if (!is_string($mediaItemId) || !is_string($uri)) {
            throw new InvalidArgumentException('stored session has no media context');
        }

        $client = new RendererControlClient($avTransportUrl, $this->logger);

        $session = new PlayToSession(
            $record->sessionId,
            $record->deviceId,
            is_string($friendlyName) ? $friendlyName : 'Unknown Renderer',
            $client,
            $this->playbackController,
            $this->logger
        );

        $session->restoreMediaContext($mediaItemId, $uri);

        return $session;
    }

    /**
     * Local owner mirror check (defense-in-depth in front of the store touch).
     *
     * The map and the owner mirror are always written together, so a local hit
     * with a mismatching requester is a foreign control attempt that never even
     * needs a database round trip.
     */
    private function ownerMatches(string $rendererId, ?string $userId): bool
    {
        return $userId !== null && $userId !== '' && ($this->owners[$rendererId] ?? null) === $userId;
    }

    /**
     * Store touch with the manager's degradation policy applied.
     *
     * True = alive (or the store blipped and the local, owner-verified session
     * keeps serving — refusing live control of a verified session over a
     * transient DB hiccup would recreate the outage this lane fixes; the next
     * successful poll re-validates). False = row confirmed gone.
     */
    private function touchQuietly(string $sessionId): bool
    {
        if ($this->store === null) {
            return true;
        }

        try {
            return $this->store->touch($sessionId);
        } catch (Throwable $e) {
            $this->logger->warning('Casting store touch failed, serving local session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Store lookup that answers "no session" on DB failure — fail-closed,
     * because a miss only costs the pre-Device-M1 404, never a wrong answer.
     */
    private function findQuietly(string $rendererId): ?CastingSessionRecord
    {
        if ($this->store === null) {
            return null;
        }

        try {
            return $this->store->find(CastingSessionStoreInterface::TYPE_PLAYTO, $rendererId);
        } catch (Throwable $e) {
            $this->logger->warning('Casting store lookup failed', [
                'renderer_id' => $rendererId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Row delete that never throws — the caller's device-side work is already
     * done, and a surviving row is self-correcting (the next touch/poll sees it
     * and the sweep bounds its life).
     */
    private function deleteQuietly(string $sessionId): void
    {
        if ($this->store === null) {
            return;
        }

        try {
            $this->store->delete($sessionId);
        } catch (Throwable $e) {
            $this->logger->warning('Casting store delete failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uniform refusal log for cross-user control attempts (wire stays the 404).
     */
    private function logCrossUserRefusal(string $op, string $rendererId, ?string $userId): void
    {
        $this->logger->warning('Play-to session control refused: requesting user does not own the session', [
            'operation' => $op,
            'renderer_id' => $rendererId,
            'requesting_user_id' => $userId,
        ]);
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string UUID in standard format
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
