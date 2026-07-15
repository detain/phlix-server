<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

use Exception;
use Phlix\Common\Logger\StructuredLogger;
use Workerman\Worker;

/**
 * SsdpAdvertiser broadcasts SSDP NOTIFY messages for DLNA/UPnP device discovery.
 *
 * Extends Worker to integrate with Workerman's process lifecycle.
 * Every 30 seconds, sends an SSDP alive NOTIFY message to the multicast address
 * 239.255.255.250:1900. On shutdown, sends a byebye NOTIFY message.
 *
 * @since 0.12.0
 * @see UPnP Device Architecture 2.0 specification
 */
class SsdpAdvertiser extends Worker
{
    /** SSDP multicast address */
    public const SSDP_MULTICAST_ADDRESS = '239.255.255.250';

    /** SSDP multicast port */
    public const SSDP_PORT = 1900;

    /** Broadcast interval in seconds */
    public const BROADCAST_INTERVAL_SECONDS = 30;

    /** Notification subtype: alive */
    public const NTS_ALIVE = 'ssdp:alive';

    /** Notification subtype: byebye */
    public const NTS_BYEBYE = 'ssdp:byebye';

    /** @var string|null IP address to advertise in LOCATION header */
    private ?string $ipAddress;

    /** @var string Server ID used in USN */
    private string $serverId;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger = null;

    /** @var int Port to advertise in LOCATION header */
    private int $port;

    /** @var resource|null UDP socket for SSDP broadcasts */
    private $socket = null;

    /** @var int Timer ID for Workerman timer */
    private int $timerId = 0;

    /**
     * Create a new SsdpAdvertiser.
     *
     * @param string $serverId Server ID used in USN (typically the UDN)
     * @param string|null $ipAddress IP address for LOCATION header (auto-detected if null)
     * @param int $port Port for LOCATION header
     * @param StructuredLogger|null $logger Optional logger
     *
     * @since 0.12.0
     */
    public function __construct(
        string $serverId,
        ?string $ipAddress = null,
        int $port = 8080,
        ?StructuredLogger $logger = null
    ) {
        parent::__construct();

        $this->serverId = $serverId;
        $this->ipAddress = $ipAddress;
        $this->port = $port;
        $this->logger = $logger;

        $this->onWorkerStart = function (SsdpAdvertiser $worker): void {
            $this->socket = $this->createUdpSocket();
            if ($this->socket === null) {
                return;
            }

            // Send initial alive message
            $this->sendAlive();

            // Schedule periodic broadcasts
            $this->timerId = \Workerman\Timer::add(
                self::BROADCAST_INTERVAL_SECONDS,
                function (): void {
                    $this->sendAlive();
                }
            );
        };

        $this->onWorkerStop = function (SsdpAdvertiser $worker): void {
            // Send byebye message
            $this->sendByebye();

            // Cancel timer
            if ($this->timerId > 0) {
                \Workerman\Timer::del($this->timerId);
                $this->timerId = 0;
            }

            // Close socket
            if ($this->socket !== null) {
                fclose($this->socket);
                $this->socket = null;
            }
        };
    }

    /**
     * Get the IP address used for advertisements.
     *
     * @return string IP address
     *
     * @since 0.12.0
     */
    public function getIpAddress(): string
    {
        if ($this->ipAddress !== null) {
            return $this->ipAddress;
        }

        return $this->detectLocalIpAddress();
    }

    /**
     * Send SSDP alive NOTIFY message.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function sendAlive(): void
    {
        $this->sendNotify(self::NTS_ALIVE);
    }

    /**
     * Send SSDP byebye NOTIFY message.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function sendByebye(): void
    {
        $this->sendNotify(self::NTS_BYEBYE);
    }

    /**
     * Send an SSDP NOTIFY message.
     *
     * @param string $nts Notification subtype (ssdp:alive or ssdp:byebye)
     * @return void
     *
     * @since 0.12.0
     */
    private function sendNotify(string $nts): void
    {
        if ($this->socket === null) {
            return;
        }

        $ipAddress = $this->getIpAddress();
        $location = sprintf('http://%s:%d/dlna/description.xml', $ipAddress, $this->port);

        $usn = 'uuid:' . $this->serverId;

        $message = sprintf(
            "NOTIFY * HTTP/1.1\r\n" .
            "HOST: %s:%d\r\n" .
            "NT: %s\r\n" .
            "NTS: %s\r\n" .
            "LOCATION: %s\r\n" .
            "USN: %s\r\n" .
            "\r\n",
            self::SSDP_MULTICAST_ADDRESS,
            self::SSDP_PORT,
            $usn,
            $nts,
            $location,
            $usn
        );

        $this->sendUdpMessage($message);
    }

    /**
     * Send a UDP message to the SSDP multicast address.
     *
     * @param string $message The UDP message to send
     * @return void
     *
     * @since 0.12.0
     */
    private function sendUdpMessage(string $message): void
    {
        if ($this->socket === null) {
            return;
        }

        $address = self::SSDP_MULTICAST_ADDRESS;
        $port = self::SSDP_PORT;

        try {
            $result = @fwrite($this->socket, $message);
            if ($result === false) {
                // Silently ignore write failures in case network is unavailable
            }
        } catch (Exception $e) {
            // Silently ignore socket errors
        }
    }

    /**
     * Create a UDP socket for SSDP broadcasts.
     *
     * @return resource|null Socket resource or null on failure
     *
     * @since 0.12.0
     */
    private function createUdpSocket()
    {
        $socket = @fsockopen(
            'udp://' . self::SSDP_MULTICAST_ADDRESS,
            self::SSDP_PORT,
            $errno,
            $errstr,
            1
        );

        if ($socket === false) {
            $this->logger?->error('SsdpAdvertiser: Failed to create UDP socket for SSDP multicast', [
                'errno' => $errno,
                'errstr' => $errstr,
                'address' => self::SSDP_MULTICAST_ADDRESS,
                'port' => self::SSDP_PORT,
            ]);
            return null;
        }

        // Set socket to non-blocking for timeout behavior
        stream_set_blocking($socket, false);

        return $socket;
    }

    /**
     * Detect the local IP address for LOCATION header.
     *
     * @return string Local IP address
     *
     * @since 0.12.0
     */
    private function detectLocalIpAddress(): string
    {
        // Connect to an external address to determine the local IP
        $socket = @fsockopen('8.8.8.8', 53, $errno, $errstr, 1);
        if ($socket !== false) {
            $localAddress = stream_socket_get_name($socket, false);
            fclose($socket);

            if ($localAddress !== false) {
                $parts = explode(':', $localAddress);
                if (count($parts) >= 2) {
                    return $parts[0];
                }
            }
        }

        // Fallback to localhost
        return '127.0.0.1';
    }

    /**
     * Get the LOCATION URL for this advertiser.
     *
     * @return string LOCATION URL
     *
     * @since 0.12.0
     */
    public function getLocationUrl(): string
    {
        $ipAddress = $this->getIpAddress();
        return sprintf('http://%s:%d/dlna/description.xml', $ipAddress, $this->port);
    }

    /**
     * Get the USN (Unique Service Name) for this advertiser.
     *
     * @return string USN
     *
     * @since 0.12.0
     */
    public function getUsn(): string
    {
        return 'uuid:' . $this->serverId;
    }
}
