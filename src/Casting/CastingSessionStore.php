<?php

/**
 * Phlix media server component: Casting.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Casting;

use JsonException;
use Phlix\Common\Database\WriteResult;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * DB-backed `casting_sessions` store (Device-M1, migration 111).
 *
 * ## Why a table
 *
 * The HTTP worker pool is `count = 14`; the casting managers' live-session
 * maps are process-local. A cast started on worker A was invisible to worker
 * B, so pause/stop/status hit a spurious 404 while worker A's poll timer kept
 * firing for a session nobody could reach. The row is the fleet-visible
 * register; the local map stays the hot cache of live objects. Re-attach is
 * possible because every casting transport here is stateless per command
 * (DLNA SOAP POST, Roku ECP HTTP, Cast HTTP JSON, RAOP fresh socket per
 * command) — the client is rebuilt from the row's `state`, no socket handoff.
 *
 * ## Connection acquisition
 *
 * The `Connection` arrives via constructor exactly like
 * {@see \Phlix\Auth\QuickConnectStateStore} and the DB-backed rate limiters:
 * `CoreServicesProvider` binds `Connection::class` to the SHARED pooled mysql
 * connection, `CastingServicesProvider` injects it into this store, and the
 * managers receive the store through an explicit container binding (PHP-DI
 * skips optional ctor params during autowiring — the documented silent-null
 * hazard). Every statement is single-statement, never a multi-query
 * transaction, so the pooled per-query borrow idiom is the right one.
 *
 * ## Touch throttling
 *
 * Poll timers fire every 5s per live session × every worker; a raw UPDATE per
 * poll would be pure write amplification for a column whose only consumer is
 * the stale sweep. `touch()` therefore de-duplicates in-process: the first
 * touch in a `{@see $touchIntervalSeconds}` window writes, repeats answer
 * "alive" from the monotonic-clock memory, and an eviction (`delete()`, or a
 * touch that found zero rows) drops the memo so a recycled session id cannot
 * inherit a stale optimism. Throttle state is per-worker and harmless: a
 * worker that stops touching simply re-reads liveness on its next real write.
 *
 * ## Stale sweep
 *
 * Rows abandoned without a stop (device left the LAN, worker killed -9) are
 * deleted lazily on `find()`/`insert()` — bounded `DELETE ... LIMIT`, DB-clock
 * horizon ({@see $staleHorizonSeconds}), best-effort like every other
 * janitorial sweep in this codebase (QuickConnect's `purgeExpired()` is the
 * precedent). A dedicated resident worker was deliberately NOT added: the
 * NatPmpMaintenance pattern exists, but a sweep whose only trigger can be a
 * request is exactly as fresh as the table is consulted, and one more resident
 * timer for a handful of LAN rows is scope the fleet does not need.
 *
 * @package Phlix\Casting
 * @since 1.5.0
 * @see \Phlix\Auth\QuickConnectStateStore the sibling DB-transient-store idiom
 */
final class CastingSessionStore implements CastingSessionStoreInterface
{
    /** Default touch memo window (seconds) — polls are 5s, the sweep horizon is hours. */
    public const DEFAULT_TOUCH_INTERVAL_SECONDS = 60;

    /** Default stale-row horizon: 24h without any control op or poll touch. */
    public const DEFAULT_STALE_HORIZON_SECONDS = 86400;

    /** Upper bound on rows one lazy sweep may delete, so the janitor can never stall a request. */
    private const SWEEP_BATCH_LIMIT = 200;

    /** @var Connection Shared pooled MySQL connection */
    private Connection $db;

    /** @var int Seconds a successful touch stays memoised */
    private int $touchIntervalSeconds;

    /** @var int Seconds of silence after which a row is considered stale */
    private int $staleHorizonSeconds;

    /** @var callable():int Monotonic seconds source (injectable for tests) */
    private $monotonicClock;

    /** @var array<string, int> session id => monotonic seconds of last real UPDATE */
    private array $lastTouchedAt = [];

