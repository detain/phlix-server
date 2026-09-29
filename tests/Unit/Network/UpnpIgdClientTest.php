<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Net\LanEndpointGuard;
use Phlix\Network\UpnpIgdClient;
use Psr\Log\NullLogger;
use ReflectionMethod;

class UpnpIgdClientTest extends TestCase
{
    private UpnpIgdClient $client;

    protected function setUp(): void
    {
        $this->client = new UpnpIgdClient(new NullLogger(), 500);
    }

    public function testDiscoverGatewayReturnsNullOnTimeout(): void
    {
        $result = $this->client->discoverGateway();
        $this->assertNull($result);
    }

    public function testGetExternalIpReturnsNullForInvalidUrl(): void
    {
        $result = $this->client->getExternalIp('http://invalid.example.com:9999/xml/device.xml');
        $this->assertNull($result);
    }

    public function testAddPortMappingReturnsFalseForInvalidUrl(): void
    {
        $result = $this->client->addPortMapping(
            'http://invalid.example.com:9999/ctrl',
            '32400',
            '192.168.1.100',
            '32400'
        );
        $this->assertFalse($result);
    }

    public function testRemovePortMappingReturnsFalseForInvalidUrl(): void
    {
        $result = $this->client->removePortMapping(
            'http://invalid.example.com:9999/ctrl',
            '32400'
        );
        $this->assertFalse($result);
    }

    public function testClientCanBeInstantiated(): void
    {
        $client = new UpnpIgdClient();
        $this->assertInstanceOf(UpnpIgdClient::class, $client);
    }

    public function testClientWithCustomTimeout(): void
    {
        $client = new UpnpIgdClient(new NullLogger(), 5000);
        $this->assertInstanceOf(UpnpIgdClient::class, $client);
    }

    /**
     * H1(d): the SSDP LOCATION must be a literal IP equal to the datagram
     * source. A spoofed reply advertising the cloud-metadata endpoint from a
     * different source is refused before any socket opens.
     */
    public function testSsdpLocationMustMatchDatagramSource(): void
    {
        $notify = "NOTIFY * HTTP/1.1\r\n"
            . "HOST: 239.255.255.250:1900\r\n"
            . "LOCATION: http://169.254.169.254/latest/meta-data/\r\n\r\n";

        $method = new ReflectionMethod(UpnpIgdClient::class, 'parseSsdpResponse');
        $method->setAccessible(true);

        // Reply arrives from the gateway, but the LOCATION points at metadata.
        $this->assertNull($method->invoke($this->client, $notify, '192.168.1.1'));

        // Same header pinned to its true source is accepted and normalised.
        $pinned = "HTTP/1.1 200 OK\r\n"
            . "LOCATION: http://192.168.1.1:1900/gateway.xml\r\n\r\n";
        $this->assertSame(
            'http://192.168.1.1:1900/gateway.xml',
            $method->invoke($this->client, $pinned, '192.168.1.1')
        );

        // Cross-source (host literal differs from where it came from) refuses.
        $this->assertNull($method->invoke($this->client, $pinned, '10.0.0.5'));
    }

    /**
     * H1(d): a document controlURL pointing at a foreign host must resolve to
     * null; a relative or same-host controlURL is kept.
     */
    public function testResolveUrlRejectsCrossHostControlUrl(): void
    {
        $method = new ReflectionMethod(UpnpIgdClient::class, 'resolveUrl');
        $method->setAccessible(true);

        $base = 'http://192.168.1.1:1900/gateway.xml';

        $this->assertSame(
            'http://192.168.1.1:1900/wanipctrl',
            $method->invoke($this->client, $base, '/wanipctrl')
        );
        $this->assertSame(
            'http://192.168.1.1:1900/ctrl/same',
            $method->invoke($this->client, $base, 'http://192.168.1.1:1900/ctrl/same')
        );
        // Foreign absolute host.
        $this->assertNull($method->invoke($this->client, $base, 'http://10.9.9.9/ctrl'));
        // Metadata host.
        $this->assertNull($method->invoke($this->client, $base, 'http://169.254.169.254/ctrl'));
    }

    // ------------------------------------------------------------------
    // Device-lane rework (item 1): Exception 5 reply reads are bounded on
    // bytes AND idle silence — the claim that soapRequest()/asyncHttpGet()
    // were unbounded while the register said "capped the same way".
    // ------------------------------------------------------------------

    /**
     * A hostile gateway must not grow the worker without bound: the reader
     * aborts once the reply crosses the shared 1 MiB ceiling. The stream
     * position proves it STOPPED there instead of draining the socket.
     */
    public function testReadResponseBoundedAbortsOversizedReply(): void
    {
        $method = new ReflectionMethod(UpnpIgdClient::class, 'readResponseBounded');
        $method->setAccessible(true);

        $stream = fopen('php://memory', 'r+b');
        $this->assertIsResource($stream);
        // No newlines: every fgets delivers a full 4095-byte chunk.
        fwrite($stream, str_repeat('A', LanEndpointGuard::MAX_RESPONSE_BYTES + 8192));
        rewind($stream);

        $result = $method->invoke($this->client, $stream);
        $consumed = ftell($stream);
        fclose($stream);

        $this->assertNull($result, 'an over-cap reply must fail loud, never buffer');
        $this->assertLessThanOrEqual(
            LanEndpointGuard::MAX_RESPONSE_BYTES + 4096,
            $consumed,
            sprintf('the reader must stop at the cap plus one fgets chunk (consumed %d)', $consumed)
        );
    }

    /**
     * Positive control: a complete short reply passes through byte-identical.
     */
    public function testReadResponseBoundedReturnsCompleteShortReply(): void
    {
        $method = new ReflectionMethod(UpnpIgdClient::class, 'readResponseBounded');
        $method->setAccessible(true);

        $payload = "HTTP/1.1 200 OK\r\nContent-Type: text/xml\r\n\r\n<xml>ok</xml>";
        $stream = fopen('php://memory', 'r+b');
        $this->assertIsResource($stream);
        fwrite($stream, $payload);
        rewind($stream);

        $this->assertSame($payload, $method->invoke($this->client, $stream));
        fclose($stream);
    }

    /**
     * The stalled-drip case: bytes arrive, then silence forever. The read must
     * sit out exactly the idle ceiling and return null — the pre-fix loop
     * pinned an HTTP worker on this stream indefinitely.
     */
    public function testReadResponseBoundedAbortsStalledStream(): void
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair is unavailable on this host.');
        }
        [$mine, $peer] = $pair;
        $this->assertNotFalse(fwrite($mine, "HTTP/1.1 200 OK\r\n"));

        $method = new ReflectionMethod(UpnpIgdClient::class, 'readResponseBounded');
        $method->setAccessible(true);

        $start = hrtime(true);
        $result = $method->invoke($this->client, $mine, 1);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

        fclose($mine);
        fclose($peer);

        $this->assertNull($result, 'an idle timeout must abort the read, not hand on partial bytes');
        $this->assertGreaterThanOrEqual(900.0, $elapsedMs, 'the read must really wait out the 1 s ceiling');
        $this->assertLessThan(3000.0, $elapsedMs, '…and come back bounded, never hang');
    }
}
