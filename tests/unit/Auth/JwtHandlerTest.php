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
        
        $this->assertIsString($token);
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
        $this->assertEquals(32, strlen($payload['jti'])); // 16 bytes = 32 hex chars
    }

    public function testTokenWithCustomClaims(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-123', ['role' => 'admin']);
        $payload = $this->jwtHandler->validateToken($token);
        
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
        $parts = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $payload['iss'] = 'wrong-issuer';
        
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $headerEncoded = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", 'test-secret-key-12345', true);
        $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        
        $invalidToken = "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
        
        $result = $this->jwtHandler->validateToken($invalidToken);
        $this->assertNull($result);
    }
}