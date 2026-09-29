<?php

/**
 * Phlix media server component: Ssdp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Discovery\Ssdp;

use InvalidArgumentException;
use Phlix\Common\Net\ProviderUrlAllowlist;
use Phlix\Common\Net\SsrfGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SSDP discovery service for finding DLNA/UPnP devices on the network.
 *
 * Provides methods to discover devices via M-SEARCH requests and to
 * announce the Phlix server via SSDP NOTIFY messages.
 *
 * ## H1 — the LOCATION header is attacker-influenced input
 *
 * The `LOCATION` URL a device (or a spoofed SSDP responder on the segment)
 * advertises is fetched by {@see self::resolveDeviceDescription()} straight
 * from the authenticated renderer-list request. Raw `file_get_contents()`
 * against it is a blind SSRF oracle: `http://169.254.169.254/…` (cloud
 * metadata), `http://127.0.0.1:…` (loopback admin services), `file://…`
 * (absolute path disclosure), and non-LAN public hosts all answered through
 * the worker's network position. The gate below is the inverse of
 * {@see ProviderUrlAllowlist}'s provider policy on purpose: a UPnP device
 * description lives ON THE LAN, so the fetch is allowed ONLY at RFC1918
 * targets — everything the provider path allows here is exactly what must be
 * refused, and vice versa. Shared primitives are reused, not re-rolled:
 * RFC 3986 redirect absolutisation from {@see ProviderUrlAllowlist::resolveRedirect()}
 * (scheme-laundering STOP included) and IPv4-mapped-collapse / CIDR matching
 * from {@see SsrfGuard}. Redirects are never auto-followed by the wrapper;
 * each hop re-enters the full gate before its request. Bodies are read
 * through a hard byte cap. The residual blocking fetch (bounded by
 * `timeout` + the byte cap) is registered in
 * `docs/dev/BLOCKING_IO_EXCEPTIONS.md`.
 *
 * @since 0.12.0
 */
class SsdpDiscovery
{
    /** Maximum manual redirect hops per device description (H1). */
    private const MAX_REDIRECT_HOPS = 3;

    /** Hard response-body ceiling for one device description (H1): 1 MiB. */
    private const MAX_RESPONSE_BYTES = 1048576;

    /** Per-request stream timeout in seconds for the bounded description fetch. */
    private const FETCH_TIMEOUT_SECONDS = 5;

    /**
     * The ONLY address ranges a device description may be fetched from (H1).
     * Loopback, link-local incl. the 169.254.169.254 metadata endpoint, CGNAT,
     * and every public range are refused by simply not being listed.
     */
    private const ALLOWED_LAN_CIDRS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /** Default search target for all UPnP devices */
    public const DEFAULT_ST = 'urn:schemas-upnp-org:device:*';

    /** Default search target for MediaServer devices */
    public const ST_MEDIA_SERVER = 'urn:schemas-upnp-org:device:MediaServer:1';

    /** Default search target for MediaRenderer devices */
    public const ST_MEDIA_RENDERER = 'urn:schemas-upnp-org:device:MediaRenderer:1';

    /** @var SsdpSocket */
    private SsdpSocket $socket;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * H1 DNS seam: hostname → list of IP strings. Null uses the default
     * blocking `gethostbyname()` resolver; tests inject a pinned resolver so
     * the gate is exercised with zero network.
     *
     * @var (callable(string): list<string>)|null
     */
    private $hostResolver;

    /**
     * H1 fetch seam: one guarded GET → {status, headers, body} or null on
     * transport failure. Null uses the bounded stream-context fopen path.
     *
     * @var (callable(string): array{status: int, headers: list<string>, body: string}|null)|null
     */
    private $fetcher;

