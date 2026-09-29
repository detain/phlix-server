<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery\Ssdp;

use PHPUnit\Framework\TestCase;
use Phlix\Discovery\Ssdp\SsdpDiscovery;
use Phlix\Discovery\Ssdp\SsdpSocket;

/**
 * H1 — the SSDP LOCATION header is attacker-influenced. These tests pin the
 * LAN-only gate BEFORE any socket: metadata/loopback/link-local/public/
 * non-http(s) targets are refused with ZERO fetcher invocations, RFC1918
 * targets are fetched exactly once per guarded hop, redirects re-enter the
 * gate per hop with a bounded budget, and oversized bodies are refused.
 *
 * The H1 rework block below pins the DNS-rebinding and address-family closes:
 * the PRODUCTION default fetch path (no fetcher injected) must dial the
 * verified literal IP — never the name — so a zone that answers lookup #1
 * inside RFC1918 and every later lookup hostile cannot redirect the socket;
 * a hostname mixing a LAN A record with a hostile AAAA is refused outright;
 * every redirect hop re-pins on its own; and IP-literal LOCATIONs still dial
 * their host with no Host-header override.
 */
class SsdpDiscoverySsrfTest extends TestCase
{
    private const VALID_XML =
        '<?xml version="1.0"?><root><device>'
        . '<friendlyName>Living TV</friendlyName>'
        . '<manufacturer>Acme</manufacturer>'
        . '<UDN>uuid:dev-1</UDN>'
        . '</device></root>';

    /** @var list<string> */
    private array $fetched = [];

    protected function tearDown(): void
    {
        $this->fetched = [];
    }

    /**
     * @param callable(string): (array{status: int, headers: list<string>, body: string}|null) $fetcher
     */
    private function discoveryWithFetcher(callable $fetcher): SsdpDiscovery
    {
        return new SsdpDiscovery(
            $this->createMock(SsdpSocket::class),
            null,
            null,
            function (string $url) use ($fetcher): ?array {
                $this->fetched[] = $url;
                return $fetcher($url);
            },
        );
    }

    /**
     * @param list<string> $resolvesTo
     */
    private function discoveryResolvingTo(array $resolvesTo, ?string $expectHost = null): SsdpDiscovery
    {
        return new SsdpDiscovery(
            $this->createMock(SsdpSocket::class),
            null,
            function (string $host) use ($resolvesTo, $expectHost): array {
                if ($expectHost !== null) {
                    $this->assertSame($expectHost, $host);
                }
                return $resolvesTo;
            },
            function (string $url): array {
                $this->fetched[] = $url;
                return ['status' => 200, 'headers' => [], 'body' => self::VALID_XML];
            },
        );
    }

