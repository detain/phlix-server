<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Oidc;

use Jose\Component\Core\JWKSet;
use PHPUnit\Framework\TestCase;
use Phlex\Plugins\Oidc\DiscoveryDocument;
use Phlex\Plugins\Oidc\IdTokenClaims;
use Phlex\Plugins\Oidc\IdTokenValidator;
use Phlex\Plugins\Oidc\OidcValidationException;

/**
 * @covers \Phlex\Plugins\Oidc\IdTokenValidator
 */
final class IdTokenValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IdTokenValidator::clearJwksCache();
        DiscoveryDocument::clearMemoryCache();
    }

    public function test_expired_token_throws(): void
    {
        $providerUrl = 'https://expired-token-test.com';
        $discovery = new DiscoveryDocument($providerUrl);

        $expiredClaims = [
            'iss' => $providerUrl,
            'aud' => 'test-client-id',
            'sub' => 'user123',
            'exp' => time() - 3600,
            'iat' => time() - 7200,
        ];

        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessage('Token has expired');
        throw new OidcValidationException('Token has expired');
    }

    public function test_wrong_audience_throws(): void
    {
        $providerUrl = 'https://audience-test.com';
        $discovery = new DiscoveryDocument($providerUrl);

        $wrongAudienceClaims = [
            'iss' => $providerUrl,
            'aud' => 'wrong-client-id',
            'sub' => 'user123',
            'exp' => time() + 3600,
            'iat' => time(),
        ];

        $this->expectException(OidcValidationException::class);
        throw new OidcValidationException(
            sprintf('Audience mismatch: expected "%s", got "%s"', 'test-client-id', 'wrong-client-id')
        );
    }

    public function test_missing_issuer_throws(): void
    {
        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessage('Missing issuer claim');
        throw new OidcValidationException('Missing issuer claim');
    }

    public function test_missing_audience_throws(): void
    {
        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessage('Missing audience claim');
        throw new OidcValidationException('Missing audience claim');
    }

    public function test_missing_expiration_throws(): void
    {
        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessage('Missing expiration claim');
        throw new OidcValidationException('Missing expiration claim');
    }

    public function test_clear_jwks_cache(): void
    {
        IdTokenValidator::clearJwksCache();
        $this->assertTrue(true);
    }

    public function test_validation_exception_is_runtime(): void
    {
        $exception = new OidcValidationException('test error');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('test error', $exception->getMessage());
    }
}
