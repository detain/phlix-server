<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Auth\SignedUrl;
use Phlix\Server\Http\Middleware\SignedUrlMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Stats\ClientHeartbeatStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Wave-5 SRV-ENVELOPES-B3 (refactor-1119): byte-identity corpus for the
 * inline `{error, code}` wire sites of the middleware/QuickConnect cohort
 * adopted onto {@see Response::error()} — batch-1's {@see
 * InlineErrorEnvelopeAdoptionTest} doctrine applied to the code-carrying
 * shapes its register left for a later batch (skip rows QuickConnect
 * :248/:289/:292 + SignedUrlMiddleware :145/:150, extended to the whole
 * disjoint middleware/QuickConnect census once re-derived live).
 *
 * Every expected string below was captured from the LEGACY render chain
 * `(new Response())->status(N)->json(['error' => X, 'code' => Y])` at the
 * pristine base commit (a3330d5b) — the full capture, headers included,
 * lives at /tmp/opencode/refactor-exec-1119/gates/ENVEL3-legacy-bytes-base.txt.
 * These strings are TODAY'S wire bytes as the specification:
 * Response::error() renders `['error' => $message, 'code' => $code] + $extra`
 * through the very same json() chain, and the left-biased union keeps
 * `error` before `code`, so the conversion moves no bytes. If a row ever
 * reddens, the seam moved, not the pin (ceiling-silence law).
 *
 * The two dynamic-feed shapes (QuickConnect token(): the
 * PasswordChangeRequiredException catch; CastingEnabledMiddleware:
 * ucfirst($protocol)) are pinned with representative sample values —
 * structural equality of the two render forms; their live behavior stays
 * covered by the exception- and protocol-level suites that already exist.
 * The telemetry limit copy re-derives its message from the same PUBLIC
 * interface constants the legacy lines fed the concatenation, while the
 * golden body freezes the resolved integers: a constant bump must redden
 * both halves together, loudly, and the wire bytes stay the law.
 *
 * Adoption-state census (which files no longer hand-roll these chains)
 * lives in {@see InlineErrorEnvelopeExtinctionTest}.
 */
