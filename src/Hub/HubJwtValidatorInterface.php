<?php

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * Interface for hub JWT validation.
 *
 * Allows interchangeable implementations for production use
 * (HubJwtValidator) and testing scenarios.
 *
 * @package Phlix\Hub
 * @since 0.11.0
 */
interface HubJwtValidatorInterface
{
    /**
     * Validates a hub-issued JWT and returns the extracted claims.
     *
     * @param string $jwt The raw JWT string to validate.
     *
     * @return HubUserClaims|null The validated claims, or null if the token is invalid.
     */
    public function validate(string $jwt): ?HubUserClaims;

    /**
     * Forces a refresh of the cached JWKS.
     *
     * @return void
     */
    public function refreshJwks(): void;
}
