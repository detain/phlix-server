<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Response;

/**
 * Wave-5 SRV-ENVELOPES-B4 (refactor-1119, E-10/A-05 batch-4): byte-identity
 * corpus for the 16 inline `{error}` half-envelope sites adopted in
 * AudiobookController onto Response::errorPlain().
 *
 * Fifteen are pure single-key literal renders across 400/401/404/416/500.
 * The sixteenth (AudiobookController.php:519-522 at capture) is the 416
 * Content-Range site: a header written INSIDE the legacy chain before
 * json(). The adoption hoists header() ahead of errorPlain() — header
 * first, exactly as on the legacy wire — so the headers array insertion
 * order (Content-Range, Content-Type, Cache-Control) is preserved key for
 * key. test_content_range_416_site... pins that order byte-for-byte;
 * hoisting rules follow the rateLimited Retry-After precedent.
 *
 * These strings are TODAY'S bytes as the specification (captured live at
 * 97d2ffe1), deliberately green at the parent; if the adoption commit and
 * the goldens disagree, the conversion is wrong, not the pin.
 */
final class AudiobookInlineEnvelopeAdoptionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function audiobookHalfEnvelopeCorpus(): array
    {
        return [
            'audiobook_id_required_400' => [
                400, 'Audiobook ID is required', [],
                "{\n    \"error\": \"Audiobook ID is required\"\n}",
            ],
            'audiobook_not_found_404' => [
                404, 'Audiobook not found', [],
                "{\n    \"error\": \"Audiobook not found\"\n}",
            ],
            'audiobook_authentication_required_401' => [
                401, 'Authentication required', [],
                "{\n    \"error\": \"Authentication required\"\n}",
            ],
            'audiobook_file_size_failed_500' => [
                500, 'Failed to get file size', [],
                "{\n    \"error\": \"Failed to get file size\"\n}",
            ],
            'audiobook_invalid_range_length_416' => [
                416, 'Invalid range length', [],
                "{\n    \"error\": \"Invalid range length\"\n}",
            ],
        ];
    }

    #[DataProvider('audiobookHalfEnvelopeCorpus')]
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

    #[DataProvider('audiobookHalfEnvelopeCorpus')]
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

    #[DataProvider('audiobookHalfEnvelopeCorpus')]
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

    public function test_content_range_416_site_preserves_header_wire_order_through_the_seam(): void
    {
        // Legacy chain (capture: AudiobookController.php:519-522):
        //   ->status(416)->header('Content-Range', "bytes */{$fileSize}")
        //   ->json(['error' => 'Range not satisfiable'])
        // Adoption hoists header() ahead of errorPlain(); both must render the
        // SAME headers insertion order -- Content-Range first, then the two
        // json()-seeded headers.
        $fileSize = 12345678;

        $legacy = (new Response())
            ->status(416)
            ->header('Content-Range', "bytes */{$fileSize}")
            ->json(['error' => 'Range not satisfiable']);
        $adopted = (new Response())
            ->header('Content-Range', "bytes */{$fileSize}")
            ->errorPlain(416, 'Range not satisfiable');

        self::assertSame(416, $adopted->statusCode);
        self::assertSame($legacy->statusCode, $adopted->statusCode);
        self::assertSame($legacy->headers, $adopted->headers);
        self::assertSame($legacy->body, $adopted->body);

        // The order itself, golden: exact keys in exact sequence.
        self::assertSame(
            [
                'Content-Range' => "bytes */{$fileSize}",
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
            ],
            $adopted->headers,
        );
        self::assertSame("{\n    \"error\": \"Range not satisfiable\"\n}", $adopted->body);
    }
}
