<?php

/**
 * Phlix media server component: TimeShift.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\TimeShift;

use Phlix\Common\Util\RowMap;
use Phlix\Common\Uuid;

/**
 * TimeShiftSession value object: an active Live TV time-shift (pause/rewind)
 * session backed by a rolling on-disk buffer.
 *
 * Replaces the ephemeral per-worker entry in Recorder::$activeTimeShifts with a
 * durable, cross-worker row (see {@see DbTimeShiftSessionStore}). Time fields are
 * epoch seconds to match Recorder.php's time()-based bookkeeping.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Immutable value object for a Live TV time-shift session
 * @since 0.45.0
 * @see DbTimeShiftSessionStore For persistence
 */
final class TimeShiftSession
{
    /** Session is live: the rolling buffer is being written. */
    public const string STATUS_ACTIVE = 'active';

    /** Session has been torn down; the buffer is no longer advancing. */
    public const string STATUS_STOPPED = 'stopped';

    /**
     * @param string      $id             Unique time-shift session id (CHAR(36) UUID PK)
     * @param string      $session_id     Owning playback session id (URL route key)
     * @param string      $channel_id     The channel being time-shifted
     * @param string      $buffer_dir     Filesystem path to the rolling buffer directory
     * @param int         $buffer_start_at Epoch of the oldest buffered second
     * @param int         $buffer_end_at   Epoch of the newest buffered second (live edge)
     * @param int         $window_seconds  Maximum buffer window in seconds
     * @param int         $cursor_position Playback cursor offset within the buffer (seconds)
     * @param int|null    $pid            Detached ffmpeg capture PID (null until spawned)
     * @param string      $status         One of the STATUS_* constants
     * @param string|null $created_at     DB-managed creation timestamp (read-back only)
     * @param string|null $updated_at     DB-managed update timestamp (read-back only)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $session_id,
        public readonly string $channel_id,
        public readonly string $buffer_dir,
        public readonly int $buffer_start_at,
        public readonly int $buffer_end_at,
        public readonly int $window_seconds,
        public readonly int $cursor_position = 0,
        public readonly ?int $pid = null,
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly ?string $created_at = null,
        public readonly ?string $updated_at = null,
    ) {
    }

    /**
     * Create a fresh active session for a channel, generating a new id and
     * seeding the buffer window from the current time.
     *
     * @param string $session_id     Owning playback session id
     * @param string $channel_id     The channel to time-shift
     * @param string $buffer_dir     Filesystem path to the rolling buffer directory
     * @param int    $window_seconds Maximum buffer window in seconds
     * @return self A fresh active session at cursor 0 with no capture pid yet
     */
    public static function start(
        string $session_id,
        string $channel_id,
        string $buffer_dir,
        int $window_seconds,
    ): self {
        $now = time();

        return new self(
            id: Uuid::v4(),
            session_id: $session_id,
            channel_id: $channel_id,
            buffer_dir: $buffer_dir,
            buffer_start_at: $now,
            buffer_end_at: $now,
            window_seconds: $window_seconds,
            cursor_position: 0,
            pid: null,
            status: self::STATUS_ACTIVE,
        );
    }

    /**
     * Hydrate a session from a database row.
     *
     * @param array<string, mixed> $row A `livetv_timeshift_sessions` row map
     * @return self
     */
    public static function fromRow(array $row): self
    {
        $map = RowMap::fromMixed($row);

        $pidRaw = $map['pid'] ?? null;
        $createdAt = $map['created_at'] ?? null;
        $updatedAt = $map['updated_at'] ?? null;

        return new self(
            id: self::asString($map['id'] ?? ''),
            session_id: self::asString($map['session_id'] ?? ''),
            channel_id: self::asString($map['channel_id'] ?? ''),
            buffer_dir: self::asString($map['buffer_dir'] ?? ''),
            buffer_start_at: self::asInt($map['buffer_start_at'] ?? 0),
            buffer_end_at: self::asInt($map['buffer_end_at'] ?? 0),
            window_seconds: self::asInt($map['window_seconds'] ?? 0),
            cursor_position: self::asInt($map['cursor_position'] ?? 0),
            pid: is_numeric($pidRaw) ? (int) $pidRaw : null,
            status: self::asString($map['status'] ?? self::STATUS_ACTIVE),
            created_at: is_scalar($createdAt) ? (string) $createdAt : null,
            updated_at: is_scalar($updatedAt) ? (string) $updatedAt : null,
        );
    }

    /**
     * @return array<string, mixed> A summary array of the session
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'channel_id' => $this->channel_id,
            'buffer_dir' => $this->buffer_dir,
            'buffer_start_at' => $this->buffer_start_at,
            'buffer_end_at' => $this->buffer_end_at,
            'window_seconds' => $this->window_seconds,
            'cursor_position' => $this->cursor_position,
            'pid' => $this->pid,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @param mixed $value Raw column value
     * @return string
     */
    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed $value Raw column value
     * @return int
     */
    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
