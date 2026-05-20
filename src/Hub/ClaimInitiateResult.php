<?php

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * Result DTO for the claim initiation process.
 *
 * @description Contains the data returned when initiating a Hub claim flow,
 *             including the claim code, expiration time, claim ID, and Hub URL.
 */
final class ClaimInitiateResult
{
    /**
     * @param string $claimCode   The human-readable claim code for device pairing
     * @param int    $expiresIn   Time in seconds until the claim code expires
     * @param string $claimId     Unique identifier for this claim request
     * @param string $hubBaseUrl  Base URL of the Hub service for subsequent requests
     */
    public function __construct(
        public readonly string $claimCode,
        public readonly int $expiresIn,
        public readonly string $claimId,
        public readonly string $hubBaseUrl,
    ) {
    }
}
