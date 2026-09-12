<?php

/**
 * Phlix media server component: SyncPlay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Session\SyncPlay;

/**
 * GroupState - Manages state for a SyncPlay group
 *
 * This class encapsulates all state for a single SyncPlay watching group,
 * including member management, playback state, queue management, and chat.
 *
 * ## Playback States
 *
 * - STATE_PLAYING: Media is actively playing
 * - STATE_PAUSED: Media is paused
 * - STATE_BUFFERING: Media is buffering (waiting for data)
 * - STATE_STOPPED: No media is loaded or playback is stopped
 *
 * ## Host Election
 *
 * When the host leaves the group, a new host is automatically elected
 * based on the oldest member (longest time in the group). This ensures
 * continuity even when the original host disconnects.
 *
 * ## Position Tolerance
 *
 * Position tolerance (default 2000ms) is used to determine if a member's
 * playback position is "in sync" with the group. Members outside this
 * tolerance may need to seek to catch up.
 *
 * ## S446 — Member Sync Policy: NUDGE, never force-seek
 *
 * The group records each member's LAST SELF-REPORTED playback position in
 * milliseconds ({@see recordMemberPosition()}), fed by the live
 * playback_sync ingest in SyncPlayManager::handlePlaybackSync(). On that
 * report the server evaluates {@see isMemberInSync()} — the predicate S291
 * removed as unreachably dead and whose liveness this storage makes
 * legitimate — and the DECIDED out-of-sync reaction is a NUDGE: one soft,
 * corrective playback_sync directive (drift + rate guidance keys) aimed at
 * the drifting member only. Per the owner ruling (2026-09-12) the server
 * NEVER issues a hostile seek in response to a member's own report: a
 * force-seek is hostile UX and seek frames stay reserved for host commands.
 *
 * Thresholds are DERIVED, never invented:
 * - sync window: {@see POSITION_TOLERANCE} (2000ms), the constant already
 *   documented for exactly this purpose;
 * - nudge cooldown: {@see NUDGE_COOLDOWN_MS} (1000ms) — the sync
 *   subsystem's own round-trip bound, TimeSync::MAX_ACCEPTABLE_RTT, so a
 *   second directive is never emitted before the member could plausibly
 *   have acted on the first;
 * - report staleness: {@see MEMBER_POSITION_STALENESS_MS} (300s) — the
 *   estate's existing stale-connection budget (config server.php
 *   'stale_connection_timeout' => 300, mirrored by the WS worker's 300s
 *   SyncPlay group-cleanup tick);
 * - guidance rate step: {@see NUDGE_RATE_STEP} (0.1) — the same 0.1 factor
 *   TimeSync already uses for drift correction (DRIFT_CORRECTION_FACTOR).
 *
 * Position storage is LIVE-ONLY worker state (mirrored in nothing): it is
 * absent from getState(), serialize()/deserialize() and the bridge merge,
 * so a write-through mirror frame (S445) can replace membership/host facets
 * but can neither fabricate nor clobber a member's reported position. Only
 * the wholesale paths that replace the GroupState object itself (adopt of
 * an unseen group, bridge delete) reset it — legitimately, because the live
 * reports died with the object.
 *
 * @author Phlix Development Team
 * @copyright 2024 Phlix Media Server
 * @license Proprietary
 *
 * @see SyncPlayManager For group lifecycle management
 * @see TimeSync For time synchronization
 */
class GroupState
{
    /**
     * Playback state: Media is actively playing.
     */
    public const STATE_PLAYING = 'playing';

    /**
     * Playback state: Media is paused.
     */
    public const STATE_PAUSED = 'paused';

    /**
     * Playback state: Media is buffering.
     */
    public const STATE_BUFFERING = 'buffering';

    /**
     * Playback state: No media loaded or playback stopped.
     */
    public const STATE_STOPPED = 'stopped';

    /**
     * Maximum number of members allowed per group.
     */
    public const MAX_MEMBERS = 50;

    /**
     * Default playback position tolerance in milliseconds.
     *
     * Members whose position differs from host by more than this
     * are considered "out of sync".
     */
    public const POSITION_TOLERANCE = 2000;

