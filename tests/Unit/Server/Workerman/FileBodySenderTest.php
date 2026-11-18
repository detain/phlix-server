<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Closure;
use PHPUnit\Framework\TestCase;
use Phlix\Server\Workerman\FileBodySender;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;
use stdClass;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Pins the file-backed send path against Workerman's own encode().
 *
 * The legacy bytes come from calling the vendor `Http::encode()` on a second,
 * identical response, so the expected header block is whatever Workerman emits
 * today rather than a hand-copied string. No socket is involved: the recording
 * connection captures every raw write and can simulate Workerman's buffer-full
 * signal, a failed write (disconnect) and a throwing write.
 */
final class FileBodySenderTest extends TestCase
{
    /** @var list<string> Temp files to remove after each test. */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testHeaderBlockMatchesWorkermanEncodeForAFullFileResponse(): void
    {
        $bytes = str_repeat('abcdefgh', 100);
        $path = $this->fixture($bytes);

        $legacy = $this->connection();
        Http::encode($this->fileResponse($path), $legacy);
        // Below 2 MiB Workerman sends head . body as one write.
        self::assertCount(1, $legacy->writes);
        $legacyHead = substr($legacy->writes[0], 0, -strlen($bytes));

        $streamed = $this->connection();
        FileBodySender::send($streamed, $this->fileResponse($path));

        self::assertSame($legacyHead, $streamed->writes[0], 'header block differs from Workerman encode()');
        self::assertStringContainsString("Content-Length: 800\r\n", $streamed->writes[0]);
        self::assertStringContainsString("Accept-Ranges: bytes\r\n", $streamed->writes[0]);
        self::assertSame($bytes, implode('', array_slice($streamed->writes, 1)));
    }

    public function testWindowStreamsExactlyTheRequestedBytesAs206(): void
    {
        $bytes = implode('', array_map(static fn (int $i): string => chr($i % 256), range(0, 999)));
        $path = $this->fixture($bytes);

        $conn = $this->connection();
        FileBodySender::send($conn, $this->fileResponse($path, 100, 300));

        self::assertStringStartsWith('HTTP/1.1 206', $conn->writes[0]);
        self::assertStringContainsString("Content-Range: bytes 100-399/1000\r\n", $conn->writes[0]);
        self::assertStringContainsString("Content-Length: 300\r\n", $conn->writes[0]);
        self::assertSame(substr($bytes, 100, 300), implode('', array_slice($conn->writes, 1)));
    }

    public function testBodyLargerThanOneChunkIsDeliveredInOrderWithoutConcatenation(): void
    {
        $bytes = random_bytes(FileBodySender::CHUNK_SIZE * 2 + 1000);
        $path = $this->fixture($bytes);

        $conn = $this->connection();
        FileBodySender::send($conn, $this->fileResponse($path));

        // The first write is the header alone, then body chunks of at most CHUNK_SIZE.
        self::assertStringEndsWith("\r\n\r\n", $conn->writes[0]);
        self::assertSame(
            [FileBodySender::CHUNK_SIZE, FileBodySender::CHUNK_SIZE, 1000],
            array_map('strlen', array_slice($conn->writes, 1)),
        );
        self::assertSame($bytes, implode('', array_slice($conn->writes, 1)));
        self::assertNull($conn->onBufferDrain, 'the drain callback is cleared when the body finishes');
    }

    public function testStreamPausesWhileBufferFullAndResumesOnDrain(): void
    {
        $bytes = random_bytes(FileBodySender::CHUNK_SIZE * 2 + 10);
        $path = $this->fixture($bytes);

        $conn = $this->connection();
        // Signal buffer-full after the header and the first chunk have been written.
        $conn->fillAfterWrite = 2;
        FileBodySender::send($conn, $this->fileResponse($path));
        self::assertCount(2, $conn->writes, 'no write while the buffer is full');

        ($conn->onBufferDrain)($conn);
        self::assertSame($bytes, implode('', array_slice($conn->writes, 1)));
        self::assertNull($conn->onBufferDrain);
    }

