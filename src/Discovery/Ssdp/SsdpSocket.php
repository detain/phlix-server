<?php

declare(strict_types=1);

namespace Phlix\Discovery\Ssdp;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Raw UDP socket wrapper for SSDP (Simple Service Discovery Protocol).
 *
 * SSDP uses UDP multicast address 239.255.255.250 port 1900 for discovering
 * DLNA/UPnP devices on the network.
 *
 * @since 0.12.0
 */
class SsdpSocket
{
    /** SSDP multicast address */
    public const MULTICAST_ADDR = '239.255.255.250';

    /** SSDP port */
    public const PORT = 1900;

    /** @var \Socket|null Raw socket */
    private \Socket|null $socket = null;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var int Socket timeout in seconds */
    private int $timeoutSecs;

    /**
     * @param LoggerInterface|null $logger Logger instance
     * @param int $timeoutSecs Socket timeout in seconds
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        int $timeoutSecs = 5
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->timeoutSecs = $timeoutSecs;
    }

    /**
     * Send an SSDP M-SEARCH and return raw responses.
     *
     * @param string $st Search target (e.g., 'urn:schemas-upnp-org:device:*')
     * @param int $mx Maximum wait time in seconds
     * @return array<string> Array of raw response strings
     *
     * @since 0.12.0
     */
    public function search(string $st, int $mx = 3): array
    {
        $socket = $this->createSocket();
        if ($socket === null) {
            return [];
        }

        $searchRequest = $this->buildSearchRequest($st, $mx);
        $sent = @socket_sendto($socket, $searchRequest, strlen($searchRequest), 0, self::MULTICAST_ADDR, self::PORT);

        if ($sent === false) {
            $this->logger->warning('SSDP: Failed to send M-SEARCH');
            $this->close();
            return [];
        }

        /** @var array<string> $responses */
        $responses = $this->receiveResponses($socket);

        return $responses;
    }

    /**
     * Send an SSDP NOTIFY announcement.
     *
     * @param string $nt Notification type
     * @param string $location Device description URL
     * @param string $usn Unique Service Name
     *
     * @since 0.12.0
     */
    public function announce(string $nt, string $location, string $usn): void
    {
        $socket = $this->createSocket();
        if ($socket === null) {
            return;
        }

        $notifyMessage = $this->buildNotifyMessage($nt, $location, $usn);
        @socket_sendto($socket, $notifyMessage, strlen($notifyMessage), 0, self::MULTICAST_ADDR, self::PORT);

        $this->close();
    }

    /**
     * Parse a received SSDP response line.
     *
     * Extracts LOCATION, SERVER, NT, USN, and CACHE-CONTROL fields.
     *
     * @param string $data Raw HTTP-like response data
     * @return array<string, string>|null Parsed fields or null if invalid
     *
     * @since 0.12.0
     */
    public function parseResponse(string $data): ?array
    {
        if ($data === '') {
            return null;
        }

        $lines = explode("\r\n", $data);
        if (count($lines) < 2) {
            // Try LF-only line endings
            $lines = explode("\n", $data);
        }

        $result = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            $result[$key] = $value;
        }

        if (empty($result)) {
            return null;
        }

        return $result;
    }

    /**
     * Close the socket.
     *
     * @since 0.12.0
     */
    public function close(): void
    {
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Create and configure the UDP socket.
     *
     * @return \Socket|null Socket or null on failure
     *
     * @phpstan-return \Socket|null
     */
    private function createSocket(): \Socket|null
    {
        if ($this->socket !== null) {
            return $this->socket;
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            $this->logger->error('SSDP: Failed to create socket');
            return null;
        }

        // Set socket timeout
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeoutSecs, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeoutSecs, 'usec' => 0]);

        // Allow multiple processes to bind to the same port
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        // Enable multicast TTL
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 1);

        // Enable multicast loopback
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_LOOP, 1);

        // Bind to any address and the SSDP port
        if (!@socket_bind($socket, '0.0.0.0', self::PORT)) {
            $error = socket_last_error($socket);
            $this->logger->warning("SSDP: Failed to bind to port " . self::PORT . ": {$error}");
            @socket_close($socket);
            return null;
        }

        // Join the multicast group
        $interface = '0.0.0.0';
        @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_IF, $interface);

        $this->socket = $socket;
        return $socket;
    }

    /**
     * Build SSDP M-SEARCH request message.
     *
     * @param string $st Search target
     * @param int $mx Maximum wait time
     * @return string Formatted M-SEARCH request
     */
    private function buildSearchRequest(string $st, int $mx): string
    {
        $host = self::MULTICAST_ADDR . ':' . self::PORT;
        $cacheTimeout = $mx;

        return "M-SEARCH * HTTP/1.1\r\n" .
            "HOST: {$host}\r\n" .
            "MAN: \"ssdp:discover\"\r\n" .
            "MX: {$cacheTimeout}\r\n" .
            "ST: {$st}\r\n" .
            "USER-AGENT: Phlix/1.0\r\n" .
            "\r\n";
    }

    /**
     * Build SSDP NOTIFY message.
     *
     * @param string $nt Notification type
     * @param string $location Device description URL
     * @param string $usn Unique Service Name
     * @return string Formatted NOTIFY request
     */
    private function buildNotifyMessage(string $nt, string $location, string $usn): string
    {
        $host = self::MULTICAST_ADDR . ':' . self::PORT;
        $server = 'Phlix/1.0 UPnP/1.0';
        $cacheTimeout = 1800;

        return "NOTIFY * HTTP/1.1\r\n" .
            "HOST: {$host}\r\n" .
            "NT: {$nt}\r\n" .
            "USN: {$usn}\r\n" .
            "LOCATION: {$location}\r\n" .
            "SERVER: {$server}\r\n" .
            "CACHE-CONTROL: max-age={$cacheTimeout}\r\n" .
            "\r\n";
    }

    /**
     * Receive responses from the socket.
     *
     * @param \Socket $socket Socket instance
     *
     * @return array<string> Collected responses
     */
    private function receiveResponses(\Socket $socket): array
    {
        /** @var array<string> $responses */
        $responses = [];
        $attempts = 0;
        $maxAttempts = 10;

        while ($attempts < $maxAttempts) {
            $data = '';
            $port = 0;
            $from = '';

            $bytesReceived = @socket_recvfrom($socket, $data, 65536, 0, $from, $port);

            if ($bytesReceived === false) {
                break;
            }

            if ($data === '') {
                break;
            }

            $responses[] = $data;
            $attempts++;
        }

        return $responses;
    }

    public function __destruct()
    {
        $this->close();
    }
}
