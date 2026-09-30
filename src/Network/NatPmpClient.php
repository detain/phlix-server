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
 * NAT-PMP client (RFC 6886) for Apple NAT-PMP compatible routers.
 *
 * Sends every NAT-PMP REQUEST to UDP port 5351 of the gateway — RFC 6886
 * §3.1: "a NAT-PMP client sends its request packet to port 5351 of its
 * configured gateway address" (rfc-editor.org/rfc/rfc6886.txt line 323),
 * restated for the address request in §3.2 (line 378) and for the mapping
 * request in §3.3 (line 512). Port 5350 is NOT a request port: §3.2.1
 * reserves it for the gateway's announcement multicast — the NAT gateway
 * "MUST send a gratuitous response to the link-local multicast address
 * 224.0.0.1, port 5350" (lines 432-433) — and the §3.2.1 engineering note
 * (lines 481-482): "it is convenient to have clients listen on UDP 5350
 * and servers listen on UDP 5351". Earlier revisions of this class sent
 * requests to 5350, the client-side LISTEN port, so a spec-compliant
 * gateway never saw them. Pinned by
 * testGatewayRequestsRfc6886Section31DestinationPort5351 and proven
 * end-to-end by the fork-responder round trips in NatPmpClientTest, which
 * bind the responder exactly where the RFC says the server listens.
 *
 * NOTE (not implemented): the client-side half of that split — listening
 * on UDP 5350 for the gateway's unsolicited announcements (§3.2.1/§3.6
 * reboot and SSSoE-change detection) — has no code here or anywhere in
 * src/ (no 224.0.0.1 bind exists). Mappings therefore age out silently
 * after a gateway reboot until the next explicit (re)configure. Renewal
 * guidance lives in {@see self::parseMappingResponse()} and the
 * PortForwardService natpmp leg.
 *
 * Requests port mappings without requiring SSDP discovery.
 *
 * @package Phlix\Network
 * @since 0.11.0
 */
class NatPmpClient
{
    // RFC 6886 §3.1/§3.2/§3.3: every client REQUEST targets gateway UDP
    // port 5351 ("servers listen on UDP 5351", §3.2.1 note, line 482).
    // The value was 5350 — the client-side announcement LISTEN port —
    // until this fix; see the class docblock for the full cite chain.
    private const NAT_PMP_GATEWAY_PORT = 5351;
    private const VERSION = 0;
    // RFC 6886 §3.3, "Opcodes supported: 1 - Map UDP, 2 - Map TCP"
    // (rfc-editor.org/rfc/rfc6886.txt lines 526-528). Earlier revisions of
    // this class had the pair inverted (TCP=1, UDP=2), so every TCP forward
    // PortForwardService requested rode the wire as a UDP mapping request —
    // a functional bug: gateways created (and later deleted) wrong-protocol
    // mappings. Add and delete flipped together, so the pair stayed
    // self-consistent; the corrected constants keep them consistent while
    // finally requesting the protocol the API names. Pinned by
    // testOpcodeConstantsMatchRfc6886Section33 and the per-protocol wire
    // byte tests in NatPmpClientTest.
    private const OP_CODE_MAP_UDP = 1;
    private const OP_CODE_MAP_TCP = 2;
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
     * Returns true if Swoole coroutine context is active.
     */
    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
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
        $sent = @socket_sendto($socket, $request, strlen($request), 0, $gatewayIp, self::NAT_PMP_GATEWAY_PORT);
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

