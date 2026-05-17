<?php

declare(strict_types=1);

namespace Phlex\Network;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * UPnP Internet Gateway Device client using raw sockets.
 *
 * Implements SSDP M-SEARCH discovery and SOAP AddPortMapping / GetExternalIPAddress
 * actions to automatically configure port forwarding on compatible routers.
 *
 * @package Phlex\Network
 * @since 0.11.0
 */
class UpnpIgdClient
{
    private const SSDP_MULTICAST_ADDR = '239.255.255.250';
    private const SSDP_PORT = 1900;
    private const SSDP_SEARCH_TARGET = 'urn:schemas-upnp-org:device:InternetGatewayDevice:1';
    private const SSDP_MSEARCH = "M-SEARCH * HTTP/1.1\r\nHOST: %s:%d\r\nMAN: \"ssdp:discover\"\r\nMX: 3\r\nST: %s\r\n\r\n";

    private LoggerInterface $logger;
    private int $timeout;

    public function __construct(
        ?LoggerInterface $logger = null,
        int $timeout = 3000
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->timeout = $timeout;
    }

    /**
     * Discovers a UPnP IGD on the LAN via SSDP M-SEARCH.
     *
     * Sends a UDP multicast search request and waits up to $timeout ms
     * for a HTTP 200 response containing a LOCATION header pointing to
     * the device's control URL.
     *
     * @return string|null The gateway's control URL (e.g. http://192.168.1.1:1900/xml/gateway.xml)
     *                     or null if no gateway responds within the timeout.
     */
    public function discoverGateway(): ?string
    {
        $socket = $this->createUdpSocket();
        if ($socket === null) {
            return null;
        }

        $searchReq = sprintf(
            self::SSDP_MSEARCH,
            self::SSDP_MULTICAST_ADDR,
            self::SSDP_PORT,
            self::SSDP_SEARCH_TARGET
        );

        $sent = @socket_sendto($socket, $searchReq, strlen($searchReq), 0, self::SSDP_MULTICAST_ADDR, self::SSDP_PORT);
        if ($sent === false) {
            socket_close($socket);
            return null;
        }

        $gatewayUrl = null;
        $startTime = microtime(true);
        $timeoutSec = (int) floor($this->timeout / 1000);
        $timeoutUsec = ($this->timeout % 1000) * 1000;

        while ($gatewayUrl === null) {
            $elapsed = (microtime(true) - $startTime) * 1000;
            if ($elapsed >= $this->timeout) {
                break;
            }

            $remaining = $this->timeout - (int)($elapsed * 1000);
            $sec = (int) floor($remaining / 1000);
            $usec = ($remaining % 1000) * 1000;

            $read = null;
            $write = null;
            $except = null;
            $r = [$socket];

            $modified = @socket_select($r, $write, $except, $sec, $usec);
            if ($modified === false || $modified === 0) {
                continue;
            }

            $resp = null;
            $fromAddr = null;
            $fromPort = null;

            $recvLen = @socket_recvfrom($socket, $resp, 65536, 0, $fromAddr, $fromPort);
            if ($recvLen === false || $recvLen === 0) {
                continue;
            }

            $gatewayUrl = $this->parseSsdpResponse($resp);
        }

        socket_close($socket);
        return $gatewayUrl;
    }

    /**
     * Fetches the gateway's external (WAN) IP address via SOAP GetExternalIPAddress.
     *
     * @param string $gatewayUrl The control URL from discoverGateway().
     *
     * @return string|null The external IP address or null on failure.
     */
    public function getExternalIp(string $gatewayUrl): ?string
    {
        $descUrl = $this->fetchDeviceDescription($gatewayUrl);
        if ($descUrl === null) {
            return null;
        }

        $controlUrl = $this->findWanIpConnectionService($descUrl);
        if ($controlUrl === null) {
            $controlUrl = $this->findWanConnectionDevice($descUrl);
            if ($controlUrl === null) {
                return null;
            }
        }

        $soapBody = '<?xml version="1.0" encoding="utf-8"?>' .
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" ' .
            's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
            '<s:Body><u:GetExternalIPAddress xmlns:u="urn:schemas-upnp-org:service:WANIPConnection:1" /></s:Body>' .
            '</s:Envelope>';

        $response = $this->soapRequest($controlUrl, 'GetExternalIPAddress', $soapBody);
        if ($response === null) {
            return null;
        }

        return $this->extractExternalIp($response);
    }

