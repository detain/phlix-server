<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\PortProbeOutcome;
use Phlix\Network\StunClient;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Socket;
use Stringable;

/**
 * S169/S170 — `testPortAccessibility()` must be able to answer "no", on BOTH arms.
 *
 * ## What the two probe tests used to be, and why they were replaced
 *
 * `testTestPortAccessibilityReturnsFalseForUnreachable` probed the live address
 * `192.0.2.1:32400` (RFC 5737 TEST-NET-1) and asserted `false`. Its verdict
 * depended on the HOST'S FIREWALL POLICY: on the dev box CSF *rejects* the
 * outbound SYN, so the probe came back `errno=111 "Connection refused"` in 0 ms
 * and the old fallback (`return $errno === 111 || $errno === 0;`) answered
 * `true` — RED. In a CI container the same address gives a no-route errno
 * (101/113) and the old code answered `false` — GREEN. Same code, same
 * assertion, opposite results.
 *
 * `testTestPortAccessibilityReturnsTrueForLocalhost` asserted `true` for
 * `127.0.0.1:80`, which passed for two DIFFERENT reasons depending on the box:
 * a real web server answered on the dev box, and in CI *nothing* listens on 80
 * so it passed only via the `errno === 111` clause — i.e. it was pinning the very
 * defect S169 removes, and would have gone red on the fix.
 *
 * Both are replaced by probes against sockets THIS TEST owns: an ephemeral
 * loopback listener for "open", and an ephemeral loopback port that was bound and
 * then closed for "refused". No outbound packet, no dependence on a firewall
 * policy or on what happens to be installed on the box, and the assertions got
 * stronger rather than weaker — "open" now means a handshake this test can
 * account for.
 *
 * ## Why the coroutine tests exist (S170)
 *
 * `StunClient::probePort()` forks on `inCoroutine()`. PHPUnit never runs inside a
 * coroutine (`Swoole\Coroutine::getCid()` is `-1` with the extension loaded), so
 * the suite only ever executed the blocking arm while every Swoole worker takes
 * the other one — which is how an arm with two `return true` statements survived.
 * The tests below run the SAME assertions inside a real coroutine via
 * {@see RunsInCoroutine}, and each one asserts the `transport` field the probe
 * logs, so a test cannot silently start pinning the wrong arm.
 */
class StunClientTest extends TestCase
{
    use RunsInCoroutine;

    /** @var list<resource> */
    private array $listeners = [];

    protected function tearDown(): void
    {
        foreach ($this->listeners as $listener) {
            @fclose($listener);
        }
        $this->listeners = [];
    }

    public function testClientCanBeInstantiated(): void
    {
        $client = new StunClient();
        $this->assertInstanceOf(StunClient::class, $client);
    }

    public function testClientWithCustomLogger(): void
    {
        $client = new StunClient(new NullLogger());
        $this->assertInstanceOf(StunClient::class, $client);
    }

    public function testClientWithCustomServer(): void
    {
        $client = new StunClient(new NullLogger(), 'stun.example.com', 19302);
        $this->assertInstanceOf(StunClient::class, $client);
    }

    public function testGetPublicIpReturnsNullOnFailure(): void
    {
        $client = new StunClient(new NullLogger(), 'invalid.stun.server', 9999);
        $result = $client->getPublicIp();
        $this->assertNull($result);
    }

    public function testDefaultConstants(): void
    {
        $this->assertEquals('stun.l.google.com', StunClient::DEFAULT_STUN_SERVER);
        $this->assertEquals(19302, StunClient::DEFAULT_STUN_PORT);
    }

    // -----------------------------------------------------------------------
    // Blocking arm — the one PHPUnit reaches by default.
    // -----------------------------------------------------------------------

    public function testPortAccessibilityIsFalseForAClosedPort(): void
    {
        $client = new StunClient(new NullLogger());

        $this->assertFalse(
            $client->testPortAccessibility('127.0.0.1', $this->closedLoopbackPort()),
            'a port with nothing listening is NOT open: the connection is refused, and for a '
            . 'NAT-forwarding check refused means "not forwarded" (S169).'
        );
    }

