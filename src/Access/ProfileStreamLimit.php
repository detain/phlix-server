<?php

/**
 * Phlix media server component: Access.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Access;

/**
 * Represents the stream limit settings for a profile.
 *
 * Defines the maximum number of concurrent streams and optionally
 * a total bandwidth cap for a given profile.
 *
 * @package Phlix\Access
 */
final class ProfileStreamLimit
{
    /**
     * Create a new ProfileStreamLimit instance.
     *
     * @param string  $profileId            The profile ID (UUID) this limit belongs to.
     * @param int  $maxConcurrentStreams Maximum concurrent streams allowed.
     * @param int|null $maxTotalBandwidthKbps Maximum total bandwidth in kbps, or null for unlimited.
     */
    public function __construct(
        public readonly string $profileId,
        public readonly int $maxConcurrentStreams,
        public readonly ?int $maxTotalBandwidthKbps = null,
    ) {
    }

    /**
     * Create a ProfileStreamLimit from a database row.
     *
     * @param array<string, mixed> $row Raw database row with keys:
     *                                   profile_id, max_concurrent_streams,
     *                                   max_total_bandwidth_kbps.
     *
     * @return self
     */
    public static function fromRow(array $row): self
    {
        $profileId = isset($row['profile_id']) && is_string($row['profile_id']) ? $row['profile_id'] : '';
        $maxConcurrentStreams = isset($row['max_concurrent_streams']) && is_numeric($row['max_concurrent_streams'])
            ? (int) $row['max_concurrent_streams']
            : 1;
        $maxTotalBandwidthKbps = null;
        if (isset($row['max_total_bandwidth_kbps']) && is_numeric($row['max_total_bandwidth_kbps'])) {
            $maxTotalBandwidthKbps = (int) $row['max_total_bandwidth_kbps'];
        }

        return new self(
            profileId: $profileId,
            maxConcurrentStreams: $maxConcurrentStreams,
            maxTotalBandwidthKbps: $maxTotalBandwidthKbps,
        );
    }

    /**
     * Convert the profile stream limit to an array representation.
     *
     * @return array{
     *     profile_id: string,
     *     max_concurrent_streams: int,
     *     max_total_bandwidth_kbps: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'profile_id' => $this->profileId,
            'max_concurrent_streams' => $this->maxConcurrentStreams,
            'max_total_bandwidth_kbps' => $this->maxTotalBandwidthKbps,
        ];
    }
}