    /**
     * Adds a port mapping via SOAP AddPortMapping.
     *
     * @param string $gatewayUrl  The control URL from discoverGateway().
     * @param string $externalPort External port to open on the gateway.
     * @param string $internalIp   Internal server IP address.
     * @param string $internalPort Internal port on the server.
     * @param string $protocol     Protocol (TCP or UDP).
     * @param int    $leaseDuration Lease duration in seconds (0 = permanent).
     *
     * @return bool True on success, false on failure.
     */
    public function addPortMapping(
        string $gatewayUrl,
        string $externalPort,
        string $internalIp,
        string $internalPort,
        string $protocol = 'TCP',
        int $leaseDuration = 0
    ): bool {
        $descUrl = $this->fetchDeviceDescription($gatewayUrl);
        if ($descUrl === null) {
            return false;
        }

        $controlUrl = $this->findWanIpConnectionService($descUrl);
        if ($controlUrl === null) {
            $controlUrl = $this->findWanConnectionDevice($descUrl);
            if ($controlUrl === null) {
                return false;
            }
        }

        $soapBody = '<?xml version="1.0" encoding="utf-8"?>' .
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" ' .
            's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
            '<s:Body>' .
            '<u:AddPortMapping xmlns:u="urn:schemas-upnp-org:service:WANIPConnection:1">' .
            '<NewRemoteHost></NewRemoteHost>' .
            '<NewExternalPort>' . $externalPort . '</NewExternalPort>' .
            '<NewProtocol>' . $protocol . '</NewProtocol>' .
            '<NewInternalPort>' . $internalPort . '</NewInternalPort>' .
            '<NewInternalClient>' . $internalIp . '</NewInternalClient>' .
            '<NewEnabled>1</NewEnabled>' .
            '<NewPortMappingDescription>Phlex Media Server</NewPortMappingDescription>' .
            '<NewLeaseDuration>' . $leaseDuration . '</NewLeaseDuration>' .
            '</u:AddPortMapping>' .
            '</s:Body>' .
            '</s:Envelope>';

        $response = $this->soapRequest($controlUrl, 'AddPortMapping', $soapBody);
        return $response !== null;
    }

    /**
     * Removes a port mapping via SOAP DeletePortMapping.
     *
     * @param string $gatewayUrl  The control URL from discoverGateway().
     * @param string $externalPort External port to remove.
     * @param string $protocol     Protocol (TCP or UDP).
     *
     * @return bool True on success, false on failure.
     */
    public function removePortMapping(
        string $gatewayUrl,
        string $externalPort,
        string $protocol = 'TCP'
    ): bool {
        $descUrl = $this->fetchDeviceDescription($gatewayUrl);
        if ($descUrl === null) {
            return false;
        }

        $controlUrl = $this->findWanIpConnectionService($descUrl);
        if ($controlUrl === null) {
            $controlUrl = $this->findWanConnectionDevice($descUrl);
            if ($controlUrl === null) {
                return false;
            }
        }

        $soapBody = '<?xml version="1.0" encoding="utf-8"?>' .
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" ' .
            's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
            '<s:Body>' .
            '<u:DeletePortMapping xmlns:u="urn:schemas-upnp-org:service:WANIPConnection:1">' .
            '<NewRemoteHost></NewRemoteHost>' .
            '<NewExternalPort>' . $externalPort . '</NewExternalPort>' .
            '<NewProtocol>' . $protocol . '</NewProtocol>' .
            '</u:DeletePortMapping>' .
            '</s:Body>' .
            '</s:Envelope>';

        $response = $this->soapRequest($controlUrl, 'DeletePortMapping', $soapBody);
        return $response !== null;
    }

