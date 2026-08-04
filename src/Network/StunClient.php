<?php

/**
 * Phlix media server component: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Network;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;

/**
 * STUN client (RFC 5389) for discovering the server's public IP address.
 *
 * Sends a binding request to a STUN server and extracts the XOR-MAPPED-ADDRESS
 * from the response.
 *
 * @package Phlix\Network
 * @since 0.11.0
 */
class StunClient
{
    public const DEFAULT_STUN_SERVER = 'stun.l.google.com';
    public const DEFAULT_STUN_PORT = 19302;

    private const STUN_MAGIC_COOKIE = 0x2112A442;
    private const STUN_HEADER_SIZE = 20;

    /** Connect timeout, in seconds, for {@see self::probePort()}. */
    private const PROBE_TIMEOUT = 3.0;

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
     * Returns true if Swoole coroutine context is active.
     */
    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
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
            $this->logger->debug('STUN: failed to create UDP socket');
            return null;
        }

        $request = $this->buildBindingRequest();
        $sent = @socket_sendto($socket, $request, strlen($request), 0, $this->stunServer, $this->stunPort);
        if ($sent === false) {
            socket_close($socket);
            return null;
        }

        $response = '';
        $fromAddr = '';
        $fromPort = 0;

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
     * Tests whether a given IP:port is OPEN — i.e. whether a client out on the
     * internet could connect to it.
     *
     * True **only** when a TCP handshake completes. A refused connection, a
     * timeout, an unreachable network and an unclassifiable failure are all
     * false: see {@see PortProbeOutcome} for the full rationale, in particular
     * why ECONNREFUSED reads as "not forwarded" for this probe and not as
     * "reachable, so good enough" (S169 — the old code returned `true` on both
     * arms of the coroutine path, so it could never answer "no").
     *
     * Uses Swoole\Coroutine\Socket for a non-blocking connect when in coroutine
     * context; falls back to blocking fsockopen only OUTSIDE a coroutine.
     *
     * @param string $ip   Target IP address.
     * @param int    $port Target port.
     *
     * @return bool True only if the port is open from outside.
     */
    public function testPortAccessibility(string $ip, int $port): bool
    {
        return $this->probePort($ip, $port)->isOpen();
    }

    /**
     * Probes ip:port with a single TCP connect and classifies what happened.
     *
     * The classification is what {@see testPortAccessibility()} reduces to a
     * bool, and it is public because "why not open?" is the actionable half for
     * an operator (`scripts/port-forward.php` prints it): "refused" points at
     * the router's forwarding rules or a missing NAT-loopback, "timed out"
     * points at a firewall dropping the packet, "unreachable" at routing.
     *
     * @param string $ip      Target IP address.
     * @param int    $port    Target port.
     * @param float  $timeout Connect timeout in seconds.
     */
    public function probePort(string $ip, int $port, float $timeout = self::PROBE_TIMEOUT): PortProbeOutcome
    {
        // The transport is recorded and logged because the two arms below are a
        // production/test fork: PHPUnit never runs inside a coroutine
        // (Swoole\Coroutine::getCid() returns -1 with the extension loaded), so
        // for the whole life of this method the suite exercised only the
        // blocking arm while every Swoole worker took the coroutine one. S170.
        // A test that asserts this field knows WHICH branch it just pinned.
        if (self::inCoroutine() && class_exists(\Swoole\Coroutine\Socket::class)) {
            $transport = 'coroutine';
            $outcome = $this->probeViaCoroutineSocket($ip, $port, $timeout);
        } else {
            $transport = 'blocking';
            $outcome = $this->probeViaBlockingSocket($ip, $port, $timeout);
        }

        $this->logger->debug('STUN: port probe', [
            'ip' => $ip,
            'port' => $port,
            'outcome' => $outcome->value,
            'open' => $outcome->isOpen(),
            'transport' => $transport,
        ]);

        return $outcome;
    }

    /**
     * Non-blocking connect probe — the arm every Swoole worker takes.
     *
     * Deliberately does NOT degrade to the blocking fallback on failure: this
     * runs inside a coroutine, where fsockopen() would stall the entire worker
     * for up to $timeout.
     *
     * @param string $ip      Target IP address.
     * @param int    $port    Target port.
     * @param float  $timeout Connect timeout in seconds.
     */
    private function probeViaCoroutineSocket(string $ip, int $port, float $timeout): PortProbeOutcome
    {
        try {
            $sock = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
            // S146: Swoole\Coroutine\Socket has NO setTimeout() — verified
            // absent from the class in swoole 6.2.2. The old call raised an
            // \Error, which catch (RuntimeException) does not catch. The
            // timeout is connect()'s third argument.
            $connected = $sock->connect($ip, $port, $timeout);
            // Read errCode BEFORE close(). It does survive close() on swoole
            // 6.2.1 (measured) but nothing documents that, and the classified
            // answer must not depend on it.
            //
            // ⚠ MEASURED — errCode is NOT reliably populated, so the CLASSIFICATION
            // (not the verdict) varies by swoole build. Connect to a closed
            // loopback port, same host, same test:
            //   swoole 6.2.1 / PHP 8.3.6  -> connect=false errCode=111 "Connection refused"
            //   swoole 6.2.2 / PHP 8.4.21 -> connect=false errCode=111 "Connection refused"
            //   swoole 6.2.2 / PHP 8.3.32 -> connect=false errCode=0   errMsg=""
            // SO_ERROR is no help: getOption(SOL_SOCKET, SO_ERROR) reads 0 in all
            // three (the kernel's pending error has already been consumed). So a
            // failed connect can arrive with nothing but "it failed", which maps
            // to PortProbeOutcome::Failed — not open, which is the answer callers
            // need. Do NOT make the bool depend on the classification.
            $errno = (int) $sock->errCode;
            $sock->close();

            if ($connected) {
                return PortProbeOutcome::Open;
            }

            return PortProbeOutcome::fromErrno($errno);
        } catch (\Throwable $e) {
            // \Throwable, not RuntimeException: `new Swoole\Coroutine\Socket()`
            // throws Swoole\Exception, which extends \Exception directly
            // (measured: Swoole\Exception -> Exception), and the S146 note above
            // records an \Error escaping the same way. An escaping throwable
            // inside a coroutine is not a caught failure — it is an uncaught
            // exception in that coroutine, so a port probe could take a worker
            // with it. Log it, answer "not open".
            $this->logger->warning('STUN: coroutine port probe threw', [
                'ip' => $ip,
                'port' => $port,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return PortProbeOutcome::Failed;
        }
    }

    /**
     * Blocking connect probe — used only when NOT inside a coroutine (CLI
     * scripts, and the unit suite).
     *
     * @param string $ip      Target IP address.
     * @param int    $port    Target port.
     * @param float  $timeout Connect timeout in seconds.
     */
    private function probeViaBlockingSocket(string $ip, int $port, float $timeout): PortProbeOutcome
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('tcp://' . $ip, $port, $errno, $errstr, $timeout);
        if ($socket !== false) {
            fclose($socket);
            return PortProbeOutcome::Open;
        }

        // Same classifier as the coroutine arm, so the two arms cannot drift
        // apart again — which is the whole reason S169 went unnoticed.
        return PortProbeOutcome::fromErrno($errno);
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
        while ($offset + 4 <= strlen($data)) {
            $attrHeader = substr($data, $offset, 4);
            $typeUnpack = unpack('n', substr($attrHeader, 0, 2));
            $lenUnpack = unpack('n', substr($attrHeader, 2, 2));
            if (
                !is_array($typeUnpack) || !isset($typeUnpack[1]) || !is_int($typeUnpack[1])
                || !is_array($lenUnpack) || !isset($lenUnpack[1]) || !is_int($lenUnpack[1])
            ) {
                return null;
            }
            $attrType = $typeUnpack[1];
            $attrLen = $lenUnpack[1];

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

        $familyUnpack = unpack('n', substr($data, 0, 2));
        $portUnpack = unpack('n', substr($data, 2, 2));
        if (
            !is_array($familyUnpack) || !isset($familyUnpack[1]) || !is_int($familyUnpack[1])
            || !is_array($portUnpack) || !isset($portUnpack[1]) || !is_int($portUnpack[1])
        ) {
            return null;
        }
        $family = $familyUnpack[1];
        // STUN XOR-MAPPED-ADDRESS XOR's the port with the high 16 bits of the magic cookie.
        // The result is currently unused, but retaining the computation documents the wire format.
        unset($portUnpack);

        if ($family === 0x0001) {
            $ipBytes = substr($data, 4, 4);
            $xorMask = pack('N', self::STUN_MAGIC_COOKIE);
            $xored = '';
            for ($i = 0; $i < 4; $i++) {
                $xored .= $ipBytes[$i] ^ $xorMask[$i];
            }
            $ip = sprintf('%d.%d.%d.%d', ord($xored[0]), ord($xored[1]), ord($xored[2]), ord($xored[3]));
            $packed = inet_pton($ip);
            if ($packed === false) {
                return null;
            }
            $printable = inet_ntop($packed);
            return $printable === false ? null : $printable;
        }

        return null;
    }

    /**
     * Creates a UDP socket for STUN communication.
     */
    private function createUdpSocket(): ?Socket
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

        // Fallback: use UDP socket to determine local IP
        return $this->getLocalIpViaUdpSocket();
    }

    /**
     * Determines local IP by opening a UDP socket to 8.8.8.8:53.
     *
     * Uses Swoole\Coroutine\Socket when in coroutine context for non-blocking operation.
     */
    private function getLocalIpViaUdpSocket(): ?string
    {
        if (self::inCoroutine() && class_exists(\Swoole\Coroutine\Socket::class)) {
            try {
                $sock = $this->createCoroutineSocket(SOCK_DGRAM);
                // S146: Swoole\Coroutine\Socket has NO setTimeout() — verified
                // absent from the class in swoole 6.2.2. The old call raised an
                // \Error ("Call to undefined method"). The timeout is connect()'s
                // third argument.
                // Connect to 8.8.8.8:53 (DNS) to determine local IP
                $connected = $sock->connect('8.8.8.8', 53, 2.0);
                // S197: exactly ONE close() on every path. The old shape closed
                // inside the if AND again after it, so a connected socket whose
                // local address was unusable was closed twice. Measured on swoole
                // 6.2.1: the second close() returns false rather than throwing, so
                // that was dead code and not a fault — but it read as a defect and
                // only the redundant call is removed here.
                $localAddr = $connected ? $sock->getsockname() : false;
                $sock->close();
                if ($localAddr !== false && is_array($localAddr)) {
                    $host = $localAddr['host'] ?? null;
                    if (is_string($host) && $host !== '') {
                        return $host;
                    }
                }
            } catch (\Throwable $e) {
                // \Throwable, not RuntimeException: NOTHING this block can raise is
                // a RuntimeException. Swoole\Exception and its subclass
                // Swoole\Coroutine\Socket\Exception (what the constructor above is
                // documented to raise) both extend \Exception DIRECTLY — measured
                // 2026-08-03, `Swoole\Exception -> Exception -> END` — and the
                // S146 note above records an \Error, which is not an Exception at
                // all. The old catch (RuntimeException) therefore contained none of
                // the three, and this block exists only to degrade to the blocking
                // fallback below. Matches probeViaCoroutineSocket() in this class.
                // S197.
            }
        }

        // Blocking fallback
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

    /**
     * Constructs the coroutine socket used by {@see self::getLocalIpViaUdpSocket()}.
     *
     * ⚠ This is a SEAM, and it is here for one reason that was measured rather
     * than assumed (S197): on swoole 6.2.1 / PHP 8.3.6 — the dev box and the CI
     * runner — **nothing inside that try block can be provoked into throwing**.
     * `connect()`, `getsockname()` and `close()` all return false and set
     * `errCode` instead; a double `close()` returns false; cancelling the
     * coroutine mid-connect returns false. And a genuinely failing `socket(2)`
     * (reproduced with `setrlimit(RLIMIT_NOFILE, 16)` via FFI, and again with an
     * invalid socket type) does not raise `Swoole\Coroutine\Socket\Exception` on
     * this build at all — it **SIGSEGVs inside `new`, through an enclosing
     * `catch (\Throwable)`**. So the widened catch could not otherwise be pinned
     * by any test, and an unpinned catch clause is exactly how the narrow one
     * survived. Overriding this method is how the test throws a real
     * `Swoole\Exception` at the line the production code throws it from.
     *
     * @param int $type Socket type, e.g. SOCK_DGRAM.
     */
    protected function createCoroutineSocket(int $type): \Swoole\Coroutine\Socket
    {
        return new \Swoole\Coroutine\Socket(AF_INET, $type, 0);
    }
}