    /**
     * S446 — sentinel proving this class is the code home of the decided
     * out-of-sync policy (NUDGE over force-seek, owner ruling 2026-09-12).
     * The companion test-home sentinel lives in
     * tests/Unit/Session/SyncPlay/SyncPlayMemberSyncNudgeTest.php; the two
     * together are the token's only code homes.
     */
    public const SYNC_NUDGE_POLICY_ID = 'S446NUDGEPOIX9Q4';

    /**
     * S446 — minimum spacing between two corrective nudges to the SAME
     * member, in milliseconds.
     *
     * DERIVED, not invented: equal to TimeSync::MAX_ACCEPTABLE_RTT (1000ms)
     * — the sync subsystem's own bound for one full round trip. Re-issuing
     * a directive faster than the member could possibly have acknowledged
     * the previous one would be spam, so one round-trip envelope is the
     * natural rate floor.
     */
    public const NUDGE_COOLDOWN_MS = 1000;

    /**
     * S446 — age past which a member's stored position is no longer
     * actionable, in milliseconds.
     *
     * DERIVED, not invented: equal to the estate's existing stale-connection
     * budget — config/server.php 'websocket.stale_connection_timeout' => 300
     * seconds, the same figure the WS worker's SyncPlay group-cleanup tick
     * runs on. A position older than that belongs to a member the server
     * would already treat as disconnected, so it must never trigger a nudge.
     */
    public const MEMBER_POSITION_STALENESS_MS = 300_000;

    /**
     * S446 — soft speed-up / slow-down delta the nudge recommends, as a
     * fraction of normal rate (1 + 0.1 / 1 - 0.1).
     *
     * DERIVED, not invented: 0.1 is the correction factor the SyncPlay time
     * authority already applies to clock drift (TimeSync::DRIFT_CORRECTION_FACTOR).
     * It is guidance only — the client may ignore it; it is not a seek.
     */
    public const NUDGE_RATE_STEP = 0.1;

    /** @var string Unique group identifier (format: sp_*) */
    private string $id;

    /** @var string Display name of the group */
    private string $name;

    /** @var string|null SHA256 hash of the group password, null if no password */
    private ?string $passwordHash = null;

    /** @var array<string, array{name: string, connection_id: string|null, joined_at: int, is_active: bool, is_host?: bool}> Group members indexed by member ID */
    private array $members = [];

    /** @var string|null The member ID of the current host, null if no host */
    private ?string $hostId = null;

    /** @var string|null The current media item ID being played */
    private ?string $currentMediaId = null;

    /** @var int Duration of the current media in milliseconds */
    private int $currentMediaDuration = 0;

    /** @var int Current playback position in milliseconds */
    private int $playbackPosition = 0;

    /** @var string Current playback state (one of STATE_*) */
    private string $playbackState = self::STATE_STOPPED;

    /** @var array<int, array{media_id: string, media_info: array<string, mixed>, added_at: int, added_by: string|null}> Playback queue items */
    private array $playbackQueue = [];

    /** @var array<int, array{member_id: string, message: string, timestamp: int}> Chat messages (max 100 stored) */
    private array $chatMessages = [];

    /** @var int Unix timestamp when the group was created */
    private int $createdAt;

    /** @var int Unix timestamp of the last group activity */
    private int $lastActivityAt;

    /** @var int Position tolerance in milliseconds for sync detection */
    private int $positionTolerance;

    /**
     * S446 — last self-reported playback position per member, LIVE-ONLY.
     *
     * Keyed by member ID; `position` is milliseconds (frames have carried ms
     * since S417), `at_ms` is the wall-clock millisecond stamp the report was
     * ingested at. Never serialized, never mirrored over the S445 bridge,
     * never part of getState() — see the class policy docblock.
     *
     * @var array<string, array{position: int, at_ms: int}>
     */
    private array $memberPositions = [];

    /**
     * S446 — wall-clock millisecond stamp of the last nudge slot claimed per
     * member ({@see claimNudgeSlot()}). LIVE-ONLY like {@see $memberPositions}.
     *
     * @var array<string, int>
     */
    private array $lastNudgeAtMs = [];

