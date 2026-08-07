<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * File-based cross-process state store for the relay tunnel + hub heartbeat.
 *
 * The admin UI and health endpoints run in the HTTP worker, but the relay
 * tunnel and hub heartbeat run in SEPARATE forked processes
 * (`phlix-relay-tunnel`, `phlix-hub-heartbeat`) with no shared memory. This
 * store is the cross-process bridge: each fork holds its own instance pointing
 * at the SAME `$configDir`, so the HTTP worker can read state the forks write.
 *
 * Three SINGLE-WRITER JSON files (one owner each — never shared between two
 * writer processes, to avoid multi-process write races):
 *   - `relay-tunnel.state.json`  — sole writer = the relay fork (S38).
 *   - `hub-heartbeat.state.json` — sole writer = the heartbeat fork (S40).
 *   - `relay-control.json`       — sole writer = the HTTP worker (S39): the
 *     operator kill-switch persisted by the admin relay Enable/Disable controls;
 *     the relay fork READS it at boot ({@see \Phlix\Hub\RelayConsumer} via
 *     `start.php`) IN ADDITION to the `PHLIX_RELAY_DISABLED` env var, so the
 *     toggle takes effect on the next reload. (HTTP worker writes, relay fork
 *     reads — the mirror of the two state files above, preserving single-writer
 *     discipline for every file.)
 *
 * Writes are ATOMIC (write to a unique `*.tmp` then `rename()`, `@chmod 0600`),
 * mirroring {@see HubClient::storeEnrollment()}. Reads are best-effort and
 * null-safe: a missing or unparseable file returns `[]` and NEVER throws, so a
 * never-started fork simply reports "offline/unknown" (mirroring
 * {@see HubClient::loadEnrollment()}'s silent-null contract).
 *
 * @package Phlix\Hub
 * @since 0.20.0
 */
final class RelayStateStore
{
    /** Sole writer: the relay fork ({@see RelayConsumer}). */
    public const RELAY_STATE_FILE = 'relay-tunnel.state.json';

    /** Sole writer: the heartbeat fork ({@see HubClient} heartbeat loop). */
    public const HEARTBEAT_STATE_FILE = 'hub-heartbeat.state.json';

    /**
     * Sole writer: the HTTP worker (the admin relay Enable/Disable controls).
     * Read by the relay fork at boot as an operator kill-switch (S39).
     */
    public const RELAY_CONTROL_FILE = 'relay-control.json';

    /**
     * Fallback staleness threshold, in seconds, for a state file that does not
     * declare its own `staleAfterSeconds` (e.g. one written before S40's
     * staleness gate existed). 3x the 60 s hub-heartbeat cadence.
     */
    public const DEFAULT_STALE_AFTER_SECONDS = 180;

    /** Lower clamp for a writer-declared `staleAfterSeconds`. */
    public const MIN_STALE_AFTER_SECONDS = 30;

    /** Upper clamp for a writer-declared `staleAfterSeconds`. */
    public const MAX_STALE_AFTER_SECONDS = 3600;

    /**
     * Reason substituted into a stale relay state when its liveness fields are
     * forced down (the writer fork stopped refreshing the file).
     */
    public const STALE_RELAY_REASON =
        'relay tunnel state is stale — the phlix-relay-tunnel worker is not running';

    /**
     * @var string Directory the state files live in (same as hub-enrollment.json).
     */
    private string $configDir;

    /**
     * @param string $configDir Directory for the state files (already writable;
     *                          the same dir as `hub-enrollment.json`).
     */
    public function __construct(string $configDir)
    {
        $this->configDir = $configDir;
    }

    /**
     * Persist the relay-tunnel state (sole writer: the relay fork).
     *
     * An `updatedAt` ISO-8601 timestamp is always stamped on write. Callers pass
     * the observable tunnel fields:
     *   {connected, active, reconnectAttempts, activeSessions, lastDisconnectTime,
     *    lastConnectError, lastConnectErrorAt}.
     *
     * @param array<string, mixed> $state Relay-tunnel state fields.
     *
     * @return void
     *
     * @since 0.20.0
     */
    public function writeRelayState(array $state): void
    {
        $this->writeState(self::RELAY_STATE_FILE, $state);
    }

    /**
     * Best-effort read of the relay-tunnel state.
     *
     * @return array<string, mixed> The decoded state, or `[]` when the file is
     *                              missing/unreadable/unparseable (never throws).
     *
     * @since 0.20.0
     */
    public function readRelayState(): array
    {
        return $this->readState(self::RELAY_STATE_FILE);
    }

    /**
     * Persist the hub-heartbeat state (sole writer: the heartbeat fork).
     *
     * The relay fork must NEVER call this — the two files are single-writer to
     * avoid cross-process write races. Wired for S40's heartbeat writes; an
     * `updatedAt` ISO-8601 timestamp is stamped on write.
     *
     * @param array<string, mixed> $state Hub-heartbeat state fields.
     *
     * @return void
     *
     * @since 0.20.0
     */
    public function writeHeartbeatState(array $state): void
    {
        $this->writeState(self::HEARTBEAT_STATE_FILE, $state);
    }

    /**
     * Best-effort read of the hub-heartbeat state.
     *
     * @return array<string, mixed> The decoded state, or `[]` when the file is
     *                              missing/unreadable/unparseable (never throws).
     *
     * @since 0.20.0
     */
    public function readHeartbeatState(): array
    {
        return $this->readState(self::HEARTBEAT_STATE_FILE);
    }

    /**
     * Read the relay-tunnel state with the STALENESS GATE applied (S40).
     *
     * State files survive restarts, crashes and `SIGKILL`, and nothing removes
     * them — so a raw read of a frozen file reports `connected: true` forever
     * after the relay fork dies. That is strictly worse than the live probe the
     * cheap-read design replaced, so every reader of the liveness fields must
     * go through this method rather than {@see readRelayState()}.
     *
     * When the file has not been refreshed inside its declared cadence the
     * liveness fields are forced DOWN (`connected`/`active` false,
     * `activeSessions` 0) and {@see STALE_RELAY_REASON} is reported. The gate
     * only ever downgrades — it never turns a "down" state into an "up" one —
     * and it is a no-op for a state that is already down (so the S39
     * kill-switch reason survives untouched however old the file is).
     *
     * @param int|null $now Unix time to compare against (tests); defaults to `time()`.
     *
     * @return array<string, mixed> The state, plus `stale => true` when the
     *                              gate fired.
     *
     * @since 0.20.0
     */
    public function readLiveRelayState(?int $now = null): array
    {
        $state = $this->readRelayState();

        $wasLive = ($state['connected'] ?? false) === true || ($state['active'] ?? false) === true;
        if (!$wasLive || !self::isStateStale($state, $now)) {
            return $state;
        }

        $state['connected'] = false;
        $state['active'] = false;
        $state['activeSessions'] = 0;
        $state['stale'] = true;
        $state['lastConnectError'] = self::STALE_RELAY_REASON;
        $state['lastConnectErrorAt'] = (new \DateTimeImmutable())->setTimestamp($now ?? time())->format('c');

        return $state;
    }

    /**
     * Read the hub-heartbeat state, flagged with the staleness gate (S40).
     *
     * Same rationale as {@see readLiveRelayState()}: if the
     * `phlix-hub-heartbeat` fork dies, its last successful tick stays in the
     * file forever and `/api/v1/health/network` would report `healthy`
     * indefinitely. Nothing is rewritten here (the recorded measurements stay
     * honest as historical facts) — the reader is simply told they are too old
     * to describe the present.
     *
     * @param int|null $now Unix time to compare against (tests); defaults to `time()`.
     *
     * @return array<string, mixed> The state, plus `stale => true` when it is
     *                              past its declared cadence.
     *
     * @since 0.20.0
     */
    public function readLiveHeartbeatState(?int $now = null): array
    {
        $state = $this->readHeartbeatState();

        if ($state !== [] && self::isStateStale($state, $now)) {
            $state['stale'] = true;
        }

        return $state;
    }

    /**
     * Whether a persisted state snapshot is older than its writer's cadence.
     *
     * The threshold is taken from the writer-declared `staleAfterSeconds` field
     * (clamped to {@see MIN_STALE_AFTER_SECONDS}..{@see MAX_STALE_AFTER_SECONDS})
     * so a reader never has to guess a fork's refresh interval, falling back to
     * {@see DEFAULT_STALE_AFTER_SECONDS}.
     *
     * A NEVER-WRITTEN state (`[]`, i.e. missing file) is not stale — there is
     * nothing to gate and the readers already treat it as offline. A non-empty
     * state with no parseable `updatedAt` IS stale: {@see writeState()} stamps
     * that field on every write, so its absence means the payload did not come
     * from this store.
     *
     * @param array<string, mixed> $state A decoded state snapshot.
     * @param int|null             $now   Unix time to compare against; defaults to `time()`.
     *
     * @return bool `true` when the snapshot is too old to describe the present.
     *
     * @since 0.20.0
     */
    public static function isStateStale(array $state, ?int $now = null): bool
    {
        if ($state === []) {
            return false;
        }

        $updatedAt = $state['updatedAt'] ?? null;
        $writtenAt = is_string($updatedAt) ? strtotime($updatedAt) : false;
        if ($writtenAt === false) {
            return true;
        }

        $declared = $state['staleAfterSeconds'] ?? null;
        $threshold = is_numeric($declared)
            ? max(self::MIN_STALE_AFTER_SECONDS, min(self::MAX_STALE_AFTER_SECONDS, (int) $declared))
            : self::DEFAULT_STALE_AFTER_SECONDS;

        return (($now ?? time()) - $writtenAt) > $threshold;
    }

    /**
     * Persist the operator relay kill-switch (sole writer: the HTTP worker).
     *
     * `true` disables the relay tunnel; `false` clears the kill-switch. The
     * relay fork honors this at boot ({@see isRelayDisabled()}) IN ADDITION to
     * the `PHLIX_RELAY_DISABLED` env var, so the change takes effect on the next
     * server reload rather than immediately (the tunnel runs in a separate fork
     * with no live control channel).
     *
     * Unlike the diagnostic state writes, this is a LOAD-BEARING lever, so the
     * caller is told whether the write actually persisted.
     *
     * @param bool $disabled `true` to disable the relay tunnel, `false` to enable.
     *
     * @return bool `true` when the flag was persisted, `false` on write failure.
     *
     * @since 0.20.0
     */
    public function setRelayDisabled(bool $disabled): bool
    {
        return $this->writeState(self::RELAY_CONTROL_FILE, ['disabled' => $disabled]);
    }

    /**
     * Whether the operator relay kill-switch is set (best-effort, null-safe).
     *
     * Missing/unparseable control file → `false` (not disabled), so a fresh
     * install with no persisted flag behaves exactly as before this lever
     * existed.
     *
     * @return bool `true` when the persisted kill-switch disables the relay.
     *
     * @since 0.20.0
     */
    public function isRelayDisabled(): bool
    {
        $state = $this->readState(self::RELAY_CONTROL_FILE);
        return ($state['disabled'] ?? false) === true;
    }

    /**
     * Atomically write a state file: stamp `updatedAt`, write a unique tmp file,
     * chmod 0600, then rename() over the target (atomic on the same filesystem).
     *
     * Best-effort: any failure (unencodable payload, unwritable dir, failed
     * rename) is swallowed — the diagnostic state writes treat it as never
     * load-bearing, so a write failure must never disrupt the resident worker.
     * The boolean return lets the load-bearing kill-switch write
     * ({@see setRelayDisabled()}) surface a genuine persist failure to the caller.
     *
     * @param string               $file  State file name (a *_FILE const).
     * @param array<string, mixed> $state State fields to persist.
     *
     * @return bool `true` when the file was written and renamed into place.
     */
    private function writeState(string $file, array $state): bool
    {
        $state['updatedAt'] = (new \DateTimeImmutable())->format('c');

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $path = $this->pathFor($file);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Unique tmp name per process so a stray concurrent writer (should not
        // happen given the single-writer discipline) cannot corrupt the tmp.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Best-effort read of a state file.
     *
     * @param string $file State file name (a *_STATE_FILE const).
     *
     * @return array<string, mixed> The decoded state, or `[]` on any failure.
     */
    private function readState(string $file): array
    {
        $path = $this->pathFor($file);
        if (!is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Absolute path for a state file inside the config dir.
     *
     * @param string $file State file name.
     *
     * @return string
     */
    private function pathFor(string $file): string
    {
        return rtrim($this->configDir, '/') . '/' . $file;
    }
}
