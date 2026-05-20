<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Epg\SchedulesDirect;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\ChannelManager;
use Phlix\LiveTv\GuideManager;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

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
     * @param string $cachePath Path to the cached token JSON file
     * @param string $token Token string to cache
     * @return bool True on success
     */
    private static function saveCachedToken(string $cachePath, string $token): bool
    {
        // Ensure directory exists
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $data = [
            'token' => $token,
            'cached_at' => time(),
            'expires_at' => time() + 82800, // 23 hours
        ];

        $result = @file_put_contents($cachePath, json_encode($data), LOCK_EX);

        return $result !== false;
    }
}
