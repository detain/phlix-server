<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Dlna\RendererControlClient;
use ReflectionProperty;

/**
 * Device-lane rework (item 3): the constructor LAN-gate that
 * docs/dev/BLOCKING_IO_EXCEPTIONS.md Exception 6 cites as the fail-loud
 * control-plane sink guard had ZERO executed coverage. The legacy
 * RendererControlClientTest is class-level @group network — excluded by
 * phpunit.xml — and every one of its cases first opens a TCP connect to a
 * hardcoded live renderer (192.168.1.100), so nothing in the default suite
 * ever reached, let alone asserted, the gate.
 *
 * The gate itself is pure pre-socket validation: construction either throws
 * or does not, with no network and no dependence on what is installed on the
 * box. These cases make the cited guarantee origin-true.
 */
final class RendererControlClientLanGateTest extends TestCase
{
    /**
     * Non-http(s) schemes, userinfo smuggling and empty input are laundered
     * away BEFORE any host classification — the first ctor message.
     */
    public function testConstructorRejectsNonHttpSchemesAndSmugglingShapes(): void
    {
        $urls = [
            'file:///etc/passwd',
            'gopher://192.168.1.5:11211/_',
            'http://user:pw@192.168.1.5:8200/ctrl',
            '',
        ];

        foreach ($urls as $url) {
            try {
                new RendererControlClient($url, $this->logger());
                $this->fail('expected the LAN gate to reject ' . var_export($url, true));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Renderer control URL is not a valid http(s) endpoint', $e->getMessage());
            }
        }
    }

    /**
     * Refused and non-LAN hosts — metadata endpoint, loopback (v4 and v6),
     * a public address, IPv4 multicast, and IPv6 multicast (the ff00::/8 row
     * this same rework added to LanEndpointGuard::REFUSED_CIDRS).
     */
    public function testConstructorRejectsRefusedAndPublicHosts(): void
    {
        $urls = [
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:9112/control',
            'http://[::1]:8200/avtransport',
            'http://8.8.8.8/control',
            'http://239.255.255.250:1900/x',
            'http://[ff02::1]:8200/ctrl',
        ];

        foreach ($urls as $url) {
            try {
                new RendererControlClient($url, $this->logger());
                $this->fail('expected the LAN gate to reject ' . var_export($url, true));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Renderer control URL must address a private LAN host', $e->getMessage());
            }
        }
    }

    /**
     * Positive control: a private-LAN literal constructs without any socket,
     * and the stored control URL is the normalized endpoint with its trailing
     * slash trimmed (the same value every SOAP POST targets).
     */
    public function testConstructorAcceptsPrivateLanLiteralAndTrimsTrailingSlash(): void
    {
        $client = new RendererControlClient('http://192.168.1.50:8200/avtransport/', $this->logger());

        $property = new ReflectionProperty(RendererControlClient::class, 'rendererUrl');
        $property->setAccessible(true);

        $this->assertSame('http://192.168.1.50:8200/avtransport', $property->getValue($client));

        // https on RFC1918 is equally LAN-valid (M4 verifies the cert at send
        // time; construction must not pre-refuse it).
        $this->assertInstanceOf(
            RendererControlClient::class,
            new RendererControlClient('https://172.16.5.9/control', $this->logger())
        );
    }

    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }
}
