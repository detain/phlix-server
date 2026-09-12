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
 * SyncPlayBridge - the internal envelope for the S445 write-through publish
 * transport between HTTP (REST) workers and the single authoritative WebSocket
 * worker's SyncPlayManager.
 *
 * ## What this is (and is NOT)
 *
 * This is a PRIVATE, process-to-process channel — NOT part of the client-facing
 * SyncPlay wire protocol. The client protocol is gated by {@see Messages}
 * `VALID_TYPES` on the public :8097 socket; registering a bridge operation
 * there would make internal mutations client-spoofable and would touch the
 * S417-pinned outbound frame shape. So the bridge rides its OWN newline-
 * delimited JSON envelope on a private unix socket (default
 * `var/syncplay-bridge.sock`), authenticated by unix file permissions (0600,
 * same-user supervisor topology — the master forks all workers) plus a shared
 * secret token echoed in every frame and constant-time checked on receipt.
 *
 * Full model, ordering and loss posture: `docs/dev/SYNCPLAY_WRITE_THROUGH_BRIDGE.md`.
 *
 * ## Frame shapes
 *
 *     {"op":"syncplay_bridge_group_upsert","bridge_version":1,"token":"…","issued_at_ms":1700000000000,"group":{…GroupState::serialize()…}}
 *     {"op":"syncplay_bridge_group_delete","bridge_version":1,"token":"…","issued_at_ms":1700000000000,"group_id":"sp_…"}
 *
 * @package Phlix\Session\SyncPlay
 * @since 3.5
 */
final class SyncPlayBridge
{
    /**
     * Shared secret every bridge frame must carry. The unix socket's 0600
     * permissions are the primary gate; this token is the defense-in-depth
     * tripwire against misconfiguration (world-writable var/, wrong-socket
     * wiring) — a frame that arrives without it is dropped with a warning.
     */
    public const TOKEN = 'S445XWORKPUBX9Q3';

    /** Envelope format version (this file's shape, independent of the client protocol version). */
    public const VERSION = 1;

    /** Full group state (GroupState::serialize()) replaces/adopts the live group's REST-owned facets. */
    public const OP_GROUP_UPSERT = 'syncplay_bridge_group_upsert';

    /** The group no longer exists; tombstone it. */
    public const OP_GROUP_DELETE = 'syncplay_bridge_group_delete';

    /** Hard ceiling on a single accepted NDJSON line; longer streams are dropped (defensive, private socket). */
    public const MAX_LINE_BYTES = 1048576;

    /**
     * Hard ceiling on a frame the PUBLISHER will attempt. Must stay below the
     * empty unix-stream kernel buffers of the supported (Linux) venue — a
     * single fwrite of a frame this size always completes in-kernel against a
     * fresh connection even if the listener never reads (measured 219 264
     * bytes absorbed with a wedged listener), which is what makes publish
     * provably non-stalling under the swoole SWOOLE_HOOK_UNIX runtime hook
     * (a partial second write there blocks despite non-blocking mode — see
     * SyncPlayBridgePublisher + docs/dev/BLOCKING_IO_EXCEPTIONS.md).
     */
    public const MAX_PUBLISH_FRAME_BYTES = 163840;

    /**
     * Absolute default socket path, resolved from this file's location so it
     * works regardless of CWD: `<repo>/var/syncplay-bridge.sock`.
     */
    public static function defaultSocketPath(): string
    {
        return dirname(__DIR__, 3) . '/var/syncplay-bridge.sock';
    }

    /**
     * Resolve the bridge socket path from the `syncplay_bridge` config block.
     *
     * @param array<string, mixed> $bridgeConfig e.g. $appConfig['syncplay_bridge'] ?? []
     */
    public static function socketPathFromConfig(array $bridgeConfig): string
    {
        $raw = $bridgeConfig['socket_path'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : self::defaultSocketPath();
    }

    /**
     * Is the bridge enabled for this config block? On unless explicitly disabled
     * (`'enabled' => false` / `SYNCPLAY_BRIDGE=0`), so a bare dev config still
     * gets the write-through rail (a missing listener is a cheap, logged no-op).
     *
     * @param array<string, mixed> $bridgeConfig
     */
    public static function isEnabled(array $bridgeConfig): bool
    {
        $raw = $bridgeConfig['enabled'] ?? true;

        return $raw !== false;
    }

    /**
     * Build one bridge frame envelope.
     *
     * @param string $op        one of self::OP_*
     * @param array<string, mixed> $payload op-specific body ('group' or 'group_id')
     * @param int|null $issuedAtMs monotonic-ish publish stamp (ms); defaults to now
     * @return array<string, mixed>
     */
    public static function frame(string $op, array $payload, ?int $issuedAtMs = null): array
    {
        // Envelope keys are written LAST so a payload can never shadow them.
        $frame = $payload;
        $frame['op'] = $op;
        $frame['bridge_version'] = self::VERSION;
        $frame['token'] = self::TOKEN;
        $frame['issued_at_ms'] = $issuedAtMs ?? (int) round(microtime(true) * 1000);

        return $frame;
    }

    /**
     * Encode a frame as one NDJSON line (trailing newline included).
     *
     * @param array<string, mixed> $frame
     */
    public static function encode(array $frame): string
    {
        return json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Parse and validate one received NDJSON line.
     *
     * Parse-don't-validate: everything the application sees downstream is already
     * typed. Returns null for ANY malformed or unauthorized line (the caller
     * logs/drops); a null here is never a partial-trust frame.
     *
     * @return array{op: string, issued_at_ms: int, group?: array<string, mixed>, group_id?: string}|null
     */
    public static function parse(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || strlen($line) > self::MAX_LINE_BYTES) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $op = $decoded['op'] ?? null;
        if (!is_string($op) || ($op !== self::OP_GROUP_UPSERT && $op !== self::OP_GROUP_DELETE)) {
            return null;
        }

        if (($decoded['bridge_version'] ?? null) !== self::VERSION) {
            return null;
        }

        $token = $decoded['token'] ?? null;
        if (!is_string($token) || !hash_equals(self::TOKEN, $token)) {
            return null;
        }

        $issuedAtMs = $decoded['issued_at_ms'] ?? null;
        if (!is_int($issuedAtMs) || $issuedAtMs < 0) {
            return null;
        }

        if ($op === self::OP_GROUP_DELETE) {
            $groupId = $decoded['group_id'] ?? null;
            if (!is_string($groupId) || $groupId === '') {
                return null;
            }

            return ['op' => $op, 'issued_at_ms' => $issuedAtMs, 'group_id' => $groupId];
        }

        $group = $decoded['group'] ?? null;
        if (!is_array($group) || !isset($group['id']) || !is_string($group['id']) || $group['id'] === '') {
            return null;
        }

        // GroupState::serialize() is a JSON object, so the decoded assoc array's
        // top-level keys are strings; the shape is validated by the caller's
        // deserializer (GroupState::deserialize) on apply.
        /** @var array<string, mixed> $groupState */
        $groupState = $group;

        return ['op' => $op, 'issued_at_ms' => $issuedAtMs, 'group' => $groupState];
    }

    /** Never instantiated - static envelope utility. */
    private function __construct()
    {
    }
}
