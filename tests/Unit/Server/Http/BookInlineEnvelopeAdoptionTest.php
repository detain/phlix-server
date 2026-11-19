<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Response;

/**
 * Wave-5 SRV-ENVELOPES-B4 (refactor-1119, E-10/A-05 batch-4): byte-identity
 * corpus for the 24 inline `{error}` half-envelope sites adopted in
 * BookController onto Response::errorPlain().
 *
 * All shapes captured live at the pristine base for this batch (56b2f74b):
 * pure single-key `['error' => literal]` renders across
 * 400/401/404/500/503. No dynamic feeds and no extra keys at these sites —
 * the controller was the cleanest of the batch-4 trio. These strings are
 * TODAY'S bytes as the specification, deliberately green at the parent: the
 * adoption commit must keep both forms rendering identically, or the
 * conversion is wrong, not the pin (ceiling-silence law; doctrine of
 * InlineErrorEnvelopeAdoptionTest / ProfilesInlineEnvelopeAdoptionTest).
 *
 * Live-driving witnesses stay in BookControllerTest + BookControllerParentalTest.
 */
final class BookInlineEnvelopeAdoptionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function bookHalfEnvelopeCorpus(): array
    {
        return [
            'book_library_id_required_400' => [
                400, 'Library ID is required', [],
                "{\n    \"error\": \"Library ID is required\"\n}",
            ],
            'book_library_not_found_404' => [
                404, 'Library not found', [],
                "{\n    \"error\": \"Library not found\"\n}",
            ],
            'book_not_a_book_library_404' => [
                404, 'Not a book library', [],
                "{\n    \"error\": \"Not a book library\"\n}",
            ],
            'book_book_id_required_400' => [
                400, 'Book ID is required', [],
                "{\n    \"error\": \"Book ID is required\"\n}",
            ],
            'book_not_found_404' => [
                404, 'Book not found', [],
                "{\n    \"error\": \"Book not found\"\n}",
            ],
            'book_authentication_required_401' => [
                401, 'Authentication required', [],
                "{\n    \"error\": \"Authentication required\"\n}",
            ],
            'book_progress_unavailable_503' => [
                503, 'Progress tracking not available', [],
                "{\n    \"error\": \"Progress tracking not available\"\n}",
            ],
            'book_cover_not_found_404' => [
                404, 'Cover not found', [],
                "{\n    \"error\": \"Cover not found\"\n}",
            ],
            'book_cover_read_failed_500' => [
                500, 'Failed to read cover', [],
                "{\n    \"error\": \"Failed to read cover\"\n}",
            ],
            'book_file_not_found_404' => [
                404, 'File not found', [],
                "{\n    \"error\": \"File not found\"\n}",
            ],
            'book_file_read_failed_500' => [
                500, 'Failed to read file', [],
                "{\n    \"error\": \"Failed to read file\"\n}",
            ],
        ];
    }

    #[DataProvider('bookHalfEnvelopeCorpus')]
    public function test_legacy_render_matches_pinned_golden_bytes(
        int $status,
        string $message,
        array $extra,
        string $expectedBody,
    ): void {
        $response = (new Response())->status($status)->json(['error' => $message] + $extra);

        self::assertSame($status, $response->statusCode);
        self::assertSame($expectedBody, $response->body);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $response->headers,
        );
    }

    #[DataProvider('bookHalfEnvelopeCorpus')]
    public function test_error_plain_adoption_render_is_byte_identical_to_golden(
        int $status,
        string $message,
        array $extra,
        string $expectedBody,
    ): void {
        $response = (new Response())->errorPlain($status, $message, $extra);

        self::assertSame($status, $response->statusCode);
        self::assertSame($expectedBody, $response->body);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $response->headers,
        );
    }

    #[DataProvider('bookHalfEnvelopeCorpus')]
    public function test_error_plain_render_equals_legacy_chain_render(
        int $status,
        string $message,
        array $extra,
    ): void {
        $legacy = (new Response())->status($status)->json(['error' => $message] + $extra);
        $adopted = (new Response())->errorPlain($status, $message, $extra);

        self::assertSame($legacy->statusCode, $adopted->statusCode);
        self::assertSame($legacy->headers, $adopted->headers);
        self::assertSame($legacy->body, $adopted->body);
    }

    public function test_book_file_not_found_is_distinct_from_media_item_controller_copy(): void
    {
        // Wire-truth note, pinned so a future "normalize the copy" wave cannot
        // silently merge this 404 with MediaItemController's
        // 'File not found on disk': the book surface has carried the bare
        // 'File not found' since capture and the corpus golden above freezes
        // exactly that byte sequence.
        $body = (new Response())->errorPlain(404, 'File not found')->body;

        self::assertSame("{\n    \"error\": \"File not found\"\n}", $body);
    }
}
