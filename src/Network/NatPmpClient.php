<?php

declare(strict_types=1);

namespace Phlix\Network;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;

/**
 * NAT-PMP client (RFC 6886) for Apple NAT-PMP compatible routers.
 *
 * Communicates with the NAT-PMP gateway on UDP port 5350/5351 to
 * request port mappings without requiring SSDP discovery.
 *
 * @package Phlix\Network
 * @since 0.11.0
 */
class NatPmpClient
{
    private const NAT_PMP_PORT = 5350;
    private const VERSION = 0;
    private const OP_CODE_MAP_TCP = 1;
    private const OP_CODE_MAP_UDP = 2;
    private const RESPONSE_FLAG = 0x80;

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
     * Discovers the NAT-PMP gateway address on the LAN.
     *
     * Sends a NAT-PMP public address request to the gateway address
     * (typically 192.168.1.1) and expects a response.
     *
     * @param string $gatewayIp The router's LAN IP address.
     *
     * @return string|null The external IP address or null on failure.
     */
    public function discoverGateway(string $gatewayIp): ?string
    {
        $socket = $this->createUdpSocket();
        if ($socket === null) {
            $this->logger->debug('NAT-PMP: failed to create UDP socket');
            return null;
        }

        $request = $this->buildPublicAddressRequest();
        $sent = @socket_sendto($socket, $request, strlen($request), 0, $gatewayIp, self::NAT_PMP_PORT);
        if ($sent === false) {
            socket_close($socket);
            return null;
        }

        $response = '';
        $fromAddr = '';
        $fromPort = 0;

        $startTime = microtime(true);

        while ((microtime(true) - $startTime) * 1000 < $this->timeout) {
            $read = [$socket];
            $write = null;
            $except = null;
            $modified = @socket_select($read, $write, $except, 0, 500000);
            if ($modified === false || $modified === 0) {
                usleep(100000);
                continue;
            }

            $recvLen = @socket_recvfrom($socket, $response, 1024, 0, $fromAddr, $fromPort);
            if ($recvLen === false || $recvLen < 12) {
                continue;
            }

            socket_close($socket);
            return $this->parseExternalIp($response);
        }

        socket_close($socket);
        return null;
    }

    /**
     * Adds a TCP port mapping via NAT-PMP.
     *
     * @param string $gatewayIp   The router's LAN IP address.
     * @param int    $externalPort The external port to request.
     * @param int    $internalPort The internal port on the server.
     * @param int    $leaseDuration Mapping lease in seconds (3600 default).
     *
     * @return int|null The assigned external port or null on failure.
     */
    public function addPortMapping(
        string $gatewayIp,
        int $externalPort,
        int $internalPort,
        int $leaseDuration = 3600
    ): ?int {
        return $this->mapPort($gatewayIp, self::OP_CODE_MAP_TCP, $externalPort, $internalPort, $leaseDuration);
    }

    /**
     * Removes a port mapping via NAT-PMP.
     *
     * @param string $gatewayIp   The router's LAN IP address.
     * @param int    $externalPort The external port to remove.
     * @param string $protocol     Protocol (TCP or UDP).
     *
     * @return bool True on success, false on failure.
     */
    public function removePortMapping(
        string $gatewayIp,
        int $externalPort,
        string $protocol = 'TCP'
    ): bool {
        $opCode = strtoupper($protocol) === 'UDP' ? self::OP_CODE_MAP_UDP : self::OP_CODE_MAP_TCP;
        $socket = $this->createUdpSocket();
        if ($socket === null) {
            return false;
        }

        $request = $this->buildUnmapRequest($opCode, $externalPort);
        $sent = @socket_sendto($socket, $request, strlen($request), 0, $gatewayIp, self::NAT_PMP_PORT);
        if ($sent === false) {
            socket_close($socket);
            return false;
        }

        $response = '';
        $fromAddr = '';
        $fromPort = 0;

        $startTime = microtime(true);
        while ((microtime(true) - $startTime) * 1000 < $this->timeout) {
            $read = [$socket];
            $write = null;
            $except = null;
            $modified = @socket_select($read, $write, $except, 0, 500000);
            if ($modified === false || $modified === 0) {
                usleep(100000);
                continue;
            }

            $recvLen = @socket_recvfrom($socket, $response, 1024, 0, $fromAddr, $fromPort);
            socket_close($socket);

            if ($recvLen !== false && $recvLen >= 12 && strlen($response) >= 2) {
                $responseOpCode = ord($response[1]);
                if ($responseOpCode === ($opCode | self::RESPONSE_FLAG)) {
                    return true;
                }
            }
            return false;
        }

        socket_close($socket);
        return false;
    }

