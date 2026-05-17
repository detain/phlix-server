<?php

declare(strict_types=1);

namespace Phlex\Network;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * STUN client (RFC 5389) for discovering the server's public IP address.
 *
 * Sends a binding request to a STUN server and extracts the XOR-MAPPED-ADDRESS
 * from the response.
 *
 * @package Phlex\Network
 * @since 0.11.0
 */
class StunClient
{
    public const DEFAULT_STUN_SERVER = 'stun.l.google.com';
    public const DEFAULT_STUN_PORT = 19302;

    private const STUN_MAGIC_COOKIE = 0x2112A442;
    private const STUN_HEADER_SIZE = 20;

    private LoggerInterface $logger;
    private string $stunServer;
    private int $stunPort;

    public function __construct(
        ?LoggerInterface $logger = null,
        string $stunServer = self::DEFAULT_STUN_SERVER,
        int $stunPort = self::DEFAULT_STUN_PORT
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->stunServer = $stunServer;
        $this->stunPort = $stunPort;
    }

    /**
     * Returns the server's public IP address as seen from outside.
     *
     * Sends a RFC 5389 binding request to the configured STUN server
     * and extracts the XOR-MAPPED-ADDRESS from the response.
     *
     * @return string|null The public IP address or null on failure.
     */
    public function getPublicIp(): ?string
    {
        $socket = $this->createUdpSocket();
        if ($socket === null) {
            return null;
        }

        $request = $this->buildBindingRequest();
        $sent = @socket_sendto($socket, $request, strlen($request), 0, $this->stunServer, $this->stunPort);
        if ($sent === false) {
            socket_close($socket);
            return null;
        }

        $response = null;
        $fromAddr = null;
        $fromPort = null;

        $read = [$socket];
        $write = null;
        $except = null;
        $modified = @socket_select($read, $write, $except, 3, 0);
        if ($modified === false || $modified === 0) {
            socket_close($socket);
            return null;
        }

        $recvLen = @socket_recvfrom($socket, $response, 65536, 0, $fromAddr, $fromPort);
        socket_close($socket);

        if ($recvLen === false || $recvLen < self::STUN_HEADER_SIZE) {
            return null;
        }

        return $this->parseXorMappedAddress($response, self::STUN_HEADER_SIZE);
    }

    /**
     * Tests whether a given IP:port is reachable from the outside.
     *
     * Attempts a TCP connect to the target. Returns true if the connection
     * succeeds or is refused (meaning the port is accessible but nothing
     * is listening). Returns false if the connection times out or fails
     * in a way that indicates a firewall is blocking it.
     *
     * @param string $ip   Target IP address.
     * @param int    $port Target port.
     *
     * @return bool True if the port appears accessible from outside.
     */
    public function testPortAccessibility(string $ip, int $port): bool
    {
        $socket = @fsockopen('tcp://' . $ip, $port, $errno, $errstr, 3);
        if ($socket !== false) {
            fclose($socket);
            return true;
        }

        return $errno === 111 || $errno === 0;
    }

    /**
     * Builds a RFC 5389 binding request message.
     */
    private function buildBindingRequest(): string
    {
        $msgType = 0x0001;
        $msgLength = 0;
        $magicCookie = self::STUN_MAGIC_COOKIE;
        $transactionId = $this->generateTransactionId();

        $header = pack('n', $msgType);
        $header .= pack('n', $msgLength);
        $header .= pack('N', $magicCookie);
        $header .= $transactionId;

        return $header;
    }

    /**
     * Generates a 12-byte random transaction ID.
     */
    private function generateTransactionId(): string
    {
        $id = '';
        for ($i = 0; $i < 12; $i++) {
            $id .= chr(mt_rand(0, 255));
        }
        return $id;
    }

    /**
     * Parses the XOR-MAPPED-ADDRESS attribute from a STUN response.
     */
    private function parseXorMappedAddress(string $data, int $offset): ?string
    {
        $attrType = null;
        $attrLen = null;

        while ($offset + 4 <= strlen($data)) {
            $attrHeader = substr($data, $offset, 4);
            $attrType = unpack('n', substr($attrHeader, 0, 2))[1];
            $attrLen = unpack('n', substr($attrHeader, 2, 2))[1];

            if ($offset + 4 + $attrLen > strlen($data)) {
                break;
            }

            if ($attrType === 0x0020) {
                $attrData = substr($data, $offset + 4, $attrLen);
                return $this->decodeXorAddress($attrData);
            }

            $offset += 4 + $attrLen;
            if ($attrLen % 4 !== 0) {
                $offset += 4 - ($attrLen % 4);
            }
        }

        return null;
    }

    /**
     * Decodes the XOR-mapped IP address from attribute data.
     */
    private function decodeXorAddress(string $data): ?string
    {
        if (strlen($data) < 8) {
            return null;
        }

        $family = unpack('n', substr($data, 0, 2))[1];
        $port = unpack('n', substr($data, 2, 2))[1] ^ (self::STUN_MAGIC_COOKIE >> 16);

        if ($family === 0x0001) {
            $ipBytes = substr($data, 4, 4);
            $xorMask = pack('N', self::STUN_MAGIC_COOKIE);
            $xored = '';
            for ($i = 0; $i < 4; $i++) {
                $xored .= $ipBytes[$i] ^ $xorMask[$i];
            }
            $ip = sprintf('%d.%d.%d.%d', ord($xored[0]), ord($xored[1]), ord($xored[2]), ord($xored[3]));
            return inet_ntop(inet_pton($ip)) ?: null;
        }

        return null;
    }

    /**
     * Creates a UDP socket for STUN communication.
     */
    private function createUdpSocket(): mixed
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return null;
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => 5,
            'usec' => 0,
        ]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
            'sec' => 5,
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
