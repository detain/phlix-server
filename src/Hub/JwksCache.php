<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * In-memory JWKS cache with TTL support and fetch-rate bookkeeping.
 *
 * Caches JWK objects keyed by kid (key ID) with a configurable
 * time-to-live. Supports invalidation for key rotation scenarios.
 *
 * Expired entries are retained (served only through {@see getStale()}) so a
 * validator under a forged-unknown-kid flood can keep verifying legitimately
 * rotated keys while its refetch is rate-limited.
 *
 * @package Phlix\Hub
 * @since 0.11.0
 */
final class JwksCache
{
    /** @var array<string, array{jwk: array<string, mixed>, expires_at: int}> Cache entries keyed by kid */
    private array $cache = [];

    /** @var int Cache TTL in seconds (default 900 = 15 minutes) */
    private int $ttl;

    /** @var int Unix timestamp of the last recorded JWKS fetch attempt; 0 = never. */
    private int $lastFetchAttemptAt = 0;

    /**
     * Creates a new JwksCache.
     *
     * @param int $ttl Cache TTL in seconds (default 900).
     */
    public function __construct(int $ttl = 900)
    {
        $this->ttl = $ttl;
    }

    /**
     * Gets a cached JWK by key ID.
     *
     * Returns null if the key is not cached OR if the cached entry
     * has expired. Expired entries are retained (not purged on read) so
     * {@see getStale()} can still serve them while a refetch is cooling down.
     *
     * @param string $kid The key ID to look up.
     *
     * @return array<string, mixed>|null The JWK array, or null if not found/expired.
     */
    public function get(string $kid): ?array
    {
        $entry = $this->cache[$kid] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expires_at'] <= time()) {
            return null;
        }

        return $entry['jwk'];
    }

    /**
     * Gets a cached JWK by key ID, ignoring its expiry.
     *
     * Used by the validator as the cooldown fallback: serving the last-known
     * key for an already-seen kid beats dropping every request while the
     * refetch is rate-limited (a key rotation that already happened on the
     * hub keeps validating until the next successful fetch refreshes it).
     *
     * @param string $kid The key ID to look up.
     *
     * @return array<string, mixed>|null The JWK array if ever cached, else null.
     */
    public function getStale(string $kid): ?array
    {
        return $this->cache[$kid]['jwk'] ?? null;
    }

    /**
     * Records that a JWKS fetch attempt was just made (success or failure).
     *
     * @return void
     */
    public function noteFetchAttempt(): void
    {
        $this->lastFetchAttemptAt = time();
    }

    /**
     * Whether a fetch attempt happened within the last $cooldownSeconds.
     *
     * A cooldown of 0 or less disables rate-limiting (always false), and a
     * cache that never recorded an attempt is never cooling down.
     *
     * @param int $cooldownSeconds Minimum seconds between refetch attempts.
     *
     * @return bool True while the cooldown window is still open.
     */
    public function isFetchCoolingDown(int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0 || $this->lastFetchAttemptAt === 0) {
            return false;
        }

        return (time() - $this->lastFetchAttemptAt) < $cooldownSeconds;
    }

    /**
     * Stores a JWK in the cache with the configured TTL.
     *
     * @param string $kid The key ID.
     * @param array<string, mixed> $jwk The JWK array to cache.
     *
     * @return void
     */
    public function set(string $kid, array $jwk): void
    {
        $this->cache[$kid] = [
            'jwk' => $jwk,
            'expires_at' => time() + $this->ttl,
        ];
    }

    /**
     * Invalidates all cached JWKS.
     *
     * Used when a key rotation is detected or when explicit
     * cache invalidation is required.
     *
     * @return void
     */
    public function invalidate(): void
    {
        $this->cache = [];
    }

    /**
     * Returns all cached JWKS.
     *
     * @return array<string, array<string, mixed>> All cached JWKs (excludes expired).
     */
    public function getAll(): array
    {
        $now = time();
        $result = [];

        foreach ($this->cache as $kid => $entry) {
            if ($entry['expires_at'] > $now) {
                $result[$kid] = $entry['jwk'];
            }
        }

        return $result;
    }
}