    public function testPortAccessibilityIsTrueForAListeningPort(): void
    {
        $client = new StunClient(new NullLogger());

        $this->assertTrue(
            $client->testPortAccessibility('127.0.0.1', $this->listeningLoopbackPort()),
            'a completed TCP handshake is the one thing that means open'
        );
    }

    public function testProbePortClassifiesAClosedPortAsRefusedOnTheBlockingArm(): void
    {
        $logger = new ProbeRecordingLogger();
        $client = new StunClient($logger);

        $outcome = $client->probePort('127.0.0.1', $this->closedLoopbackPort());

        $this->assertSame(PortProbeOutcome::Refused, $outcome);
        $this->assertFalse($outcome->isOpen());
        // Proves WHICH arm produced that verdict — without this the test could
        // not tell the two implementations apart.
        $this->assertSame('blocking', $logger->lastProbeField('transport'));
        $this->assertSame('refused', $logger->lastProbeField('outcome'));
    }

    public function testProbePortClassifiesAListeningPortAsOpenOnTheBlockingArm(): void
    {
        $logger = new ProbeRecordingLogger();
        $client = new StunClient($logger);

        $outcome = $client->probePort('127.0.0.1', $this->listeningLoopbackPort());

        $this->assertSame(PortProbeOutcome::Open, $outcome);
        $this->assertTrue($outcome->isOpen());
        $this->assertSame('blocking', $logger->lastProbeField('transport'));
        $this->assertSame('open', $logger->lastProbeField('outcome'));
    }

    // -----------------------------------------------------------------------
    // Coroutine arm — the one production actually runs. S170.
    // -----------------------------------------------------------------------

    public function testPhpunitIsNotInACoroutineOnTheMainStack(): void
    {
        // The measurement S169 and S170 both rest on, asserted rather than
        // asserted-about: swoole is LOADED and getCid() is still -1, so every
        // test that does not use RunsInCoroutine takes the blocking arm.
        $this->assertTrue(extension_loaded('swoole'), 'swoole is expected in CI and on the dev box');
        $this->assertSame(-1, \Swoole\Coroutine::getCid());
    }

    public function testProbePortDoesNotCallAClosedPortOpenInsideACoroutine(): void
    {
        $logger = new ProbeRecordingLogger();
        $client = new StunClient($logger);
        $port = $this->closedLoopbackPort();

        $outcome = $this->runInCoroutine(static fn (): PortProbeOutcome => $client->probePort('127.0.0.1', $port));

        // ⚠ The EXACT classification is deliberately not asserted here, and that
        // is a measurement rather than a hedge: Swoole\Coroutine\Socket::errCode
        // is not reliably populated. Connecting to a closed loopback port gives
        // errCode 111 on swoole 6.2.1/PHP 8.3.6 and on 6.2.2/PHP 8.4.21, and
        // errCode 0 with an empty errMsg on 6.2.2/PHP 8.3.32 — so "refused"
        // legitimately arrives as Failed on some builds. Asserting Refused here
        // would make this test's verdict depend on which swoole the runner has,
        // which is the same class of defect as depending on the host firewall.
        // The exact errno->outcome mapping is pinned by PortProbeOutcomeTest and
        // by the blocking-arm test above (fsockopen reports errno 111 in all
        // three environments).
        $this->assertNotSame(
            PortProbeOutcome::Open,
            $outcome,
            'the coroutine arm must be able to answer "not open" — it previously could not, '
            . 'because BOTH of its branches returned true (S169).'
        );
        $this->assertFalse($outcome->isOpen());
        $this->assertContains(
            $outcome,
            [PortProbeOutcome::Refused, PortProbeOutcome::Failed],
            'a closed loopback port is either classified refused or, where the build does not '
            . 'report an errno, unclassifiable — never anything else'
        );
        $this->assertSame(
            'coroutine',
            $logger->lastProbeField('transport'),
            'this test is worthless unless the CORO arm produced the verdict'
        );
    }