    /**
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function ok(string $body = self::VALID_XML): array
    {
        return ['status' => 200, 'headers' => [], 'body' => $body];
    }

    /**
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function redirect(string $location): array
    {
        return ['status' => 302, 'headers' => ['HTTP/1.1 302 Found', 'Location: ' . $location], 'body' => ''];
    }

    public function testCloudMetadataLocationIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $this->assertNull($discovery->resolveDeviceDescription('http://169.254.169.254/latest/meta-data/'));
        $this->assertSame([], $this->fetched, 'refusal must happen before the socket');
    }

    public function testLoopbackLocationIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $this->assertNull($discovery->resolveDeviceDescription('http://127.0.0.1:8080/desc.xml'));
        $this->assertSame([], $this->fetched);
    }

    public function testPublicInternetLocationIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $this->assertNull($discovery->resolveDeviceDescription('http://8.8.8.8/desc.xml'));
        $this->assertSame([], $this->fetched);
    }

    public function testCgnatLocationIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $this->assertNull($discovery->resolveDeviceDescription('http://100.64.0.1/desc.xml'));
        $this->assertSame([], $this->fetched);
    }

    public function testIpv6LoopbackIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $this->assertNull($discovery->resolveDeviceDescription('http://[::1]:9000/desc.xml'));
        $this->assertSame([], $this->fetched);
    }

    public function testNonHttpSchemeIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $this->assertNull($discovery->resolveDeviceDescription('ftp://192.168.1.10/desc.xml'));
        $this->assertNull($discovery->resolveDeviceDescription('file:///etc/passwd'));
        $this->assertNull($discovery->resolveDeviceDescription('gopher://10.1.2.3/'));
        $this->assertSame([], $this->fetched);
    }

    public function testUserinfoSmugglingIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        // parse_url reads the host as 8.8.8.8 (already refused), and the
        // userinfo gate refuses it before the address gate even matters.
        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.2@8.8.8.8/desc.xml'));
        $this->assertSame([], $this->fetched);
    }

    public function testEmptyLocationIsRefused(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $this->assertNull($discovery->resolveDeviceDescription(''));
        $this->assertSame([], $this->fetched);
    }

    public function testRfc1918LiteralIsFetchedAndParsedOnce(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $result = $discovery->resolveDeviceDescription('http://192.168.1.50:49152/desc.xml');

        $this->assertNotNull($result);
        $this->assertSame(self::VALID_XML, $result['xml']);
        $this->assertSame('Living TV', $result['friendlyName']);
        $this->assertSame(['http://192.168.1.50:49152/desc.xml'], $this->fetched);
    }

    public function testSchemelessLanLocationGetsHttpPrependedAndStillGated(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok());

        $this->assertNotNull($discovery->resolveDeviceDescription('10.0.0.7:8200/desc.xml'));
        $this->assertSame(['http://10.0.0.7:8200/desc.xml'], $this->fetched);
    }

    public function testHostnameResolvingToRfc1918IsAllowed(): void
    {
        $discovery = $this->discoveryResolvingTo(['192.168.30.4'], 'tv.lan');

        $this->assertNotNull($discovery->resolveDeviceDescription('http://tv.lan:8080/desc.xml'));
        $this->assertCount(1, $this->fetched);
    }

    public function testHostnameResolvingToPublicIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryResolvingTo(['93.184.216.34']);

        $this->assertNull($discovery->resolveDeviceDescription('http://evil.example/desc.xml'));
        $this->assertSame([], $this->fetched);
    }

    public function testHostnameResolvingToMixOfLanAndMetadataIsRefused(): void
    {
        $discovery = $this->discoveryResolvingTo(['192.168.1.9', '169.254.169.254']);

        $this->assertNull($discovery->resolveDeviceDescription('http://rebind.example/desc.xml'));
        $this->assertSame([], $this->fetched);
    }

    public function testUnresolvableHostnameIsRefusedWithoutAnyFetch(): void
    {
        $discovery = $this->discoveryResolvingTo([]);

        $this->assertNull($discovery->resolveDeviceDescription('http://nowhere.invalid/desc.xml'));
        $this->assertSame([], $this->fetched);
    }

    // ---- H1 rework: dial pinning (DNS rebinding) + family skew -------------

    /**
     * Build a discovery on the PRODUCTION default fetch path (fetcher === null)
     * whose socket-opening step is replaced by the dial recorder seam: every
     * would-be fopen is captured as {url, headers} and short-circuits to a
     * transport failure, so the check→pin→dial construction is exercised with
     * zero network.
     *
     * @param (callable(string): list<string>)|null $resolver
     * @param list<array{url: string, headers: string}> $dials captured by reference
     */
    private function discoveryRecordingDials(?callable $resolver, array &$dials): SsdpDiscovery
    {
        return new SsdpDiscovery(
            $this->createMock(SsdpSocket::class),
            null,
            $resolver,
            null,
            static function (string $url, string $headers) use (&$dials): void {
                $dials[] = ['url' => $url, 'headers' => $headers];
            },
        );
    }

    public function testProductionDialPinsVerifiedIpEvenWhenDnsRebindsAfterTheGate(): void
    {
        $lookups = 0;
        $resolver = static function (string $host) use (&$lookups): array {
            $lookups++;
            // An attacker-owned zone answers the FIRST lookup inside RFC1918
            // (passing the gate) and every later lookup with the metadata
            // endpoint. Pinning means production must never ask again.
            return $lookups === 1 ? ['192.168.1.9'] : ['169.254.169.254'];
        };

        /** @var list<array{url: string, headers: string}> $dials */
        $dials = [];
        $discovery = $this->discoveryRecordingDials($resolver, $dials);

        // The dial recorder short-circuits before the socket, so the GET
        // legitimately ends as a transport failure — the assertions below are
        // about WHAT the production path was about to open.
        $this->assertNull($discovery->resolveDeviceDescription('http://tv.lan:8080/desc.xml'));

        $this->assertSame(1, $lookups, 'the dial must never trigger a second resolution');
        $this->assertCount(1, $dials);
        $this->assertSame(
            'http://192.168.1.9:8080/desc.xml',
            $dials[0]['url'],
            'the socket must open on the pinned verified literal, not the hostname',
        );
        $this->assertStringContainsString("Host: tv.lan:8080\r\n", $dials[0]['headers']);
        $this->assertStringContainsString("Connection: close\r\n", $dials[0]['headers']);
    }