    public function __construct(
        string $id,
        string $name,
        ?string $passwordHash = null,
        int $positionTolerance = self::POSITION_TOLERANCE
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->passwordHash = $passwordHash;
        $this->positionTolerance = $positionTolerance;
        $this->createdAt = time();
        $this->lastActivityAt = time();
    }

    /**
     * Get the unique group identifier.
     *
     * @return string Group ID (format: sp_*)
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the group's display name.
     *
     * @return string The group name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Check if the group is password protected.
     *
     * @return bool True if a password is required to join
     */
    public function hasPassword(): bool
    {
        return $this->passwordHash !== null;
    }

    /**
     * Verify a password against the group's password.
     *
     * If the group has no password, this always returns true.
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $password The password to verify
     * @return bool True if password is correct or no password required
     */
    public function verifyPassword(string $password): bool
    {
        if ($this->passwordHash === null) {
            return true;
        }

        return hash_equals($this->passwordHash, hash('sha256', $password));
    }

    /**
     * Get all members of the group.
     *
     * @return array<string, array{name: string, connection_id: string|null, joined_at: int, is_active: bool,
     *     is_host?: bool}> Members indexed by ID
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * Get the number of members in the group.
     *
     * @return int Member count
     */
    public function getMemberCount(): int
    {
        return count($this->members);
    }

    /**
     * Check if a member exists in the group.
     *
     * @param string $memberId The member ID to check
     * @return bool True if member exists
     */
    public function hasMember(string $memberId): bool
    {
        return isset($this->members[$memberId]);
    }

    /**
     * Get a specific member's data.
     *
     * @param string $memberId The member ID to retrieve
     * @return array{name: string, connection_id: string|null, joined_at: int, is_active: bool, is_host?: bool}|null
     * Member data array or null if not found
     */
    public function getMember(string $memberId): ?array
    {
        return $this->members[$memberId] ?? null;
    }

    /**
     * Add a member to the group.
     *
     * @param string $memberId Unique identifier for the member
     * @param array{name?: string, connection_id?: string|null} $memberData Member data including name and optional
     * connection_id
     * @return bool True if added successfully, false if at capacity or duplicate
     */
    public function addMember(string $memberId, array $memberData): bool
    {
        if (count($this->members) >= self::MAX_MEMBERS) {
            return false;
        }

        if (isset($this->members[$memberId])) {
            return false;
        }

        $this->members[$memberId] = [
            'name' => $memberData['name'] ?? 'Unknown',
            'connection_id' => $memberData['connection_id'] ?? null,
            'joined_at' => time(),
            'is_active' => true,
        ];

        $this->lastActivityAt = time();

        return true;
    }

    /**
     * Remove a member from the group.
     *
     * If the removed member was the host, a new host will be automatically
     * elected from the remaining members.
     *
     * @param string $memberId The member ID to remove
     * @return bool True if removed, false if member not found
     */
    public function removeMember(string $memberId): bool
    {
        if (!isset($this->members[$memberId])) {
            return false;
        }

        unset($this->members[$memberId]);
        // S446 — a departed member's live position and nudge stamp die with it;
        // stale entries must never be handed to a re-joining identity.
        unset($this->memberPositions[$memberId], $this->lastNudgeAtMs[$memberId]);

        // If host left, elect new host
        if ($this->hostId === $memberId) {
            $this->electNewHost();
        }

        $this->lastActivityAt = time();

        return true;
    }