    public function test403ClosesTheConnectionWhenTheFileCannotBeOpened(): void
    {
        $path = $this->fixture('x');
        $response = $this->fileResponse($path);
        unlink($path);

        $conn = $this->connection();
        FileBodySender::send($conn, $response);

        self::assertSame([], $conn->writes, 'no header is sent before a failed open');
        self::assertCount(1, $conn->closes);
        $closed = $conn->closes[0];
        self::assertInstanceOf(WorkermanResponse::class, $closed);
        self::assertSame(403, $closed->getStatusCode());
        self::assertSame('403 Forbidden', $closed->rawBody());
    }

    public function testMissingFileWithAnOffsetIsA403NotA416(): void
    {
        $path = $this->fixture('abcdef');
        $response = $this->fileResponse($path, 2);
        unlink($path);

        $conn = $this->connection();
        FileBodySender::send($conn, $response);

        self::assertSame([], $conn->writes, 'no 416 and no header when the file cannot be stat-ed');
        self::assertCount(1, $conn->closes);
        $closed = $conn->closes[0];
        self::assertInstanceOf(WorkermanResponse::class, $closed);
        self::assertSame(403, $closed->getStatusCode());
    }

    public function testRangeStartingAtOrPastEofAnswers416WithoutAHeaderBlock(): void
    {
        $path = $this->fixture('abc');

        // 3 is EOF exactly (zero-length window); 10 is past EOF (negative length without a window).
        foreach ([3, 10] as $offset) {
            $conn = $this->connection();
            FileBodySender::send($conn, $this->fileResponse($path, $offset));

            self::assertCount(1, $conn->writes, "offset $offset: one write, the 416");
            self::assertStringStartsWith('HTTP/1.1 416 ', $conn->writes[0]);
            self::assertStringContainsString("Content-Range: bytes */3\r\n", $conn->writes[0]);
            self::assertStringEndsWith("Content-Length: 0\r\n\r\n", $conn->writes[0]);
            self::assertStringNotContainsString('Content-Length: -', $conn->writes[0]);
            self::assertSame([], $conn->closes, "offset $offset: a bodyless 416 keeps the connection open");
            self::assertNull($conn->onBufferDrain, "offset $offset: no body stream is started");
        }
    }

    public function testAFailedHeaderWriteSendsNoBodyAndStartsNoStream(): void
    {
        $path = $this->fixture(str_repeat('x', 100));

        $conn = $this->connection();
        $conn->failAfterWrite = 1;
        FileBodySender::send($conn, $this->fileResponse($path));

        self::assertCount(1, $conn->writes, 'only the failed header write is attempted');
        self::assertNull($conn->onBufferDrain, 'no stream starts after a failed header write');
        self::assertSame([], $conn->closes, 'the connection is left to Workerman, as encode() does');
    }

    public function testAWindowPastEofIsClampedToTheFileSize(): void
    {
        $path = $this->fixture('abcdef');

        $conn = $this->connection();
        // The window asks for 10 bytes from offset 0 and the file has 6: RFC 7233 2.1 clamps it.
        FileBodySender::send($conn, $this->fileResponse($path, 0, 10));

        self::assertStringStartsWith('HTTP/1.1 206', $conn->writes[0]);
        self::assertStringContainsString("Content-Length: 6\r\n", $conn->writes[0]);
        self::assertStringNotContainsString("Content-Length: 10\r\n", $conn->writes[0]);
        self::assertStringContainsString("Content-Range: bytes 0-5/6\r\n", $conn->writes[0]);
        self::assertSame('abcdef', implode('', array_slice($conn->writes, 1)));
        self::assertSame([], $conn->closes, 'a clamped window ends at EOF and keeps the connection open');
        self::assertNull($conn->onBufferDrain, 'the drain callback is cleared when the clamped body finishes');
    }

