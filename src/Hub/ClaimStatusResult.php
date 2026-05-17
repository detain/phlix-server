<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Polling result DTO for checking claim status.
 *
 * @description Represents the status of a device claim operation, returned
 *             during the claim polling process with optional enrollment details.
 */
final class ClaimStatusResult
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_EXPIRED = 'expired';

    /**
     * @param string      $status        Current claim status (pending/claimed/expired)
     * @param string|null $enrollmentJwt JWT for authenticated enrollment (set when claimed)
     * @param string|null $hubJwksUrl    URL to the Hub's JWKS endpoint (set when claimed)
     * @param string|null $serverId      Unique server identifier (set when claimed)
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $enrollmentJwt = null,
        public readonly ?string $hubJwksUrl = null,
        public readonly ?string $serverId = null,
    ) {
    }

    /**
     * Checks if the claim is in pending state.
     *
     * @return bool True if status is pending, false otherwise
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Checks if the claim has been successfully claimed.
     *
     * @return bool True if status is claimed, false otherwise
     */
    public function isClaimed(): bool
    {
        return $this->status === self::STATUS_CLAIMED;
    }

    /**
     * Checks if the claim has expired.
     *
     * @return bool True if status is expired, false otherwise
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }
}
