<?php

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * In-memory JWKS cache with TTL support.
 *
 * Caches JWK objects keyed by kid (key ID) with a configurable
 * time-to-live. Supports invalidation for key rotation scenarios.
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
     * has expired. Expired entries are not automatically purged on
     * read but will return null.
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
            unset($this->cache[$kid]);
            return null;
        }

        return $entry['jwk'];
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