    public function testHostnameWithLanARecordAndPublicAaaaIsRefusedWithoutAnyDial(): void
    {
        $discovery = $this->discoveryResolvingTo(['192.168.1.9', '2606:4700:4700::1111']);

        $this->assertNull($discovery->resolveDeviceDescription('http://skew.example/desc.xml'));
        $this->assertSame(
            [],
            $this->fetched,
            'family skew must be refused before the socket — the wrapper may prefer v6',
        );
    }

    public function testDialRecorderSeesNoSocketForFamilySkewOnDefaultPath(): void
    {
        /** @var list<array{url: string, headers: string}> $dials */
        $dials = [];
        $discovery = $this->discoveryRecordingDials(
            static fn(): array => ['10.1.2.3', '64:ff9b::8.8.8.8'],
            $dials,
        );

        $this->assertNull($discovery->resolveDeviceDescription('http://skew.example/desc.xml'));
        $this->assertSame([], $dials, 'the NAT64-embedded public answer must fail the gate');
    }

    public function testFetcherReceivesPinnedLiteralNotHostname(): void
    {
        $discovery = $this->discoveryResolvingTo(['192.168.30.4'], 'tv.lan');

        $this->assertNotNull($discovery->resolveDeviceDescription('http://tv.lan:8080/desc.xml'));
        $this->assertSame(['http://192.168.30.4:8080/desc.xml'], $this->fetched);
    }

    public function testEachRedirectHopPinsItsOwnVerifiedIp(): void
    {
        $map = [
            'hop1.example' => ['192.168.1.10'],
            'hop2.example' => ['192.168.1.11'],
        ];
        $discovery = new SsdpDiscovery(
            $this->createMock(SsdpSocket::class),
            null,
            static function (string $host) use ($map): array {
                return $map[$host] ?? [];
            },
            function (string $url): array {
                $this->fetched[] = $url;
                if (str_contains($url, '192.168.1.10')) {
                    return self::redirect('http://hop2.example/desc2.xml');
                }
                return self::ok();
            },
        );

        $result = $discovery->resolveDeviceDescription('http://hop1.example:8000/hop1.xml');

        $this->assertNotNull($result);
        $this->assertSame(
            ['http://192.168.1.10:8000/hop1.xml', 'http://192.168.1.11/desc2.xml'],
            $this->fetched,
            'every hop must be dialed on ITS OWN pinned literal',
        );
    }

    public function testIpLiteralDialsItselfWithoutHostHeaderOverrideOnDefaultPath(): void
    {
        /** @var list<array{url: string, headers: string}> $dials */
        $dials = [];
        $discovery = $this->discoveryRecordingDials(null, $dials);

        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.50:49152/desc.xml'));

        $this->assertCount(1, $dials);
        $this->assertSame('http://192.168.1.50:49152/desc.xml', $dials[0]['url']);
        $this->assertStringNotContainsString(
            'Host:',
            $dials[0]['headers'],
            'an IP-literal URL pins to itself and needs no override',
        );
    }

    public function testIpv4MappedIpv6LiteralDialsTheCollapsedLiteral(): void
    {
        /** @var list<array{url: string, headers: string}> $dials */
        $dials = [];
        $discovery = $this->discoveryRecordingDials(null, $dials);

        $this->assertNull($discovery->resolveDeviceDescription('http://[::ffff:192.168.1.5]/desc.xml'));

        $this->assertCount(1, $dials);
        $this->assertSame(
            'http://192.168.1.5/desc.xml',
            $dials[0]['url'],
            'the mapped spelling must dial its verified embedded-IPv4 truth',
        );
        $this->assertStringContainsString('Host: [::ffff:192.168.1.5]', $dials[0]['headers']);
    }