    public function testAWindowEndingExactlyAtEofIsNotClamped(): void
    {
        $path = $this->fixture('abcdef');

        $conn = $this->connection();
        FileBodySender::send($conn, $this->fileResponse($path, 2, 4));

        self::assertStringStartsWith('HTTP/1.1 206', $conn->writes[0]);
        self::assertStringContainsString("Content-Length: 4\r\n", $conn->writes[0]);
        self::assertStringContainsString("Content-Range: bytes 2-5/6\r\n", $conn->writes[0]);
        self::assertSame('cdef', implode('', array_slice($conn->writes, 1)));
        self::assertSame([], $conn->closes, 'a window that ends at EOF keeps the connection open');
    }

    public function testAWindowStartingInsideTheFileIsClampedToTheBytesFromItsOffset(): void
    {
        $path = $this->fixture('abcdef');

        $conn = $this->connection();
        // Offset 2 leaves 4 bytes in a 6-byte file. The clamp must use the bytes left after the offset,
        // not the file size: min(10, 6) would advertise 6 and the body would end early and close.
        FileBodySender::send($conn, $this->fileResponse($path, 2, 10));

        self::assertStringStartsWith('HTTP/1.1 206', $conn->writes[0]);
        self::assertStringContainsString("Content-Length: 4\r\n", $conn->writes[0]);
        self::assertStringNotContainsString("Content-Length: 6\r\n", $conn->writes[0]);
        self::assertStringContainsString("Content-Range: bytes 2-5/6\r\n", $conn->writes[0]);
        self::assertSame('cdef', implode('', array_slice($conn->writes, 1)));
        self::assertSame([], $conn->closes, 'a clamped window ends at EOF and keeps the connection open');
        self::assertNull($conn->onBufferDrain, 'the drain callback is cleared when the clamped body finishes');
    }

    /**
     * A byte range against a zero-length representation is unsatisfiable. RFC 7233 2.1 makes a
     * byte-range-spec satisfiable only when its first-byte-pos is less than the representation
     * length, and 0 is not less than 0. RFC 7233 4.4 then answers 416 with an unsatisfied-range
     * Content-Range that names the size 0, and no body. The 416 is produced by the `$length > 0`
     * arm of the EOF condition, which the offset-only arm does not cover (offset is 0 here).
     */
    public function testARangeAgainstAnEmptyFileAnswers416WithAZeroSizeContentRange(): void
    {
        $path = $this->fixture('');

        $conn = $this->connection();
        FileBodySender::send($conn, $this->fileResponse($path, 0, 10));

        self::assertCount(1, $conn->writes, 'one write, the 416');
        self::assertStringStartsWith('HTTP/1.1 416 ', $conn->writes[0]);
        self::assertStringContainsString("Content-Range: bytes */0\r\n", $conn->writes[0]);
        self::assertStringNotContainsString('Content-Range: bytes 0-', $conn->writes[0]);
        self::assertStringEndsWith("Content-Length: 0\r\n\r\n", $conn->writes[0]);
        self::assertSame([], $conn->closes, 'a bodyless 416 keeps the connection open');
        self::assertNull($conn->onBufferDrain, 'no body stream is started');
    }

    public function testAFullFileRequestForAnEmptyFileIsA200WithNoBody(): void
    {
        $path = $this->fixture('');

        $legacy = $this->connection();
        Http::encode($this->fileResponse($path), $legacy);
        self::assertCount(1, $legacy->writes, 'encode() sends the head alone for an empty file');

        $conn = $this->connection();
        FileBodySender::send($conn, $this->fileResponse($path));

        self::assertCount(1, $conn->writes, 'one write, the header; no body write');
        self::assertSame($legacy->writes[0], $conn->writes[0], 'header block differs from Workerman encode()');
        self::assertStringStartsWith('HTTP/1.1 200 ', $conn->writes[0]);
        self::assertStringContainsString("Content-Length: 0\r\n", $conn->writes[0]);
        self::assertStringNotContainsString('Content-Range:', $conn->writes[0]);
        self::assertSame([], $conn->closes, 'a complete empty response keeps the connection open');
        self::assertNull($conn->onBufferDrain, 'no body stream is started');
    }