    /**
     * Maps a port via NAT-PMP and returns the assigned external port.
     */
    private function mapPort(
        string $gatewayIp,
        int $opCode,
        int $externalPort,
        int $internalPort,
        int $leaseDuration
    ): ?int {
        $socket = $this->createUdpSocket();
        if ($socket === null) {
            return null;
        }

        $request = $this->buildMapRequest($opCode, $externalPort, $internalPort, $leaseDuration);
        $sent = @socket_sendto($socket, $request, strlen($request), 0, $gatewayIp, self::NAT_PMP_PORT);
        if ($sent === false) {
            socket_close($socket);
            return null;
        }

        $response = '';
        $fromAddr = '';
        $fromPort = 0;

        $startTime = microtime(true);
        while ((microtime(true) - $startTime) * 1000 < $this->timeout) {
            $read = [$socket];
            $write = null;
            $except = null;
            $modified = @socket_select($read, $write, $except, 0, 500000);
            if ($modified === false || $modified === 0) {
                usleep(100000);
                continue;
            }

            $recvLen = @socket_recvfrom($socket, $response, 1024, 0, $fromAddr, $fromPort);
            socket_close($socket);

            if ($recvLen !== false && $recvLen >= 16 && strlen($response) >= 12) {
                $responseOpCode = ord($response[1]);
                if ($responseOpCode === ($opCode | self::RESPONSE_FLAG)) {
                    $parts = unpack('n', substr($response, 10, 2));
                    if (is_array($parts) && isset($parts[1]) && is_int($parts[1])) {
                        return $parts[1];
                    }
                }
            }
            return null;
        }

        socket_close($socket);
        return null;
    }

    /**
     * Builds a NAT-PMP public address request.
     */
    private function buildPublicAddressRequest(): string
    {
        return chr(self::VERSION) . chr(0) . pack('n', 0);
    }

    /**
     * Builds a NAT-PMP map request.
     */
    private function buildMapRequest(
        int $opCode,
        int $externalPort,
        int $internalPort,
        int $leaseDuration
    ): string {
        return chr(self::VERSION) . chr($opCode)
            . pack('n', 0) . pack('n', $externalPort)
            . pack('n', $internalPort) . pack('N', $leaseDuration);
    }

    /**
     * Builds a NAT-PMP unmap request.
     */
    private function buildUnmapRequest(int $opCode, int $externalPort): string
    {
        return chr(self::VERSION) . chr($opCode)
            . pack('n', 0) . pack('n', $externalPort)
            . pack('n', 0) . pack('N', 0);
    }

    /**
     * Parses the external IP from a NAT-PMP response.
     */
    private function parseExternalIp(string $response): ?string
    {
        if (strlen($response) < 12) {
            return null;
        }

        $ipBytes = substr($response, 4, 4);
        if (strlen($ipBytes) < 4) {
            return null;
        }

        return sprintf('%d.%d.%d.%d', ord($ipBytes[0]), ord($ipBytes[1]), ord($ipBytes[2]), ord($ipBytes[3]));
    }

    /**
     * Creates a UDP socket for NAT-PMP communication.
     */
    private function createUdpSocket(): ?Socket
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return null;
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => (int) floor($this->timeout / 1000),
            'usec' => ($this->timeout % 1000) * 1000,
        ]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
            'sec' => 3,
            'usec' => 0,
        ]);

        $localIp = $this->getLocalIpAddress();
        if ($localIp !== null) {
            @socket_bind($socket, $localIp, 0);
        }

        return $socket;
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

        foreach ($connections as $info) {
            if (!is_array($info) || !isset($info['unicast']) || !is_array($info['unicast'])) {
                continue;
            }
            foreach ($info['unicast'] as $addr) {
                if (!is_array($addr) || !isset($addr['address']) || !is_string($addr['address'])) {
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
            $localAddr = stream_socket_get_name($sock, false);
            fclose($sock);
            if ($localAddr !== false && $localAddr !== '') {
                $colonPos = strrpos($localAddr, ':');
                $host = $colonPos !== false ? substr($localAddr, 0, $colonPos) : $localAddr;
                if ($host !== '') {
                    return $host;
                }
            }
        }

        return null;
    }
}
