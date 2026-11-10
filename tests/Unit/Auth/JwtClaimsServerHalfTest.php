<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\JwtHandler;
use Phlix\Shared\Auth\JwtClaims;
use PHPUnit\Framework\TestCase;

/**
 * LW-079-a: the server half of the shared `JwtClaims` DTO adoption.
 *
 * The shared class docblock promises that `JwtHandler::validateToken()`
 * deserializes the decoded payload into the DTO ("Phase C.5"). On the hub
 * that promise shipped literally — hub `validateToken()` returns
 * `?JwtClaims`. On the server the literal flip is NOT shippable as a drop:
 * server-minted tokens carry the S80 `profile_id` CUSTOM claim, which the
 * DTO does not model, and `validateToken()`'s array return is the transport
 * `AuthManager::refresh()` reads it from. Converting the return in place
 * would silently drop profile scoping on every refresh — the exact bug S80
 * fixed. This suite therefore pins the shippable server half:
 *
 *  1. `validateClaims()` is the server's typed entry into the shared DTO,
 *     built strictly from payloads that already passed FULL signature,
 *     expiry and issuer validation (never a standalone parse);
 *  2. legacy tokens (no `aud`) get the documented `AUD_SERVER` shim;
 *  3. the array path is untouched: `profile_id` survives in it and the
 *     L-2 digit-string-exp tolerance still validates there, while the
 *     typed view refuses that shape (requireInt) — the acceptance delta
 *     is deliberate and is the `fromPayloadStrict` migration track.
 */
final class JwtClaimsServerHalfTest extends TestCase
{
    private const SECRET = 'test-secret-key-12345';