    public function testAFileThatShrinksAfterItsSizeWasTakenClosesAfterTheBytesThatExist(): void
    {
        $path = $this->fixture('abcdef');

        $conn = $this->connection();
        // The size is 6 when the header goes out; the file is cut to 3 bytes before the body is read.
        $conn->onWrite = static function (RecordingConnection $c, int $count) use ($path): void {
            if ($count === 1) {
                file_put_contents($path, 'abc');
            }
        };
        FileBodySender::send($conn, $this->fileResponse($path));

        self::assertStringContainsString("Content-Length: 6\r\n", $conn->writes[0]);
        self::assertSame('abc', implode('', array_slice($conn->writes, 1)));
        self::assertSame([null], $conn->closes, 'the connection is closed once the shrunken file runs out');
        self::assertNull($conn->onBufferDrain, 'the drain callback is cleared on the short-file close');
    }

    public function testA416KeepsOneAcceptRangesAndOneContentLengthWhenTheOriginalSetThem(): void
    {
        $path = $this->fixture('abc');
        $response = (new WorkermanResponse(200, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => '999',
        ]))->withFile($path, 10, 0);

        $conn = $this->connection();
        FileBodySender::send($conn, $response);

        self::assertCount(1, $conn->writes, 'one write, the 416');
        $head = $conn->writes[0];
        self::assertStringStartsWith('HTTP/1.1 416 ', $head);
        self::assertSame(1, substr_count($head, 'Accept-Ranges:'), 'Accept-Ranges appears exactly once');
        self::assertSame(1, substr_count($head, 'Content-Length:'), 'Content-Length appears exactly once');
        self::assertSame(1, substr_count($head, 'Content-Range:'), 'Content-Range appears exactly once');
        self::assertStringContainsString("Content-Length: 0\r\n", $head);
        self::assertStringNotContainsString('Content-Length: 999', $head);
    }

    public function testBodyIsCappedAtContentLengthWhenTheFileIsLonger(): void
    {
        $bytes = random_bytes(FileBodySender::CHUNK_SIZE * 2 + 1000);
        $path = $this->fixture($bytes);
        $length = FileBodySender::CHUNK_SIZE + 10;

        $conn = $this->connection();
        FileBodySender::send($conn, $this->fileResponse($path, 0, $length));

        self::assertStringContainsString("Content-Length: $length\r\n", $conn->writes[0]);
        self::assertSame(
            [FileBodySender::CHUNK_SIZE, 10],
            array_map('strlen', array_slice($conn->writes, 1)),
        );
        self::assertSame(substr($bytes, 0, $length), implode('', array_slice($conn->writes, 1)));
        self::assertSame([], $conn->closes, 'a complete window keeps the connection open');
    }

    public function testSendFailureMidStreamReleasesTheHandleAndClearsTheCallbacks(): void
    {
        $bytes = random_bytes(FileBodySender::CHUNK_SIZE * 2 + 10);
        $path = $this->fixture($bytes);

        $conn = $this->connection();
        // Header and first chunk are written, then the buffer reports full.
        $conn->fillAfterWrite = 2;
        FileBodySender::send($conn, $this->fileResponse($path));
        $drain = $conn->onBufferDrain;
        self::assertInstanceOf(Closure::class, $drain);
        $handle = $this->streamHandle($drain);
        $openWhilePaused = is_resource($handle);
        self::assertTrue($openWhilePaused, 'the handle stays open while the stream is paused');

        // The next chunk write reports a dead connection (a disconnect).
        $conn->failAfterWrite = 3;
        $drain($conn);

        self::assertCount(3, $conn->writes, 'no write after the failed one');
        self::assertFalse(is_resource($handle), 'the file handle is closed');
        self::assertNull($conn->onBufferDrain);
        self::assertNull($conn->onBufferFull);
        self::assertFalse($conn->context->streamSending);
    }

    public function testAnExceptionMidStreamStillReleasesTheHandleAndClearsTheCallbacks(): void
    {
        $bytes = random_bytes(FileBodySender::CHUNK_SIZE * 2 + 10);
        $path = $this->fixture($bytes);

        $conn = $this->connection();
        $conn->fillAfterWrite = 2;
        FileBodySender::send($conn, $this->fileResponse($path));
        $drain = $conn->onBufferDrain;
        self::assertInstanceOf(Closure::class, $drain);
        $handle = $this->streamHandle($drain);

        // The next chunk write throws. The exception is caught here and asserted below.
        $conn->throwAfterWrite = 3;
        $thrown = null;
        try {
            $drain($conn);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertFalse(is_resource($handle), 'the finally block closes the handle');
        self::assertNull($conn->onBufferDrain);
        self::assertNull($conn->onBufferFull);
    }

    /**
     * Writes a temp file with the given bytes and returns its path.
     *
     * @param string $bytes File content.
     */
    private function fixture(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fbs');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return $path;
    }

    /**
     * Builds a file-backed response with the Content-Type set, so the header block
     * carries no Content-Disposition and the output is deterministic.
     *
     * @param string $path File to serve.
     * @param int $offset Window start.
     * @param int $length Window length (0 = to EOF).
     */
    private function fileResponse(string $path, int $offset = 0, int $length = 0): WorkermanResponse
    {
        $response = new WorkermanResponse(200, ['Content-Type' => 'video/mp4']);

        return $response->withFile($path, $offset, $length);
    }

    /**
     * The open handle a body stream holds, read from the drain callback's captured state.
     *
     * @param Closure $drain The onBufferDrain callback installed by FileBodySender.
     */
    private function streamHandle(Closure $drain): mixed
    {
        $captured = (new ReflectionFunction($drain))->getStaticVariables();

        return $captured['state']->handle;
    }

    /**
     * A TcpConnection with no event loop or socket: the constructor is bypassed and
     * the recording double replaces send() and close().
     */
    private function connection(): RecordingConnection
    {
        $conn = (new ReflectionClass(RecordingConnection::class))->newInstanceWithoutConstructor();
        $conn->context = new stdClass();

        return $conn;
    }
}

