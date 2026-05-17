<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Immutable DTO containing the extracted claims from a hub-issued JWT.
 *
 * These claims are extracted after cryptographic validation of the
 * hub JWT and represent the user identity and authorization context
 * granted by the hub.
 *
 * @package Phlex\Hub
 * @since 0.11.0
 */
final class HubUserClaims
{
    /**
     * Creates a new HubUserClaims instance.
     *
     * @param string $userId    The user's ID on the hub (the hub_user_id claim).
     * @param string $serverId The server ID this token is scoped to.
     * @param string $subject  The JWT subject (same as userId for user tokens).
     * @param string $issuer   The JWT issuer (must be 'phlex-hub').
     * @param int    $expiresAt Unix timestamp when this token expires.
     * @param array<string> $scope     Array of scope strings granted by the hub.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $serverId,
        public readonly string $subject,
        public readonly string $issuer,
        public readonly int $expiresAt,
        public readonly array $scope = [],
    ) {
    }

    /**
     * Checks whether the token has expired.
     *
     * @return bool True if the token's expiration time is in the past.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt < time();
    }

    /**
     * Checks whether this token has a specific scope.
     *
     * @param string $scope The scope string to check for.
     *
     * @return bool True if the scope is present in the token's scopes.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scope, true);
    }
}
