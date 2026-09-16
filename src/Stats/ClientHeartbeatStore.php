<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Stats;

use Phlix\Common\Database\WriteResult;
use Workerman\MySQL\Connection;

/**
 * Best-effort landing store for opt-in client telemetry heartbeats (S518 / AD-27).
 *
 * ## What this is NOT
 *
 * It is deliberately *not* the server→hub heartbeat (`server_heartbeats`, a
 * `HeartbeatDto` audience: an enrolled-SERVER's uptime/transcode telemetry with
 * an FK to `servers`). AD-27's heartbeat is a CLIENT build's hourly tick
 * (instance id, version, platform, build token) from devices that may never
 * pair with anyone — the survey's "wrong audience" rejection, honoured here by
 * a separate table, separate row shape and zero FK/PII surface.
 *
 * ## Privacy posture (owner directive: "do tier 3" → opt-in)
 *
 * - No user id, no IP, no account linkage of any kind is stored. The only
 *   device identifier is the client-generated opaque `instance_id` — the row's
 *   existence already means the human clicked consent (the controller gate that
 *   refuses anything without `consent: true` before this class ever runs).
 * - One row PER INSTANCE, UPSERTed in place: the table is bounded by fleet size
 *   and a device that un-installs simply stops refreshing `last_seen_at`.
 *   This IS the retention posture — there is no per-tick history to reap, so
 *   no reaper exists to forget to run. (Re-derive before adding columns that
 *   would make the row unbounded; it must stay one-row-per-device.)
 * - Every failure path returns false and logs at the caller; a telemetry tick
 *   must never turn into a 5xx ("every failure swallowed" — survey-c contract).
 *
 * @package Phlix\Stats
 * @since 1.2.3
 * @see \Phlix\Server\Http\Controllers\Auth\QuickConnectController::heartbeat() the only writer
 */
final class ClientHeartbeatStore implements ClientHeartbeatStoreInterface
{
    // Column-width caps live on ClientHeartbeatStoreInterface (the wire
    // vocabulary shared with the controller); the schema authority is
    // migrations/106_client_heartbeats.sql, which the interface pins.

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Insert-or-refresh the single row for one client instance.
     *
     * `INSERT … ON DUPLICATE KEY UPDATE` (the migration-105-accumulation idiom
     * pointed at a non-accumulating target): `first_seen_at` survives every
     * refresh, `last_seen_at` rides the clock, and the descriptive columns
     * overwrite because a self-updated device's current version is the fact
     * operators care about (fleet firmware floor — TN-4).
     *
     * @param string $instanceId  Opaque client-generated id, already consent-gated.
     * @param string $version     Client version string (bounded, non-null).
     * @param string $clientType  Platform/taxonomy label (bounded, non-null).
     * @param string $buildToken  Per-build identifier (bounded; '' allowed —
     *                            release builds legitimately have none).
     *
     * @return bool True only when the row provably landed. False means
     *              "not recorded" — never throws, never 5xxes the caller.
     */
    public function record(
        string $instanceId,
        string $version,
        string $clientType,
        string $buildToken
    ): bool {
        // Boundary re-check (the controller already parsed; this class must stay
        // honest even if a second writer appears later — the column widths are
        // the contract, and a silent truncation would corrupt the fleet census).
        if (
            $instanceId === ''
            || strlen($instanceId) > self::MAX_INSTANCE_ID_LENGTH
            || strlen($version) > self::MAX_VERSION_LENGTH
            || strlen($clientType) > self::MAX_CLIENT_TYPE_LENGTH
            || strlen($buildToken) > self::MAX_BUILD_TOKEN_LENGTH
        ) {
            return false;
        }

        try {
            $result = $this->db->query(
                'INSERT INTO client_heartbeats'
                . ' (instance_id, version, client_type, build_token, first_seen_at, last_seen_at)'
                . " VALUES (?, ?, ?, ?, NOW(), NOW())"
                . ' ON DUPLICATE KEY UPDATE'
                . ' version = VALUES(version),'
                . ' client_type = VALUES(client_type),'
                . ' build_token = VALUES(build_token),'
                . ' last_seen_at = NOW()',
                [$instanceId, $version, $clientType, $buildToken],
            );

            // WriteResult table: a successful INSERT|UPDATE upsert reports an int
            // rowCount ≥ 1; `null` is the zero-row/no-recognition shape that
            // must not be reported as recorded. (`false` is unreachable — the
            // client throws instead — but the guard treats it identically.)
            return !WriteResult::wroteNothing($result);
        } catch (\Throwable) {
            // Swallow-by-contract: storage errors belong in the server log, not
            // in the client's response. The next hourly tick retries.
            return false;
        }
    }

    /**
     * Fleet census for the admin read surface: the most recently seen devices.
     *
     * Read-only, ordered by `last_seen_at`; `$limit` is clamped so a UI paging
     * bug cannot ask for the whole table.
     *
     * @return list<array<string, mixed>> Rows keyed by the table's columns.
     */
    public function recent(int $limit = 200): array
    {
        $clamped = max(1, min(1000, $limit));
        try {
            $result = $this->db->query(
                'SELECT instance_id, version, client_type, build_token, first_seen_at, last_seen_at'
                . ' FROM client_heartbeats ORDER BY last_seen_at DESC LIMIT ?',
                [$clamped],
            );

            if (!is_array($result)) {
                return [];
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = array_values($result);

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Total distinct consenting instances (the census denominator beside the page).
     */
    public function countInstances(): int
    {
        try {
            $result = $this->db->query('SELECT COUNT(*) AS total FROM client_heartbeats');
            if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
                return 0;
            }

            $total = $result[0]['total'] ?? 0;

            return is_numeric($total) ? (int) $total : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
