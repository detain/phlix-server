<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\PortProbeOutcome;
use Phlix\Network\StunClient;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
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

    public function testProbePortClassifiesAClosedPortAsRefusedInsideACoroutine(): void
    {
        $logger = new ProbeRecordingLogger();
        $client = new StunClient($logger);
        $port = $this->closedLoopbackPort();

        $outcome = $this->runInCoroutine(static fn (): PortProbeOutcome => $client->probePort('127.0.0.1', $port));

        $this->assertSame(
            PortProbeOutcome::Refused,
            $outcome,
            'the coroutine arm must be able to answer "not open" — it previously could not, '
            . 'because BOTH of its branches returned true (S169).'
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

        // Swoole reports its own code 711 here rather than an errno; .invalid is
        // reserved by RFC 2606 so this never leaves the resolver as a real query
        // that could succeed.
        $outcome = $this->runInCoroutine(
            static fn (): PortProbeOutcome => $client->probePort('phlix-s169.invalid', 32400, 1.0)
        );

        $this->assertSame(PortProbeOutcome::Unresolved, $outcome);
        $this->assertFalse($outcome->isOpen(), 'an unresolvable target is not an open port');
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
     * @param array<string, mixed> $context
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
}
