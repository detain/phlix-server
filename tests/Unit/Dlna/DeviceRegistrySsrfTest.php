<?php

/**
 * DeviceRegistry SSRF boundary tests — proves a spoofed/unsolicited SSDP LOCATION can
 * never steer the device-description fetch off the datagram source. Refusal is asserted
 * PRE-socket: the injected fetcher's call log stays empty.
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Dlna\DeviceRegistry;

class DeviceRegistrySsrfTest extends TestCase
{
    /** @var list<string> */
    private array $fetched = [];

    private const DEVICE_XML = '<?xml version="1.0"?>'
        . '<root xmlns="urn:schemas-upnp-org:device-1-0">'
        . '<device><deviceType>urn:schemas-upnp-org:device:MediaRenderer:1</deviceType>'
        . '<friendlyName>Living Room</friendlyName><UDN>uuid:test-1</UDN>'
        . '</device></root>';

    private function registryWithFetcher(): DeviceRegistry
    {
        $this->fetched = [];

        return new DeviceRegistry(
            DeviceRegistry::DEFAULT_CACHE_TTL,
            null,
            function (string $url): array {
                $this->fetched[] = $url;

                return ['status' => 200, 'body' => self::DEVICE_XML];
            }
        );
    }

    /**
     * @param non-empty-string $location
     * @param non-empty-string $source
     */
    private function callFetch(DeviceRegistry $registry, string $location, string $source): ?object
    {
        $method = new \ReflectionMethod($registry, 'fetchDeviceDescription');
        $method->setAccessible(true);

        /** @var object|null $result */
        $result = $method->invoke($registry, 'uuid:usn-1', 'urn:test', $location, $source, 'test/1.0');

        return $result;
    }

    public function testLocationNotEqualToSourceIsRefusedPreSocket(): void
    {
        $registry = $this->registryWithFetcher();

        // Reply claims a metadata host; it did not come from that host.
        $device = $this->callFetch($registry, 'http://169.254.169.254/latest/meta-data', '192.168.1.50');

        $this->assertNull($device);
        $this->assertSame([], $this->fetched, 'fetch must not be attempted for an unpinned LOCATION');
    }

    public function testCrossHostLocationIsRefusedPreSocket(): void
    {
        $registry = $this->registryWithFetcher();

        $device = $this->callFetch($registry, 'http://10.0.0.9/device.xml', '192.168.1.50');

        $this->assertNull($device);
        $this->assertSame([], $this->fetched);
    }

    public function testHostnameLocationIsRefusedPreSocket(): void
    {
        $registry = $this->registryWithFetcher();

        $device = $this->callFetch($registry, 'http://evil.example.com/device.xml', '192.168.1.50');

        $this->assertNull($device);
        $this->assertSame([], $this->fetched);
    }

    public function testLoopbackLocationEvenFromLoopbackSourceIsRefused(): void
    {
        $registry = $this->registryWithFetcher();

        $device = $this->callFetch($registry, 'http://127.0.0.1:9999/device.xml', '127.0.0.1');

        $this->assertNull($device);
        $this->assertSame([], $this->fetched);
    }

    public function testNonHttpSchemeIsRefused(): void
    {
        $registry = $this->registryWithFetcher();

        $device = $this->callFetch($registry, 'file:///etc/passwd', '192.168.1.50');

        $this->assertNull($device);
        $this->assertSame([], $this->fetched);
    }

    public function testPinnedLanLocationIsFetchedOnThePinnedOrigin(): void
    {
        $registry = $this->registryWithFetcher();

        $device = $this->callFetch($registry, 'http://192.168.1.50:8200/device.xml', '192.168.1.50');

        $this->assertNotNull($device);
        $this->assertCount(1, $this->fetched);
        $this->assertStringContainsString('192.168.1.50', $this->fetched[0]);
        $this->assertSame('Living Room', $device->getFriendlyName());
    }

    public function testCrossHostPresentationUrlIsDropped(): void
    {
        $this->fetched = [];
        $hostileXml = '<?xml version="1.0"?>'
            . '<root xmlns="urn:schemas-upnp-org:device-1-0">'
            . '<device><deviceType>urn:schemas-upnp-org:device:MediaRenderer:1</deviceType>'
            . '<friendlyName>Living Room</friendlyName><UDN>uuid:test-1</UDN>'
            . '<presentationURL>http://169.254.169.254/</presentationURL>'
            . '</device></root>';

        $registry = new DeviceRegistry(
            DeviceRegistry::DEFAULT_CACHE_TTL,
            null,
            function (string $url) use ($hostileXml): array {
                $this->fetched[] = $url;

                return ['status' => 200, 'body' => $hostileXml];
            }
        );

        $device = $this->callFetch($registry, 'http://192.168.1.50:8200/device.xml', '192.168.1.50');

        $this->assertNotNull($device);
        // Foreign-host presentation URL must not be adopted.
        $this->assertStringNotContainsString('169.254', $device->getPresentationUrl());
    }
}
