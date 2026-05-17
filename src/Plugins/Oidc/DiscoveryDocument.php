<?php

declare(strict_types=1);

namespace Phlex\Plugins\Oidc;

use RuntimeException;

/**
 * Fetches and caches OIDC Discovery documents for 24 hours.
 *
 * The discovery document contains endpoints and capabilities of the OIDC
 * provider, including authorization, token, userinfo, and JWKS endpoints.
 *
 * @package Phlex\Plugins\Oidc
 * @since 0.11.0
 */
final class DiscoveryDocument
{
    private const CACHE_TTL = 86400;
    private const DISCOVERY_PATH = '/.well-known/openid-configuration';

    /** @var array<string, mixed>|null Cached discovery data */
    private static ?array $memoryCache = null;

    /** @var int|null Cache timestamp */
    private static ?int $cacheTimestamp = null;

    /** @var string The provider base URL */
    private string $providerUrl;

    /** @var string Path to file cache directory */
    private string $cacheDir;

    /** @var array<string, mixed>|null The parsed discovery document */
    private ?array $document = null;

    public function __construct(string $providerUrl, ?string $cacheDir = null)
    {
        $this->providerUrl = rtrim($providerUrl, '/');
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . '/phlex_oidc_cache';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get a value from the discovery document.
     *
     * @param string $key The key to look up
     * @return mixed The value or null if not found
     */
    public function get(string $key): mixed
    {
        $doc = $this->getDocument();

        return $doc[$key] ?? null;
    }

    /**
     * Get the issuer identifier from the discovery document.
     *
     * @return string The issuer URL
     * @throws RuntimeException When issuer is not found
     */
    public function issuer(): string
    {
        $issuer = $this->get('issuer');
        if ($issuer === null) {
            throw new RuntimeException('OIDC discovery document missing issuer');
        }
        if (!is_string($issuer)) {
            throw new RuntimeException('OIDC discovery document issuer is not a string');
        }
        return $issuer;
    }

    /**
     * Get the authorization endpoint URL.
     *
     * @return string The authorization endpoint URL
     * @throws RuntimeException When endpoint is not found
     */
    public function authorizationEndpoint(): string
    {
        $endpoint = $this->get('authorization_endpoint');
        if ($endpoint === null) {
            throw new RuntimeException('OIDC discovery document missing authorization_endpoint');
        }
        if (!is_string($endpoint)) {
            throw new RuntimeException('OIDC discovery document authorization_endpoint is not a string');
        }
        return $endpoint;
    }

    /**
     * Get the token endpoint URL.
     *
     * @return string The token endpoint URL
     * @throws RuntimeException When endpoint is not found
     */
    public function tokenEndpoint(): string
    {
        $endpoint = $this->get('token_endpoint');
        if ($endpoint === null) {
            throw new RuntimeException('OIDC discovery document missing token_endpoint');
        }
        if (!is_string($endpoint)) {
            throw new RuntimeException('OIDC discovery document token_endpoint is not a string');
        }
        return $endpoint;
    }

    /**
     * Get the userinfo endpoint URL.
     *
     * @return string|null The userinfo endpoint URL or null if not available
     */
    public function userinfoEndpoint(): ?string
    {
        $value = $this->get('userinfo_endpoint');
        if ($value === null) {
            return null;
        }
        return is_string($value) ? $value : null;
    }

    /**
     * Get the JWKS URI for token verification.
     *
     * @return string The JWKS URI
     * @throws RuntimeException When JWKS URI is not found
     */
    public function jwksUri(): string
    {
        $uri = $this->get('jwks_uri');
        if ($uri === null) {
            throw new RuntimeException('OIDC discovery document missing jwks_uri');
        }
        if (!is_string($uri)) {
            throw new RuntimeException('OIDC discovery document jwks_uri is not a string');
        }
        return $uri;
    }

    /**
     * Get the full discovery document, fetching and caching if necessary.
     *
     * @return array<string, mixed> The discovery document
     */
    public function getDocument(): array
    {
        if ($this->document !== null) {
            return $this->document;
        }

        $this->document = $this->loadFromCache();
        if ($this->document !== null) {
            return $this->document;
        }

        /** @var array<string, mixed> $fetched */
        $fetched = $this->fetchDiscoveryDocument();
        $this->document = $fetched;
        $this->saveToCache($this->document);

        /** @var array<string, mixed> */
        return $this->document;
    }

    /**
     * Get the provider URL.
     *
     * @return string The provider URL
     */
    public function getProviderUrl(): string
    {
        return $this->providerUrl;
    }

    /**
     * Fetch the discovery document from the OIDC provider.
     *
     * @return array<string, mixed> The discovery document
     * @throws RuntimeException When fetch fails
     */
    private function fetchDiscoveryDocument(): array
    {
        $url = $this->providerUrl . self::DISCOVERY_PATH;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new RuntimeException('Failed to fetch OIDC discovery document from: ' . $url);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OIDC discovery document is not valid JSON');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * Try to load the discovery document from cache.
     *
     * @return array<string, mixed>|null The cached document or null if cache is stale/missing
     */
    private function loadFromCache(): ?array
    {
        if (self::$memoryCache !== null && self::$cacheTimestamp !== null) {
            if ((time() - self::$cacheTimestamp) < self::CACHE_TTL) {
                return self::$memoryCache;
            }
        }

        $cacheFile = $this->getCacheFilePath();
        if (!is_file($cacheFile)) {
            return null;
        }

        $cacheData = @file_get_contents($cacheFile);
        if ($cacheData === false) {
            return null;
        }

        $cached = json_decode($cacheData, true);
        if (!is_array($cached)) {
            return null;
        }

        $cachedAt = $cached['_cached_at'] ?? 0;
        if (!is_int($cachedAt) && !(is_string($cachedAt) && is_numeric($cachedAt))) {
            return null;
        }
        $cachedAtInt = is_string($cachedAt) ? (int) $cachedAt : $cachedAt;
        if ((time() - $cachedAtInt) > self::CACHE_TTL) {
            return null;
        }

        /** @var array<string, mixed> $cached */
        self::$memoryCache = $cached;
        self::$cacheTimestamp = $cachedAtInt;

        return $cached;
    }

    /**
     * Save the discovery document to cache.
     *
     * @param array<string, mixed> $document The document to cache
     */
    private function saveToCache(array $document): void
    {
        $document['_cached_at'] = time();
        self::$memoryCache = $document;
        self::$cacheTimestamp = time();

        $cacheFile = $this->getCacheFilePath();
        file_put_contents($cacheFile, json_encode($document));
    }

    /**
     * Get the cache file path for this provider.
     *
     * @return string The cache file path
     */
    private function getCacheFilePath(): string
    {
        $hash = md5($this->providerUrl);
        return $this->cacheDir . '/discovery_' . $hash . '.json';
    }

    /**
     * Clear the in-memory cache (for testing purposes).
     */
    public static function clearMemoryCache(): void
    {
        self::$memoryCache = null;
        self::$cacheTimestamp = null;
    }
}
