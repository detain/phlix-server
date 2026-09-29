<?php

/**
 * Phlix media server component: Mdns.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Discovery\Mdns;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Raw UDP socket wrapper for mDNS (multicast DNS) discovery.
 *
 * mDNS uses UDP multicast address 224.0.0.251 port 5353 for discovering
 * services on the local network (Chromecast, AirPlay, Roku, etc.).
 *
 * @since 0.12.0
 */
class MdnsSocket
{
    /** mDNS multicast address */
    public const MULTICAST_ADDR = '224.0.0.251';

    /** mDNS port */
    public const PORT = 5353;

    /** DNS query types */
    public const QTYPE_A = 1;
    public const QTYPE_NS = 2;
    public const QTYPE_CNAME = 5;
    public const QTYPE_SOA = 6;
    public const QTYPE_PTR = 12;
    public const QTYPE_HINFO = 13;
    public const QTYPE_MX = 15;
    public const QTYPE_TXT = 16;
    public const QTYPE_AAAA = 28;
    public const QTYPE_SRV = 33;
    public const QTYPE_ANY = 255;

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
     * Send an mDNS query for a service type.
     *
     * @param string $name DNS name to query (e.g., '_googlecast._tcp.local.')
     * @param int $qtype DNS query type (default: PTR)
     * @return array<string> Array of raw response strings
     *
     * @since 0.12.0
     */
    public function query(string $name, int $qtype = self::QTYPE_PTR): array
    {
        $socket = $this->createSocket();
        if ($socket === null) {
            return [];
        }

        $queryPacket = $this->buildQueryPacket($name, $qtype);
        $sent = @socket_sendto($socket, $queryPacket, strlen($queryPacket), 0, self::MULTICAST_ADDR, self::PORT);

        if ($sent === false) {
            $this->logger->warning('mDNS: Failed to send query');
            $this->close();
            return [];
        }

        /** @var array<string> $responses */
        $responses = $this->receiveResponses($socket);

        return $responses;
    }

    /**
     * Parse a received mDNS response.
     *
     * Extracts SRV (port, host) and TXT records from the DNS response.
     *
     * @param string $data Raw DNS response data
     * @return array<string, mixed>|null Parsed response or null if invalid
     *
     * @since 0.12.0
     */
    public function parseResponse(string $data): ?array
    {
        if ($data === '' || strlen($data) < 12) {
            return null;
        }

        try {
            return $this->parseDnsResponse($data);
        } catch (\Throwable $e) {
            $this->logger->debug('mDNS: Failed to parse response', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Close the socket.
     *
     * @since 0.12.0
     */
    public function close(): void
    {
        if ($this->socket === null) {
            return;
        }

        // Capture and clear FIRST so a re-entrant close() (this also runs from
        // __destruct) is an idempotent no-op even if socket_close() below
        // throws.
        $socket = $this->socket;
        $this->socket = null;

        try {
            // `@` suppresses a benign "not a valid Socket" warning on an
            // already-freed handle; it does NOT suppress the thrown TypeError
            // below (error suppression only affects diagnostics), so the guard
            // still catches the coroutine-runtime case.
            @socket_close($socket);
        } catch (\Throwable $e) {
            // Under the Swoole coroutine runtime SWOOLE_HOOK_SOCKETS hands
            // socket_create() back a Swoole\Coroutine\Socket; the NATIVE
            // socket_close() then rejects it with a TypeError once the
            // coroutine runtime is torn down at worker shutdown — which is
            // exactly when __destruct fires. Socket cleanup must NEVER fatal a
            // worker (it fatal'd the whole HTTP fleet on a restart), and the
            // underlying fd is reclaimed by the OS at process exit regardless.
            $this->logger->debug('mDNS: socket_close failed (coroutine-runtime socket at shutdown)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create and configure the UDP socket for mDNS.
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
            $this->logger->error('mDNS: Failed to create socket');
            return null;
        }

        // Set socket timeout
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeoutSecs, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeoutSecs, 'usec' => 0]);

        // Allow multiple processes to bind to the same port
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        // Enable multicast TTL (recommended: 255 for local network)
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 255);

        // Enable multicast loopback
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_LOOP, 1);

        // Bind to any address and the mDNS port
        if (!@socket_bind($socket, '0.0.0.0', self::PORT)) {
            $error = socket_last_error($socket);
            $this->logger->warning("mDNS: Failed to bind to port " . self::PORT . ": {$error}");
            @socket_close($socket);
            return null;
        }

        // Join the multicast group so inbound mDNS datagrams are delivered here.
        $this->joinMulticastGroup($socket);

        $this->socket = $socket;
        return $socket;
    }

