<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlex\Plugins\Oidc\IdTokenClaims;

/**
 * @covers \Phlex\Plugins\Oidc\IdTokenClaims
 */
final class IdTokenClaimsTest extends TestCase
{
    public function test_from_array_creates_valid_claims(): void
    {
        $claims = [
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => 'client-id-123',
            'exp' => time() + 3600,
            'iat' => time(),
            'nonce' => 'test-nonce',
            'email' => 'test@example.com',
            'email_verified' => true,
            'name' => 'Test User',
            'given_name' => 'Test',
            'family_name' => 'User',
            'picture' => 'https://example.com/photo.jpg',
            'locale' => 'en-US',
        ];

        $idTokenClaims = IdTokenClaims::fromArray($claims);

        $this->assertSame('user123', $idTokenClaims->sub);
        $this->assertSame('https://example.com', $idTokenClaims->iss);
        $this->assertSame('client-id-123', $idTokenClaims->aud);
        $this->assertSame($claims['exp'], $idTokenClaims->exp);
        $this->assertSame($claims['iat'], $idTokenClaims->iat);
        $this->assertSame('test-nonce', $idTokenClaims->nonce);
        $this->assertSame('test@example.com', $idTokenClaims->email);
        $this->assertTrue($idTokenClaims->emailVerified);
        $this->assertSame('Test User', $idTokenClaims->name);
        $this->assertSame('Test', $idTokenClaims->givenName);
        $this->assertSame('User', $idTokenClaims->familyName);
        $this->assertSame('https://example.com/photo.jpg', $idTokenClaims->picture);
        $this->assertSame('en-US', $idTokenClaims->locale);
    }

    public function test_from_array_with_missing_optional_claims(): void
    {
        $claims = [
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => 'client-id-123',
            'exp' => time() + 3600,
            'iat' => time(),
        ];

        $idTokenClaims = IdTokenClaims::fromArray($claims);

        $this->assertSame('user123', $idTokenClaims->sub);
        $this->assertNull($idTokenClaims->nonce);
        $this->assertNull($idTokenClaims->email);
        $this->assertFalse($idTokenClaims->emailVerified);
        $this->assertNull($idTokenClaims->name);
        $this->assertNull($idTokenClaims->givenName);
        $this->assertNull($idTokenClaims->familyName);
        $this->assertNull($idTokenClaims->picture);
        $this->assertNull($idTokenClaims->locale);
    }

    public function test_get_claim_returns_value(): void
    {
        $claims = IdTokenClaims::fromArray([
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => 'client-id',
            'exp' => time() + 3600,
            'iat' => time(),
            'custom_claim' => 'custom_value',
        ]);

        $this->assertSame('custom_value', $claims->getClaim('custom_claim'));
        $this->assertSame('default', $claims->getClaim('nonexistent', 'default'));
        $this->assertNull($claims->getClaim('nonexistent'));
    }

    public function test_has_claim_returns_true_when_present(): void
    {
        $claims = IdTokenClaims::fromArray([
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => 'client-id',
            'exp' => time() + 3600,
            'iat' => time(),
            'existing_claim' => 'value',
        ]);

        $this->assertTrue($claims->hasClaim('existing_claim'));
        $this->assertFalse($claims->hasClaim('nonexistent_claim'));
    }

    public function test_array_audience_is_handled(): void
    {
        $claims = IdTokenClaims::fromArray([
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => ['client-id-1', 'client-id-2'],
            'exp' => time() + 3600,
            'iat' => time(),
        ]);

        $this->assertSame(['client-id-1', 'client-id-2'], $claims->aud);
    }

    public function test_raw_claims_preserved(): void
    {
        $originalClaims = [
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => 'client-id',
            'exp' => time() + 3600,
            'iat' => time(),
            'extra_claim' => 'extra_value',
        ];

        $idTokenClaims = IdTokenClaims::fromArray($originalClaims);
        $this->assertSame($originalClaims, $idTokenClaims->rawClaims);
    }
}
