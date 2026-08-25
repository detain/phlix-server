<?php

/**
 * Phlix media server component: Ssdp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

        // Join the multicast group so inbound SSDP datagrams are delivered here.
        $this->joinMulticastGroup($socket);

        $this->socket = $socket;
        return $socket;
    }

    /**
     * Join the SSDP multicast group on a bound socket.
     *
     * ## The only correct spelling, and the wrong one that looks correct
     *
     * PHP exposes this as `MCAST_JOIN_GROUP` with an **array optval**. The
     * BSD-style `IP_ADD_MEMBERSHIP` + packed `struct ip_mreq` spelling that
     * every C example uses is not available: PHP does not define
     * `IP_ADD_MEMBERSHIP` at all (verified — `defined()` is false on 8.3.6), so
     * code that falls back to the raw option number `12` and passes
     * `inet_pton($group) . inet_pton($iface)` hands a binary string where an
     * int is expected. That call **returns TRUE** and joins nothing.
     *
     * This class's own pre-S297 join site did not even reach that spelling: it
     * called `IP_MULTICAST_IF` with `'0.0.0.0'` under a comment reading "Join
     * the multicast group". `IP_MULTICAST_IF` selects the **outbound**
     * interface — it is not a membership join, and a three-arm experiment on a
     * real socket confirmed it (S297): no-join received nothing, the
     * `IP_MULTICAST_IF` spelling received nothing, and only the array form
     * below received the datagram. The array spelling is the one
     * `Dlna\SsdpAdvertiser` ships (S51) and `Discovery\Mdns\MdnsSocket`
     * re-verified end-to-end (S296), both measured working. A silently failed
     * join is indistinguishable from "nobody answered", which is exactly why
     * the failure here is logged rather than `@`-swallowed into a quiet TRUE.
     *
     * `interface => 0` means "let the kernel pick, by route" — correct for the
     * single-homed common case and the same default the outbound half already
     * relies on.
     *
     * ## Swoole coroutine runtime
     *
     * Under the daemon's default hook mask (`SWOOLE_HOOK_SOCKETS` is in the
     * `SwooleRuntime` allowlist), `socket_create()` hands back a
     * `Swoole\Coroutine\Socket`, not a native `\Socket` — the parameter is
     * therefore deliberately untyped, a native `\Socket` type would TypeError
     * at the call boundary before the method body runs. On the Swoole runtime
     * the join is routed through Swoole's hooked `setOption()`, whose
     * multicast-join support is NOT covered by the three-arm test (PHPUnit CLI
     * runs without a coroutine runtime, so the test exercises a native socket).
     *
     * @param \Socket $socket The bound UDP socket (blocking, with a receive
     *        timeout) to join on.
     * @param int $interfaceIndex Interface index to join on; 0 = let the
     *        kernel route. Production never passes anything else. It is a
     *        parameter only so a test can run THIS method — rather than a copy
     *        of it — on a host whose LAN interface does not loop multicast
     *        back to itself, which is the usual state of a VM and of CI. The
     *        interface choice is not what is under test; the option spelling
     *        is.
     *
     * @return bool True when the join reported success. Deliberately NOT the
     *        evidence that delivery works — that is what the three-arm
     *        experiment in `tests/Unit/Discovery/Ssdp/SsdpMulticastJoinTest`
     *        asserts.
     */
    private function joinMulticastGroup(mixed $socket, int $interfaceIndex = 0): bool
    {
        if (!defined('MCAST_JOIN_GROUP')) {
            $this->logger->warning('SSDP: MCAST_JOIN_GROUP is not defined; cannot join multicast group');
            return false;
        }

        try {
            $joined = @socket_set_option(
                $socket,
                IPPROTO_IP,
                MCAST_JOIN_GROUP,
                ['group' => self::MULTICAST_ADDR, 'interface' => $interfaceIndex]
            );
        } catch (\Throwable $e) {
            // Defensive: under the Swoole coroutine runtime the socket is a
            // Swoole\Coroutine\Socket and the hooked setOption() may throw for
            // an option it does not implement — the same class of runtime
            // variance `close()` guards against. The join must never fatal a
            // worker; it degrades to "discovery hears nothing".
            $this->logger->warning('SSDP: Failed to join multicast group ' . self::MULTICAST_ADDR, [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($joined !== true) {
            // Deliberately LOUD. The whole hazard of this call is that its
            // wrong spellings fail silently, so the one thing that must not
            // happen is a quiet degradation to "discovery hears nothing".
            $this->logger->warning('SSDP: Failed to join multicast group ' . self::MULTICAST_ADDR);
        }

        return $joined === true;
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