    /**
     * @param SsdpSocket $socket SSDP socket instance
     * @param LoggerInterface|null $logger Optional logger
     * @param (callable(string): list<string>)|null $hostResolver H1 test/boot DNS seam
     * @param (callable(string): array{status: int, headers: list<string>, body: string}|null)|null $fetcher
     */
    public function __construct(
        SsdpSocket $socket,
        ?LoggerInterface $logger = null,
        ?callable $hostResolver = null,
        ?callable $fetcher = null
    ) {
        $this->socket = $socket;
        $this->logger = $logger ?? new NullLogger();
        $this->hostResolver = $hostResolver;
        $this->fetcher = $fetcher;
    }

    /**
     * Discover all DLNA/UPnP devices on the network.
     *
     * Sends an M-SEARCH request and collects responses, then parses
     * each response into SsdpDevice objects.
     *
     * @param string $st Search target (default: all UPnP devices)
     * @return SsdpDevice[] Array of discovered devices
     *
     * @since 0.12.0
     */
    public function discoverDevices(string $st = self::DEFAULT_ST): array
    {
        $responses = $this->socket->search($st, 3);

        if (empty($responses)) {
            $this->logger->info('SSDP: No devices discovered');
            return [];
        }

        $devices = [];
        foreach ($responses as $response) {
            $parsed = $this->socket->parseResponse($response);
            if ($parsed === null) {
                continue;
            }

            $device = $this->createDeviceFromParsed($parsed);
            if ($device !== null) {
                $devices[] = $device;
            }
        }

        $this->logger->debug('SSDP: Discovered {count} devices', ['count' => count($devices)]);
        return $devices;
    }

    /**
     * Announce the Phlix server via SSDP NOTIFY.
     *
     * @param string $serverId Unique server identifier (UUID)
     * @param string $friendlyName Human-readable server name
     * @param string $baseUrl Base URL of the Phlix server
     * @param int $port Phlix server port
     *
     * @since 0.12.0
     */
    public function announceServer(string $serverId, string $friendlyName, string $baseUrl, int $port): void
    {
        $usn = "uuid:phlix-server-{$serverId}::urn:schemas-upnp-org:device:MediaServer:1";
        $nt = 'urn:schemas-upnp-org:device:MediaServer:1';
        // L6: a baseUrl that already carries a port (http://host:8096) must not
        // get the port appended a second time (http://host:8096:8096).
        $trimmedBase = rtrim($baseUrl, '/');
        $baseHasPort = is_int(parse_url($trimmedBase, PHP_URL_PORT));
        $location = $baseHasPort ? $trimmedBase : $trimmedBase . ':' . $port;

        $this->socket->announce($nt, $location, $usn);

        $this->logger->info('SSDP: Announced server', [
            'serverId' => $serverId,
            'friendlyName' => $friendlyName,
            'location' => $location,
        ]);
    }

