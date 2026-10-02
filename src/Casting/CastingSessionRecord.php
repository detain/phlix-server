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
 * One parsed `casting_sessions` row (Device-M1).
 *
 * The store parses at the boundary: by the time a manager holds this object,
 * `state` is a decoded array and every scalar is typed. A row whose `state`
 * column is not a JSON object never becomes a record — the store throws
 * {@see \RuntimeException} instead, and the manager treats that as a corrupt
 * row (delete + honest 404), because a session nobody can rebuild is dead
 * weight regardless of how it got that way.
 *
 * ## What `state` may contain (and what it must never)
 *
 * Only what re-attaching a control client to the SAME device requires: the
 * device endpoint fields (host/port/URL and the display metadata the status
 * responses echo) and the media URL the session reports progress against.
 * The four casting transports carry no credentials in their clients — LAN
 * devices are addressed, not authenticated — so there is nothing secret to
 * leak here by construction, and nothing secret may ever be added.
 *
 * @package Phlix\Casting
 * @since 1.5.0
 */
final readonly class CastingSessionRecord
{
    /**
     * @param string $sessionId UUID of the casting session
     * @param string $type One of {@see CastingSessionStoreInterface::TYPE_*}
     * @param string $deviceId Device identifier the manager keys its map on
     * @param string $userId Owning (authenticated) user — every control op must match it
     * @param array<string, mixed> $state Re-attach payload, see class docblock
     * @param string $createdAt DATETIME text as stored (diagnostics only)
     * @param string $lastSeenAt DATETIME text of the last touch (diagnostics only)
     */
    public function __construct(
        public string $sessionId,
        public string $type,
        public string $deviceId,
        public string $userId,
        public array $state,
        public string $createdAt,
        public string $lastSeenAt,
    ) {
    }
}
