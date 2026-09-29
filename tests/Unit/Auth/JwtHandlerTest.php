<?php

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;

class JwtHandlerTest extends TestCase
{
    private JwtHandler $jwtHandler;

    protected function setUp(): void
    {
        $this->jwtHandler = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
    }

    public function testCreateAccessToken(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-123');

        $this->assertNotEmpty($token);
        $this->assertCount(3, explode('.', $token));
    }

    public function testValidateValidToken(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-123');
        $payload = $this->jwtHandler->validateToken($token);

        $this->assertIsArray($payload);
        $this->assertEquals('user-123', $payload['sub']);
        $this->assertEquals('access', $payload['type']);
    }

    public function testValidateInvalidToken(): void
    {
        $payload = $this->jwtHandler->validateToken('invalid.token.here');

        $this->assertNull($payload);
    }

    public function testIsAccessToken(): void
    {
        $accessToken = $this->jwtHandler->createAccessToken('user-123');
        $refreshToken = $this->jwtHandler->createRefreshToken('user-123');

        $this->assertTrue($this->jwtHandler->isAccessToken($accessToken));
        $this->assertFalse($this->jwtHandler->isAccessToken($refreshToken));
    }

    public function testIsRefreshToken(): void
    {
        $accessToken = $this->jwtHandler->createAccessToken('user-123');
        $refreshToken = $this->jwtHandler->createRefreshToken('user-123');

        $this->assertFalse($this->jwtHandler->isRefreshToken($accessToken));
        $this->assertTrue($this->jwtHandler->isRefreshToken($refreshToken));
    }

    public function testGetUserIdFromToken(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-456');
        $userId = $this->jwtHandler->getUserIdFromToken($token);

        $this->assertEquals('user-456', $userId);
    }

    public function testRefreshTokenHasJti(): void
    {
        $refreshToken = $this->jwtHandler->createRefreshToken('user-123');
        $payload = $this->jwtHandler->validateToken($refreshToken);

        $this->assertIsArray($payload);
        $this->assertEquals('refresh', $payload['type']);
        $this->assertArrayHasKey('jti', $payload);
        $jti = $payload['jti'];
        $this->assertIsString($jti);
        $this->assertEquals(32, strlen($jti)); // 16 bytes = 32 hex chars
    }

    public function testTokenWithCustomClaims(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-123', ['role' => 'admin']);
        $payload = $this->jwtHandler->validateToken($token);

        $this->assertNotNull($payload);
        $this->assertEquals('admin', $payload['role']);
    }

    public function testExpiredTokenReturnsNull(): void
    {
        // Create a handler with very short TTL
        $shortLivedHandler = new JwtHandler('test-secret-key-12345', 'HS256', -10, 604800);
        $token = $shortLivedHandler->createAccessToken('user-123');

        // Token should be expired
        $payload = $shortLivedHandler->validateToken($token);
        $this->assertNull($payload);
    }

    public function testInvalidIssuerReturnsNull(): void
    {
        // Manually craft a token with wrong issuer by decoding and re-encoding
        $token = $this->jwtHandler->createAccessToken('user-123');
        /** @var array<string, mixed> $payload */
        $payload = $this->decodePayload($token);
        $payload['iss'] = 'wrong-issuer';

        $result = $this->jwtHandler->validateToken($this->resign($payload));
        $this->assertNull($result);
    }

    /**
     * L-2 (security audit 2026-09-29): a MISSING exp used to mean "valid
     * forever" — the expiration check short-circuited on isset(). Every
     * malformed-exp shape must now die in validateToken even when the HS256
     * signature is perfectly correct.
     */
    public function testMissingExpIsRejectedEvenWithValidSignature(): void
    {
        $payload = $this->decodePayload($this->jwtHandler->createAccessToken('user-123'));
        unset($payload['exp']);

        $this->assertNull($this->jwtHandler->validateToken($this->resign($payload)));
    }

    public function testNonNumericExpIsRejected(): void
    {
        foreach (['far-future', [], true, null, -1, 0] as $shape) {
            $payload = $this->decodePayload($this->jwtHandler->createAccessToken('user-123'));
            $payload['exp'] = $shape;

            $this->assertNull(
                $this->jwtHandler->validateToken($this->resign($payload)),
                'exp shape ' . json_encode($shape) . ' must be rejected'
            );
        }
    }

    public function testDigitStringExpStillValidates(): void
    {
        // Numeric-string exp (some hand-rolled minters json_encode ints as
        // strings) stays accepted — L-2 rejects SHAPE, not lenient numerics.
        $payload = $this->decodePayload($this->jwtHandler->createAccessToken('user-123'));
        $payload['exp'] = (string) (time() + 3600);

        $validated = $this->jwtHandler->validateToken($this->resign($payload));
        $this->assertNotNull($validated);
        $this->assertSame('user-123', $validated['sub']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $token): array
    {
        $parts = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $this->assertIsArray($payload);

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
        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", 'test-secret-key-12345', true);
        $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }
}