    /**
     * Update a member's data.
     *
     * Only the fields known to the member shape are accepted; unknown keys are
     * ignored to preserve the typed structure.
     *
     * @param string $memberId The member ID to update
     * @param array<string, mixed> $updates Key-value pairs to update
     * @return bool True if updated, false if member not found
     */
    public function updateMember(string $memberId, array $updates): bool
    {
        if (!isset($this->members[$memberId])) {
            return false;
        }

        $current = $this->members[$memberId];

        if (array_key_exists('name', $updates) && is_string($updates['name'])) {
            $current['name'] = $updates['name'];
        }
        if (array_key_exists('connection_id', $updates)) {
            $rawConn = $updates['connection_id'];
            $current['connection_id'] = is_string($rawConn) ? $rawConn : null;
        }
        if (array_key_exists('joined_at', $updates) && is_int($updates['joined_at'])) {
            $current['joined_at'] = $updates['joined_at'];
        }
        if (array_key_exists('is_active', $updates) && is_bool($updates['is_active'])) {
            $current['is_active'] = $updates['is_active'];
        }
        if (array_key_exists('is_host', $updates) && is_bool($updates['is_host'])) {
            $current['is_host'] = $updates['is_host'];
        }

        $this->members[$memberId] = $current;
        $this->lastActivityAt = time();

        return true;
    }

    /**
     * Get the current host's member ID.
     *
     * @return string|null The host's member ID, or null if no host
     */
    public function getHostId(): ?string
    {
        return $this->hostId;
    }

    /**
     * Set a member as the group host.
     *
     * @param string $hostId The member ID to set as host
     * @return bool True if set successfully, false if member not found
     */
    public function setHost(string $hostId): bool
    {
        if (!isset($this->members[$hostId])) {
            return false;
        }

        $this->hostId = $hostId;
        $this->members[$hostId]['is_host'] = true;

        $this->lastActivityAt = time();

        return true;
    }

    /**
     * Elect a new host when the current host leaves.
     *
     * The new host is selected based on the oldest member (earliest joined).
     * If no members remain, returns null.
     *
     * @return string|null The new host's member ID, or null if group is empty
     */
    public function electNewHost(): ?string
    {
        if (empty($this->members)) {
            $this->hostId = null;
            return null;
        }

        // Get the oldest member as fallback
        $oldestMember = null;
        $oldestTime = PHP_INT_MAX;

        foreach ($this->members as $id => $member) {
            $joinedAt = $member['joined_at'] ?? 0;
            if ($joinedAt < $oldestTime) {
                $oldestTime = $joinedAt;
                $oldestMember = $id;
            }
        }

        if ($this->hostId !== null && isset($this->members[$this->hostId])) {
            $this->members[$this->hostId]['is_host'] = false;
        }

        $this->hostId = $oldestMember;

        if ($oldestMember !== null) {
            $this->members[$oldestMember]['is_host'] = true;
        }

        return $this->hostId;
    }

    /**
     * Check if a member is the group host.
     *
     * @param string $memberId The member ID to check
     * @return bool True if the member is the host
     */
    public function isHost(string $memberId): bool
    {
        return $this->hostId === $memberId;
    }

    /**
     * Get the current media item ID.
     *
     * @return string|null The media ID or null if no media is loaded
     */
    public function getCurrentMediaId(): ?string
    {
        return $this->currentMediaId;
    }

    /**
     * Get the duration of the current media.
     *
     * @return int Duration in milliseconds
     */
    public function getCurrentMediaDuration(): int
    {
        return $this->currentMediaDuration;
    }

    /**
     * Get the current playback position.
     *
     * @return int Position in milliseconds
     */
    public function getPlaybackPosition(): int
    {
        return $this->playbackPosition;
    }

    /**
     * Get the current playback state.
     *
     * @return string One of STATE_PLAYING, STATE_PAUSED, STATE_BUFFERING, STATE_STOPPED
     */
    public function getPlaybackState(): string
    {
        return $this->playbackState;
    }

    /**
     * Check if media is currently playing.
     *
     * @return bool True if playback state is STATE_PLAYING
     */
    public function isPlaying(): bool
    {
        return $this->playbackState === self::STATE_PLAYING;
    }

    /**
     * Set the current media item to play.
     *
     * @param string|null $mediaId The media item ID, or null to clear
     * @param int $duration Duration in milliseconds (default: 0)
     * @return void
     */
    public function setCurrentMedia(?string $mediaId, int $duration = 0): void
    {
        $this->currentMediaId = $mediaId;
        $this->currentMediaDuration = $duration;
        $this->playbackPosition = 0;
        $this->playbackState = self::STATE_STOPPED;
        // S446 — reported positions are meaningless across a media change
        // (they measure a different timeline); drop them with the position
        // they were relative to. Fresh reports re-populate on the next tick.
        $this->memberPositions = [];
        $this->lastNudgeAtMs = [];
        $this->lastActivityAt = time();
    }