/**
 * Test double for a Workerman TcpConnection: records raw writes and closes.
 *
 * `fillAfterWrite` simulates Workerman raising onBufferFull after that many writes.
 * `failAfterWrite` makes send() return false on that write (a disconnect).
 * `throwAfterWrite` makes send() throw on that write. Write numbers are 1-based and 0 = never.
 */
final class RecordingConnection extends TcpConnection
{
    /** @var list<mixed> Every value passed to send(), in order. */
    public array $writes = [];

    /** @var list<mixed> Every value passed to close(), in order. */
    public array $closes = [];

    /** @var array<string, mixed> Stands in for the per-connection header queue. */
    public array $headers = [];

    /** Raise onBufferFull after this many writes (0 = never). */
    public int $fillAfterWrite = 0;

    /** Make send() return false on this write number (0 = never). */
    public int $failAfterWrite = 0;

    /** Make send() throw on this write number (0 = never). */
    public int $throwAfterWrite = 0;

    /** Runs on every write, before the failure checks, with the connection and the 1-based write number. */
    public ?Closure $onWrite = null;

    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        $this->writes[] = $sendBuffer;
        $count = count($this->writes);
        if ($this->onWrite !== null) {
            ($this->onWrite)($this, $count);
        }
        if ($this->throwAfterWrite > 0 && $count === $this->throwAfterWrite) {
            throw new RuntimeException('connection reset by peer');
        }
        if ($this->failAfterWrite > 0 && $count === $this->failAfterWrite) {
            return false;
        }
        if ($this->fillAfterWrite > 0 && $count === $this->fillAfterWrite && $this->onBufferFull) {
            ($this->onBufferFull)($this);
        }

        return null;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->closes[] = $data;
    }
}
