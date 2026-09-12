<?php

/**
 * Phlix media server component: SyncPlay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Session\SyncPlay;

use Phlix\Common\Logger\StructuredLogger;

/**
 * SyncPlayBridgePublisher - the HTTP-side half of the S445 write-through
 * publish transport.
 *
 * Called by {@see \Phlix\Server\Http\Controllers\SyncPlayController} AFTER the
 * mutation has been durably persisted to `syncplay_snapshots` (write-through
 * ordering: persist first, publish second). Each call opens one short-lived
 * connection to the private unix socket, writes exactly one NDJSON frame, and
 * closes — so 14 concurrent HTTP workers can never interleave inside a frame
 * and there is no persistent-connection state to fail over.
 *
 * ## Bounded & non-fatal by design
 *
 * Publish is ONE connect (capped at `publish_timeout_ms`, default 250 ms)
 * plus ONE `fwrite` of a frame that is hard-capped at
 * {@see SyncPlayBridge::MAX_PUBLISH_FRAME_BYTES} — smaller than the empty
 * kernel unix-stream buffers on the supported (Linux) venue, so the single
 * write ALWAYS completes in-kernel without ever waiting on the peer
 * (measured: a fresh connection absorbed 219 264 bytes in one fwrite with
 * the listener never polling). This shape is deliberate: a select-retry
 * partial-write loop was proven to HANG under the swoole SWOOLE_HOOK_UNIX
 * runtime hook when a wedged listener's receive buffer is full — the second
 * fwrite blocks despite non-blocking mode (the register entry in
 * docs/dev/BLOCKING_IO_EXCEPTIONS.md carries the measurement). Oversized
 * frames are refused (logged false), never chunked; durability lives in the
 * snapshot store regardless, and a missing listener costs one instant
 * ENOENT connect. The REST response is NEVER degraded by bridge failure —
 * fire-and-forget loss posture per docs/dev/SYNCPLAY_WRITE_THROUGH_BRIDGE.md.
 *
 * @package Phlix\Session\SyncPlay
 * @since 3.5
 */
final class SyncPlayBridgePublisher
{
    private string $socketPath;

    private int $timeoutMs;

    private ?StructuredLogger $logger;

    /**
     * @param string|null $socketPath bridge socket path; null → SyncPlayBridge::defaultSocketPath()
     * @param int         $timeoutMs  hard budget for connect+full-write of one frame
     * @param StructuredLogger|null $logger channel to warn on publish failure
     */
    public function __construct(?string $socketPath = null, int $timeoutMs = 250, ?StructuredLogger $logger = null)
    {
        $this->socketPath = $socketPath ?? SyncPlayBridge::defaultSocketPath();
        $this->timeoutMs = max(1, $timeoutMs);
        $this->logger = $logger;
    }

    /**
     * Publish the current state of one group (upsert facet merge on the WS side).
     *
     * @param GroupState $group the persisted group state to mirror
     * @return bool true when the frame was fully written to the socket
     */
    public function publishUpsert(GroupState $group): bool
    {
        return $this->publish(SyncPlayBridge::frame(
            SyncPlayBridge::OP_GROUP_UPSERT,
            ['group' => $group->serialize()]
        ));
    }

    /**
     * Publish the deletion of one group (tombstone on the WS side).
     *
     * @param string $groupId group id that no longer exists
     * @return bool true when the frame was fully written to the socket
     */
    public function publishDelete(string $groupId): bool
    {
        return $this->publish(SyncPlayBridge::frame(
            SyncPlayBridge::OP_GROUP_DELETE,
            ['group_id' => $groupId]
        ));
    }

    /**
     * Write one frame as a single NDJSON line on a short-lived connection.
     *
     * @param array<string, mixed> $frame
     */
    private function publish(array $frame): bool
    {
        try {
            $line = SyncPlayBridge::encode($frame);
        } catch (\JsonException $e) {
            $this->warn('bridge frame encode failed', ['error' => $e->getMessage()]);

            return false;
        }

        if (strlen($line) > SyncPlayBridge::MAX_PUBLISH_FRAME_BYTES) {
            // Refuse, never chunk: a partial write could block the publisher
            // (see class docblock — measured). The snapshot row already holds
            // the truth; the group self-heals on a later smaller frame.
            $this->warn('bridge frame exceeds the publish cap; refused', [
                'bytes' => strlen($line),
                'cap' => SyncPlayBridge::MAX_PUBLISH_FRAME_BYTES,
                'op' => $frame['op'] ?? null,
            ]);

            return false;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            $this->timeoutMs / 1000.0
        );

        if ($socket === false) {
            $this->warn('bridge publish failed (connect)', [
                'socket' => $this->socketPath,
                'errno' => $errno,
                'error' => $errstr,
                'op' => $frame['op'] ?? null,
            ]);

            return false;
        }

        try {
            // Best-effort flag for venues without the swoole unix hook; the
            // bound does NOT rely on it — see the single-write rationale above.
            @stream_set_blocking($socket, false);

            $written = @fwrite($socket, $line);
            if ($written !== strlen($line)) {
                $this->warn('bridge publish failed (write)', [
                    'socket' => $this->socketPath,
                    'op' => $frame['op'] ?? null,
                    'written' => $written,
                ]);

                return false;
            }

            return true;
        } finally {
            @fclose($socket);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warn(string $message, array $context): void
    {
        $this->logger?->warning("[SyncPlayBridge] {$message}", $context);
    }
}
