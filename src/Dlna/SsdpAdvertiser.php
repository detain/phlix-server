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
use Phlix\Config\EffectiveConfig;
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

    /** Unique Service Name for this server */
    public const USN = 'uuid:PHLIXSERVER';

    /** Notification subtype: alive */
    public const NTS_ALIVE = 'ssdp:alive';

    /** Notification subtype: byebye */
    public const NTS_BYEBYE = 'ssdp:byebye';

    /** @var string|null IP address to advertise in LOCATION header */
    private ?string $ipAddress;

    /** @var int Port to advertise in LOCATION header */
    private int $port;

    /** @var resource|null UDP socket for SSDP broadcasts */
    private $socket = null;

    /** @var int Timer ID for Workerman timer */
    private int $timerId = 0;

    /**
     * Path to `config/database.php`, used to bootstrap the settings overlay
     * inside this worker's own process. Null in tests and in any caller that
     * has already bootstrapped {@see EffectiveConfig}.
     */
    private ?string $dbConfigPath;

    /**
     * Create a new SsdpAdvertiser.
     *
     * @param string|null $ipAddress IP address for LOCATION header (auto-detected if null)
     * @param int $port Port for LOCATION header
     * @param string|null $dbConfigPath Path to `config/database.php`, so this
     *        worker can read the effective `dlna.enabled` in its own fork
     *
     * @since 0.12.0
     */
    public function __construct(?string $ipAddress = null, int $port = 8080, ?string $dbConfigPath = null)
    {
        parent::__construct();

        $this->ipAddress = $ipAddress;
        $this->port = $port;
        $this->dbConfigPath = $dbConfigPath;

        $this->onWorkerStart = function (SsdpAdvertiser $worker): void {
            // Load the persisted `server_settings` overrides into THIS fork.
            // The master deliberately does not do this (its DB connection would
            // be inherited by every child), so the effective value is resolved
            // here — which also means it is re-read on every graceful reload.
            // Never throws: an unreachable settings store yields the file
            // defaults. {@see \Phlix\Config\EffectiveConfig}
            //
            // Conditional on being TOLD where the database config lives. The
            // rule is "if I was given a path, loading the overlay is my job;
            // otherwise the surrounding process has already bootstrapped it and
            // re-running it here would CLOBBER that state" — which is exactly
            // what an unconditional call did to this class's own tests, and
            // would equally clobber any caller that had already bootstrapped.
            // start.php always passes the path, so the production fork always
            // loads its own overrides.
            if ($this->dbConfigPath !== null) {
                EffectiveConfig::bootstrap(null, $this->dbConfigPath);
            }

            // Honour an admin `dlna.enabled = false` override. Idle rather than
            // exit, so a graceful reload can pick the value up again without
            // the master having to re-fork a worker it no longer knows about.
            if (!self::isEnabled()) {
                return;
            }

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
     * Is this server advertising itself to DLNA/UPnP devices?
     *
     * Backs the `dlna.enabled` setting. Read through
     * {@see EffectiveConfig::file()} (memoised per bootstrap generation), so an
     * admin override applies on the next graceful reload without needing the
     * master process to re-evaluate anything.
     *
     * ## What this does and does NOT gate
     *
     * It gates the SSDP advertiser — the broadcast that makes this server
     * appear in a smart TV's source list — and nothing else. It deliberately
     * does not claim to gate DLNA *browsing*, because the ContentDirectory
     * service is not currently registered at all: `Application::loadCdsRoutes()`
     * resolves {@see CdsServer} inside a bare `catch (\Throwable)`, and that
     * resolution always throws because {@see DlnaServer} has no DI registration
     * and un-autowirable `string` constructor parameters. See `config/dlna.php`
     * for the production evidence. If that is ever fixed, extend the gate to
     * the CDS routes and widen the schema `helpText` in the same change.
     *
     * Defaults to TRUE when the key is absent, so existing installs keep
     * advertising exactly as they did before `config/dlna.php` existed.
     *
     * @return bool
     *
     * @since 1.3.0
     */
    public static function isEnabled(): bool
    {
        return (EffectiveConfig::file('dlna')['enabled'] ?? true) !== false;
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
            self::USN,
            $nts,
            $location,
            self::USN
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
        return self::USN;
    }
}
