<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Arr;

use PHPUnit\Framework\TestCase;
use Phlex\Arr\ArrClientFactory;
use Phlex\Arr\SonarrClient;
use Phlex\Arr\RadarrClient;

/**
 * Unit tests for ArrClientFactory.
 *
 * @package Phlex\Tests\Unit\Arr
 * @since 0.12.0
 */
class ArrClientFactoryTest extends TestCase
{
    public function testCreatesSonarrClientWithConfig(): void
    {
        $config = [
            'sonarr' => [
                'url' => 'http://sonarr.local:8989',
                'api_key' => 'sonarr-api-key-123',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        $this->assertInstanceOf(SonarrClient::class, $client);
    }

    public function testCreatesRadarrClientWithConfig(): void
    {
        $config = [
            'radarr' => [
                'url' => 'http://radarr.local:7878',
                'api_key' => 'radarr-api-key-456',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createRadarrClient();

        $this->assertInstanceOf(RadarrClient::class, $client);
    }

    public function testReturnsNullWhenSonarrNotEnabled(): void
    {
        $config = [
            'sonarr' => [
                'url' => 'http://localhost:8989',
                'api_key' => 'some-key',
                'enabled' => false,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenRadarrNotEnabled(): void
    {
        $config = [
            'radarr' => [
                'url' => 'http://localhost:7878',
                'api_key' => 'some-key',
                'enabled' => false,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createRadarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenSonarrApiKeyEmpty(): void
    {
        $config = [
            'sonarr' => [
                'url' => 'http://localhost:8989',
                'api_key' => '',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenRadarrApiKeyEmpty(): void
    {
        $config = [
            'radarr' => [
                'url' => 'http://localhost:7878',
                'api_key' => '',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createRadarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenSonarrConfigMissing(): void
    {
        $config = [];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        $this->assertNull($client);
    }

    public function testReturnsNullWhenRadarrConfigMissing(): void
    {
        $config = [];

        $factory = new ArrClientFactory($config);
        $client = $factory->createRadarrClient();

        $this->assertNull($client);
    }

    public function testCreatesBothClientsSimultaneously(): void
    {
        $config = [
            'sonarr' => [
                'url' => 'http://sonarr.local:8989',
                'api_key' => 'sonarr-key',
                'enabled' => true,
            ],
            'radarr' => [
                'url' => 'http://radarr.local:7878',
                'api_key' => 'radarr-key',
                'enabled' => true,
            ],
        ];

        $factory = new ArrClientFactory($config);

        $sonarrClient = $factory->createSonarrClient();
        $radarrClient = $factory->createRadarrClient();

        $this->assertInstanceOf(SonarrClient::class, $sonarrClient);
        $this->assertInstanceOf(RadarrClient::class, $radarrClient);
    }

    public function testDefaultValuesWhenConfigPartial(): void
    {
        $config = [
            'sonarr' => [
                'enabled' => true,
                // url and api_key missing - should use defaults
            ],
        ];

        $factory = new ArrClientFactory($config);
        $client = $factory->createSonarrClient();

        // With empty API key, should return null
        $this->assertNull($client);
    }
}
