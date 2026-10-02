<?php

/**
 * Phlix media server component: Casting.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Casting;

/**
 * Shared cross-worker store for casting sessions (Device-M1).
 *
 * The HTTP pool runs multiple resident workers; each manager keeps its live
 * session objects (and their poll timers) worker-local, and this store is the
 * fleet-visible register that lets ANY worker honour a control request for a
 * session started elsewhere — by rebuilding the stateless device client from
 * the row's `state` payload. The interface exists so unit tests can hand the
 * managers an in-memory fake without a database, exactly like the
 * `QuickConnectStateStoreInterface` seam it mirrors.
 *
 * ## Failure posture
 *
 * `insert()`/`touch()` return booleans, `find()` returns null on miss; real
 * database errors propagate as exceptions and the CALLING manager decides the
 * degradation (start fails closed; control ops on an already-owned local
 * session fail open — see the managers for the reasoning). Janitorial work
 * (the stale sweep) is best-effort and never throws.
 *
 * @package Phlix\Casting
 * @since 1.5.0
 */
interface CastingSessionStoreInterface
{
    /** Casting class: DLNA PlayTo renderer session */
    public const TYPE_PLAYTO = 'playto';

    /** Casting class: Roku ECP session */
    public const TYPE_ROKU = 'roku';

    /** Casting class: Chromecast session */
    public const TYPE_CAST = 'cast';

    /** Casting class: AirPlay RAOP session */
    public const TYPE_AIRPLAY = 'airplay';

    /**
     * Register a new session. Returns false when the row could not be written
     * (the caller must then NOT keep a session only it can see).
     *
     * @param array<string, mixed> $state Re-attach payload (see {@see CastingSessionRecord})
     */
    public function insert(string $sessionId, string $type, string $deviceId, string $userId, array $state): bool;

    /**
     * Look up the live session for a device class+id. Null means "no such
     * session" — a genuinely dead row and a never-existing one are the same.
     */
    public function find(string $type, string $deviceId): ?CastingSessionRecord;

    /**
     * Stamp `last_seen_at = NOW()` for a session.
     *
     * @return bool false when the row is gone (replaced or swept) — the calling
     *              worker must evict its local object; throttled repeats return
     *              true without touching the database.
     */
    public function touch(string $sessionId): bool;

    /**
     * Remove a session's row by its id. No-op-safe on unknown ids.
     */
    public function delete(string $sessionId): void;

    /**
     * Remove whatever row the device class currently has, whatever session id
     * it carries — the REPLACE primitive behind start-path dedupe across
     * workers (the previous owner's worker notices via its next `touch()`).
     */
    public function deleteByDevice(string $type, string $deviceId): void;

    /**
     * All live sessions owned by a user (listing/audit seam).
     *
     * @return list<CastingSessionRecord>
     */
    public function findByUser(string $userId): array;
}