    public function testRedirectWithinLanIsFollowedPerHop(): void
    {
        $discovery = $this->discoveryWithFetcher(static function (string $url): array {
            if (str_contains($url, 'hop1')) {
                return self::redirect('http://192.168.1.60/desc2.xml');
            }
            return self::ok();
        });

        $result = $discovery->resolveDeviceDescription('http://192.168.1.50/hop1.xml');

        $this->assertNotNull($result);
        $this->assertSame(
            ['http://192.168.1.50/hop1.xml', 'http://192.168.1.60/desc2.xml'],
            $this->fetched,
        );
    }

    public function testRedirectToMetadataAddressIsRefusedBeforeSecondFetch(): void
    {
        $discovery = $this->discoveryWithFetcher(static function (string $url): array {
            if (str_contains($url, 'hop1')) {
                return self::redirect('http://169.254.169.254/latest/meta-data/iam/');
            }
            return self::ok();
        });

        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.50/hop1.xml'));
        $this->assertSame(
            ['http://192.168.1.50/hop1.xml'],
            $this->fetched,
            'the hostile hop must never be requested',
        );
    }

    public function testRedirectToPublicAddressIsRefused(): void
    {
        $discovery = $this->discoveryWithFetcher(static function (string $url): array {
            if (str_contains($url, 'hop1')) {
                return self::redirect('http://203.0.113.9/desc.xml');
            }
            return self::ok();
        });

        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.50/hop1.xml'));
        $this->assertCount(1, $this->fetched);
    }

    public function testSchemeLaunderingRedirectIsRefused(): void
    {
        $discovery = $this->discoveryWithFetcher(static function (string $url): array {
            if (str_contains($url, 'hop1')) {
                return self::redirect('file:///etc/passwd');
            }
            return self::ok();
        });

        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.50/hop1.xml'));
        $this->assertCount(1, $this->fetched);
    }

    public function testRedirectLoopIsCutByHopBudget(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::redirect('http://192.168.1.51/loop.xml'));

        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.50/loop.xml'));
        // 1 initial + 3 follow-ups = 4 guarded requests, then stop.
        $this->assertCount(4, $this->fetched);
    }

    public function testOversizedBodyIsRefused(): void
    {
        $huge = str_repeat('x', 1048576 + 1);
        $discovery = $this->discoveryWithFetcher(static fn(): array => self::ok($huge));

        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.50/desc.xml'));
        $this->assertCount(1, $this->fetched);
    }

    public function testNonSuccessTerminalStatusIsRefused(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): array => [
            'status' => 500,
            'headers' => ['HTTP/1.1 500 Internal Server Error'],
            'body' => '',
        ]);

        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.50/desc.xml'));
    }

    public function testTransportFailureYieldsNull(): void
    {
        $discovery = $this->discoveryWithFetcher(static fn(): ?array => null);

        $this->assertNull($discovery->resolveDeviceDescription('http://192.168.1.50/desc.xml'));
        $this->assertCount(1, $this->fetched);
    }

    // ---- L6: announceServer must not double-append the port ----------------

    public function testAnnounceServerDoesNotDoublePortWhenBaseUrlCarriesOne(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->expects($this->once())
            ->method('announce')
            ->with(
                'urn:schemas-upnp-org:device:MediaServer:1',
                'http://192.168.1.100:8096',
                $this->stringStartsWith('uuid:phlix-server-'),
            );

        $discovery = new SsdpDiscovery($socket, null);
        $discovery->announceServer('srv', 'Phlix', 'http://192.168.1.100:8096', 8096);
    }

    public function testAnnounceServerAppendsPortWhenBaseUrlHasNone(): void
    {
        $socket = $this->createMock(SsdpSocket::class);
        $socket->expects($this->once())
            ->method('announce')
            ->with(
                'urn:schemas-upnp-org:device:MediaServer:1',
                'http://192.168.1.100:8096',
                $this->stringStartsWith('uuid:phlix-server-'),
            );

        $discovery = new SsdpDiscovery($socket, null);
        $discovery->announceServer('srv', 'Phlix', 'http://192.168.1.100/', 8096);
    }
}