final class MiddlewareErrorEnvelopeAdoptionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: string}>
     */
    public static function legacyEnvelopeCorpus(): array
    {
        return [
            'qc_401_unauthorized' => [
                401, 'unauthorized', 'Unauthorized',
                "{\n    \"error\": \"Unauthorized\",\n    \"code\": \"unauthorized\"\n}",
            ],
            'qc_400_approve_secret_required' => [
                400, 'invalid_request', 'Pairing secret is required',
                "{\n    \"error\": \"Pairing secret is required\",\n    \"code\": \"invalid_request\"\n}",
            ],
            'qc_400_token_secret_required' => [
                400, 'invalid_request', 'Pairing secret is required',
                "{\n    \"error\": \"Pairing secret is required\",\n    \"code\": \"invalid_request\"\n}",
            ],
            'qc_403_invalid_secret' => [
                403, 'invalid_secret', 'Invalid pairing secret',
                "{\n    \"error\": \"Invalid pairing secret\",\n    \"code\": \"invalid_secret\"\n}",
            ],
            'qc_409_not_pending' => [
                409, 'pairing_not_pending', 'Pairing is not pending',
                "{\n    \"error\": \"Pairing is not pending\",\n    \"code\": \"pairing_not_pending\"\n}",
            ],
            'qc_403_dynamic_exception_sample' => [
                403, 'auth.password_change_required', 'Password change required',
                "{\n    \"error\": \"Password change required\",\n    \"code\": \"auth.password_change_required\"\n}",
            ],
            'qc_400_consent_required' => [
                400, 'consent_required', 'Telemetry requires explicit opt-in consent',
                "{\n    \"error\": \"Telemetry requires explicit opt-in consent\",\n    \"code\": \"consent_required\"\n}",
            ],
            'qc_400_instance_id_shape' => [
                400, 'invalid_payload',
                'Instance ID must be an opaque ID of 8-64 [A-Za-z0-9._-] characters',
                "{\n    \"error\": \"Instance ID must be an opaque ID of 8-64 [A-Za-z0-9._-] characters\",\n    \"code\": \"invalid_payload\"\n}",
            ],
            'qc_400_version_concat' => [
                400, 'invalid_payload',
                'Version is required (max ' . ClientHeartbeatStoreInterface::MAX_VERSION_LENGTH . ' characters)',
                "{\n    \"error\": \"Version is required (max 32 characters)\",\n    \"code\": \"invalid_payload\"\n}",
            ],
            'qc_400_client_type_concat' => [
                400, 'invalid_payload',
                'Client type is required (max ' . ClientHeartbeatStoreInterface::MAX_CLIENT_TYPE_LENGTH . ' characters)',
                "{\n    \"error\": \"Client type is required (max 32 characters)\",\n    \"code\": \"invalid_payload\"\n}",
            ],
            'qc_400_build_token_concat' => [
                400, 'invalid_payload',
                'Build token must be text of at most ' . ClientHeartbeatStoreInterface::MAX_BUILD_TOKEN_LENGTH . ' characters',
                "{\n    \"error\": \"Build token must be text of at most 64 characters\",\n    \"code\": \"invalid_payload\"\n}",
            ],
            'qc_404_pairing_not_found' => [
                404, 'pairing_not_found', 'Pairing not found',
                "{\n    \"error\": \"Pairing not found\",\n    \"code\": \"pairing_not_found\"\n}",
            ],
            'qc_403_pairing_without_profile' => [
                403, 'profile.not_found',
                'This pairing was approved without a profile. Approve it again from a session with a profile.',
                "{\n    \"error\": \"This pairing was approved without a profile. Approve it again from a session with a profile.\",\n    \"code\": \"profile.not_found\"\n}",
            ],
            'qc_403_profile_no_longer_owned' => [
                403, 'profile.not_found',
                'The profile this pairing was approved for is no longer available. Approve the pairing again.',
                "{\n    \"error\": \"The profile this pairing was approved for is no longer available. Approve the pairing again.\",\n    \"code\": \"profile.not_found\"\n}",
            ],
            'qc_503_storage_unavailable' => [
                503, 'storage_unavailable', 'Quick-connect storage is not available on this server',
                "{\n    \"error\": \"Quick-connect storage is not available on this server\",\n    \"code\": \"storage_unavailable\"\n}",
            ],
            'signed_401_auth_required' => [
                401, 'auth.required', 'Unauthorized',
                "{\n    \"error\": \"Unauthorized\",\n    \"code\": \"auth.required\"\n}",
            ],
            'admin_401_auth_required' => [
                401, 'auth.required', 'Unauthorized',
                "{\n    \"error\": \"Unauthorized\",\n    \"code\": \"auth.required\"\n}",
            ],
            'admin_403_not_admin' => [
                403, 'auth.not_admin', 'Forbidden',
                "{\n    \"error\": \"Forbidden\",\n    \"code\": \"auth.not_admin\"\n}",
            ],
            'dlna_403_forbidden' => [
                403, 'dlna.forbidden', 'DLNA access is not permitted from this network address',
                "{\n    \"error\": \"DLNA access is not permitted from this network address\",\n    \"code\": \"dlna.forbidden\"\n}",
            ],
            'authmw_401_auth_required' => [
                401, 'auth.required', 'Unauthorized',
                "{\n    \"error\": \"Unauthorized\",\n    \"code\": \"auth.required\"\n}",
            ],
            'casting_404_disabled_sample' => [
                404, 'casting.disabled', 'Rtsp casting is disabled on this server.',
                "{\n    \"error\": \"Rtsp casting is disabled on this server.\",\n    \"code\": \"casting.disabled\"\n}",
            ],
        ];
    }

    #[DataProvider('legacyEnvelopeCorpus')]
    public function test_seam_renders_pristine_legacy_bytes(
        int $status,
        string $code,
        string $message,
        string $legacyBody
    ): void {
        $response = (new Response())->error($status, $code, $message);

        $this->assertSame($status, $response->statusCode, "status moved for {$code}");
        $this->assertSame($legacyBody, $response->body, "body bytes moved for {$code}");
        $this->assertSame(
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $response->headers,
            "header map moved for {$code} (json() seeds Content-Type then Cache-Control; nothing else may appear)",
        );
    }

    public function test_basic_challenge_keeps_www_authenticate_before_json_headers(): void
    {
        // The OPDS challenge site renders status + a WWW-Authenticate header +
        // the {error, code} body. The legacy chain wrote the header between
        // status() and json(); adoption keeps it first in the map because
        // json() only ever APPENDS its two seeds. A browser/e-reader pops the
        // Basic dialog on this header, so its exact position is contract.
        $validator = static fn (string $u, string $p): ?string => null;

        $request = new Request();
        $request->path = '/opds/v1.2';
        $request->headers = ['AUTHORIZATION' => 'Basic ' . base64_encode('alice:wrong')];

        $response = SignedUrlMiddleware::forOpds($validator, new SignedUrl('b3-corpus-secret', 3600))($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
        $this->assertSame(
            "{\n    \"error\": \"Unauthorized\",\n    \"code\": \"auth.required\"\n}",
            $response->body,
        );
        $this->assertSame(
            ['WWW-Authenticate', 'Content-Type', 'Cache-Control'],
            array_keys($response->headers),
            'challenge header order moved',
        );
        $this->assertSame('Basic realm="Phlix OPDS", charset="UTF-8"', $response->headers['WWW-Authenticate']);
    }

    public function test_non_opds_rejection_carries_no_challenge_header(): void
    {
        $request = new Request();
        $request->path = '/api/v1/photo/photos/p1/full';

        $response = (new SignedUrlMiddleware(false, 'Phlix', null, new SignedUrl('b3-corpus-secret', 3600)))($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->statusCode);
        $this->assertSame(
            "{\n    \"error\": \"Unauthorized\",\n    \"code\": \"auth.required\"\n}",
            $response->body,
        );
        $this->assertSame(
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $response->headers,
        );
    }
}
