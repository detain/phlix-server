<?php

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * Loaded enrollment DTO representing an established Hub enrollment.
 *
 * @description Contains all data related to a successful Hub enrollment,
 *             including the enrollment JWT, Hub endpoints, server ID, and
 *             timestamp for determining expiration.
 */
final class StoredEnrollment
{
    /**
     * @param string $enrollmentJwt JWT string used for authenticated Hub requests
     * @param string $hubJwksUrl   URL to the Hub's JWKS endpoint for token verification
     * @param string $serverId      Unique identifier for this server in the Hub
     * @param string $hubBaseUrl   Base URL of the Hub service
     * @param int    $enrolledAt   Unix timestamp when enrollment was created
     */
    public function __construct(
        public readonly string $enrollmentJwt,
        public readonly string $hubJwksUrl,
        public readonly string $serverId,
        public readonly string $hubBaseUrl,
        public readonly int $enrolledAt,
    ) {
    }

    /**
     * Checks if the enrollment has expired based on the enrollment timestamp.
     *
     * @description Enrollment is considered expired if it was created more than
     *             7 days ago (604800 seconds). This is a simplified check that
     *             assumes JWT expiration is handled separately.
     *
     * @return bool True if the enrollment appears expired, false otherwise
     */
    public function isExpired(): bool
    {
        $expirationThreshold = 7 * 24 * 60 * 60; // 7 days in seconds

        return (time() - $this->enrolledAt) > $expirationThreshold;
    }
}