    /**
     * @param Connection $db Shared pooled connection (from `Connection::class` in the container)
     * @param int $touchIntervalSeconds Touch memo window; lower-bounded at 1
     * @param int $staleHorizonSeconds Sweep horizon; lower-bounded at 60
     * @param callable():int|null $monotonicClock Monotonic seconds, default `hrtime(true)` based
     *
     * @since 1.5.0
     */
    public function __construct(
        Connection $db,
        int $touchIntervalSeconds = self::DEFAULT_TOUCH_INTERVAL_SECONDS,
        int $staleHorizonSeconds = self::DEFAULT_STALE_HORIZON_SECONDS,
        ?callable $monotonicClock = null,
    ) {
        $this->db = $db;
        $this->touchIntervalSeconds = max(1, $touchIntervalSeconds);
        $this->staleHorizonSeconds = max(60, $staleHorizonSeconds);
        $this->monotonicClock = $monotonicClock ?? static fn (): int => intdiv(hrtime(true), 1_000_000_000);
    }

    /**
     * {@inheritDoc}
     *
     * Replace-race handling: `uk_type_device` makes a concurrent start on the
     * SAME device (two users, two workers, same instant) throw 1062 for the
     * loser after the winner's `deleteByDevice()`+INSERT interleaved; one
     * delete-then-retry gives the later call the row, matching the local maps'
     * last-writer-wins REPLACE semantics. A second failure is a real DB outage:
     * return false and let the manager fail the start closed.
     */
    public function insert(string $sessionId, string $type, string $deviceId, string $userId, array $state): bool
    {
        $this->purgeStale();

        try {
            $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            // Non-encodable state (INF/NAN/resource) is a caller bug — fail the
            // start loudly through the false, never persist a mangled row.
            return false;
        }

        try {
            $result = $this->db->query(
                'INSERT INTO casting_sessions (session_id, type, device_id, user_id, state, created_at, last_seen_at)'
                . ' VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [$sessionId, $type, $deviceId, $userId, $payload],
            );
        } catch (\Throwable) {
            try {
                $this->deleteByDevice($type, $deviceId);
                $result = $this->db->query(
                    'INSERT INTO casting_sessions'
                    . ' (session_id, type, device_id, user_id, state, created_at, last_seen_at)'
                    . ' VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                    [$sessionId, $type, $deviceId, $userId, $payload],
                );
            } catch (\Throwable) {
                return false;
            }
        }

        return !WriteResult::wroteNothing($result);
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $type, string $deviceId): ?CastingSessionRecord
    {
        $this->purgeStale();

        $result = $this->db->query(
            'SELECT session_id, type, device_id, user_id, state, created_at, last_seen_at'
            . ' FROM casting_sessions WHERE type = ? AND device_id = ?',
            [$type, $deviceId],
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return self::hydrate($row);
    }

    /**
     * {@inheritDoc}
     *
     * The UPDATE is scoped to `session_id`, so a row already REPLACED by
     * another worker's start (new id under the same device key) affects zero
     * rows here — that is precisely the "your session is gone, evict yourself"
     * signal the managers' poll loops rely on.
     */
    public function touch(string $sessionId): bool
    {
        $now = ($this->monotonicClock)();

        $last = $this->lastTouchedAt[$sessionId] ?? null;
        if ($last !== null && ($now - $last) < $this->touchIntervalSeconds) {
            return true;
        }

        $affected = $this->db->query(
            'UPDATE casting_sessions SET last_seen_at = NOW() WHERE session_id = ?',
            [$sessionId],
        );

        if (!is_int($affected) || $affected < 1) {
            // MySQL's rowCount reports CHANGED rows, not matched rows: a touch
            // landing in the same second as the previous stamp writes an
            // identical DATETIME and changes zero rows for a row that is very
            // much alive. One bounded existence read disambiguates "same-second
            // re-stamp" (keep serving) from "row replaced or swept" (evict) —
            // paid only on the rare zero-affected path.
            $stillThere = $this->db->query(
                'SELECT 1 FROM casting_sessions WHERE session_id = ? LIMIT 1',
                [$sessionId],
            );

            if (!is_array($stillThere) || $stillThere === []) {
                unset($this->lastTouchedAt[$sessionId]);
                return false;
            }
        }

        $this->lastTouchedAt[$sessionId] = $now;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $sessionId): void
    {
        unset($this->lastTouchedAt[$sessionId]);

        $this->db->query('DELETE FROM casting_sessions WHERE session_id = ?', [$sessionId]);
    }

    /**
     * {@inheritDoc}
     *
     * Also drops the touch memo for whatever session id the device held —
     * looked up first, because the memo is keyed by id and a replaced device
     * row must never leave a stale optimistic entry behind. A lookup failure
     * only costs one extra real UPDATE later, so it is swallowed.
     */
    public function deleteByDevice(string $type, string $deviceId): void
    {
        try {
            $existing = $this->findRowSessionId($type, $deviceId);
        } catch (\Throwable) {
            $existing = null;
        }

        if ($existing !== null) {
            unset($this->lastTouchedAt[$existing]);
        }

        $this->db->query(
            'DELETE FROM casting_sessions WHERE type = ? AND device_id = ?',
            [$type, $deviceId],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findByUser(string $userId): array
    {
        $result = $this->db->query(
            'SELECT session_id, type, device_id, user_id, state, created_at, last_seen_at'
            . ' FROM casting_sessions WHERE user_id = ?',
            [$userId],
        );

        if (!is_array($result)) {
            return [];
        }

        $records = [];
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }
            $records[] = self::hydrate($row);
        }

        return $records;
    }

    /**
     * Best-effort bounded removal of rows untouched beyond the horizon.
     *
     * The horizon literal is an int cast of a constructor-bounded value — the
     * only string-interpolated token in this class, and safe by construction.
     * Prepared `INTERVAL ? SECOND` would also work under the house's emulated
     * prepares; inlining keeps the sweep one obvious statement. Failures are
     * swallowed: a sweep that cannot run must never sink the lookup that asked
     * for it (QuickConnect::purgeExpired() is the doctrine source).
     */
    private function purgeStale(): void
    {
        try {
            $this->db->query(
                'DELETE FROM casting_sessions'
                . ' WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL ' . $this->staleHorizonSeconds . ' SECOND)'
                . ' LIMIT ' . self::SWEEP_BATCH_LIMIT
            );
        } catch (\Throwable) {
            // Janitorial only.
        }
    }

    /**
     * Parse one row into the typed record; a corrupt `state` payload throws.
     *
     * @param array<mixed, mixed> $row (SELECT rows are keyed by column name;
     *        the widened shape is what the driver's generic array hands us)
     *
     * @throws RuntimeException when `state` is not a JSON object — the manager
     *                          treats that as a dead row it may delete
     */
    private static function hydrate(array $row): CastingSessionRecord
    {
        $stateRaw = $row['state'] ?? null;
        $state = null;

        if (is_array($stateRaw)) {
            $state = $stateRaw;
        } elseif (is_string($stateRaw) && $stateRaw !== '') {
            try {
                $decoded = json_decode($stateRaw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        if ($state === null) {
            throw new RuntimeException('casting_sessions row carries a non-object state payload');
        }

        return new CastingSessionRecord(
            self::textField($row['session_id'] ?? null),
            self::textField($row['type'] ?? null),
            self::textField($row['device_id'] ?? null),
            self::textField($row['user_id'] ?? null),
            $state,
            self::textField($row['created_at'] ?? null),
            self::textField($row['last_seen_at'] ?? null),
        );
    }

    /**
     * Parse one column to its string form — scalars only, anything else ''.
     *
     * The register's own columns are all VARCHAR/CHAR/DATETIME as PHP strings;
     * the numeric arms exist so a driver quirk degrades to a visible-empty
     * value instead of a PHP 8 fatal on casting arrays or nulls blindly.
     */
    private static function textField(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Current session id for a device key, or null — memo cleanup helper.
     */
    private function findRowSessionId(string $type, string $deviceId): ?string
    {
        $result = $this->db->query(
            'SELECT session_id FROM casting_sessions WHERE type = ? AND device_id = ?',
            [$type, $deviceId],
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        $id = $result[0]['session_id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