    /**
     * Parse a device description URL and return parsed data.
     *
     * H1: the URL is attacker-influenced (raw SSDP LOCATION header), so every
     * request goes through the LAN-only gate BEFORE any socket is opened —
     * scheme must be http(s) with no userinfo, the host must resolve ONLY to
     * RFC1918 addresses (loopback / 169.254 link-local metadata / CGNAT / all
     * public ranges refused), redirects are followed manually with each hop
     * re-entering the gate, and the response body is capped. A refused URL
     * produces zero network I/O.
     *
     * @param string $locationUrl Device description URL
     * @return array<string, mixed>|null Parsed device data or null on failure
     *
     * @since 0.12.0
     */
    public function resolveDeviceDescription(string $locationUrl): ?array
    {
        $normalized = $this->normalizeLocation($locationUrl);
        if ($normalized === null) {
            $this->logger->warning('SSDP: Refusing device description URL (malformed or non-http scheme)', [
                'url' => $locationUrl,
            ]);
            return null;
        }

        $current = $normalized;
        $xmlContent = null;

        // 1 initial request + MAX_REDIRECT_HOPS guarded follow-ups. The wrapper
        // never follows a hop on its own; each Location is absolutised and
        // re-gated here before the next request.
        for ($hop = 0; $hop <= self::MAX_REDIRECT_HOPS; $hop++) {
            try {
                $this->assertLanAddress($current);
            } catch (InvalidArgumentException $refusal) {
                $this->logger->warning('SSDP: ' . $refusal->getMessage(), ['url' => $current]);
                return null;
            }

            $response = $this->fetchOnceBounded($current);
            if ($response === null) {
                $this->logger->warning('SSDP: Failed to fetch device description', ['url' => $current]);
                return null;
            }

            if (strlen($response['body']) > self::MAX_RESPONSE_BYTES) {
                $this->logger->warning('SSDP: Device description exceeds size cap', [
                    'url' => $current,
                    'cap' => self::MAX_RESPONSE_BYTES,
                ]);
                return null;
            }

            $status = $response['status'];
            if ($status >= 200 && $status < 300) {
                $xmlContent = $response['body'];
                break;
            }

            $location = $this->findHeader($response['headers'], 'location');
            if ($status < 300 || $status >= 400 || $location === null) {
                $this->logger->warning('SSDP: Device description fetch returned HTTP status', [
                    'url' => $current,
                    'status' => $status,
                ]);
                return null;
            }

            $next = ProviderUrlAllowlist::resolveRedirect($current, $location);
            if ($next === null || $next === '') {
                $this->logger->warning('SSDP: Refusing device description redirect hop', [
                    'url' => $current,
                    'location' => $location,
                ]);
                return null;
            }
            $current = $next;

            if ($hop === self::MAX_REDIRECT_HOPS) {
                $this->logger->warning('SSDP: Device description redirect hop budget exhausted', [
                    'url' => $normalized,
                ]);
                return null;
            }
        }

        if ($xmlContent === null) {
            $this->logger->warning('SSDP: No successful device description response', ['url' => $normalized]);
            return null;
        }

        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            $this->logger->warning('SSDP: Invalid XML in device description', ['url' => $normalized]);
            return null;
        }

        $result = [
            'url' => $normalized,
            'xml' => $xmlContent,
        ];

        // Try to extract device info from XML
        if (isset($xml->device)) {
            $device = $xml->device;
            $result['deviceType'] = (string)($device->deviceType ?? '');
            $result['friendlyName'] = (string)($device->friendlyName ?? '');
            $result['manufacturer'] = (string)($device->manufacturer ?? '');
            $result['modelName'] = (string)($device->modelName ?? '');
            $result['udn'] = (string)($device->UDN ?? '');
        }

