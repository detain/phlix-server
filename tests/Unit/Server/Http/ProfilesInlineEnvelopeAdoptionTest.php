<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\PasswordChangeRequiredException;
use Phlix\Common\Http\UploadErrorMessages;
use Phlix\Server\Http\Response;

/**
 * Wave-5 SRV-ENVELOPES-B4 (refactor-1119, E-10/A-05 batch-4): byte-identity
 * corpus for the inline envelope sites adopted in ProfilesController.
 *
 * All 29 inline `{error}`-bearing sites captured live at the pristine base
 * commit for this batch (a3330d5b): 28 half-envelope `['error' => ...]`
 * renders onto Response::errorPlain(), and the one SRV-M17 hold-path site
 * (ProfilesController.php:466-469, `['error' => ..., 'code' => $e->errorCode]`)
 * onto the full-envelope Response::error() seam. These strings are TODAY'S
 * bytes as the specification, deliberately green at the parent: the adoption
 * commit must keep both forms rendering identically, or the conversion is
 * wrong, not the pin (ceiling-silence law; same doctrine as
 * InlineErrorEnvelopeAdoptionTest, whose style this file follows).
 *
 * Coverage of the distinct shapes: static literals, the three dynamic
 * $e->getMessage() feeds (lines 146/239/527), and the upload-error
 * concatenation feed (lines 514-516, UploadErrorMessages::describe()). The
 * 403 full-envelope test additionally pins the legacy key ORDER (error before
 * code) — Response::error() builds ['error' => message, 'code' => code] so
 * the order is preserved by construction; the SRV-M17 boundary witness
 * (PasswordChangeRequiredHttpBoundaryTest::assertHoldEnvelope) keeps the
 * decoded-key-order law live.
 */
final class ProfilesInlineEnvelopeAdoptionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function profilesHalfEnvelopeCorpus(): array
    {
        return [
            'profiles_unauthorized_401' => [
                401, 'Unauthorized', [],
                "{\n    \"error\": \"Unauthorized\"\n}",
            ],
            'profiles_name_required_400' => [
                400, 'Profile name is required', [],
                "{\n    \"error\": \"Profile name is required\"\n}",
            ],
            'profiles_dynamic_message_400' => [
                // Feed shape ×3 (146/239/527): json(['error' => $e->getMessage()])
                400, 'boom from getMessage()', [],
                "{\n    \"error\": \"boom from getMessage()\"\n}",
            ],
            'profiles_invalid_id_400' => [
                400, 'Invalid profile ID', [],
                "{\n    \"error\": \"Invalid profile ID\"\n}",
            ],
            'profiles_not_found_404' => [
                404, 'Profile not found', [],
                "{\n    \"error\": \"Profile not found\"\n}",
            ],
            'profiles_content_rating_text_400' => [
                400, 'The content rating must be text', [],
                "{\n    \"error\": \"The content rating must be text\"\n}",
            ],
            'profiles_pin_text_400' => [
                400, 'The PIN must be text', [],
                "{\n    \"error\": \"The PIN must be text\"\n}",
            ],
            'profiles_pin_length_400' => [
                400, 'Invalid PIN length', [],
                "{\n    \"error\": \"Invalid PIN length\"\n}",
            ],
            'profiles_pin_digits_400' => [
                400, 'PIN must contain only digits', [],
                "{\n    \"error\": \"PIN must contain only digits\"\n}",
            ],
            'profiles_pin_required_400' => [
                400, 'PIN is required', [],
                "{\n    \"error\": \"PIN is required\"\n}",
            ],
            'profiles_no_file_400' => [
                400, 'No file uploaded', [],
                "{\n    \"error\": \"No file uploaded\"\n}",
            ],
            'profiles_upload_error_concat_400' => [
                // Feed shape (514-516): 'Upload error: ' . UploadErrorMessages::describe($error)
                400,
                'Upload error: ' . UploadErrorMessages::describe(UPLOAD_ERR_INI_SIZE),
                [],
                "{\n    \"error\": \"Upload error: "
                . UploadErrorMessages::describe(UPLOAD_ERR_INI_SIZE)
                . "\"\n}",
            ],
            'profiles_invalid_file_data_400' => [
                400, 'Invalid file data', [],
                "{\n    \"error\": \"Invalid file data\"\n}",
            ],
            'profiles_storage_error_500' => [
                500, 'Storage error, please try again later', [],
                "{\n    \"error\": \"Storage error, please try again later\"\n}",
            ],
        ];
    }

    #[DataProvider('profilesHalfEnvelopeCorpus')]
    public function test_legacy_render_matches_pinned_golden_bytes(
        int $status,
        string $message,
        array $extra,
        string $expectedBody,
    ): void {
        // The legacy call-site chain, re-driven at the current commit:
        // ->status(N) then ->json() with a single-literal error array.
        $response = (new Response())->status($status)->json(['error' => $message] + $extra);

        self::assertSame($status, $response->statusCode);
        self::assertSame($expectedBody, $response->body);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $response->headers,
        );
    }

    #[DataProvider('profilesHalfEnvelopeCorpus')]
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

    #[DataProvider('profilesHalfEnvelopeCorpus')]
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

    public function test_hold_path_full_envelope_adopts_onto_error_seam_byte_identically(): void
    {
        // ProfilesController.php:466-469 — the SRV-M17 hold 403 carries the
        // contracts-registered code, so it rides Response::error(), not
        // errorPlain(). Legacy form: status(403) then json with the two
        // literal keys in error-then-code order.
        // The hold copy lives in the exception's argumentless constructor
        // default; drive it live instead of duplicating the string
        // (single source of truth law — mirrors the SRV-M17 boundary witness).
        $message = (new PasswordChangeRequiredException())->getMessage();
        $code = PasswordChangeRequiredException::ERROR_CODE;

        $legacy = (new Response())->status(403)->json([
            'error' => $message,
            'code' => $code,
        ]);
        $adopted = (new Response())->error(403, $code, $message);

        self::assertSame(403, $adopted->statusCode);
        self::assertSame($legacy->statusCode, $adopted->statusCode);
        self::assertSame($legacy->headers, $adopted->headers);
        self::assertSame($legacy->body, $adopted->body);

        // Key-order law, golden: error first, code second — byte-pinned.
        self::assertSame(
            "{\n    \"error\": \"" . $message . "\",\n    \"code\": \"" . $code . "\"\n}",
            $adopted->body,
        );

        $decoded = json_decode((string) $adopted->body, true);
        self::assertIsArray($decoded);
        self::assertSame(['error', 'code'], array_keys($decoded));
    }
}