    /**
     * Update the playback state and position.
     *
     * @param string $state One of STATE_PLAYING, STATE_PAUSED, STATE_BUFFERING, STATE_STOPPED
     * @param int $position Current position in milliseconds
     * @return void
     */
    public function updatePlayback(string $state, int $position): void
    {
        $this->playbackState = $state;
        $this->playbackPosition = $position;
        $this->lastActivityAt = time();
    }

    /**
     * Set the playback position without changing state.
     *
     * Used during synchronization when receiving seek commands.
     *
     * @param int $position New position in milliseconds
     * @return void
     */
    public function setPlaybackPosition(int $position): void
    {
        $this->playbackPosition = $position;
        $this->lastActivityAt = time();
    }

    // -----------------------------------------------------------------
    // S446 — per-member reported position storage + the live sync predicate
    // -----------------------------------------------------------------

    /**
     * Record a member's self-reported playback position (LIVE-ONLY state).
     *
     * Fed by the playback_sync ingest in SyncPlayManager. Only existing,
     * active members can hold a position; a report for an unknown member is
     * rejected with false rather than silently fabricating live state — the
     * caller has already resolved server-derived identity, so false means a
     * race (member left concurrently) and the report is simply dropped.
     *
     * @param string $memberId  Server-derived member identity
     * @param int    $positionMs Reported playback position in milliseconds
     * @param int    $nowMs      Wall-clock milliseconds of ingestion (caller-supplied
     *                           so the predicate is a pure function of injected time)
     * @return bool True when stored, false for a non-member
     */
    public function recordMemberPosition(string $memberId, int $positionMs, int $nowMs): bool
    {
        if (!isset($this->members[$memberId])) {
            return false;
        }

        $this->memberPositions[$memberId] = [
            'position' => $positionMs,
            'at_ms' => $nowMs,
        ];

        return true;
    }

    /**
     * Read back a member's stored live position, if any.
     *
     * @return array{position: int, at_ms: int}|null Null when the member never reported
     */
    public function getMemberPosition(string $memberId): ?array
    {
        return $this->memberPositions[$memberId] ?? null;
    }

    /**
     * The sync predicate S291 removed as unreachable dead code — revived with
     * the liveness that removal was waiting for: per-member position storage
     * ({@see recordMemberPosition()}) and a decided out-of-sync reaction
     * (NUDGE, see the class policy docblock and SyncPlayManager::
     * handlePlaybackSync()).
     *
     * Semantics are S291's verbatim: a group that is not actively PLAYING is
     * trivially in sync (drift does not accumulate against a stopped clock),
     * and any candidate position within the group's position tolerance of the
     * authoritative playback position is in sync.
     *
     * @param int $memberPosition A candidate member position in milliseconds
     * @return bool True when in sync (or when there is nothing to sync to)
     */
    public function isInSync(int $memberPosition): bool
    {
        if ($this->playbackState !== self::STATE_PLAYING) {
            return true;
        }

        return abs($memberPosition - $this->playbackPosition) <= $this->positionTolerance;
    }

    /**
     * Predicate over the member's STORED live position — the consumption
     * site the policy docblock exists for.
     *
     * Unknown (never reported) or too-old-to-act-on (past
     * {@see MEMBER_POSITION_STALENESS_MS}) positions answer "in sync":
     * the server never nudges on absence of evidence, only on evidence of
     * drift. A rewound clock likewise cannot manufacture extra nudges —
     * staleness ages only forward.
     *
     * @param string $memberId Server-derived member identity
     * @param int    $nowMs    Wall-clock milliseconds (injected for determinism)
     * @return bool True when the member must NOT be nudged
     */
    public function isMemberInSync(string $memberId, int $nowMs): bool
    {
        $stored = $this->memberPositions[$memberId] ?? null;
        if ($stored === null) {
            return true;
        }

        if (($nowMs - $stored['at_ms']) > self::MEMBER_POSITION_STALENESS_MS) {
            return true;
        }

        return $this->isInSync($stored['position']);
    }

