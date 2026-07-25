<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\BodylessResponse;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * The one thing this class exists for: a `HEAD` reply must put the size of the
 * entity a `GET` would have returned on the wire, ONCE.
 *
 * ## Why the assertions here are all on encoded bytes
 *
 * Workerman's `Response::__toString()` appends its own
 * `Content-Length: strlen($body)` unconditionally and LAST
 * (`vendor/workerman/workerman/src/Protocols/Http/Response.php:580-583`). A handler
 * that correctly sets the real size on an empty body therefore emits two
 * contradictory `Content-Length` fields, which RFC 9110 §8.6 makes an invalid
 * message: recipients must reject it, HAProxy drops it as a request-smuggling
 * defence, and clients disagree about which value wins. None of that is visible in
 * a `Response::$headers` array — the array holds only the correct value — so every
 * assertion below reads `(string) $response`.
 *
 * The second half of the contract matters just as much: this subclass must be a
 * drop-in. Anything that is NOT a bodyless caller-sized reply has to render
 * byte-for-byte identically to the parent, and that is asserted directly against
 * the parent's own output rather than against a transcription of it.
 *
 * @covers \Phlix\Server\Workerman\BodylessResponse
 */
final class BodylessResponseTest extends TestCase
{
    /**
     * THE DEFECT: a real `Content-Length` on an empty body survives alone.
     */
    public function test_a_caller_supplied_content_length_is_the_only_one_emitted(): void
    {
        $response = new BodylessResponse(200, [
            'Content-Type'   => 'video/mp4',
            'Accept-Ranges'  => 'bytes',
            'Content-Length' => '123456789',
        ]);

        $wire = (string) $response;

        self::assertSame(1, substr_count($wire, 'Content-Length:'), "Encoded bytes were:\n" . $wire);
        self::assertStringContainsString("Content-Length: 123456789\r\n", $wire);
        self::assertStringNotContainsString('Content-Length: 0', $wire);
    }

    /**
     * DISCRIMINATING CONTROL: the parent, given the identical input, emits BOTH —
     * so this test proves the defect is real and that the fix is what removes it,
     * rather than asserting a property that held all along.
     */
    public function test_the_parent_encoder_really_does_emit_two_content_lengths(): void
    {
        $headers = [
            'Content-Type'   => 'video/mp4',
            'Accept-Ranges'  => 'bytes',
            'Content-Length' => '123456789',
        ];

        $parentWire = (string) new WorkermanResponse(200, $headers);

        self::assertSame(2, substr_count($parentWire, 'Content-Length:'));
        // And the WRONG one is last, so a client taking the final value reads zero.
        self::assertStringEndsWith("Content-Length: 0\r\n\r\n", $parentWire);
    }

    /**
     * CONSEQUENCE: a `HEAD` reply carries no body, and the head is properly
     * terminated (a missing CRLFCRLF would hang the connection).
     */
    public function test_the_reply_is_a_terminated_head_with_no_body(): void
    {
        $wire = (string) new BodylessResponse(200, ['Content-Type' => 'audio/flac', 'Content-Length' => '42']);

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $wire);
        self::assertStringEndsWith("\r\n\r\n", $wire);

        $parts = explode("\r\n\r\n", $wire, 2);
        self::assertSame('', $parts[1] ?? 'HEADER TERMINATOR MISSING');
    }

    /**
     * CONSEQUENCE: the framework's own defaults are still applied, so this is not
     * a partial reimplementation that drops `Connection` or `Content-Type`.
     */
    public function test_the_frameworks_default_headers_are_still_applied(): void
    {
        $wire = (string) new BodylessResponse(200, ['Content-Length' => '7']);

        self::assertStringContainsString("Connection: keep-alive\r\n", $wire);
        self::assertStringContainsString("Content-Type: text/html;charset=utf-8\r\n", $wire);
    }

    /**
     * SECURITY: the parent's header-injection guard is NOT weakened. A CR/LF in a
     * header value (or a colon in a name) drops the field, exactly as upstream does.
     */
    public function test_header_injection_is_still_refused(): void
    {
        $wire = (string) new BodylessResponse(200, [
            'Content-Length' => '5',
            'X-Evil'         => "ok\r\nX-Injected: yes",
            "Bad:Name"       => 'value',
        ]);

        self::assertStringNotContainsString('X-Injected', $wire);
        self::assertStringNotContainsString('X-Evil', $wire);
        self::assertStringNotContainsString('Bad:Name', $wire);
    }

    /**
     * DROP-IN CONTRACT: anything that is not a bodyless caller-sized reply renders
     * EXACTLY as the parent renders it — asserted against the parent's real output,
     * so the two encoders cannot drift.
     *
     * @dataProvider delegatedShapes
     *
     * @param array<string, string> $headers
     */
    public function test_every_other_shape_is_byte_identical_to_the_parent(
        int $status,
        array $headers,
        string $body,
    ): void {
        self::assertSame(
            (string) new WorkermanResponse($status, $headers, $body),
            (string) new BodylessResponse($status, $headers, $body),
        );
    }

    /**
     * @return iterable<string, array{0: int, 1: array<string, string>, 2: string}>
     */
    public static function delegatedShapes(): iterable
    {
        yield 'a normal buffered reply' => [200, ['Content-Type' => 'application/json'], '{"ok":true}'];
        yield 'an empty body with NO declared length' => [204, ['X-Trace' => 'abc'], ''];
        yield 'a 304 with no length' => [304, ['ETag' => '"v1"'], ''];
        yield 'a 416 with a Content-Range but no length' => [
            416,
            ['Content-Type' => 'video/mp4', 'Content-Range' => 'bytes */26'],
            '',
        ];
        yield 'no headers at all' => [500, [], ''];
        yield 'server-sent events' => [200, ['Content-Type' => 'text/event-stream'], ''];
        // Transfer-Encoding already suppresses the parent's Content-Length, so the
        // narrowing must not take over and change that framing.
        yield 'chunked framing' => [
            200,
            ['Content-Type' => 'text/plain', 'Transfer-Encoding' => 'chunked', 'Content-Length' => '9'],
            '',
        ];
        // A declared length WITH a body is the parent's own (tolerated) duplicate
        // case, and is left exactly as it was — this class only narrows HEAD.
        yield 'a declared length alongside a body' => [
            200,
            ['Content-Type' => 'text/plain', 'Content-Length' => '3'],
            'abc',
        ];
    }

    /**
     * INTEGRATION with the class that actually selects this encoder: a Phlix
     * {@see Response} whose body is empty and which declares a length must reach
     * the wire with one `Content-Length`, and one that declares none must be
     * untouched.
     */
    public function test_phlix_response_selects_this_encoder_for_a_sized_bodyless_reply(): void
    {
        $head = (new Response())
            ->status(200)
            ->header('Content-Type', 'video/x-matroska')
            ->header('Content-Length', '4242');

        $wire = (string) $head->toWorkermanResponse();
        self::assertSame(1, substr_count($wire, 'Content-Length:'), "Encoded bytes were:\n" . $wire);
        self::assertStringContainsString("Content-Length: 4242\r\n", $wire);

        $noContent = (new Response())->noContent();
        $noContentWire = (string) $noContent->toWorkermanResponse();
        self::assertSame(
            (string) new WorkermanResponse(
                $noContent->statusCode,
                $noContent->headers,
                '',
            ),
            $noContentWire,
            'A 204 must still be encoded exactly as Workerman encodes it.',
        );
    }
}
