<?php

declare(strict_types=1);

namespace Phlex\Discovery\Mdns;

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
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
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

        // Join the multicast group on all interfaces
        $mcastAddr = inet_pton(self::MULTICAST_ADDR);
        if ($mcastAddr !== false) {
            $addMembership = defined('IP_ADD_MEMBERSHIP') ? \IP_ADD_MEMBERSHIP : 12;
            @socket_set_option($socket, IPPROTO_IP, $addMembership, $mcastAddr);
        }

        $this->socket = $socket;
        return $socket;
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
     * Decode a DNS name from wire format.
     *
     * @param string $data Response data
     * @param int $offset Offset to start reading
     * @return array{string, int} Decoded name and new offset
     */
    private function decodeDnsName(string $data, int $offset): array
    {
        $name = '';
        $originalOffset = $offset;

        while ($offset < strlen($data)) {
            $len = ord($data[$offset]);

            // End of name
            if ($len === 0) {
                $offset++;
                break;
            }

            // Compression pointer
            if (($len & 0xC0) === 0xC0) {
                if ($offset + 1 >= strlen($data)) {
                    break;
                }
                $ptr = (($len & 0x3F) << 8) | ord($data[$offset + 1]);
                $offset += 2;

                $target = $this->decodeDnsName($data, $ptr);
                return [$name . $target[0], $offset];
            }

            if ($len > 63) {
                // Invalid label length
                break;
            }

            $offset++;
            if ($offset + $len > strlen($data)) {
                break;
            }

            if ($name !== '') {
                $name .= '.';
            }
            $name .= substr($data, $offset, $len);
            $offset += $len;
        }

        return [$name, $offset];
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
        }

        $records = [];

        // Parse answer records
        $totalAnswers = $answerCount + $authorityCount + $additionalCount;
        for ($i = 0; $i < $totalAnswers; $i++) {
            if ($offset >= strlen($data)) {
                break;
            }

            $name = '';
            // Check for compression pointer at offset
            if (($data[$offset] ?? '') !== '' && (ord($data[$offset]) & 0xC0) === 0xC0) {
                $ptr = (ord($data[$offset]) & 0x3F) << 8 | ord($data[$offset + 1] ?? "\x00");
                $nameResult = $this->decodeDnsName($data, $ptr);
                $name = $nameResult[0];
                $offset += 2;
            } else {
                $nameResult = $this->decodeDnsName($data, $offset);
                $name = $nameResult[0];
                $offset = $nameResult[1];
            }

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

            $rdata = substr($data, $offset, $rdlength);
            $offset += $rdlength;

            $record = [
                'name' => $name,
                'type' => $type,
                'data' => $this->parseRecordData($type, $rdata, $name),
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
     * @param string $rdata Raw record data
     * @param string $name Record name
     * @return mixed Parsed record data
     */
    private function parseRecordData(int $type, string $rdata, string $name): mixed
    {
        switch ($type) {
            case self::QTYPE_PTR:
                $result = $this->decodeDnsName($rdata, 0);
                return ['ptr' => $result[0]];

            case self::QTYPE_SRV:
                if (strlen($rdata) < 6) {
                    return [];
                }
                $srvData = unpack('npriority/nweight/nport', substr($rdata, 0, 6));
                if ($srvData === false) {
                    return [];
                }
                $targetResult = $this->decodeDnsName($rdata, 6);
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