        return $result;
    }

    /**
     * Normalise a raw LOCATION value into a fetchable http(s) URL, or reject it.
     *
     * A schemeless value gets `http://` prepended (the historic behaviour real
     * devices rely on: `192.168.1.50:49152/desc.xml`); anything that does not
     * parse, is not http(s), or smuggles userinfo is refused here — before the
     * address gate, before any socket.
     *
     * @return string|null The normalised absolute URL, or null when refused.
     */
    private function normalizeLocation(string $locationUrl): ?string
    {
        $candidate = trim($locationUrl);
        if ($candidate === '' || str_contains($candidate, "\0")) {
            return null;
        }

        // Schemeless values (the historic `host:port/path` LOCATION shape) get
        // http:// prepended. Detection is on `scheme://` specifically because
        // bare `parse_url('localhost:8080/x')` misparses `localhost` as a scheme.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) !== 1) {
            $candidate = 'http://' . $candidate;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        if (!isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return null;
        }

        // `http://lan-host@public-host/` must not read as the LAN host.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $candidate;
    }

    /**
     * LAN-only address gate (H1): every address the host resolves to must fall
     * inside {@see self::ALLOWED_LAN_CIDRS}.
     *
     * IPv4-mapped / compatible / NAT64 IPv6 spellings are collapsed through
     * the shared {@see SsrfGuard::embeddedIpv4()} primitive so `::ffff:169.254
     * .169.254`-style smuggling is evaluated on its embedded IPv4 truth. A
     * hostname resolving to a MIX of LAN and other addresses is refused
     * outright — the wrapper may connect to any of them.
     *
     * @throws InvalidArgumentException when the target is not exclusively LAN.
     */
    private function assertLanAddress(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('SSDP: device description URL carries no host.');
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $addresses = $this->resolveHost($host);
        if ($addresses === []) {
            throw new InvalidArgumentException(
                sprintf('SSDP: could not resolve device description host "%s"; refusing.', $host),
            );
        }

        foreach ($addresses as $ip) {
            $effectiveIp = SsrfGuard::embeddedIpv4($ip) ?? $ip;
            if (!SsrfGuard::ipMatchesAnyCidr($effectiveIp, self::ALLOWED_LAN_CIDRS)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'SSDP: refusing device description fetch — host "%s" resolves to non-LAN address %s.',
                        $host,
                        $ip,
                    ),
                );
            }
        }
    }

    /**
     * Resolve a host to its IP list via the injected seam, or the default
     * blocking resolver for a plain hostname. IP literals resolve to themselves.
     *
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolver = $this->hostResolver ?? static function (string $name): array {
            $address = gethostbyname($name);
            return $address !== $name ? [$address] : [];
        };

        $resolved = $resolver($host);

        $out = [];
        foreach ($resolved as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false && !in_array($ip, $out, true)) {
                $out[] = $ip;
            }
        }
        return $out;
    }

    /**
     * One bounded GET: the injected fetch seam when present, else a stream
     * request with `follow_location = 0` (redirects are gated by the caller,
     * never by the wrapper), a 5 s timeout, and a read that stops at
     * MAX_RESPONSE_BYTES + 1 so an oversized body is detectable and bounded.
     *
     * @return array{status: int, headers: list<string>, body: string}|null
     *         Null on any transport failure.
     */
    private function fetchOnceBounded(string $url): ?array
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::FETCH_TIMEOUT_SECONDS,
                'follow_location' => 0,
                'max_redirects' => 1,
                'ignore_errors' => true,
                'header' => "Connection: close\r\n",
            ],
        ]);

        /** @var list<string>|null $http_response_header */
        $http_response_header = [];

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return null;
        }

        $body = stream_get_contents($handle, self::MAX_RESPONSE_BYTES + 1);
        fclose($handle);

        if (!is_string($body)) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', $line, $m) === 1) {
                $status = (int)$m[1];
            }
        }

        if ($status === 0) {
            return null;
        }

        return ['status' => $status, 'headers' => $http_response_header, 'body' => $body];
    }

    /**
     * Case-insensitive single header value from a raw response-header list.
     *
     * @param list<string> $headers
     */
    private function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $line) {
            if (preg_match('#^' . preg_quote($name, '#') . ':\s*(.*?)\s*$#i', $line, $m) === 1) {
                return $m[1] === '' ? null : $m[1];
            }
        }
        return null;
    }

    /**
     * Create an SsdpDevice from parsed SSDP response fields.
     *
     * @param array<string, string> $parsed Parsed response fields
     * @return SsdpDevice|null Device instance or null if invalid
     */
    private function createDeviceFromParsed(array $parsed): ?SsdpDevice
    {
        $usn = $parsed['USN'] ?? '';
        $nt = $parsed['NT'] ?? '';
        $location = $parsed['LOCATION'] ?? '';
        $server = $parsed['SERVER'] ?? '';
        $cacheControl = $parsed['CACHE-CONTROL'] ?? '';

        if ($usn === '' || $nt === '') {
            return null;
        }

        $cacheTimeout = $this->parseCacheTimeout($cacheControl);

        // Try to extract device type from NT
        $deviceType = null;
        if (strpos($nt, 'urn:') === 0) {
            $deviceType = $nt;
        }

        return new SsdpDevice(
            $usn,
            $nt,
            $location,
            $server,
            $cacheTimeout,
            $deviceType
        );
    }

    /**
     * Parse CACHE-CONTROL max-age value.
     *
     * @param string $cacheControl CACHE-CONTROL header value
     * @return int Timeout in seconds
     */
    private function parseCacheTimeout(string $cacheControl): int
    {
        if (preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
            return (int)$matches[1];
        }

        return 1800; // Default 30 minutes
    }
}
