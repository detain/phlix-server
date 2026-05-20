<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Iptv;

use Psr\Log\LoggerInterface;

/**
 * Factory for creating IptvTunerDriver instances from configuration.
 *
 * Reads IPTV source configuration from config/livetv.php and creates
 * one driver instance per configured source.
 *
 * @since 0.12.0
 */
final class IptvTunerDriverFactory
{
    /**
     * Build an IptvTunerDriver from configuration.
     *
     * @param array<string, mixed> $config Configuration array from config/livetv.php
     * @param LoggerInterface|null $logger Optional logger instance
     * @return IptvTunerDriver|null Configured driver or null if IPTV is disabled
     *
     * @example
     * ```php
     * $config = include 'config/livetv.php';
     * $driver = IptvTunerDriverFactory::build($config);
     * ```
     */
    public static function build(array $config, ?LoggerInterface $logger = null): ?IptvTunerDriver
    {
        $iptvConfig = $config['iptv'] ?? null;
        if (!is_array($iptvConfig)) {
            return null;
        }

        $enabled = $iptvConfig['enabled'] ?? false;
        if (!$enabled) {
            return null;
        }

        $sources = $iptvConfig['sources'] ?? null;
        if (!is_array($sources) || empty($sources)) {
            return null;
        }

        // Use the first source for the driver
        // In a more complex implementation, we'd have one driver per source
        $sourceConfig = $sources[0];
        if (!is_array($sourceConfig)) {
            return null;
        }

        $nameValue = $sourceConfig['name'] ?? null;
        $playlistValue = $sourceConfig['playlist_url'] ?? null;
        $epgValue = $sourceConfig['epg_url'] ?? null;

        $device = new IptvDevice(
            sourceId: self::generateSourceId(is_string($nameValue) ? $nameValue : 'default'),
            name: is_string($nameValue) ? $nameValue : 'IPTV Source',
            playlistUrl: is_string($playlistValue) ? $playlistValue : '',
            epgUrl: is_string($epgValue) ? $epgValue : null,
            isEnabled: true,
        );

        $m3uParser = new M3UParser($logger);
        $xmlTvParser = new XmlTvParser($logger);

        return new IptvTunerDriver($m3uParser, $xmlTvParser, $device, $logger);
    }

    /**
     * Build all IPTV devices from configuration.
     *
     * @param array<string, mixed> $config Configuration array from config/livetv.php
     * @return IptvDevice[] Array of configured devices
     *
     * @example
     * ```php
     * $config = include 'config/livetv.php';
     * $devices = IptvTunerDriverFactory::buildDevices($config);
     * foreach ($devices as $device) {
     *     echo "IPTV Source: {$device->name}\n";
     * }
     * ```
     */
    public static function buildDevices(array $config): array
    {
        $devices = [];

        $iptvConfig = $config['iptv'] ?? null;
        if (!is_array($iptvConfig)) {
            return $devices;
        }

        $enabled = $iptvConfig['enabled'] ?? false;
        if (!$enabled) {
            return $devices;
        }

        $sources = $iptvConfig['sources'] ?? null;
        if (!is_array($sources)) {
            return $devices;
        }

        foreach ($sources as $index => $sourceConfig) {
            if (!is_array($sourceConfig)) {
                continue;
            }

            $sourceEnabled = $sourceConfig['enabled'] ?? true;
            if (!$sourceEnabled) {
                continue;
            }

            $name = $sourceConfig['name'] ?? null;
            $playlistValue = $sourceConfig['playlist_url'] ?? null;
            $epgValue = $sourceConfig['epg_url'] ?? null;

            $devices[] = new IptvDevice(
                sourceId: self::generateSourceId(is_string($name) ? $name : (string) $index),
                name: is_string($name) ? $name : "IPTV Source {$index}",
                playlistUrl: is_string($playlistValue) ? $playlistValue : '',
                epgUrl: is_string($epgValue) ? $epgValue : null,
                isEnabled: true,
            );
        }

        return $devices;
    }

    /**
     * Generate a source ID from a name.
     *
     * @param string|null $name The source name
     * @return string A URL-safe source ID
     */
    private static function generateSourceId(?string $name): string
    {
        /** @var string */
        $safeName = is_string($name) ? $name : 'unnamed';
        /** @var string */
        $trimmed = trim($safeName);
        /** @var string|null */
        $replaced = preg_replace('/[^a-zA-Z0-9]+/', '_', $trimmed);
        if ($replaced === null) {
            $replaced = $trimmed;
        }
        return 'iptv_' . strtolower($replaced);
    }
}
