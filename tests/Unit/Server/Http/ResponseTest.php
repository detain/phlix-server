<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Response;

/**
 * Unit tests for Response class.
 */
class ResponseTest extends TestCase
{
    public function testCanCreateJsonResponse(): void
    {
        $response = (new Response())->json(['key' => 'value']);

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals('application/json', $response->headers['Content-Type']);
        $this->assertStringContainsString('"key"', $response->body);
    }

    public function testCanChainMethods(): void
    {
        $response = (new Response())
            ->status(201)
            ->header('X-Custom', 'value')
            ->json(['created' => true]);

        $this->assertEquals(201, $response->statusCode);
        $this->assertEquals('value', $response->headers['X-Custom']);
    }

    public function testCanCreateHtmlResponse(): void
    {
        $response = (new Response())->html('<h1>Hello</h1>');

        $this->assertEquals('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testCanRedirect(): void
    {
        $response = (new Response())->redirect('https://example.com', 301);

        $this->assertEquals(301, $response->statusCode);
        $this->assertEquals('https://example.com', $response->headers['Location']);
    }

    public function testNoContentResponse(): void
    {
        $response = (new Response())->noContent();

        $this->assertEquals(204, $response->statusCode);
        $this->assertEquals('', $response->body);
    }

    public function testWithFileRecordsPathOffsetAndLength(): void
    {
        $response = (new Response())->status(206)->withFile('/tmp/seg.ts', 10, 20);

        $this->assertSame('/tmp/seg.ts', $response->filePath);
        $this->assertSame(10, $response->fileOffset);
        $this->assertSame(20, $response->fileLength);
        $this->assertSame(206, $response->statusCode);
        // Streaming a file must not also buffer a body.
        $this->assertSame('', $response->body);
    }

    public function testToWorkermanResponseCarriesFileToWithFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phlix_resp_');
        $this->assertIsString($tmp);
        file_put_contents($tmp, 'ABCDEFGHIJ');

        try {
            $wr = (new Response())
                ->status(206)
                ->header('Content-Type', 'video/mp2t')
                ->withFile($tmp, 2, 4)
                ->toWorkermanResponse();

            // Workerman records the file it will stream on its public `$file`
            // property; a non-null value proves withFile() was invoked (the body is
            // never read into memory here).
            $this->assertNotNull($wr->file);
            $this->assertSame($tmp, $wr->file['file']);
            $this->assertSame(2, $wr->file['offset']);
            $this->assertSame(4, $wr->file['length']);
        } finally {
            unlink($tmp);
        }
    }

    /**
     * CGI/FPM fallback: a partial (Range) file-backed response streams only the
     * requested window and computes the same `Content-Length` / `Accept-Ranges` /
     * `Content-Range` headers (plus a forced 206) that Workerman's event-loop
     * `withFile()` path emits automatically — kept in sync so both entrypoints
     * answer a Range request identically (Reviewer S3 round 1, finding 3).
     *
     * Runs in a separate process because {@see Response::send()} calls
     * `header()` / `http_response_code()`, which must not leak process-global
     * header state into other tests.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSendCgiFallbackEmitsRangeHeadersAndStreamsWindow(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phlix_resp_');
        $this->assertIsString($tmp);
        file_put_contents($tmp, 'ABCDEFGHIJ');

        try {
            // Caller doesn't set status(206) explicitly here (mirroring what a
            // consumer that forgot to would produce) — finalizeFileHeaders() must
            // still force it, exactly like Workerman's native withFile() does.
            $response = (new Response())
                ->header('Content-Type', 'video/mp2t')
                ->withFile($tmp, 2, 4);

            ob_start();
            $response->send();
            $out = ob_get_clean();

            $this->assertSame('CDEF', $out);
            $this->assertSame(206, $response->statusCode);
            $this->assertSame('4', $response->headers['Content-Length']);
            $this->assertSame('bytes', $response->headers['Accept-Ranges']);
            $this->assertSame('bytes 2-5/10', $response->headers['Content-Range']);
        } finally {
            unlink($tmp);
        }
    }

    /**
     * CGI/FPM fallback: a whole-file (no Range) file-backed response streams the
     * entire file and computes `Content-Length` / `Accept-Ranges` without forcing
     * 206 or emitting `Content-Range` — mirroring Workerman's `withFile()` with a
     * zero offset/length.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSendCgiFallbackEmitsContentLengthForWholeFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phlix_resp_');
        $this->assertIsString($tmp);
        file_put_contents($tmp, 'ABCDEFGHIJ');

        try {
            $response = (new Response())->withFile($tmp);

            ob_start();
            $response->send();
            $out = ob_get_clean();

            $this->assertSame('ABCDEFGHIJ', $out);
            $this->assertSame(200, $response->statusCode);
            $this->assertSame('10', $response->headers['Content-Length']);
            $this->assertSame('bytes', $response->headers['Accept-Ranges']);
            $this->assertArrayNotHasKey('Content-Range', $response->headers);
        } finally {
            unlink($tmp);
        }
    }

    /**
     * CGI/FPM fallback: a file-backed response whose backing file has vanished
     * (e.g. a TOCTOU race between {@see TranscodeFileServer::serveJobFile()}'s
     * `is_file()` check and {@see Response::send()}) degrades gracefully:
     * {@see Response::finalizeFileHeaders()} no-ops (its `!is_file()` guard) so no
     * Content-Length/Content-Range/206 is fabricated for a file that isn't there,
     * and {@see Response::streamFileToOutput()} no-ops (its `fopen()` failure guard)
     * so nothing is streamed — the response completes cleanly with the caller's
     * original status and no body rather than throwing or emitting bogus headers.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSendCgiFallbackWithMissingFileDegradesGracefully(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phlix_resp_');
        $this->assertIsString($tmp);
        // Prime a valid file then delete it, so the Response points at a path the
        // withFile() caller believed existed but that has since disappeared.
        file_put_contents($tmp, 'ABCDEFGHIJ');
        $response = (new Response())->status(206)->withFile($tmp, 2, 4);
        unlink($tmp);

        ob_start();
        $response->send();
        $out = ob_get_clean();

        // finalizeFileHeaders() bailed → no fabricated Content-Length/Content-Range;
        // streamFileToOutput()'s fopen() failed → empty body; the caller's status
        // is left untouched (not forced to 206 for a file that isn't there).
        $this->assertSame('', $out);
        $this->assertSame(206, $response->statusCode);
        $this->assertArrayNotHasKey('Content-Length', $response->headers);
        $this->assertArrayNotHasKey('Content-Range', $response->headers);
        $this->assertArrayNotHasKey('Accept-Ranges', $response->headers);
    }
}