    /**
     * Atomically claim this member's nudge slot if the cooldown has elapsed.
     *
     * Check-and-set in one call, so the same ingest tick can never emit two
     * directives and back-to-back reports inside the cooldown window can
     * never emit more than one. Callers MUST evaluate isMemberInSync() first
     * and only claim in order to actually send: a claim burns the slot even
     * if the send afterwards fails, which is deliberate — the slot rate-limits
     * the reaction channel, not a successful write.
     *
     * Boundary: exactly NUDGE_COOLDOWN_MS after the previous claim the slot
     * is available again (>= semantics).
     *
     * @param string $memberId Server-derived member identity
     * @param int    $nowMs    Wall-clock milliseconds (injected for determinism)
     * @return bool True when the caller owns the slot and may send one nudge
     */
    public function claimNudgeSlot(string $memberId, int $nowMs): bool
    {
        $last = $this->lastNudgeAtMs[$memberId] ?? null;
        if ($last !== null && ($nowMs - $last) < self::NUDGE_COOLDOWN_MS) {
            return false;
        }

        $this->lastNudgeAtMs[$memberId] = $nowMs;

        return true;
    }

    /**
     * Get the current playback queue.
     *
     * @return array<int, array{media_id: string, media_info: array<string, mixed>, added_at: int,
     *     added_by: string|null}> Queue items
     */
    public function getPlaybackQueue(): array
    {
        return $this->playbackQueue;
    }

    /**
     * Add an item to the playback queue.
     *
     * @param string $mediaId The media item ID to add
     * @param array<string, mixed> $mediaInfo Additional media information (title, thumbnail, etc.)
     * @return void
     */
    public function addToQueue(string $mediaId, array $mediaInfo): void
    {
        $this->playbackQueue[] = [
            'media_id' => $mediaId,
            'media_info' => $mediaInfo,
            'added_at' => time(),
            'added_by' => $this->hostId,
        ];
        $this->lastActivityAt = time();
    }

    /**
     * Remove an item from the playback queue.
     *
     * @param string $mediaId The media ID to remove
     * @return bool True if found and removed
     */
    public function removeFromQueue(string $mediaId): bool
    {
        foreach ($this->playbackQueue as $index => $item) {
            if ($item['media_id'] === $mediaId) {
                array_splice($this->playbackQueue, $index, 1);
                $this->lastActivityAt = time();
                return true;
            }
        }
        return false;
    }

    /**
     * Clear all items from the playback queue.
     *
     * @return void
     */
    public function clearQueue(): void
    {
        $this->playbackQueue = [];
        $this->lastActivityAt = time();
    }

    /**
     * Get the next item in the queue without removing it.
     *
     * @return array{media_id: string, media_info: array<string, mixed>, added_at: int, added_by: string|null}|null The
     * first queue item or null if queue is empty
     */
    public function getNextInQueue(): ?array
    {
        return $this->playbackQueue[0] ?? null;
    }

    /**
     * Get recent chat messages.
     *
     * Returns the most recent messages up to the specified limit.
     * Messages are returned in chronological order (oldest first).
     *
     * @param int $limit Maximum number of messages to return (default: 50, max: 100)
     * @return array<int, array{member_id: string, message: string, timestamp: int}> Chat messages
     */
    public function getChatMessages(int $limit = 50): array
    {
        return array_slice($this->chatMessages, -$limit);
    }

    /**
     * Add a chat message to the group chat.
     *
     * Messages are stored in a rolling buffer of up to 100 messages.
     * Older messages are automatically discarded when the limit is exceeded.
     *
     * @param string $memberId The ID of the member sending the message
     * @param string $message The chat message content
     * @return void
     */
    public function addChatMessage(string $memberId, string $message): void
    {
        $this->chatMessages[] = [
            'member_id' => $memberId,
            'message' => $message,
            'timestamp' => time(),
        ];

        // Keep only last 100 messages
        if (count($this->chatMessages) > 100) {
            array_shift($this->chatMessages);
        }

        $this->lastActivityAt = time();
    }