    /**
     * Join the mDNS multicast group on a bound socket.
     *
     * ## The only correct spelling, and the wrong one that looks correct
     *
     * PHP exposes this as `MCAST_JOIN_GROUP` with an **array** optval. The
     * BSD-style `IP_ADD_MEMBERSHIP` + packed `struct ip_mreq` spelling that
     * every C example uses is not available: PHP does not define
     * `IP_ADD_MEMBERSHIP` at all (verified — `defined()` is false on 8.3), so
     * code that falls back to the raw option number `12` and passes
     * `inet_pton($group) . inet_pton($iface)` hands a binary string where an
     * int is expected. That call **returns TRUE** and joins nothing.
     *
     * That is not a hypothetical: this class's own join site made the raw-12
     * call, passing `inet_pton($group)` alone as the optval (S51's control
     * measured the 8-byte group+iface variant of the same spelling with the
     * identical outcome), and a three-arm experiment on a real socket
     * confirmed it — no-join received nothing, the raw-12 spelling returned
     * TRUE and received nothing, and only the array form below received the
     * datagram. The array spelling is the one `Dlna\SsdpAdvertiser` ships
     * (S51), measured working. A silently failed join is indistinguishable
     * from "nobody answered", which is exactly why the failure here is logged
     * rather than `@`-swallowed into a quiet TRUE.
     *
     * `interface => 0` means "let the kernel pick, by route" — correct for the
     * single-homed common case and the same default the outbound half already
     * relies on.
     *
     * ## Swoole coroutine runtime
     *
     * Under the daemon's default hook mask (`SWOOLE_HOOK_SOCKETS` is in the
     * `SwooleRuntime` allowlist), `socket_create()` hands back a
     * `Swoole\Coroutine\Socket`, not a native `\Socket` — see the `close()`
     * docblock for the same phenomenon. The parameter is therefore deliberately
     * untyped: a native `\Socket` type would TypeError at the call boundary
     * before the method body runs. On the Swoole runtime the join is routed
     * through Swoole's hooked `setOption()`, whose multicast-join support is
     * NOT covered by the three-arm test (PHPUnit CLI runs without a coroutine
     * runtime, so the test exercises a native socket).
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
     *        experiment in `tests/Unit/Discovery/Mdns/MdnsMulticastJoinTest`
     *        asserts.
     */
    private function joinMulticastGroup(mixed $socket, int $interfaceIndex = 0): bool
    {
        if (!defined('MCAST_JOIN_GROUP')) {
            $this->logger->warning('mDNS: MCAST_JOIN_GROUP is not defined; cannot join multicast group');
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
            $this->logger->warning('mDNS: Failed to join multicast group ' . self::MULTICAST_ADDR, [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($joined !== true) {
            // Deliberately LOUD. The whole hazard of this call is that its
            // wrong spellings fail silently, so the one thing that must not
            // happen is a quiet degradation to "discovery hears nothing".
            $this->logger->warning('mDNS: Failed to join multicast group ' . self::MULTICAST_ADDR);
        }

        return $joined === true;
    }

    /**
     * Build mDNS query packet.
     *
     * @param string $name DNS name to query
     * @param int $qtype DNS query type
     * @return string Raw query packet
     */
    private function buildQueryPacket(string $name, int $qtype): string
    {
        $transactionId = random_int(0, 0xFFFF);
        $flags = 0x0000; // Standard query
        $questions = 1;
        $answerRRs = 0;
        $authorityRRs = 0;
        $additionalRRs = 0;

        $packet = pack('n', $transactionId);
        $packet .= pack('n', $flags);
        $packet .= pack('n', $questions);
        $packet .= pack('n', $answerRRs);
        $packet .= pack('n', $authorityRRs);
        $packet .= pack('n', $additionalRRs);

        // Add question section
        $packet .= $this->encodeDnsName($name);
        $packet .= pack('n', $qtype);
        $packet .= pack('n', 1); // Class: IN (Internet)

        return $packet;
    }

    /**
     * Encode a DNS name into the wire format.
     *
     * @param string $name DNS name (e.g., '_googlecast._tcp.local.')
     * @return string Encoded name
     */
    private function encodeDnsName(string $name): string
    {
        $labels = explode('.', $name);
        $encoded = '';

        foreach ($labels as $label) {
            if ($label === '') {
                continue;
            }
            $len = strlen($label);
            $encoded .= chr($len) . $label;
        }

        $encoded .= "\x00"; // Root label (terminator)

        return $encoded;
    }

    /**
     * Hard ceiling on compression-pointer jumps followed for a single name.
     *
     * RFC 1035 §4.1.4 requires a pointer to target an EARLIER offset, which by
     * itself bounds the walk. A strictly-backward-only pointer chain cannot form
     * a cycle, but a label chain that keeps bouncing back can still be walked an
     * unbounded number of times inside one 64 KiB datagram; this cap makes the
     * per-parse work O(1) regardless, and any responder that legitimately needs
     * more than {@see MAX_PTR_JUMPS} chained pointers is malformed for our
     * purposes (real mDNS answers use one or two).
     */
    private const MAX_PTR_JUMPS = 10;

    /**
     * Per-parse budget on the total number of labels emitted, so a hostile
     * datagram cannot make us concatenate a megabyte of one-byte labels into a
     * single name string before the buffer-length guard trips.
     */
    private const MAX_NAME_LABELS = 128;

    /**
     * Decode a DNS name from wire format.
     *
     * Handles RFC 1035 message compression with an ITERATIVE pointer walk (no
     * recursion), so a cyclic-pointer datagram — the exact shape that crashed
     * the pre-fix recursive decoder — is rejected instead of overflowing the
     * stack. A pointer whose target is not strictly behind the pointer itself
     * (forward or self-pointer) is malformed by spec and throws, letting
     * {@see parseResponse()} drop the whole record.
     *
     * @param string $data Response data
     * @param int $offset Offset to start reading
     * @return array{string, int} Decoded name and new offset (offset PAST the
     *         in-stream name when a pointer was followed, per RFC 1035)
     * @throws \RuntimeException On a forward/self pointer or an exhausted pointer budget
     */
    private function decodeDnsName(string $data, int $offset): array
    {
        $name = '';
        $dataLen = strlen($data);
        $returnOffset = null;   // Where the caller resumes once the pointer is followed
        $ptrJumps = 0;
        $labels = 0;

        while (true) {
            if ($offset < 0 || $offset >= $dataLen) {
                // Ran off the end without a terminating label: partial name,
                // handled by the caller's length checks.
                break;
            }

            $len = ord($data[$offset]);

            // End of name
            if ($len === 0) {
                $offset++;
                break;
            }

            // Compression pointer (label type 11)
            if (($len & 0xC0) === 0xC0) {
                $pointerStart = $offset;
                if ($pointerStart + 1 >= $dataLen) {
                    // Truncated pointer pair
                    $offset++;
                    break;
                }
                $ptr = (($len & 0x3F) << 8) | ord($data[$pointerStart + 1]);
                $offset += 2;

                // RFC 1035 §4.1.4: a pointer MUST reference an earlier offset.
                // A forward or self-pointer is only ever a decompression bomb,
                // so reject it as malformed and drop the record.
                if ($ptr >= $pointerStart) {
                    throw new \RuntimeException(
                        "mDNS: malformed DNS compression pointer (target {$ptr} is not behind "
                        . "pointer offset {$pointerStart})"
                    );
                }

                // The caller continues AFTER the pointer pair, not at the tail
                // of the jumped-to name.
                if ($returnOffset === null) {
                    $returnOffset = $offset;
                }

                $ptrJumps++;
                if ($ptrJumps > self::MAX_PTR_JUMPS) {
                    throw new \RuntimeException(
                        'mDNS: DNS name compression-pointer budget exceeded ('
                        . self::MAX_PTR_JUMPS . ')'
                    );
                }

                $offset = $ptr;
                continue;
            }

            if ($len > 63) {
                // Invalid label length
                break;
            }

            $offset++;
            if ($offset + $len > $dataLen) {
                break;
            }

            $labels++;
            if ($labels > self::MAX_NAME_LABELS) {
                // Per-parse budget exhausted: treat as truncated rather than
                // building an unbounded name string.
                break;
            }

            if ($name !== '') {
                $name .= '.';
            }
            $name .= substr($data, $offset, $len);
            $offset += $len;
        }

        return [$name, $returnOffset ?? $offset];
    }

    /**
     * Parse DNS response to extract records.
     *
     * @param string $data Raw DNS response
     * @return array<string, mixed> Parsed DNS records
     */
    private function parseDnsResponse(string $data): array
    {
        if (strlen($data) < 12) {
            return [];
        }

        // Parse header
        $header = unpack(
            'ntransactionId/nflags/nquestionCount/nanswerCount/nauthorityCount/nadditionalCount',
            substr($data, 0, 12)
        );
        if ($header === false) {
            return [];
        }
        $transactionId = $header['transactionId'];
        $flags = $header['flags'];
        $questionCount = $header['questionCount'];
        $answerCount = $header['answerCount'];
        $authorityCount = $header['authorityCount'];
        $additionalCount = $header['additionalCount'];

        $offset = 12;

        // Skip questions
        for ($i = 0; $i < $questionCount; $i++) {
            $result = $this->decodeDnsName($data, $offset);
            $offset = $result[1];
            $offset += 4; // Skip QTYPE and QCLASS
            if ($offset > strlen($data)) {
                // Truncated question section — nothing trustworthy left.
                break;
            }
        }

        $records = [];

        // Parse answer records
        $totalAnswers = $answerCount + $authorityCount + $additionalCount;
        for ($i = 0; $i < $totalAnswers; $i++) {
            if ($offset >= strlen($data)) {
                break;
            }

            // decodeDnsName() follows leading compression pointers itself and
            // resumes the caller past the pointer pair, so no special-casing
            // is needed here (and the walk can no longer recurse unboundedly).
            $nameResult = $this->decodeDnsName($data, $offset);
            $name = $nameResult[0];
            $offset = $nameResult[1];

            if ($offset + 10 > strlen($data)) {
                break;
            }

            $typeData = unpack('n', substr($data, $offset, 2));
            if ($typeData === false) {
                break;
            }
            $type = $typeData[1];
            $offset += 2;
            // Skip class
            $offset += 2;
            // Skip TTL
            $offset += 4;
            $rdlengthData = unpack('n', substr($data, $offset, 2));
            if ($rdlengthData === false) {
                break;
            }
            $rdlength = $rdlengthData[1];
            $offset += 2;

            if ($offset + $rdlength > strlen($data)) {
                break;
            }

            $rdataStart = $offset;
            $rdata = substr($data, $offset, $rdlength);
            $offset += $rdlength;

            $record = [
                'name' => $name,
                'type' => $type,
                // Names embedded in RDATA are compressed against the WHOLE
                // message (RFC 1035 §4.1.4), so the name-bearing record types
                // get the full packet plus the absolute RDATA offset instead of
                // only the slice — decoding pointers inside a bare slice reads
                // the wrong bytes (and hides that real responders compress here).
                'data' => $this->parseRecordData($type, $data, $rdataStart, $rdata, $name),
            ];

            $records[] = $record;
        }

        return [
            'transactionId' => $transactionId,
            'flags' => $flags,
            'records' => $records,
        ];
    }

    /**
     * Parse record data based on type.
     *
     * @param int $type DNS record type
     * @param string $packet The FULL response packet (pointer targets are message-absolute)
     * @param int $rdataOffset Absolute offset of the RDATA inside $packet
     * @param string $rdata Raw record data slice
     * @param string $name Record name
     * @return mixed Parsed record data
     */
    private function parseRecordData(int $type, string $packet, int $rdataOffset, string $rdata, string $name): mixed
    {
        switch ($type) {
            case self::QTYPE_PTR:
                $result = $this->decodeDnsName($packet, $rdataOffset);
                return ['ptr' => $result[0]];

            case self::QTYPE_SRV:
                if (strlen($rdata) < 6) {
                    return [];
                }
                $srvData = unpack('npriority/nweight/nport', substr($rdata, 0, 6));
                if ($srvData === false) {
                    return [];
                }
                $targetResult = $this->decodeDnsName($packet, $rdataOffset + 6);
                return [
                    'priority' => $srvData['priority'],
                    'weight' => $srvData['weight'],
                    'port' => $srvData['port'],
                    'target' => $targetResult[0],
                ];

            case self::QTYPE_TXT:
                $txtRecords = [];
                $pos = 0;
                while ($pos < strlen($rdata)) {
                    $len = ord($rdata[$pos]);
                    $pos++;
                    if ($pos + $len > strlen($rdata)) {
                        break;
                    }
                    $txtRecords[] = substr($rdata, $pos, $len);
                    $pos += $len;
                }
                return ['txt' => $txtRecords];

            case self::QTYPE_A:
                if (strlen($rdata) < 4) {
                    return [];
                }
                return ord($rdata[0]) . '.' . ord($rdata[1]) . '.' . ord($rdata[2]) . '.' . ord($rdata[3]);

            case self::QTYPE_AAAA:
                if (strlen($rdata) < 16) {
                    return [];
                }
                $ipv6 = '';
                for ($i = 0; $i < 16; $i += 2) {
                    if ($i > 0) {
                        $ipv6 .= ':';
                    }
                    $ipv6 .= bin2hex(substr($rdata, $i, 2));
                }
                return $ipv6;

            default:
                return ['raw' => bin2hex($rdata)];
        }
    }

    /**
     * Receive responses from the socket.
     *
     * ## Source-port gating (off-protocol datagrams are dropped)
     *
     * The socket binds 0.0.0.0:5353, so it is handed EVERY unicast datagram the
     * host receives on port 5353 plus the multicast group traffic — including
     * junk an off-segment attacker can route straight at the box. Per RFC 6762
     * §6 an mDNS responder ALWAYS sends its replies from port 5353: both the
     * multicast answers (to 224.0.0.251:5353) and the unicast answers delivered
     * to a querier on an ephemeral port originate at the responder's 5353.
     * Legacy unicast DNS from port 53 is explicitly not mDNS and this flow has
     * no consumer for it (MdnsDiscovery only ever parses what `query()`
     * collected from its own 5353 transactions).
     *
     * Destination-group inspection is NOT available here — PHP's
     * `socket_recvfrom()` exposes only source addr/port (no IP_RECVDSTADDR),
     * and restricting the SOURCE ADDRESS to the multicast group would break the
     * legitimate unicast answers from resolved peers that this very method is
     * how MdnsDiscovery::resolveService() receives them. So the safe, cheap,
     * fully-exercised law is: **source port == 5353, everything else dropped**.
     * Our own loopback query echoes (IP_MULTICAST_LOOP, sent from our bound
     * 5353 socket) still pass and are discarded later as DNS queries, which is
     * what the existing round-trip test asserts.
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
        $maxAttempts = 20;
        $receives = 0;
        // Hard loop bound: a firehose of rejected datagrams must not spin the
        // worker; each receive costs a real packet, so cap total absorbs too.
        $maxReceives = 200;

        while ($attempts < $maxAttempts && $receives < $maxReceives) {
            $data = '';
            $port = 0;
            $from = '';

            $bytesReceived = @socket_recvfrom($socket, $data, 65536, 0, $from, $port);

            if ($bytesReceived === false || $bytesReceived === 0) {
                break;
            }

            if ($data === '') {
                break;
            }

            $receives++;

            if ($port !== self::PORT) {
                // Off-protocol sender (see gating rationale above): drop it
                // unseen by the parser — malformed-packet hardening stays as
                // defence-in-depth for datagrams that DO pass this gate.
                $this->logger->debug('mDNS: dropping datagram from non-mDNS source port', [
                    'from' => $from,
                    'port' => $port,
                ]);
                continue;
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
