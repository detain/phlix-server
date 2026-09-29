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
 * same-user supervisor topology — the master forks all workers) plus a
 * per-boot shared secret token echoed in every frame and constant-time
 * checked on receipt (MED-1(1): resolved once at boot — config token →
 * `PHLIX_SYNCPLAY_BRIDGE_TOKEN` env → fresh random bytes — and pinned
 * pre-fork by start.php so every forked worker inherits the same secret;
 * {@see self::initToken()}).
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
     * Per-boot shared secret every bridge frame must carry (MED-1(1)).
     *
     * This REPLACES the old compile-time constant — a secret published in the
     * source of every repo reader made the hash_equals check theater rather
     * than defense. Resolution order ({@see self::initToken()}, called
     * pre-fork from start.php so all forked workers inherit one value):
     *   1. explicit config value  — $appConfig['syncplay_bridge']['token']
     *   2. env PHLIX_SYNCPLAY_BRIDGE_TOKEN
     *   3. fresh random 32-hex-char secret (per-process)
     *
     * The unix socket's 0600 permissions remain the PRIMARY gate; this token
     * is the defense-in-depth tripwire against misconfiguration
     * (world-writable var/, wrong-socket wiring, a second checkout aimed at
     * the same path) — and post-MED-1 it is a tripwire a source-reading
     * attacker cannot pass. A token mismatch FAILS CLOSED: parse() drops the
     * frame and the listener logs a warning.
     */
    private static ?string $token = null;

    /** @var bool One-shot guard for the loud lazy-boot warning in token(). */
    private static bool $lazyWarned = false;

    /**
     * Resolve and pin this process's bridge token. IDEMPOTENT: once pinned,
     * later calls never overwrite it — that is what makes the master→worker
     * fork inheritance and any defensive per-worker re-call order-insensitive.
     *
     * @param string|null $configured Explicit token (config value); null falls
     *                                back to the env var, then to fresh randoms.
     * @return string The pinned token
     */
    public static function initToken(?string $configured = null): string
    {
        if (self::$token !== null) {
            return self::$token;
        }

        $env = getenv('PHLIX_SYNCPLAY_BRIDGE_TOKEN');
        $fromEnv = is_string($env) && $env !== '' ? $env : null;

        self::$token = $configured ?? $fromEnv ?? bin2hex(random_bytes(16));

        return self::$token;
    }

    /**
     * The pinned token. Lazily self-boots (random + ONE-TIME loud stderr
     * warning) when the entry point forgot initToken(): a lazily generated
     * secret is process-local, so cross-process bridge traffic then fails
     * CLOSED on the token mismatch — degraded (frames dropped, warnings
     * logged, REST rail still durable-persists) but never insecure. Operators
     * of split topologies must set syncplay_bridge.token or
     * PHLIX_SYNCPLAY_BRIDGE_TOKEN so publisher and listener converge.
     */
    public static function token(): string
    {
        if (self::$token === null) {
            $token = self::initToken();
            if (!self::$lazyWarned) {
                self::$lazyWarned = true;
                error_log(
                    '[phlix] SyncPlayBridge: initToken() was never called at boot; generated a process-local'
                    . ' random bridge token. Cross-process bridge traffic will fail closed until fixed — boot'
                    . ' via start.php or set syncplay_bridge.token / PHLIX_SYNCPLAY_BRIDGE_TOKEN.'
                );
            }

            return $token;
        }

        return self::$token;
    }

    /**
     * Forget the pinned token so a test can re-run boot resolution.
     *
     * @internal Test seam only — production code must never call this.
     */
    public static function resetTokenForTesting(): void
    {
        self::$token = null;
        self::$lazyWarned = false;
    }

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
        $frame['token'] = self::token();
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
        if (!is_string($token) || !hash_equals(self::token(), $token)) {
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