    /**
     * Get the timestamp when the group was created.
     *
     * @return int Unix timestamp
     */
    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    /**
     * Get the timestamp of the last group activity.
     *
     * Activity includes member joins/leaves, playback commands, and chat messages.
     * Used for stale group cleanup.
     *
     * @return int Unix timestamp
     */
    public function getLastActivityAt(): int
    {
        return $this->lastActivityAt;
    }

    /**
     * Get the position tolerance setting.
     *
     * @return int Tolerance in milliseconds
     */
    public function getPositionTolerance(): int
    {
        return $this->positionTolerance;
    }

    /**
     * Get the full group state for broadcasting to clients.
     *
     * Returns a comprehensive state array including members dictionary,
     * playback info, queue, and timestamps.
     *
     * @return array<string, mixed> Full group state
     *
     * @example
     * ```php
     * $state = $group->getState();
     * // ['group_id' => 'sp_abc123', 'group_name' => 'Movie Night', 'members' => ['member_id' => [...]], ...]
     * ```
     */
    public function getState(): array
    {
        $membersDict = [];
        foreach ($this->members as $id => $member) {
            $membersDict[$id] = [
                'id' => $id,
                'name' => $member['name'] ?? 'Unknown',
                'is_host' => $id === $this->hostId,
                'joined_at' => $member['joined_at'] ?? time(),
            ];
        }

        return [
            'group_id' => $this->id,
            'group_name' => $this->name,
            'member_count' => $this->getMemberCount(),
            'members' => $membersDict,
            'host_id' => $this->hostId,
            'current_media_id' => $this->currentMediaId,
            'current_media_duration' => $this->currentMediaDuration,
            'playback_position' => $this->playbackPosition,
            'playback_state' => $this->playbackState,
            'queue' => $this->playbackQueue,
            'created_at' => $this->createdAt,
            'last_activity_at' => $this->lastActivityAt,
        ];
    }

