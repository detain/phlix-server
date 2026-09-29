<?php

/**
 * HDHomeRun discovery SSRF boundary tests — proves the LOCATION a spoofed SSDP reply
 * advertises is pinned to the datagram source and gated to LAN space BEFORE any socket
 * is opened (the injected fetcher's call log stays empty on refusal).
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Tuners\HdHomeRun;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunDiscovery;

class HdHomeRunDiscoverySsrfTest extends TestCase
{
    /** @var list<string> */
    private array $fetched = [];

    private const XML = '<?xml version="1.0"?><root><friendlyName>HDHD-1A2B3C4D</friendlyName>'
        . '<deviceType>urn:schemas-upnp-org:device:MediaServer:1</deviceType></root>';

    private function discoveryWithFetcher(): HdHomeRunDiscovery
    {
        $this->fetched = [];

        return new HdHomeRunDiscovery(null, 1, function (string $url): array {
            $this->fetched[] = $url;

            return ['status' => 200, 'body' => self::XML];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function callFetch(HdHomeRunDiscovery $discovery, string $location, string $source): ?array
    {
        $method = new \ReflectionMethod($discovery, 'fetchDeviceDescription');
        $method->setAccessible(true);

        /** @var array<string, mixed>|null $result */
        $result = $method->invoke($discovery, $location, $source);

        return $result;
    }

    public function testLocationNotEqualToSourceIsRefusedPreSocket(): void
    {
        $discovery = $this->discoveryWithFetcher();

        $info = $this->callFetch($discovery, 'http://169.254.169.254/lineup.json', '192.168.1.20');

        $this->assertNull($info);
        $this->assertSame([], $this->fetched);
    }

    public function testCrossHostLocationIsRefusedPreSocket(): void
    {
        $discovery = $this->discoveryWithFetcher();

        $info = $this->callFetch($discovery, 'http://10.9.9.9/desc.xml', '192.168.1.20');

        $this->assertNull($info);
        $this->assertSame([], $this->fetched);
    }

    public function testPublicLocationIsRefusedPreSocket(): void
    {
        $discovery = $this->discoveryWithFetcher();

        $info = $this->callFetch($discovery, 'http://8.8.8.8/desc.xml', '8.8.8.8');

        $this->assertNull($info);
        $this->assertSame([], $this->fetched);
    }

    public function testPinnedLanLocationIsFetchedAndLineupStaysOnPinnedHost(): void
    {
        $discovery = $this->discoveryWithFetcher();

        $info = $this->callFetch($discovery, 'http://192.168.1.20:80/desc.xml', '192.168.1.20');

        $this->assertNotNull($info);
        $this->assertCount(1, $this->fetched);
        $this->assertSame('192.168.1.20', $info['ip_address']);
        $this->assertStringContainsString('192.168.1.20', (string) $info['lineup_url']);
        $this->assertStringNotContainsString('8.8.8.8', (string) $info['lineup_url']);
    }
}