            // L4: the reply must come from the gateway the request was sent to.
            // Any other host on the segment gets the same UDP response shape and
            // must never be parsed as the router's answer.
            if ($fromAddr !== $gatewayIp) {
                $this->logger->warning('NAT-PMP: discarding reply from unexpected source', [
                    'expected' => $gatewayIp,
                    'received' => $fromAddr,
                ]);
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
     * @param int    $leaseDuration Mapping lease in seconds. Default 7200 —
     *     RFC 6886 §3.3: "The RECOMMENDED Port Mapping Lifetime is 7200
     *     seconds (two hours)." (rfc-editor.org/rfc/rfc6886.txt line 575.)
     *     The PREVIOUS default of 3600 halved every lease for no cited
     *     reason; the gateway is free to reduce the offered lifetime
     *     further either way (§3.3, lines 664-666: "The NAT gateway MAY
     *     reduce the lifetime from what the client requested"), which is
     *     exactly why the GRANTED lifetime travels back in the return
     *     record instead of the request value being trusted.
     *
     * @return array{external_port: int, granted_lifetime: int}|null The
     *     assigned external port AND the lifetime the gateway actually
     *     granted (reply bytes 12-15), or null on failure. Renew guidance:
     *     §3.3 (lines 679-681) "The client SHOULD begin trying to renew the
     *     mapping halfway to expiry time, like DHCP."
     */
    public function addPortMapping(
        string $gatewayIp,
        int $externalPort,
        int $internalPort,
        int $leaseDuration = 7200
    ): ?array {
        return $this->mapPort($gatewayIp, self::OP_CODE_MAP_TCP, $externalPort, $internalPort, $leaseDuration);
    }

    /**
     * Removes a port mapping via NAT-PMP.
     *
     * @param string $gatewayIp   The router's LAN IP address.
     * @param int    $externalPort The mapped port to delete. RFC 6886 §3.4
     *     keys the deletion on the request's Internal Port slot, and this
     *     class only ever creates symmetric mappings (internal == external,
     *     see PortForwardService), so one number identifies the mapping
     *     under either name.
     * @param string $protocol     Protocol (TCP or UDP).
     *
     * @return bool True only when the gateway's reply carries §3.5 result
     *     code 0. §3.4 (rfc-editor.org/rfc/rfc6886.txt lines 706-717): a
     *     successful deletion reply "MUST contain a result code of 0", and
     *     deleting an already-gone mapping "MUST respond ... as if the
     *     request were successful" (idempotent teardown answers code 0, so
     *     true is correct there); an UNSUCCESSFUL deletion "MUST contain a
     *     non-zero result code and the requested mapping" (lines 718-719),
     *     and deleting a manually-assigned mapping answers "Not Authorized"
     *     error, result code 2 (lines 721-723). Until this gate the reply's
     *     opcode echo alone returned true over a code-2 refusal — the
     *     mapping stayed alive on the router while teardown reported
     *     success.
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

        // RFC 6886 §3.4 keys the deletion by the request's Internal Port
        // slot and mandates Suggested External Port = 0; mappings are
        // created here symmetrically (internal == external, see
        // PortForwardService), so the caller's port fills the internal slot.
        $request = $this->buildUnmapRequest($opCode, $externalPort);
        $sent = @socket_sendto($socket, $request, strlen($request), 0, $gatewayIp, self::NAT_PMP_GATEWAY_PORT);
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

            // L4: see discoverGateway() — only the configured gateway's reply is
            // trusted as the unmap acknowledgement.
            if ($fromAddr !== $gatewayIp) {
                continue;
            }

            socket_close($socket);

            if ($recvLen === false || $recvLen < 12 || strlen($response) < 12) {
                return false;
            }

            // The deletion reply is "formatted as defined in Section 3.3"
            // (§3.4, line 708), so the opcode echo is 128 + the request's
            // opcode and the result code sits at bytes 2-3.
            if (ord($response[1]) !== ($opCode | self::RESPONSE_FLAG)) {
                return false;
            }

            $resultCode = $this->readResultCode($response);
            if ($resultCode !== 0) {
                $this->logger->debug('NAT-PMP: mapping deletion failed on result code', [
                    'result_code' => $resultCode,
                    'port' => $externalPort,
                ]);
                return false;
            }

            return true;
        }

        socket_close($socket);
        return false;
    }

    /**
     * Maps a port via NAT-PMP and returns the reply's mapping record.
     *
     * The reply is parsed by {@see self::parseMappingResponse()}; only a
     * §3.5 result code of zero counts as a created mapping.
     *
     * @return array{external_port: int, granted_lifetime: int}|null
     */
    private function mapPort(
        string $gatewayIp,
        int $opCode,
        int $externalPort,
        int $internalPort,
        int $leaseDuration
    ): ?array {
        $socket = $this->createUdpSocket();
        if ($socket === null) {
            return null;
        }

        $request = $this->buildMapRequest($opCode, $externalPort, $internalPort, $leaseDuration);
        $sent = @socket_sendto($socket, $request, strlen($request), 0, $gatewayIp, self::NAT_PMP_GATEWAY_PORT);
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

            // L4: see discoverGateway() — only the configured gateway's reply is
            // trusted; anything else keeps the loop until the overall timeout.
            if ($fromAddr !== $gatewayIp) {
                continue;
            }

            socket_close($socket);

            if ($recvLen !== false && $recvLen >= 16) {
                return $this->parseMappingResponse($response, $opCode);
            }
            return null;
        }

        socket_close($socket);
        return null;
    }

    /**
     * Parses a NAT-PMP mapping reply, RFC 6886 §3.3 (16 bytes):
     *
     *   byte 0     Version (0)
     *   byte 1     Opcode — 128 + x, "x MUST match what the client requested"
     *   bytes 2-3  Result Code (16-bit, network byte order)
     *   bytes 4-7  Seconds Since Start of Epoch
     *   bytes 8-9  Internal Port (echoed)
     *   bytes 10-11 Mapped External Port
     *   bytes 12-15 Port Mapping Lifetime
     *
     * §3 ("Responses always contain a 16-bit result code... A result code
     * of zero indicates success") makes the result code a hard gate, and
     * §3.5 requires a failed mapping reply to carry "Mapped External Port
     * and Port Mapping Lifetime MUST be set appropriately -- i.e., zero if
     * no successful port mapping was created" — so before this gate the
     * client read the reply's zeroed port slot and RETURNED 0 as if the
     * gateway had assigned port 0. Every §3.5 code (1 Unsupported Version,
     * 2 Not Authorized/Refused, 3 Network Failure, 4 Out of resources,
     * 5 Unsupported opcode, and any undefined code — "Undefined results
     * codes MUST be treated as fatal errors of the request") now fails
     * here, mirroring parseExternalIp()'s gate on the §3.2 reply.
     *
     * The GRANTED lifetime (bytes 12-15) is part of the returned record,
     * not decoration: §3.3 (lines 664-666) — "The NAT gateway MAY reduce
     * the lifetime from what the client requested" — so the request's
     * lease value says nothing about when the mapping actually expires,
     * and §3.3 (lines 679-681) — "The client SHOULD begin trying to renew
     * the mapping halfway to expiry time, like DHCP" — makes the granted
     * number the input to the renewal deadline. The length guard therefore
     * moved from 12 to the full 16-byte reply: a datagram without the
     * lifetime field cannot answer the question this parser now has to
     * answer.
     *
     * @param string $response Raw reply payload.
     * @param int    $opCode   Request opcode (OP_CODE_MAP_UDP/OP_CODE_MAP_TCP).
     *
     * @return array{external_port: int, granted_lifetime: int}|null
     *     The assigned external port and the gateway-granted lifetime in
     *     seconds, or null on any failure.
     */
    private function parseMappingResponse(string $response, int $opCode): ?array
    {
        if (strlen($response) < 16) {
            return null;
        }

        if (ord($response[1]) !== ($opCode | self::RESPONSE_FLAG)) {
            return null;
        }

        $resultCode = $this->readResultCode($response);
        if ($resultCode !== 0) {
            $this->logger->debug('NAT-PMP: mapping request failed on result code', [
                'result_code' => $resultCode,
            ]);
            return null;
        }

        $port = unpack('n', substr($response, 10, 2));
        $lifetime = unpack('N', substr($response, 12, 4));
        if (
            is_array($port) && isset($port[1]) && is_int($port[1])
            && is_array($lifetime) && isset($lifetime[1]) && is_int($lifetime[1])
        ) {
            return ['external_port' => $port[1], 'granted_lifetime' => $lifetime[1]];
        }

        return null;
    }

    /**
     * Reads the 16-bit Result Code (bytes 2-3, network byte order) that
     * RFC 6886 §3 says every reply carries: "Responses always contain a
     * 16-bit result code in network byte order. A result code of zero
     * indicates success" — the single gate shared by parseExternalIp()
     * (§3.2), parseMappingResponse() (§3.3) and removePortMapping()
     * (§3.4, whose reply §3.4 defines as "formatted as defined in
     * Section 3.3").
     *
     * @return int|null The result code, or null when the datagram is too
     *     short to hold one — which callers must treat as failure (fail
     *     loud: an unreadable code never reads as success).
     */
    private function readResultCode(string $response): ?int
    {
        if (strlen($response) < 4) {
            return null;
        }

        $parts = unpack('n', substr($response, 2, 2));
        if (is_array($parts) && isset($parts[1]) && is_int($parts[1])) {
            return $parts[1];
        }

        return null;
    }

    /**
     * Builds a NAT-PMP public address request — exactly the 2-byte §3.2
     * shape: "Vers = 0 | OP = 0" is the whole diagram (one 16-bit row, no
     * Reserved field; RFC 6886 §3.2). Until this pass the builder appended
     * two extra zero bytes. Real-world impact was nil — servers parse the
     * fixed-offset header and ignore datagram slack, and the fork-responder
     * round trip in NatPmpClientTest answers without a length check — but
     * the spec request is 2 bytes, so this now sends 2 bytes. Deliberately
     * scoped to the ADDRESS request: the §3.3/§3.4 mapping builders were
     * already exactly 12 bytes and are untouched.
     */
    private function buildPublicAddressRequest(): string
    {
        return chr(self::VERSION) . chr(0);
    }

    /**
     * Builds a NAT-PMP mapping request, RFC 6886 §3.3 (12 bytes):
     *
     *   byte 0     Version (0)
     *   byte 1     Opcode (the OP_CODE_MAP_* constant for the protocol)
     *   bytes 2-3  Reserved — MUST be zero on transmission, ignored on reception
     *   bytes 4-5  Internal Port
     *   bytes 6-7  Suggested External Port (0 = "allocate any high port")
     *   bytes 8-11 Requested Port Mapping Lifetime in seconds
     *
     * The two port fields sit Internal-first on the wire. Until this
     * wire-fidelity pass they were emitted swapped (suggested external at
     * 4-5, internal at 6-7): harmless only for the symmetric
     * addPortMapping($gw, $p, $p) call PortForwardService makes — the
     * gateway read equal values from both slots whatever order they went
     * in — but an anonymous request (external 0) told the gateway the
     * INTERNAL port was 0, and any mismatched pair was wired backwards.
     * Pinned byte-for-byte by NatPmpClientTest on a non-symmetric case.
     */
    private function buildMapRequest(
        int $opCode,
        int $externalPort,
        int $internalPort,
        int $leaseDuration
    ): string {
        return chr(self::VERSION) . chr($opCode)
            . pack('n', 0) . pack('n', $internalPort)
            . pack('n', $externalPort) . pack('N', $leaseDuration);
    }

    /**
     * Builds a NAT-PMP mapping-deletion request, RFC 6886 §3.4 (12 bytes,
     * same §3.3 layout as buildMapRequest() with lifetime 0):
     *
     *   bytes 4-5  Internal Port — §3.4: "a client requests explicit deletion
     *              of a mapping by sending a message to the NAT gateway
     *              requesting the mapping, with the Requested Lifetime in
     *              Seconds set to zero", and the response echoes "the internal
     *              port as indicated in the deletion request": the mapping is
     *              keyed by this slot.
     *   bytes 6-7  Suggested External Port — §3.4: "MUST be set to zero by the
     *              client on sending, and MUST be ignored by the gateway on
     *              reception".
     *   bytes 8-11 Lifetime — 0, the deletion marker itself.
     *
     * The port argument therefore lives in the internal slot: callers hand
     * this API the port they mapped, and NAT-PMP mappings are created here
     * symmetrically (internal == external, see PortForwardService), so one
     * number serves both roles. Note the trap this pin guards: internal 0
     * with external 0 and lifetime 0 is NOT "delete that port" — §3.4
     * defines that shape as the wildcard that deletes every mapping this
     * client owns for the opcode's protocol, so mechanically swapping the
     * pair the way buildMapRequest() needed would turn a targeted unmap
     * into a mass deletion.
     */
    private function buildUnmapRequest(int $opCode, int $internalPort): string
    {
        return chr(self::VERSION) . chr($opCode)
            . pack('n', 0) . pack('n', $internalPort)
            . pack('n', 0) . pack('N', 0);
    }

    /**
     * Parses the external IP from a NAT-PMP public address reply.
     *
     * RFC 6886 §3.2 fixes the wire layout of the 12-byte reply, and §3
     * fixes the Result Code width ("Responses always contain a 16-bit
     * result code in network byte order"), which pins every offset:
     *
     *   byte 0     Version (unused, 0)
     *   byte 1     Opcode (128 + 0)
     *   bytes 2-3  Result Code (16-bit, network byte order)
     *   bytes 4-7  Seconds Since Start of Epoch (32-bit)
     *   bytes 8-11 External IPv4 Address (4 octets, no byte swapping)
     *
     * The address lives at BYTES 8-11. Reading bytes 4-7 — what this
     * method did until the device rework lane flagged it — returned the
     * high octets of the gateway's epoch counter as if they were an IP,
     * never the external address. (The mapping reply, RFC 6886 §3.3,
     * shares the same 2+2+4 prefix and carries the mapped external port
     * at bytes 10-11 — which is exactly where mapPort() reads it.)
     *
     * The result code at bytes 2-3 is a hard gate, not decoration.
     * §3: "Responses always contain a 16-bit result code in network byte
     * order. A result code of zero indicates success." §3.2: "If the
     * result code is non-zero, the value of the External IPv4 Address
     * field is undefined (MUST be set to zero on transmission, and MUST
     * be ignored on reception)." §3.5 defines 1 Unsupported Version,
     * 2 Not Authorized/Refused, 3 Network Failure, 4 Out of resources,
     * 5 Unsupported opcode, and requires any undefined code to be "treated
     * as a fatal error of the request" — so every non-zero value fails
     * here. Before this gate, an RFC-compliant failure reply (all-zero
     * address field) decoded into the string '0.0.0.0' and was returned
     * as a success-looking external address.
     */
    private function parseExternalIp(string $response): ?string
    {
        if (strlen($response) < 12) {
            return null;
        }

        $resultCode = $this->readResultCode($response);
        if ($resultCode !== 0) {
            $this->logger->debug('NAT-PMP: public address request failed on result code', [
                'result_code' => $resultCode,
            ]);
            return null;
        }

        $ipBytes = substr($response, 8, 4);
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
                // fallback below. S197.
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
     * ⚠ A SEAM, measured rather than assumed (S197): on swoole 6.2.1 / PHP 8.3.6 —
     * the dev box and the CI runner — nothing inside that try block can be
     * provoked into throwing. `connect()`, `getsockname()` and `close()` all
     * return false and set `errCode`; a double `close()` returns false; cancelling
     * the coroutine mid-connect returns false. And a genuinely failing `socket(2)`
     * (reproduced with `setrlimit(RLIMIT_NOFILE, 16)` via FFI, and again with an
     * invalid socket type) does not raise `Swoole\Coroutine\Socket\Exception` on
     * this build — it SIGSEGVs inside `new`, through an enclosing
     * `catch (\Throwable)`. So the widened catch could not otherwise be pinned by
     * any test, and an unpinned catch clause is exactly how the narrow one
     * survived. Overriding this method is how the test throws a real
     * `Swoole\Exception` at the line production throws it from.
     *
     * @param int $type Socket type, e.g. SOCK_DGRAM.
     */
    protected function createCoroutineSocket(int $type): \Swoole\Coroutine\Socket
    {
        // S434: routed through the construction guard — the two entry states S207
        // measured faulting inside `new` (invalid arguments, socket(2) EMFILE) are
        // refused as a typed CoroutineSocketConstructionRefused BEFORE any `new`.
        // Overriding this method remains the test seam: an override never reaches
        // the guard, exactly as it never reached the old bare construction.
        return CoroutineSocketGuard::create(AF_INET, $type, 0);
    }
}