    /**
     * Serialize group state for persistence.
     *
     * Creates an array representation of the group state that can be
     * stored and later restored using deserialize().
     *
     * @return array<string, mixed> Serialized group state
     *
     * @see deserialize() For restoring serialized state
     */
    public function serialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'password_hash' => $this->passwordHash,
            'members' => $this->members,
            'host_id' => $this->hostId,
            'current_media_id' => $this->currentMediaId,
            'current_media_duration' => $this->currentMediaDuration,
            'playback_position' => $this->playbackPosition,
            'playback_state' => $this->playbackState,
            'playback_queue' => $this->playbackQueue,
            'chat_messages' => $this->chatMessages,
            'created_at' => $this->createdAt,
            'last_activity_at' => $this->lastActivityAt,
            'position_tolerance' => $this->positionTolerance,
        ];
    }

    /**
     * Restore a group state from serialized data.
     *
     * Reconstructs a GroupState instance from data previously created
     * by serialize().
     *
     * @param array<string, mixed> $data Serialized group state
     * @return self Restored group state instance
     *
     * @see serialize() For creating serializable state
     */
    public static function deserialize(array $data): self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        if (!is_string($id) || !is_string($name)) {
            throw new \InvalidArgumentException('GroupState::deserialize requires string id and name');
        }

        $passwordHashRaw = $data['password_hash'] ?? null;
        $passwordHash = is_string($passwordHashRaw) ? $passwordHashRaw : null;

        $positionToleranceRaw = $data['position_tolerance'] ?? self::POSITION_TOLERANCE;
        $positionTolerance = is_int($positionToleranceRaw) ? $positionToleranceRaw : self::POSITION_TOLERANCE;

        $group = new self($id, $name, $passwordHash, $positionTolerance);

        $group->members = self::deserializeMembers($data['members'] ?? []);

        $hostIdRaw = $data['host_id'] ?? null;
        $group->hostId = is_string($hostIdRaw) ? $hostIdRaw : null;

        $currentMediaIdRaw = $data['current_media_id'] ?? null;
        $group->currentMediaId = is_string($currentMediaIdRaw) ? $currentMediaIdRaw : null;

        $currentMediaDurationRaw = $data['current_media_duration'] ?? 0;
        $group->currentMediaDuration = is_int($currentMediaDurationRaw) ? $currentMediaDurationRaw : 0;

        $playbackPositionRaw = $data['playback_position'] ?? 0;
        $group->playbackPosition = is_int($playbackPositionRaw) ? $playbackPositionRaw : 0;

        $playbackStateRaw = $data['playback_state'] ?? self::STATE_STOPPED;
        $group->playbackState = is_string($playbackStateRaw) ? $playbackStateRaw : self::STATE_STOPPED;

        $group->playbackQueue = self::deserializeQueue($data['playback_queue'] ?? []);
        $group->chatMessages = self::deserializeChatMessages($data['chat_messages'] ?? []);

        $createdAtRaw = $data['created_at'] ?? null;
        $group->createdAt = is_int($createdAtRaw) ? $createdAtRaw : time();

        $lastActivityRaw = $data['last_activity_at'] ?? null;
        $group->lastActivityAt = is_int($lastActivityRaw) ? $lastActivityRaw : time();

        return $group;
    }

    /**
     * Narrow a raw `members` payload into the typed members shape.
     *
     * @param mixed $raw
     * @return array<string, array{name: string, connection_id: string|null, joined_at: int, is_active: bool,
     *     is_host?: bool}>
     */
    private static function deserializeMembers(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $member) {
            if (!is_string($key) || !is_array($member)) {
                continue;
            }

            $name = $member['name'] ?? 'Unknown';
            $connId = $member['connection_id'] ?? null;
            $joinedAt = $member['joined_at'] ?? 0;
            $isActive = $member['is_active'] ?? true;

            $entry = [
                'name' => is_string($name) ? $name : 'Unknown',
                'connection_id' => is_string($connId) ? $connId : null,
                'joined_at' => is_int($joinedAt) ? $joinedAt : 0,
                'is_active' => is_bool($isActive) ? $isActive : true,
            ];

            if (isset($member['is_host']) && is_bool($member['is_host'])) {
                $entry['is_host'] = $member['is_host'];
            }

            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * Narrow a raw `playback_queue` payload into the typed queue shape.
     *
     * @param mixed $raw
     * @return array<int, array{media_id: string, media_info: array<string, mixed>, added_at: int,
     *     added_by: string|null}>
     */
    private static function deserializeQueue(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaId = $item['media_id'] ?? null;
            if (!is_string($mediaId)) {
                continue;
            }
            $mediaInfo = $item['media_info'] ?? [];
            $addedAt = $item['added_at'] ?? 0;
            $addedBy = $item['added_by'] ?? null;

            $mediaInfoOut = [];
            if (is_array($mediaInfo)) {
                foreach ($mediaInfo as $infoKey => $infoValue) {
                    if (is_string($infoKey)) {
                        $mediaInfoOut[$infoKey] = $infoValue;
                    }
                }
            }

            $out[] = [
                'media_id' => $mediaId,
                'media_info' => $mediaInfoOut,
                'added_at' => is_int($addedAt) ? $addedAt : 0,
                'added_by' => is_string($addedBy) ? $addedBy : null,
            ];
        }

        return $out;
    }

    /**
     * Narrow a raw `chat_messages` payload into the typed chat shape.
     *
     * @param mixed $raw
     * @return array<int, array{member_id: string, message: string, timestamp: int}>
     */
    private static function deserializeChatMessages(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $memberId = $msg['member_id'] ?? null;
            $message = $msg['message'] ?? null;
            $timestamp = $msg['timestamp'] ?? null;
            if (is_string($memberId) && is_string($message) && is_int($timestamp)) {
                $out[] = [
                    'member_id' => $memberId,
                    'message' => $message,
                    'timestamp' => $timestamp,
                ];
            }
        }

        return $out;
    }

    /**
     * Create a SHA256 hash of a password.
     *
     * @param string $password The plaintext password
     * @return string 64-character hex string (SHA256)
     */
    public static function hashPassword(string $password): string
    {
        return hash('sha256', $password);
    }
}
