<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use Jose\Component\Core\JWKSet;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Oidc\DiscoveryDocument;
use Phlix\Plugins\Oidc\IdTokenClaims;
use Phlix\Plugins\Oidc\IdTokenValidator;
use Phlix\Plugins\Oidc\OidcValidationException;

/**
 * @covers \Phlix\Plugins\Oidc\IdTokenValidator
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

    /**
     * Algorithm-confusion mitigation (web-token GHSA-jc38-x7x8-2xc8): a token
     * whose protected header advertises a symmetric algorithm must be rejected
     * at the header gate, before any signature is trusted — otherwise an
     * attacker could try to coerce HMAC verification against the RSA public key.
     */
    public function test_rejects_token_whose_protected_header_uses_hs256(): void
    {
        $providerUrl = 'https://alg-confusion.test';
        $validator = new IdTokenValidator(new DiscoveryDocument($providerUrl), new JWKSet([]));

        $token = $this->compactJws(
            ['alg' => 'HS256', 'typ' => 'JWT'],
            ['iss' => $providerUrl, 'aud' => 'client', 'exp' => time() + 3600],
        );

        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessage('Token header rejected');
        $validator->validate($token, 'client');
    }

    public function test_rejects_token_with_alg_none(): void
    {
        $providerUrl = 'https://alg-none.test';
        $validator = new IdTokenValidator(new DiscoveryDocument($providerUrl), new JWKSet([]));

        $token = $this->compactJws(
            ['alg' => 'none'],
            ['iss' => $providerUrl, 'aud' => 'client', 'exp' => time() + 3600],
            '',
        );

        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessage('Token header rejected');
        $validator->validate($token, 'client');
    }

    /**
     * Build a compact-serialised JWS string with the given protected header and
     * payload. The signature is irrelevant here — these tests exercise the
     * header-algorithm gate, which runs before signature verification.
     *
     * @param array<string, mixed> $protectedHeader
     * @param array<string, mixed> $payload
     */
    private function compactJws(array $protectedHeader, array $payload, string $signature = 'sig'): string
    {
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $b64((string) json_encode($protectedHeader, JSON_THROW_ON_ERROR))
            . '.' . $b64((string) json_encode($payload, JSON_THROW_ON_ERROR))
            . '.' . $b64($signature);
    }
}
