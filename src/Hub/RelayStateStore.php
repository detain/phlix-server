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
 * Two SINGLE-WRITER JSON files (one owner each — never shared between two writer
 * forks, to avoid multi-process write races):
 *   - `relay-tunnel.state.json`  — sole writer = the relay fork (S38).
 *   - `hub-heartbeat.state.json` — sole writer = the heartbeat fork (S40).
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
     * Atomically write a state file: stamp `updatedAt`, write a unique tmp file,
     * chmod 0600, then rename() over the target (atomic on the same filesystem).
     *
     * Best-effort: any failure (unencodable payload, unwritable dir, failed
     * rename) is swallowed — persisted state is diagnostic, never load-bearing,
     * so a write failure must never disrupt the resident worker.
     *
     * @param string               $file  State file name (a *_STATE_FILE const).
     * @param array<string, mixed> $state State fields to persist.
     *
     * @return void
     */
    private function writeState(string $file, array $state): void
    {
        $state['updatedAt'] = (new \DateTimeImmutable())->format('c');

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
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
            return;
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
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