    private JwtHandler $jwtHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jwtHandler = new JwtHandler(self::SECRET, 'HS256', 3600, 604800);
    }

    public function testValidateClaimsReturnsSharedDtoForAccessTokens(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-123');

        $claims = $this->jwtHandler->validateClaims($token);

        self::assertInstanceOf(JwtClaims::class, $claims);
        self::assertSame(JwtClaims::ISS_PHLIX, $claims->iss);
        self::assertSame('user-123', $claims->sub);
        self::assertSame(JwtClaims::TYPE_ACCESS, $claims->type);
        // iat/exp are typed int properties on the DTO: the arithmetic below
        // (and PHP's own type guarantee) is the assertion — a redundant
        // assertIsInt is exactly the already-narrowed noise phpstan flags.
        self::assertSame(3600, $claims->exp - $claims->iat);
        self::assertNull($claims->nbf);
        self::assertNull($claims->jti);
        self::assertSame([], $claims->scope);
        self::assertNull($claims->serverId);
        self::assertFalse($claims->isExpired());
    }

    public function testValidateClaimsReturnsSharedDtoForRefreshTokens(): void
    {
        $token = $this->jwtHandler->createRefreshToken('user-123');

        $claims = $this->jwtHandler->validateClaims($token);

        self::assertInstanceOf(JwtClaims::class, $claims);
        self::assertSame(JwtClaims::TYPE_REFRESH, $claims->type);
        self::assertSame(604800, $claims->exp - $claims->iat);
        self::assertIsString($claims->jti);
        self::assertSame(32, strlen($claims->jti), 'refresh jti is 16 random bytes hex-encoded');
    }

    public function testLegacyServerTokensWithoutAudGetTheDocumentedServerAudienceShim(): void
    {
        // The server mint does NOT stamp `aud` (that is part of the tracked
        // migration, not this row) — prove the mint is unchanged and the DTO
        // path applies exactly the shim `fromPayload()` documents.
        $token = $this->jwtHandler->createAccessToken('user-123');

        $payload = $this->jwtHandler->validateToken($token);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('aud', $payload, 'this row must not change the minted payload');

        $claims = $this->jwtHandler->validateClaims($token);
        self::assertInstanceOf(JwtClaims::class, $claims);
        self::assertSame(JwtClaims::AUD_SERVER, $claims->aud);
    }

    public function testProfileIdCustomClaimSurvivesBothViews(): void
    {
        // S80 transport law: the minted profile rides the ARRAY payload. The
        // typed DTO ignores unknown claims by design — both views coexist.
        $token = $this->jwtHandler->createAccessToken('user-123', [
            JwtHandler::CLAIM_PROFILE_ID => 'profile-99',
        ]);

        $payload = $this->jwtHandler->validateToken($token);
        self::assertIsArray($payload);
        self::assertSame('profile-99', $payload[JwtHandler::CLAIM_PROFILE_ID] ?? null);
        self::assertSame('profile-99', JwtHandler::profileIdClaim($payload));

        $claims = $this->jwtHandler->validateClaims($token);
        self::assertInstanceOf(JwtClaims::class, $claims);
        self::assertSame('user-123', $claims->sub);
    }

    public function testDigitStringExpStillValidatesAsArrayAndIsRefusedByTheTypedView(): void
    {
        // L-2's shape gate accepts digit-strings (documented leniency toward
        // hand-rolled minters). The DTO's requireInt does NOT. The split is
        // deliberate — migrating consumers onto it is the tracked follow-up.
        $payload = $this->decodePayload($this->jwtHandler->createAccessToken('user-123'));
        $payload['exp'] = (string) (time() + 3600);

        $legacy = $this->jwtHandler->validateToken($this->resign($payload));
        self::assertIsArray($legacy, 'array path keeps its documented tolerance');
        self::assertSame('user-123', $legacy['sub'] ?? null);

        self::assertNull(
            $this->jwtHandler->validateClaims($this->resign($payload)),
            'typed view demands the DTO int shape'
        );
    }

    public function testMissingIatIsRefusedByTheTypedViewWhileTheArrayPathStillPasses(): void
    {
        // Companion arm: the DTO also requires iat. Same acceptance delta,
        // same reason — pin it now so the migration track cannot drift quiet.
        $payload = $this->decodePayload($this->jwtHandler->createAccessToken('user-123'));
        unset($payload['iat']);

        self::assertIsArray($this->jwtHandler->validateToken($this->resign($payload)));
        self::assertNull($this->jwtHandler->validateClaims($this->resign($payload)));
    }

    public function testUnforgedNothing(): void
    {
        // Null-arms must cover every way a token reaches validateToken as
        // invalid: bad signature, expired, wrong issuer, malformed format.
        $token = $this->jwtHandler->createAccessToken('user-123');

        $tampered = $token . 'x';
        self::assertNull($this->jwtHandler->validateClaims($tampered));

        $expiredPayload = $this->decodePayload($token);
        $expiredPayload['exp'] = time() - 10;
        self::assertNull($this->jwtHandler->validateClaims($this->resign($expiredPayload)));

        $wrongIssuer = $this->decodePayload($token);
        $wrongIssuer['iss'] = 'not-phlix';
        self::assertNull($this->jwtHandler->validateClaims($this->resign($wrongIssuer)));

        self::assertNull($this->jwtHandler->validateClaims('garbage'));
    }

    public function testValidateClaimsNeverRunsAheadOfSignatureVerification(): void
    {
        // Security law pinned by the shared class docblock: the DTO performs
        // NO verification. The server entry point must be verification-first
        // — a token signed with the WRONG secret still decodes structurally
        // fine and must never surface claims.
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => 'phlix',
            'sub' => 'victim',
            'iat' => time(),
            'exp' => time() + 3600,
            'type' => 'access',
        ];
        $headerEncoded = rtrim(strtr(base64_encode(json_encode($header, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $rogueSignature = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            "{$headerEncoded}.{$payloadEncoded}",
            'attacker-secret',
            true,
        )), '+/', '-_'), '=');

        self::assertNull(
            $this->jwtHandler->validateClaims("{$headerEncoded}.{$payloadEncoded}.{$rogueSignature}"),
            'claims must never be produced from an unverified payload'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * Re-sign a payload with the legit test secret so only the CLAIM shape
     * (never the signature) causes rejection.
     *
     * @param array<string, mixed> $payload
     */
    private function resign(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $headerEncoded = rtrim(strtr(base64_encode(json_encode($header, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", self::SECRET, true);
        $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }
}
