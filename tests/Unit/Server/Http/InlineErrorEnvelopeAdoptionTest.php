<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Response;

/**
 * Wave-5 SRV-ENVELOPES (refactor-1119, E-10/A-05 batch-1): byte-identity corpus
 * for the inline `{error}` half-envelope sites adopted onto
 * {@see Response::errorPlain()} in WebPortalRouter + MediaItemController.
 *
 * Every shape below was captured from the LEGACY render chain
 * `(new Response())->status(N)->json(['error' => ...])` at the pristine base
 * commit (c150e3f4) — the register lives at
 * /tmp/opencode/refactor-exec-1119/gates/ENVEL-legacy-bytes-base.txt. These
 * strings are TODAY'S bytes as the specification, deliberately green at the
 * parent: the adoption commit must keep both forms rendering identically, or
 * the conversion is wrong, not the pin (ceiling-silence law; same doctrine as
 * EnvelopeWireCorpusTest, whose style this file follows).
 *
 * Coverage: all 25 distinct copies of the base-1 conversion set — static
 * literals, dynamic feeds ($e->getMessage()), interpolated copy
 * (serviceUnavailable), string concatenation with class constants
 * (METADATA_PATCH_MAX_BYTES), implode feeds (unsupported keys), and the two
 * `{error, message}` four-key chapter shapes. Key order law for extras: the
 * left-biased union in errorPlain() emits `error` first, then extras in their
 * own insertion order — byte-identical to the legacy single-literal form.
 *
 * The wire deliberately has NO `code` key at these sites (half-envelope);
 * normalizing them to `{error, code}` is an owner-gated future wave. This
 * class pins that errorPlain renders the half-envelope, and that error()
 * (which always carries a code) is NOT what replaced them.
 */
final class InlineErrorEnvelopeAdoptionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function legacyHalfEnvelopeCorpus(): array
    {
        return [
            'wpr_library_not_found_404' => [
                404, 'Library not found', [],
                "{\n    \"error\": \"Library not found\"\n}",
            ],
            'wpr_item_not_found_404' => [
                404, 'Item not found', [],
                "{\n    \"error\": \"Item not found\"\n}",
            ],
            'wpr_chapter_search_503' => [
                503, 'Chapter search not available', [],
                "{\n    \"error\": \"Chapter search not available\"\n}",
            ],
            'wpr_unauthorized_401' => [
                401, 'Unauthorized', [],
                "{\n    \"error\": \"Unauthorized\"\n}",
            ],
            'wpr_media_type_required_400' => [
                400, 'Media type is required', [],
                "{\n    \"error\": \"Media type is required\"\n}",
            ],
            'wpr_around_bounds_400' => [
                400, 'The around value must be between 1 and 300', [],
                "{\n    \"error\": \"The around value must be between 1 and 300\"\n}",
            ],
            'wpr_profile_not_available_503' => [
                503, 'Profile not available', [],
                "{\n    \"error\": \"Profile not available\"\n}",
            ],
            'wpr_no_active_profile_404' => [
                404, 'No active profile found', [],
                "{\n    \"error\": \"No active profile found\"\n}",
            ],
            'wpr_dynamic_message_400' => [
                // Feed shape: (new Response())->status(400)->json(['error' => $e->getMessage()])
                400, 'boom from getMessage()', [],
                "{\n    \"error\": \"boom from getMessage()\"\n}",
            ],
            'wpr_service_unavailable_503_interp' => [
                // Feed shape: "{$surface} isn't available ... The {$service} service isn't running."
                503,
                "Favorites isn't available on this server. The user data store service isn't running.",
                [],
                "{\n    \"error\": \"Favorites isn't available on this server."
                . " The user data store service isn't running.\"\n}",
            ],
            'wpr_settings_invalid_400' => [
                400, 'The submitted settings are not valid', [],
                "{\n    \"error\": \"The submitted settings are not valid\"\n}",
            ],
            'wpr_invalid_request_body_400' => [
                400, 'Invalid request body', [],
                "{\n    \"error\": \"Invalid request body\"\n}",
            ],
            'mic_chapter_missing_404' => [
                404, 'Chapter not found', ['message' => 'That chapter does not exist.'],
                "{\n    \"error\": \"Chapter not found\",\n    \"message\": \"That chapter does not exist.\"\n}",
            ],
            'mic_chapter_bad_start_404' => [
                404, 'Chapter not found', ['message' => 'Chapter start time is invalid.'],
                "{\n    \"error\": \"Chapter not found\",\n    \"message\": \"Chapter start time is invalid.\"\n}",
            ],
            'mic_chapter_thumb_404' => [
                404, 'Chapter not found', ['message' => 'Chapter thumbnail not found.'],
                "{\n    \"error\": \"Chapter not found\",\n    \"message\": \"Chapter thumbnail not found.\"\n}",
            ],
            'mic_q_required_400' => [
                // Feed shape: interpolated "Query parameter \"q\" is required" (escaped quotes on wire).
                400, 'Query parameter "q" is required', [],
                "{\n    \"error\": \"Query parameter \\\"q\\\" is required\"\n}",
            ],
            'mic_file_not_found_404' => [
                404, 'File not found on disk', [],
                "{\n    \"error\": \"File not found on disk\"\n}",
            ],
            'mic_no_playable_404' => [
                404, 'No playable items found', [],
                "{\n    \"error\": \"No playable items found\"\n}",
            ],
            'mic_not_series_400' => [
                400, 'Item is not a series', [],
                "{\n    \"error\": \"Item is not a series\"\n}",
            ],
            'mic_no_valid_fields_400' => [
                400, 'No valid fields to update', [],
                "{\n    \"error\": \"No valid fields to update\"\n}",
            ],
            'mic_body_not_object_400' => [
                400, 'Request body must be a JSON object', [],
                "{\n    \"error\": \"Request body must be a JSON object\"\n}",
            ],
            'mic_patch_not_object_400' => [
                400, 'Metadata JSON (metadata_json) must be a JSON object', [],
                "{\n    \"error\": \"Metadata JSON (metadata_json) must be a JSON object\"\n}",
            ],
            'mic_patch_too_large_400_concat' => [
                // Feed shape: literal . self::METADATA_PATCH_MAX_BYTES . '-byte limit' (65536).
                400, 'Metadata JSON (metadata_json) exceeds the 65536-byte limit', [],
                "{\n    \"error\": \"Metadata JSON (metadata_json) exceeds the 65536-byte limit\"\n}",
            ],
            'mic_patch_unknown_keys_concat' => [
                // Feed shape: literal . implode(', ', $names) for ['title', 'year'].
                400, 'Metadata JSON (metadata_json) contains unsupported keys: title, year', [],
                "{\n    \"error\": \"Metadata JSON (metadata_json) contains unsupported keys: title, year\"\n}",
            ],
            'mic_shape_violation_interp' => [
                // Feed shape: "metadata_json.{$key} must be {$expectedShape}".
                400, 'metadata_json.title must be string', [],
                "{\n    \"error\": \"metadata_json.title must be string\"\n}",
            ],
        ];
    }

    #[DataProvider('legacyHalfEnvelopeCorpus')]
    public function test_legacy_render_matches_pinned_golden_bytes(
        int $status,
        string $message,
        array $extra,
        string $expectedBody,
    ): void {
        // The legacy call-site chain, re-driven at the current commit:
        // ->status(N) then ->json() with a single literal array.
        $response = (new Response())->status($status)->json(['error' => $message] + $extra);

        self::assertSame($status, $response->statusCode);
        self::assertSame($expectedBody, $response->body);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $response->headers,
        );
    }

    #[DataProvider('legacyHalfEnvelopeCorpus')]
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

    #[DataProvider('legacyHalfEnvelopeCorpus')]
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

    public function test_error_plain_carries_no_code_key_even_when_error_would(): void
    {
        // The half-envelope promise: errorPlain() never grows a `code` key,
        // while error() always carries one. Decoding both proves the seam
        // difference is structural, not a string coincidence.
        $plain = json_decode((new Response())->errorPlain(404, 'Library not found')->body, true);
        $coded = json_decode((new Response())->error(404, 'not_found', 'Library not found')->body, true);

        self::assertSame(['error' => 'Library not found'], $plain);
        self::assertSame(['error' => 'Library not found', 'code' => 'not_found'], $coded);
    }

    public function test_error_plain_supports_extra_keys_in_insertion_order(): void
    {
        $body = (new Response())->errorPlain(404, 'Chapter not found', [
            'message' => 'That chapter does not exist.',
        ])->body;

        // Left-biased union law: error first, extras after (same order the
        // legacy chapter-site literals wrote them).
        self::assertSame(
            "{\n    \"error\": \"Chapter not found\",\n    \"message\": \"That chapter does not exist.\"\n}",
            $body,
        );
    }
}
