<?php

/**
 * Phlix media server component: SchedulesDirect.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Epg\SchedulesDirect;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\ChannelManager;
use Phlix\LiveTv\GuideManager;
use Psr\Log\LoggerInterface;

/**
 * Factory for building SdEpgService instances from configuration.
 *
 * Handles token caching to disk, client creation, and dependency wiring.
 *
 * @since 0.12.0
 */
final class SdEpgServiceFactory
{
    /**
     * Build a fully-wired SdEpgService from configuration.
     *
     * @param array<string, mixed> $config The schedules_direct section from livetv.php config
     * @param ChannelManager $channelManager Phlix channel manager
     * @param GuideManager $guideManager Phlix guide manager
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger
     * @return SdEpgService Configured service instance
     * @throws \RuntimeException If token cannot be obtained and auto-fetch is disabled
     */
    public static function build(
        array $config,
        ChannelManager $channelManager,
        GuideManager $guideManager,
        StructuredLogger|LoggerInterface|null $logger = null
    ): SdEpgService {
        /** @var StructuredLogger $sdLogger */
        $sdLogger = $logger instanceof StructuredLogger ? $logger : LoggerFactory::get('livetv');

        $enabled = (bool) ($config['enabled'] ?? false);

        if (!$enabled) {
            throw new \RuntimeException('Schedules Direct EPG is not enabled in configuration');
        }

        $username = self::toString($config['username'] ?? '');
        $password = self::toString($config['password'] ?? '');
        $tokenCachePath = self::toString($config['token_cache_path'] ?? '/var/phlix/sd_token.json');
        $timeoutSecs = self::toInt($config['timeout_secs'] ?? 30);

        // Try to load cached token first
        $token = self::loadCachedToken($tokenCachePath);

        // If no cached token and credentials are provided, try to fetch
        if ($token === null && $username !== '' && $password !== '') {
            $sdLogger->info('No cached SD token found, attempting to fetch with credentials');
            /** @var SdApiClient $tempClient */
            $tempClient = new SdApiClient('', $sdLogger, $timeoutSecs);
            $token = $tempClient->fetchToken($username, $password);

            if ($token !== null) {
                self::saveCachedToken($tokenCachePath, $token);
                $sdLogger->info('Successfully fetched and cached SD token');
            }
        }

        if ($token === null) {
            throw new \RuntimeException(
                'No SD token available. Provide credentials or ensure token_cache_path contains a valid token.'
            );
        }

        // Build the client with the token
        /** @var SdApiClient $client */
        $client = new SdApiClient($token, $sdLogger, $timeoutSecs);

        // Build the lineup handler
        /** @var SdLineupHandler $lineupHandler */
        $lineupHandler = new SdLineupHandler($client, $channelManager, $sdLogger);

        // Build the program mapper
        $mapper = new SdProgramMapper();

        // Build and return the service
        return new SdEpgService($client, $lineupHandler, $mapper, $guideManager, $sdLogger);
    }

    /**
     * Safely convert a value to string.
     *
     * @param mixed $value Value to convert
     * @return string Resulting string
     */
    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Safely convert a value to int.
     *
     * @param mixed $value Value to convert
     * @return int Resulting int
     */
    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * Load a cached token from the filesystem.
     *
     * @param string $cachePath Path to the cached token JSON file
     * @return string|null Token string or null if not found/expired
     */
    private static function loadCachedToken(string $cachePath): ?string
    {
        if (!file_exists($cachePath)) {
            return null;
        }

        $content = @file_get_contents($cachePath);

        if ($content === false) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return null;
        }

        $token = $data['token'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;

        // Check expiration (tokens typically last 24 hours)
        if ($expiresAt !== null) {
            $expiredAtTs = self::toInt($expiresAt);
            if (time() > $expiredAtTs) {
                return null;
            }
        }

        return is_string($token) ? $token : null;
    }

    /**
     * Save a token to the filesystem cache.
     *
     * Token is cached with a 23-hour expiration to refresh before actual expiry.
     *
     * ## Private-file write discipline (F-6)
     *
     * The cache holds a live SD API bearer token. It is written with the same
     * atomic-private pattern the hub/server key writers use: directory created
     * 0700, body staged in a same-directory `tempnam()` (POSIX-0600), an
     * explicit `chmod 0600` (belt-and-braces against umask interaction), then
     * `rename()` — so a concurrent reader either sees the complete old file or
     * the complete new file, and the token is NEVER briefly world-readable at
     * its final path (the pre-fix `file_put_contents(..., LOCK_EX)` inherited
     * the default 0666&~umask = 0644 under the common 022 umask).
     *
     * @param string $cachePath Path to the cached token JSON file
     * @param string $token Token string to cache
     * @return bool True on success
     */
    private static function saveCachedToken(string $cachePath, string $token): bool
    {
        // Ensure directory exists — owner-only, like the token it will hold.
        $dir = dirname($cachePath);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        // mkdir() masks the mode with the process umask; pin it explicitly.
        @chmod($dir, 0700);

        $data = [
            'token' => $token,
            'cached_at' => time(),
            'expires_at' => time() + 82800, // 23 hours
        ];

        $json = json_encode($data);
        if ($json === false) {
            return false;
        }

        $tmp = @tempnam($dir, '.sd-token-');
        if ($tmp === false) {
            return false;
        }

        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            return false;
        }

        // tempnam() creates 0600 by POSIX; chmod keeps the guarantee independent
        // of platform deviation before the file becomes visible at its final path.
        @chmod($tmp, 0600);

        if (!@rename($tmp, $cachePath)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }
}