    public function testPortAccessibilityIsFalseForAClosedPortInsideACoroutine(): void
    {
        $logger = new ProbeRecordingLogger();
        $client = new StunClient($logger);
        $port = $this->closedLoopbackPort();

        $accessible = $this->runInCoroutine(
            static fn (): bool => $client->testPortAccessibility('127.0.0.1', $port)
        );

        $this->assertFalse($accessible);
        $this->assertSame('coroutine', $logger->lastProbeField('transport'));
    }

    public function testPortAccessibilityIsTrueForAListeningPortInsideACoroutine(): void
    {
        $logger = new ProbeRecordingLogger();
        $client = new StunClient($logger);
        $port = $this->listeningLoopbackPort();

        $accessible = $this->runInCoroutine(
            static fn (): bool => $client->testPortAccessibility('127.0.0.1', $port)
        );

        // The counterweight to the test above: the fix must not degrade into
        // "always false", and only this pair can tell the two apart.
        $this->assertTrue($accessible);
        $this->assertSame('coroutine', $logger->lastProbeField('transport'));
        $this->assertSame('open', $logger->lastProbeField('outcome'));
    }

    public function testCoroutineArmReportsUnresolvedForANameThatCannotResolve(): void
    {
        $logger = new ProbeRecordingLogger();
        $client = new StunClient($logger);

        // Swoole reports its own code 711 ("DNS Lookup resolve failed") here
        // rather than an errno; .invalid is reserved by RFC 2606 so this never
        // leaves the resolver as a real query that could succeed. Measured as 711
        // on swoole 6.2.1 and on the 6.2.2 build that reports errCode 0 for a
        // refused connect — but the same errCode caveat applies, so Failed is
        // accepted as well and the invariant asserted is "not open".
        $outcome = $this->runInCoroutine(
            static fn (): PortProbeOutcome => $client->probePort('phlix-s169.invalid', 32400, 1.0)
        );

        $this->assertFalse($outcome->isOpen(), 'an unresolvable target is not an open port');
        $this->assertContains($outcome, [PortProbeOutcome::Unresolved, PortProbeOutcome::Failed]);
        $this->assertSame('coroutine', $logger->lastProbeField('transport'));
    }

    // -----------------------------------------------------------------------
    // Helpers — sockets this test owns, so no verdict depends on the host.
    // -----------------------------------------------------------------------

    /**
     * A loopback port with a real listener on it, closed in tearDown().
     *
     * The listener is never accept()ed: the kernel completes the handshake from
     * the backlog, which is all "is this port open" asks.
     */
    private function listeningLoopbackPort(): int
    {
        $errno = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not open a loopback listener: {$errstr} ({$errno})");
        $this->listeners[] = $server;

        return $this->portOf($server);
    }

    /**
     * A loopback port with nothing listening: bound to learn an ephemeral port
     * number, then closed. A connect to it is refused immediately by the kernel.
     */
    private function closedLoopbackPort(): int
    {
        $errno = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not open a loopback listener: {$errstr} ({$errno})");
        $port = $this->portOf($server);
        fclose($server);

        return $port;
    }

    /**
     * @param resource $server
     */
    private function portOf($server): int
    {
        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);
        $colon = strrpos($name, ':');
        $this->assertNotFalse($colon, "unexpected socket name: {$name}");

