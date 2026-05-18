<?php

declare(strict_types=1);

namespace Phlex\Arr;

use Phlex\Common\Logger\StructuredLogger;

/**
 * Factory for creating Sonarr/Radarr API clients from config.
 *
 * @package Phlex\Arr
 * @since 0.12.0
 */
class ArrClientFactory
{
    /**
     * @param array{
     *     sonarr?: array{url?: string, api_key?: string, enabled?: bool},
     *     radarr?: array{url?: string, api_key?: string, enabled?: bool}
     * } $config Configuration array with sonarr/radarr sections.
     */
    public function __construct(
        private readonly array $config
    ) {
    }

    /**
     * Creates a SonarrClient from the config.
     *
     * @param StructuredLogger|null $logger Optional logger instance.
     * @return SonarrClient|null Client instance, or null if Sonarr is not enabled.
     */
    public function createSonarrClient(?StructuredLogger $logger = null): ?SonarrClient
    {
        $sonarrConfig = $this->config['sonarr'] ?? [];

        if (!($sonarrConfig['enabled'] ?? false)) {
            return null;
        }

        $url = $sonarrConfig['url'] ?? 'http://localhost:8989';
        $apiKey = $sonarrConfig['api_key'] ?? '';

        if ($apiKey === '') {
            $logger?->warning('Sonarr API key is empty but client is enabled');
            return null;
        }

        return new SonarrClient($url, $apiKey, $logger);
    }

    /**
     * Creates a RadarrClient from the config.
     *
     * @param StructuredLogger|null $logger Optional logger instance.
     * @return RadarrClient|null Client instance, or null if Radarr is not enabled.
     */
    public function createRadarrClient(?StructuredLogger $logger = null): ?RadarrClient
    {
        $radarrConfig = $this->config['radarr'] ?? [];

        if (!($radarrConfig['enabled'] ?? false)) {
            return null;
        }

        $url = $radarrConfig['url'] ?? 'http://localhost:7878';
        $apiKey = $radarrConfig['api_key'] ?? '';

        if ($apiKey === '') {
            $logger?->warning('Radarr API key is empty but client is enabled');
            return null;
        }

        return new RadarrClient($url, $apiKey, $logger);
    }
}
