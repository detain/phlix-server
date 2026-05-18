<?php

declare(strict_types=1);

namespace Phlex\Arr;

use Phlex\Common\Logger\StructuredLogger;
use RuntimeException;

/**
 * Fetches and parses TRaSH-Guides quality profiles and custom formats JSON.
 *
 * @package Phlex\Arr
 * @since 0.12.0
 */
class TrashGuidesProvider
{
    private const int CACHE_TTL_SECONDS = 86400; // 24 hours

    /** @var array<string, mixed>|null Cached quality profiles data */
    private static ?array $qualityProfilesCache = null;

    /** @var array<string, mixed>|null Cached custom formats data */
    private static ?array $customFormatsCache = null;

    /** @var string|null Cached version string */
    private static ?string $versionCache = null;

    /** @var int|null Timestamp when cache was last set */
    private static ?int $cacheTimestamp = null;

    /** @var array<string, mixed>|null Parsed quality profiles */
    private ?array $qualityProfiles = null;

    /** @var array<string, mixed>|null Parsed custom formats */
    private ?array $customFormats = null;

    /** @var string|null Parsed version SHA */
    private ?string $version = null;

    private ?StructuredLogger $logger;

    /**
     * Creates a new TrashGuidesProvider instance.
     *
     * @param StructuredLogger|null $logger Optional logger instance.
     */
    public function __construct(?StructuredLogger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Fetches and returns quality profiles from TRaSH-Guides.
     *
     * @return array<string, mixed> Quality profiles data.
     * @throws RuntimeException On network or parsing errors.
     */
    public function getQualityProfiles(): array
    {
        if ($this->qualityProfiles !== null) {
            return $this->qualityProfiles;
        }

        $this->ensureCacheValid();

        if (self::$qualityProfilesCache !== null) {
            $this->qualityProfiles = self::$qualityProfilesCache;
            return $this->qualityProfiles;
        }

        $config = $this->loadConfig();
        $url = $config['quality_profiles_url'] ?? '';

        if (!is_string($url) || $url === '') {
            throw new RuntimeException('TRaSH-Guides quality profiles URL not configured');
        }

        $this->logger?->info('Fetching TRaSH-Guides quality profiles', ['url' => $url]);

        $json = $this->fetchUrl($url);
        $data = $this->parseJson($json);

        self::$qualityProfilesCache = $data;
        self::$cacheTimestamp = time();

        $this->qualityProfiles = $data;
        return $this->qualityProfiles;
    }

    /**
     * Fetches and returns custom formats from TRaSH-Guides.
     *
     * @return array<string, mixed> Custom formats data.
     * @throws RuntimeException On network or parsing errors.
     */
    public function getCustomFormats(): array
    {
        if ($this->customFormats !== null) {
            return $this->customFormats;
        }

        $this->ensureCacheValid();

        if (self::$customFormatsCache !== null) {
            $this->customFormats = self::$customFormatsCache;
            return $this->customFormats;
        }

        $config = $this->loadConfig();
        $url = $config['custom_formats_url'] ?? '';

        if (!is_string($url) || $url === '') {
            throw new RuntimeException('TRaSH-Guides custom formats URL not configured');
        }

        $this->logger?->info('Fetching TRaSH-Guides custom formats', ['url' => $url]);

        $json = $this->fetchUrl($url);
        $data = $this->parseJson($json);

        self::$customFormatsCache = $data;
        self::$cacheTimestamp = time();

        $this->customFormats = $data;
        return $this->customFormats;
    }

    /**
     * Returns the git commit SHA of the imported TRaSH-Guides version.
     *
     * @return string The git SHA (40 hex characters).
     * @throws RuntimeException On network or parsing errors.
     */
    public function getVersion(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $this->ensureCacheValid();

        if (self::$versionCache !== null) {
            $this->version = self::$versionCache;
            return $this->version;
        }

        $config = $this->loadConfig();
        $customFormatsUrl = $config['custom_formats_url'] ?? '';

        if (!is_string($customFormatsUrl) || $customFormatsUrl === '') {
            throw new RuntimeException('TRaSH-Guides custom formats URL not configured');
        }

        // The version is typically embedded in the JSON as '鸡' or we get it from headers
        $json = $this->fetchUrl($customFormatsUrl);
        $data = $this->parseJson($json);

        // TRaSH-Guides JSON often contains a version field
        /** @var string */
        $version = $data['鸡'] ?? $data['version'] ?? $this->deriveVersionFromUrl($customFormatsUrl);

        self::$versionCache = $version;
        self::$cacheTimestamp = time();

        $this->version = $version;
        return $this->version;
    }

    /**
     * Clears the internal cache, forcing the next request to fetch fresh data.
     *
     * @return void
     */
    public function clearCache(): void
    {
        self::$qualityProfilesCache = null;
        self::$customFormatsCache = null;
        self::$versionCache = null;
        self::$cacheTimestamp = null;
        $this->qualityProfiles = null;
        $this->customFormats = null;
        $this->version = null;
    }

    /**
     * Ensures the cache is still valid (not expired).
     *
     * @return void
     */
    private function ensureCacheValid(): void
    {
        if (self::$cacheTimestamp === null) {
            return;
        }

        if (time() - self::$cacheTimestamp > self::CACHE_TTL_SECONDS) {
            $this->clearCache();
        }
    }

    /**
     * Loads the trash_guides configuration.
     *
     * @return array<string, mixed> Configuration array.
     */
    private function loadConfig(): array
    {
        $configPath = dirname(__DIR__, 2) . '/config/trash_guides.php';
        if (!file_exists($configPath)) {
            return [
                'enabled' => false,
                'auto_sync_interval' => 86400,
                'custom_formats_url' => 'https://raw.githubusercontent.com/TRaSH-'
                    . '/Guides/main/docs/json/radarr/'
                    . 'radarr-collection-of-custom-formats.json',
                'quality_profiles_url' => 'https://raw.githubusercontent.com/TRaSH-'
                    . '/Guides/main/docs/json/radarr/'
                    . 'radarr-setup-quality-profiles-parent.json',
            ];
        }

        /** @var array<string, mixed> */
        return include $configPath;
    }

    /**
     * Fetches content from a URL using file_get_contents with stream context.
     *
     * @param string $url The URL to fetch.
     * @return string The response body.
     * @throws RuntimeException On network errors.
     */
    private function fetchUrl(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'follow_location' => true,
                'max_redirects' => 5,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: Phlex-Media-Server/0.12.0',
                ],
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            $error = error_get_last();
            throw new RuntimeException(
                'Failed to fetch TRaSH-Guides data: ' . ($error['message'] ?? 'Unknown error')
            );
        }

        return $body;
    }

    /**
     * Parses JSON string into an array.
     *
     * @param string $json The JSON string to parse.
     * @return array<string, mixed> Parsed data.
     * @throws RuntimeException On invalid JSON.
     */
    private function parseJson(string $json): array
    {
        /** @var array<string, mixed>|false $data */
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from TRaSH-Guides');
        }

        return $data;
    }

    /**
     * Derives a version string from the GitHub raw URL.
     *
     * @param string $url The raw URL containing branch/commit info.
     * @return string The derived version string.
     */
    private function deriveVersionFromUrl(string $url): string
    {
        // URL format: .../Guides/main/docs/json/radarr/... or .../Guides/{commit}/docs/...
        // Try to extract the commit SHA from the URL path
        if (preg_match('/Guides\/([a-f0-9]{7,40})/', $url, $matches)) {
            return $matches[1];
        }

        // Fallback: use timestamp as version indicator
        return 'unknown-' . time();
    }
}