    /**
     * Creates a UDP socket for SSDP communication.
     *
     * @return resource|Socket|null
     */
    private function createUdpSocket(): mixed
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return null;
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => 3,
            'usec' => 0,
        ]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
            'sec' => 3,
            'usec' => 0,
        ]);

        $bindAddr = $this->getLocalIpAddress();
        if ($bindAddr !== null) {
            @socket_bind($socket, $bindAddr, 0);
        }

        @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 2);

        return $socket;
    }

    /**
     * Parses an SSDP HTTP NOTIFY or HTTP/1.1 200 OK response to extract the LOCATION header.
     */
    private function parseSsdpResponse(string $response): ?string
    {
        if (preg_match('/^HTTP\/1\.\d\s+200/is', $response)) {
            if (preg_match('/^LOCATION:\s*(.+)$/mi', $response, $matches)) {
                return trim($matches[1]);
            }
        }
        if (preg_match('/^NOTIFY\s+/is', $response)) {
            if (preg_match('/^LOCATION:\s*(.+)$/mi', $response, $matches)) {
                return trim($matches[1]);
            }
        }
        return null;
    }

    /**
     * Fetches the device description XML from the LOCATION URL.
     *
     * @return string|null URL to the device description XML.
     */
    private function fetchDeviceDescription(string $locationUrl): ?string
    {
        $parsed = @parse_url($locationUrl);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return null;
        }

        $port = $parsed['port'] ?? 80;
        $path = $parsed['path'] ?? '/';

        $sock = @fsockopen($parsed['host'], $port, $errno, $errstr, 5);
        if ($sock === false) {
            return null;
        }

        $request = "GET {$path} HTTP/1.1\r\nHost: {$parsed['host']}:{$port}\r\nAccept: */*\r\nConnection: close\r\n\r\n";
        @fwrite($sock, $request);

        $response = '';
        while (!feof($sock)) {
            $response .= @fgets($sock, 4096);
        }
        @fclose($sock);

        $body = preg_replace('/^[^\r\n]*\r\n/', '', $response, 1);
        $body = preg_replace('/\r\n[^\r\n]+\r\n\r\n/', "\r\n\r\n", $body);

        if (preg_match('/<deviceDescriptionURL>(.+?)<\/deviceDescriptionURL>/i', $body, $matches)) {
            return $this->resolveUrl($locationUrl, $matches[1]);
        }

        if (preg_match('/<URLBase>(.+?)<\/URLBase>/i', $body, $matches)) {
            $urlBase = trim($matches[1]);
            if (preg_match('/<deviceList>.*?<\/deviceList>/is', $body, $deviceListMatch)) {
                if (preg_match('/<device>.*?<deviceType>.*?InternetGatewayDevice.*?<\/deviceType>.*?<deviceList>(.*?)<\/deviceList>.*?<\/device>/is', $deviceListMatch[0], $igDeviceMatch)) {
                    if (preg_match('/<device>.*?<deviceType>.*?WANDevice.*?<\/deviceType>.*?<deviceList>(.*?)<\/deviceList>.*?<\/device>/is', $igDeviceMatch[0], $wanDeviceMatch)) {
                        if (preg_match('/<device>.*?<serviceList>(.*?)<\/serviceList>.*?<\/device>/is', $wanDeviceMatch[0], $serviceListMatch)) {
                            if (preg_match('/<controlURL>(.+?)<\/controlURL>/is', $serviceListMatch[0], $controlMatch)) {
                                return $this->resolveUrl($locationUrl, trim($controlMatch[1]));
                            }
                        }
                    }
                }
            }
        }

        return $locationUrl;
    }

    /**
     * Resolves a relative URL against a base URL.
     */
    private function resolveUrl(string $base, string $relative): string
    {
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        $parsed = @parse_url($base);
        if (!is_array($parsed)) {
            return $relative;
        }

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? '';
        $path = $parsed['path'] ?? '/';

        if (str_starts_with($relative, '/')) {
            return $scheme . '://' . $host . ($port ? ':' . $port : '') . $relative;
        }

        $dir = dirname($path);
        if (!str_ends_with($dir, '/')) {
            $dir .= '/';
        }

        return $scheme . '://' . $host . ($port ? ':' . $port : '') . $dir . $relative;
    }

    /**
     * Finds the WAN IP connection service control URL from the device description.
     */
    private function findWanIpConnectionService(string $descUrl): ?string
    {
        $parsed = @parse_url($descUrl);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return null;
        }

        $port = $parsed['port'] ?? 80;
        $path = $parsed['path'] ?? '/';

        $content = $this->httpGet($parsed['host'], $port, $path);
        if ($content === null) {
            return null;
        }

        if (preg_match_all('/<service>.*?<serviceType>urn:schemas-upnp-org:service:WANIPConnection:1<\/serviceType>.*?<controlURL>(.+?)<\/controlURL>.*?<\/service>/is', $content, $matches)) {
            return $this->resolveUrl($descUrl, trim(end($matches[1])));
        }

        return null;
    }

    /**
     * Finds the WAN connection device control URL as fallback.
     */
    private function findWanConnectionDevice(string $descUrl): ?string
    {
        $parsed = @parse_url($descUrl);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return null;
        }

        $port = $parsed['port'] ?? 80;
        $path = $parsed['path'] ?? '/';

        $content = $this->httpGet($parsed['host'], $port, $path);
        if ($content === null) {
            return null;
        }

        if (preg_match_all('/<device>.*?<deviceType>urn:schemas-upnp-org:device:WANConnectionDevice:1<\/deviceType>.*?<serviceList>(.*?)<\/serviceList>.*?<\/device>/is', $content, $matches)) {
            foreach ($matches[1] as $serviceList) {
                if (preg_match('/<controlURL>(.+?)<\/controlURL>/is', $serviceList, $ctrlMatch)) {
                    return $this->resolveUrl($descUrl, trim($ctrlMatch[1]));
                }
            }
        }

        return null;
    }

    /**
     * Performs an HTTP GET request and returns the body.
     */
    private function httpGet(string $host, int $port, string $path): ?string
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($sock === false) {
            return null;
        }

        $request = "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nAccept: */*\r\nConnection: close\r\n\r\n";
        @fwrite($sock, $request);

        $response = '';
        while (!feof($sock)) {
            $response .= @fgets($sock, 4096);
        }
        @fclose($sock);

        if (preg_match('/\r\n\r\n(.*)$/s', $response, $matches)) {
            return $matches[1];
        }

        return preg_replace('/^[^\r\n]*\r\n/', '', $response, 1);
    }

    /**
     * Sends a SOAP request over HTTP and returns the response body.
     */
    private function soapRequest(string $url, string $action, string $body): ?string
    {
        $parsed = @parse_url($url);
        if (!is_array($parsed) || !isset($parsed['host'], $parsed['scheme'])) {
            return null;
        }

        $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
        $path = $parsed['path'] ?? '/';

        $hostHeader = $parsed['host'];
        if ($port !== ($parsed['scheme'] === 'https' ? 443 : 80)) {
            $hostHeader .= ':' . $port;
        }

        $soapAction = 'urn:schemas-upnp-org:service:WANIPConnection:1#' . $action;

        $httpBody = 'POST ' . $path . ' HTTP/1.1' . "\r\n" .
            'Host: ' . $hostHeader . "\r\n" .
            'Content-Type: text/xml; charset="utf-8"' . "\r\n" .
            'SOAPACTION: "' . $soapAction . '"' . "\r\n" .
            'Content-Length: ' . strlen($body) . "\r\n" .
            'Connection: close' . "\r\n\r\n" . $body;

        $context = null;
        if ($parsed['scheme'] === 'https') {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
        }

        $sock = @stream_socket_client(
            ($parsed['scheme'] === 'https' ? 'ssl://' : 'tcp://') . $parsed['host'] . ':' . $port,
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($sock === false) {
            return null;
        }

        @fwrite($sock, $httpBody);

        $response = '';
        while (!feof($sock)) {
            $chunk = @fgets($sock, 4096);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }
        @fclose($sock);

        if (preg_match('/\r\n\r\n(.*)$/s', $response, $matches)) {
            return $matches[1];
        }

        return preg_replace('/^[^\r\n]*\r\n/', '', $response, 1);
    }

    /**
     * Extracts the external IP address from a GetExternalIPAddress SOAP response.
     */
    private function extractExternalIp(string $responseBody): ?string
    {
        if (preg_match('/<NewExternalIPAddress>(\d+\.\d+\.\d+\.\d+)<\/NewExternalIPAddress>/i', $responseBody, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Returns the local IP address of this machine.
     */
    private function getLocalIpAddress(): ?string
    {
        $connections = @net_get_interfaces();
        if (!is_array($connections)) {
            return null;
        }

        foreach ($connections as $name => $info) {
            if (!is_array($info) || !isset($info['unicast'])) {
                continue;
            }
            foreach ($info['unicast'] as $addr) {
                if (!is_array($addr) || !isset($addr['address'])) {
                    continue;
                }
                $ip = $addr['address'];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        $sock = @fsockopen('8.8.8.8', 53, $errno, $errstr, 2);
        if ($sock !== false) {
            $localAddr = null;
            socket_getsockname($sock, $localAddr);
            fclose($sock);
            if ($localAddr !== false && is_string($localAddr)) {
                return $localAddr;
            }
        }

        return null;
    }
}