        return (int) substr($name, $colon + 1);
    }

    // -----------------------------------------------------------------------
    // Device-lane rework (item 3) — the L4 binding-reply source pin, executed.
    // Before these cases the wrong-source discard cited in
    // docs/dev/BLOCKING_IO_EXCEPTIONS.md Exception 5 had zero coverage: the
    // only getPublicIp test above asserts the UNRESOLVABLE-host null, which
    // never reaches the check.
    //
    // These two cases stay on the 'localhost' NAME path so it keeps its
    // positive and negative coverage. An IP-literal config used to be
    // refused pre-send (gethostbyname($server) === $server doubled as the
    // resolution-failure signal); resolveStunServerIp() now short-circuits
    // literals, pinned by testGetPublicIpProbesAnIpLiteralServerConfig below.
    // -----------------------------------------------------------------------

    public function testGetPublicIpDiscardsBindingSuccessFromWrongSource(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the forked-responder harness.');
        }
        if (gethostbyname('localhost') !== '127.0.0.1') {
            $this->markTestSkipped('this host does not resolve localhost to 127.0.0.1.');
        }

        $port = $this->loopbackUdpPortOn('127.0.0.1');
        $server = $this->bindUdp('127.0.0.1', $port);

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child: sniffs the real binding request, then answers it with a
            // structurally PERFECT success from a different source address.
            $rogue = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($rogue === false || @socket_bind($rogue, '127.0.0.2', 0) === false) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            $read = [$server];
            $write = null;
            $except = null;
            if (@socket_select($read, $write, $except, 5) < 1) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            $buf = '';
            $clientIp = '';
            $clientPort = 0;
            if (@socket_recvfrom($server, $buf, 1024, 0, $clientIp, $clientPort) === false) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            @socket_sendto($rogue, $this->stunSuccessReply('198.51.100.7'), 32, 0, $clientIp, $clientPort);
            posix_kill(posix_getpid(), SIGKILL);
        }

        socket_close($server);

        $client = new StunClient(new NullLogger(), 'localhost', $port);
        try {
            $this->assertNull(
                $client->getPublicIp(),
                'a STUN success from a host other than the configured server must be discarded (L4); '
                . 'without the pin this response parses as 198.51.100.7'
            );
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    public function testGetPublicIpAcceptsReplyFromTheConfiguredSource(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the forked-responder harness.');
        }
        if (gethostbyname('localhost') !== '127.0.0.1') {
            $this->markTestSkipped('this host does not resolve localhost to 127.0.0.1.');
        }

        $port = $this->loopbackUdpPortOn('127.0.0.1');
        $server = $this->bindUdp('127.0.0.1', $port);

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Same well-formed success, but from the socket the request hit —
            // the positive control proving the discard test is not just
            // pinning a timeout.
            $read = [$server];
            $write = null;
            $except = null;
            if (@socket_select($read, $write, $except, 5) < 1) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            $buf = '';
            $clientIp = '';
            $clientPort = 0;
            if (@socket_recvfrom($server, $buf, 1024, 0, $clientIp, $clientPort) === false) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            @socket_sendto($server, $this->stunSuccessReply('203.0.113.9'), 32, 0, $clientIp, $clientPort);
            posix_kill(posix_getpid(), SIGKILL);
        }

        socket_close($server);

        $client = new StunClient(new NullLogger(), 'localhost', $port);
        try {
            $this->assertSame(
                '203.0.113.9',
                $client->getPublicIp(),
                'a XOR-MAPPED-ADDRESS success from the configured server must parse normally'
            );
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * An IP-literal stunServer config must PROBE, not refuse pre-send.
     *
     * Pre-fix, getPublicIp() called gethostbyname() unconditionally and
     * treated "returned input unchanged" as failure — which is exactly
     * what gethostbyname() does for a numeric address — so a valid
     * literal config (pre-resolved 'stun.l.google.com', '1.1.1.1', a
     * loopback test server) silently never sent a binding request. This
     * harness only goes green if a request actually arrives: the child
     * blocks in socket_select() for it and answers with a well-formed
     * success, and the parent must parse the address back out.
     */
    public function testGetPublicIpProbesAnIpLiteralServerConfig(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the forked-responder harness.');
        }

        $port = $this->loopbackUdpPortOn('127.0.0.1');
        $server = $this->bindUdp('127.0.0.1', $port);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $read = [$server];
            $write = null;
            $except = null;
            if (@socket_select($read, $write, $except, 5) < 1) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            $buf = '';
            $clientIp = '';
            $clientPort = 0;
            if (@socket_recvfrom($server, $buf, 1024, 0, $clientIp, $clientPort) === false) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            @socket_sendto($server, $this->stunSuccessReply('203.0.113.9'), 32, 0, $clientIp, $clientPort);
            posix_kill(posix_getpid(), SIGKILL);
        }

        socket_close($server);

        $client = new StunClient(new NullLogger(), '127.0.0.1', $port);
        try {
            $this->assertSame(
                '203.0.113.9',
                $client->getPublicIp(),
                'an IP-literal config must resolve to itself and probe; the old code '
                . 'refused it pre-send as an apparent resolution failure'
            );
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * The other side of resolveStunServerIp(): a NAME that does not
     * resolve still refuses — and refuses through the logged pre-send
     * refusal, not by accidentally timing out on a socket it never sent
     * on (which is what testGetPublicIpReturnsNullForFailure alone could
     * not distinguish).
     */
    public function testGetPublicIpRefusesAnUnresolvableNameWithALoggedRefusal(): void
    {
        $logger = new ProbeRecordingLogger();
        $client = new StunClient($logger, 'phlix-s169.invalid', 19302);

        $this->assertNull($client->getPublicIp());
        $this->assertTrue(
            $logger->sawMessageContaining('could not resolve STUN server'),
            'the refusal must be the resolution gate, and it must be loud'
        );
    }

    /**
     * Learn an unused loopback UDP port by binding and releasing it.
     */
    private function loopbackUdpPortOn(string $address): int
    {
        $probe = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($probe);
        $this->assertNotFalse(@socket_bind($probe, $address, 0), 'could not bind an ephemeral UDP port');
        $port = 0;
        $host = '';
        $this->assertNotFalse(socket_getsockname($probe, $host, $port));
        socket_close($probe);

        return $port;
    }

    /**
     * A UDP socket bound to address:port (kept by the caller).
     */
    private function bindUdp(string $address, int $port): Socket
    {
        $server = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($server);
        if (@socket_bind($server, $address, $port) === false) {
            $reason = socket_strerror(socket_last_error($server));
            socket_close($server);
            $this->fail("cannot bind {$address}:{$port} on this host: {$reason}");
        }

        return $server;
    }

    /**
     * RFC 5389 Binding Success with a single XOR-MAPPED-ADDRESS (0x0020):
     * 20-byte header (type 0x0101, cookie) + attr(4-byte header + 8-byte value).
     * The client parses attributes from STUN_HEADER_SIZE and XORs the address
     * with the magic cookie; the transaction id is not checked, so any 12
     * random bytes complete the frame.
     */
    private function stunSuccessReply(string $claimedIp): string
    {
        $cookie = 0x2112A442;
        $xoredIp = '';
        foreach (explode('.', $claimedIp) as $index => $octet) {
            $maskByte = ($cookie >> (24 - 8 * $index)) & 0xFF;
            $xoredIp .= chr(((int) $octet) ^ $maskByte);
        }

        $attr = pack('n', 0x0020) . pack('n', 8) . pack('n', 0x0001)
            . pack('n', 40531 ^ 0x2112) . $xoredIp;

        return pack('n', 0x0101) . pack('n', strlen($attr)) . pack('N', $cookie)
            . random_bytes(12) . $attr;
    }
}

/**
 * Captures the context of StunClient's probe log line so a test can assert WHICH
 * arm ran and what it concluded.
 *
 * @internal
 */
final class ProbeRecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }

    /**
     * One field of the most recent "port probe" record.
     */
    public function lastProbeField(string $field): mixed
    {
        foreach (array_reverse($this->records) as $record) {
            if (str_contains($record['message'], 'port probe')) {
                return $record['context'][$field] ?? null;
            }
        }

        return null;
    }

    /**
     * Whether any logged message contains the given needle.
     */
    public function sawMessageContaining(string $needle): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
