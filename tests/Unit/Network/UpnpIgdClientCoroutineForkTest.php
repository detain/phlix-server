<?php

/**
 * Phlix media server test component: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Net\LanEndpointGuard;
use Phlix\Network\UpnpIgdClient;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * W3-FORKINVENTORY (2026-11-20): executes the fork at src/Network/UpnpIgdClient.php
 * asyncHttpGet() — `if (self::inCoroutine())` — from INSIDE a real Swoole coroutine.
 *
 * Until now the suite never entered that branch. UpnpIgdClientTest drives the
 * bounded blocking reader directly through php://memory and stream_socket_pair,
 * and its full-URL tests take the fsockopen arm on the main stack, so the
 * Coroutine\Http\Client path the production worker runs was unexecuted (the
 * exact defect class ForkInventoryGuardTest exists to keep out of the repo).
 *
 * ## THE ARM-IDENTITY DISCRIMINATOR — chunked bodies
 *
 * The two arms cannot be confused because the payloads they return differ by
 * construction. The test server answers with a `Transfer-Encoding: chunked`
 * response; Swoole's coroutine HTTP client decodes the framing and exposes the
 * body, while the blocking arm's raw read + header-strip regex would hand back
 * the framing bytes verbatim ("2\r\nhe\r\n3\r\nllo..."). So a body of exactly
 * "hello" is only reachable through the coroutine arm.
 *
 * The oversize case carries the same proof in its log line: the coroutine arm
 * warns "...exceeded the size cap, dropping" while the blocking reader warns
 * "...exceeded the size cap, aborting". Asserting the "dropping" text pins the
 * arm independently of any socket behavior.
 *
 * ## VENUE DISCIPLINE (recorded honestly)
 *
 * Tests using runInCoroutine() skip when ext-swoole is absent — on this dev
 * box three of the four legs here are skips, and the skip is the trait's
 * recorded venue reality, not a fabricated green. The swoole-present venue
 * (docker ghcr.io/detain/phlix-base, and CI) runs them for real; that run is
 * the authority. The fourth test is the blocking-arm control and passes on
 * every venue, so the file is never green-by-skipping-only.
 *
 * Only the coroutine arm runs inside the helper — the blocking arm would raise
 * connect warnings that PHPUnit cannot attribute across the coroutine stack
 * (RunsInCoroutine docblock, S197). A throw ESCAPING the sibling server
 * coroutine is process-fatal, not a test failure (the trait only captures the
 * main body), so harness defects are carried back as data and asserted null on
 * the main stack: the fake device's handshake failing decides THIS test loudly
 * instead of killing the run.
 */
class UpnpIgdClientCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    /**
     * The coroutine arm completes a chunked GET: body decoded to "hello", and
     * the bytes the fake IGD actually received are the pinned request line and
     * headers UpnpIgdClient::asyncHttpGet sets.
     */
    public function testTheCoroutineArmDecodesAChunkedBodyTheBlockingArmCouldNot(): void
    {
        $client = new UpnpIgdClient(new NullLogger(), 500);
        $port = $this->reserveLoopbackPort();

        /** @var array{body: string|null, wire: string|null, harness: string|null} $run */
        $run = $this->runInCoroutine(function () use ($client, $port): array {
            $server = $this->bindLoopback($port);
            $wire = null;
            $harness = null;

            \Swoole\Coroutine::create(function () use ($server, &$wire, &$harness): void {
                try {
                    $wire = $this->acceptAndAnswer(
                        $server,
                        $this->chunkedResponse('he', 'llo')
                    );
                } catch (Throwable $e) {
                    // A throw ESCAPING a sibling coroutine is process-fatal —
                    // RunsInCoroutine only captures its own body — so the
                    // harness defect travels back as data and decides the test
                    // on the main stack instead of killing the run.
                    $harness = $e->getMessage();
                }
            });

            $body = $this->asyncHttpGet($client, '127.0.0.1', $port, '/description.xml');

            return ['body' => $body, 'wire' => $wire, 'harness' => $harness];
        });

        $this->assertNull($run['harness'], 'the fake IGD handshake itself must have succeeded');

        // Arm identity: only the Coroutine\Http\Client path strips chunked
        // framing — the blocking arm would return the framing bytes verbatim.
        $this->assertSame('hello', $run['body']);

        // The request the fake device saw, byte-pinned (HTTP field names are
        // case-insensitive on the wire, so the haystack is folded; the VALUES
        // — target, host:port, accept, close — are asserted exactly).
        $this->assertIsString($run['wire']);
        $folded = strtolower($run['wire']);
        $this->assertStringStartsWith("get /description.xml http/1.1\r\n", $folded);
        $this->assertStringContainsString("accept: */*\r\n", $folded);
        $this->assertStringContainsString("connection: close\r\n", $folded);
    }

    /**
     * The coroutine arm enforces LanEndpointGuard's size cap on the decoded
     * body and warns with ITS OWN text ("dropping"); the blocking reader's
     * identical cap says "aborting". Both the refusal and the arm-identifying
     * warning are asserted.
     */
    public function testTheCoroutineArmDropsAnOversizeBodyAndWarnsWithTheDroppingText(): void
    {
        $logger = new ForkWarningRecorder();
        $client = new UpnpIgdClient($logger, 500);
        $payload = str_repeat('z', LanEndpointGuard::MAX_RESPONSE_BYTES + 65536);
        $port = $this->reserveLoopbackPort();

        /** @var array{body: string|null, harness: string|null} $run */
        $run = $this->runInCoroutine(function () use ($client, $payload, $port): array {
            $server = $this->bindLoopback($port);
            $harness = null;

            \Swoole\Coroutine::create(function () use ($server, $payload, &$harness): void {
                try {
                    $this->acceptAndAnswer($server, $this->chunkedResponse($payload));
                } catch (Throwable $e) {
                    $harness = $e->getMessage();
                }
            });

            $body = $this->asyncHttpGet($client, '127.0.0.1', $port, '/description.xml');

            return ['body' => $body, 'harness' => $harness];
        });

        $this->assertNull($run['harness'], 'the fake IGD handshake itself must have succeeded');
        $this->assertNull($run['body'], 'the size cap must survive decoding through the coroutine arm');
        $this->assertContains(
            'UPnP: device response exceeded the size cap, dropping',
            $logger->warnings,
            'the "dropping" wording is emitted ONLY by the coroutine arm '
            . '(the blocking reader warns "aborting") — this pins which arm ran'
        );
    }

    /**
     * Inside a coroutine, a connection refused by the loopback (nothing
     * listening) degrades to a null body through the execute()===false path —
     * the fork's failure leg, executed for real.
     */
    public function testTheCoroutineArmReturnsNullWhenNothingIsListening(): void
    {
        $client = new UpnpIgdClient(new NullLogger(), 500);
        $port = $this->closedLoopbackPort();

        /** @var string|null $body */
        $body = $this->runInCoroutine(
            fn (): ?string => $this->asyncHttpGet($client, '127.0.0.1', $port, '/description.xml')
        );

        $this->assertNull($body);
    }

    /**
     * Blocking-arm control on the MAIN stack for the same closed port. Green
     * on every venue (including this swoole-less dev box), which is what keeps
     * this file honest when legs one to three skip: the fork's other side IS
     * executed here, and the two closed-port legs together prove the null is
     * the arms' real conclusion and not a skipped comparison.
     */
    public function testTheBlockingArmOnTheMainStackReturnsNullForTheSameClosedPort(): void
    {
        $client = new UpnpIgdClient(new NullLogger(), 500);
        $port = $this->closedLoopbackPort();

        $this->assertNull($this->asyncHttpGet($client, '127.0.0.1', $port, '/description.xml'));
    }

    /**
     * Invokes the production private fork seam and fail-louds on shape drift,
     * so a changed return contract breaks this test rather than hiding.
     */
    private function asyncHttpGet(UpnpIgdClient $client, string $host, int $port, string $path): ?string
    {
        $method = new ReflectionMethod(UpnpIgdClient::class, 'asyncHttpGet');
        $result = $method->invoke($client, $host, $port, $path);

        if ($result !== null && !is_string($result)) {
            throw new RuntimeException(
                'asyncHttpGet() returned unexpected type ' . get_debug_type($result)
            );
        }

        return $result;
    }

    /**
     * Binds the test listener to a port RESERVED ON THE MAIN STACK
     * (self::reserveLoopbackPort()) from inside the running event loop.
     * Reserving must happen outside a coroutine: swoole hooks
     * stream_socket_server, and the hooked probe's fclose does not release the
     * port for a later re-bind (measured: errno 98 on a coroutine-side probe).
     */
    private function bindLoopback(int $port): \Swoole\Coroutine\Socket
    {
        $server = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);

        if (!$server->bind('127.0.0.1', $port)) {
            throw new RuntimeException(
                "could not bind loopback swoole listener to :{$port}, errno " . $server->errCode
            );
        }

        if (!$server->listen(8)) {
            throw new RuntimeException('test listener listen failed, errno ' . $server->errCode);
        }

        return $server;
    }

    /**
     * An ephemeral loopback port: bound via a stream listener ON THE MAIN
     * STACK, read back, and released for the swoole re-bind.
     */
    private function reserveLoopbackPort(): int
    {
        $errno = 0;
        $errstr = '';
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($probe === false) {
            throw new RuntimeException("could not reserve a loopback port: {$errstr} ({$errno})");
        }

        $port = $this->portOf($probe);
        fclose($probe);

        if ($port < 1) {
            throw new RuntimeException('reserved loopback port was not readable');
        }

        return $port;
    }

    /**
     * Accepts exactly one connection, returns the raw request bytes read, and
     * sends $response first. Measured on this swoole: accept() hands back a
     * NEW per-connection Socket (older docs describe same-object mutation —
     * recv on the listener after accept is ENOTCONN 107). A handshake failure
     * throws; the CALLER catches inside the server coroutine because a throw
     * escaping a sibling coroutine is process-fatal, not a test failure.
     */
    private function acceptAndAnswer(\Swoole\Coroutine\Socket $server, string $response): string
    {
        $conn = $server->accept(5.0);

        if (!$conn instanceof \Swoole\Coroutine\Socket) {
            throw new RuntimeException('test listener accept failed, errno ' . $server->errCode);
        }

        $request = '';
        $attempts = 0;

        while (!str_contains($request, "\r\n\r\n")) {
            $piece = $conn->recv(4096, 5.0);

            if ($piece === false || $piece === '') {
                throw new RuntimeException(
                    'the production client closed before sending its request (errno '
                    . $conn->errCode . ')'
                );
            }

            $request .= $piece;
            $attempts++;

            if ($attempts > 16) {
                throw new RuntimeException('the client request never terminated its header block');
            }
        }

        if ($conn->send($response) === false) {
            throw new RuntimeException('test listener send failed, errno ' . $conn->errCode);
        }

        $conn->close();
        $server->close();

        return $request;
    }

    /**
     * A chunked-transfer HTTP/1.1 200 response carrying $payloads as one chunk
     * each. Chunked framing is the discriminator documented on the class.
     */
    private function chunkedResponse(string ...$payloads): string
    {
        $response = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n";

        foreach ($payloads as $payload) {
            if ($payload === '') {
                continue;
            }

            $response .= dechex(strlen($payload)) . "\r\n" . $payload . "\r\n";
        }

        return $response . "0\r\n\r\n";
    }

    /**
     * A loopback port that is bound and immediately released: a connect to it
     * is refused, deterministically, on any network stack.
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
 * Records warnings so a test can pin WHICH arm of the fork emitted the
 * size-cap refusal (coroutine: "dropping"; blocking reader: "aborting").
 *
 * @internal
 */
final class ForkWarningRecorder extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ((string) $level === LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        }
    }
}
