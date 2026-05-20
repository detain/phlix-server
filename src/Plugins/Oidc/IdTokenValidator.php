<?php

declare(strict_types=1);

namespace Phlix\Plugins\Oidc;

use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\AudienceChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\ExpirationTimeChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\IssuedAtChecker;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Checker\NonceChecker;
use Jose\Component\Checker\NotBeforeChecker;
use Jose\Component\Core\Algorithm\Algorithm;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use RuntimeException;

/**
 * Validates OIDC ID tokens (RS256/RS384/RS512).
 *
 * Verifies the signature against the provider's JWKS, checks standard
 * OIDC claims (iss, aud, exp, iat, nonce), and extracts the claims.
 *
 * @package Phlix\Plugins\Oidc
 * @since 0.11.0
 */
final class IdTokenValidator
{
    private DiscoveryDocument $discovery;
    private JWKSet $jwkSet;
    private ?JWSVerifier $verifier = null;
    private ?JWSSerializerManager $serializerManager = null;

    /** @var array<string, mixed> Cached JWKS by provider URL hash */
    private static array $jwksCache = [];

    public function __construct(DiscoveryDocument $discovery, JWKSet $jwkSet)
    {
        $this->discovery = $discovery;
        $this->jwkSet = $jwkSet;
    }

    /**
     * Validate an ID token.
     *
     * @param string $idToken The ID token to validate
     * @param string $clientId The expected audience (client_id)
     * @param string $expectedNonce The expected nonce value (empty if not used)
     * @return IdTokenClaims The validated claims
     * @throws OidcValidationException When validation fails
     */
    public function validate(string $idToken, string $clientId, string $expectedNonce = ''): IdTokenClaims
    {
        try {
            return $this->doValidate($idToken, $clientId, $expectedNonce);
        } catch (OidcValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new OidcValidationException('Token validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Internal validation logic.
     *
     * @param string $idToken
     * @param string $clientId
     * @param string $expectedNonce
     * @return IdTokenClaims
     */
    private function doValidate(string $idToken, string $clientId, string $expectedNonce): IdTokenClaims
    {
        $serializerManager = $this->getSerializerManager();
        $jws = $serializerManager->unserialize($idToken);

        $signatureIndex = 0;
        $verified = $this->getVerifier()->verifyWithKeySet(
            $jws,
            $this->jwkSet,
            $signatureIndex
        );

        if (!$verified) {
            throw new OidcValidationException('Token signature verification failed');
        }

        $payload = $jws->getPayload();
        if ($payload === null) {
            throw new OidcValidationException('Token has no payload');
        }

        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            throw new OidcValidationException('Token payload is not valid JSON');
        }

        /** @var array<string, mixed> $claims */
        $this->validateClaims($claims, $clientId, $expectedNonce);

        return IdTokenClaims::fromArray($claims);
    }

    /**
     * Validate the claims in the token.
     *
     * @param array<string, mixed> $claims
     * @param string $clientId
     * @param string $expectedNonce
     * @throws OidcValidationException
     */
    private function validateClaims(array $claims, string $clientId, string $expectedNonce): void
    {
        $iss = $this->discovery->issuer();

        if (!isset($claims['iss'])) {
            throw new OidcValidationException('Missing issuer claim');
        }
        $claimIssRaw = $claims['iss'];
        if (!is_string($claimIssRaw) && !is_numeric($claimIssRaw)) {
            throw new OidcValidationException('Issuer claim is not a string or numeric');
        }
        $claimIss = (string) $claimIssRaw;
        if ($claimIss !== $iss) {
            throw new OidcValidationException(
                sprintf('Issuer mismatch: expected "%s", got "%s"', $iss, $claimIss)
            );
        }

        if (!isset($claims['aud'])) {
            throw new OidcValidationException('Missing audience claim');
        }
        $audRaw = $claims['aud'];
        if (is_array($audRaw)) {
            $aud = array_values(array_filter($audRaw, 'is_string'));
        } elseif (is_string($audRaw)) {
            $aud = [$audRaw];
        } else {
            $aud = [];
        }
        if (!in_array($clientId, $aud, true)) {
            throw new OidcValidationException(
                sprintf('Audience mismatch: expected "%s", got "%s"', $clientId, implode(', ', $aud))
            );
        }

        if (!isset($claims['exp'])) {
            throw new OidcValidationException('Missing expiration claim');
        }
        $exp = is_numeric($claims['exp']) ? (int) $claims['exp'] : 0;
        if ($exp < time()) {
            throw new OidcValidationException('Token has expired');
        }

        if (isset($claims['iat'])) {
            $iat = is_numeric($claims['iat']) ? (int) $claims['iat'] : 0;
            if ($iat > time() + 60) {
                throw new OidcValidationException('Token issued in the future');
            }
        }

        if ($expectedNonce !== '') {
            if (!isset($claims['nonce'])) {
                throw new OidcValidationException('Missing nonce claim');
            }
            $nonceRaw = $claims['nonce'];
            if (!is_string($nonceRaw) && !is_numeric($nonceRaw)) {
                throw new OidcValidationException('Nonce claim is not a string or numeric');
            }
            $nonceClaim = (string) $nonceRaw;
            if ($nonceClaim !== $expectedNonce) {
                throw new OidcValidationException('Nonce mismatch');
            }
        }

        if (isset($claims['nbf'])) {
            $nbf = is_numeric($claims['nbf']) ? (int) $claims['nbf'] : 0;
            if ($nbf > time() + 60) {
                throw new OidcValidationException('Token not yet valid');
            }
        }
    }

    /**
     * Get the JWS verifier instance.
     *
     * @return JWSVerifier
     */
    private function getVerifier(): JWSVerifier
    {
        if ($this->verifier === null) {
            $algorithmManager = new \Jose\Component\Core\AlgorithmManager([
                new RS256(),
                new RS384(),
                new RS512(),
            ]);
            $this->verifier = new JWSVerifier($algorithmManager);
        }
        return $this->verifier;
    }

    /**
     * Get the JWS serializer manager.
     *
     * @return JWSSerializerManager
     */
    private function getSerializerManager(): JWSSerializerManager
    {
        if ($this->serializerManager === null) {
            $this->serializerManager = new JWSSerializerManager([
                new CompactSerializer(),
            ]);
        }
        return $this->serializerManager;
    }

    /**
     * Fetch and cache JWKS from the discovery document.
     *
     * @param DiscoveryDocument $discovery
     * @return JWKSet
     * @throws RuntimeException
     */
    public static function fetchJwks(DiscoveryDocument $discovery): JWKSet
    {
        $providerUrl = $discovery->getProviderUrl();
        $cacheKey = md5($providerUrl);

        if (isset(self::$jwksCache[$cacheKey])) {
            $cached = self::$jwksCache[$cacheKey];
            if (!is_array($cached)) {
                unset(self::$jwksCache[$cacheKey]);
            } else {
                $fetchedAt = $cached['_fetched_at'] ?? 0;
                if (is_int($fetchedAt) || is_numeric($fetchedAt)) {
                    if ((time() - (int) $fetchedAt) < 86400) {
                        $jwks = $cached['jwks'] ?? null;
                        if ($jwks instanceof JWKSet) {
                            return $jwks;
                        }
                    }
                }
            }
        }

        $jwksUri = $discovery->jwksUri();
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $content = @file_get_contents($jwksUri, false, $context);
        if ($content === false) {
            throw new RuntimeException('Failed to fetch JWKS from: ' . $jwksUri);
        }

        $jwksData = json_decode($content, true);
        if (!is_array($jwksData) || !isset($jwksData['keys'])) {
            throw new RuntimeException('Invalid JWKS format');
        }

        $jwks = JWKSet::createFromKeyData($jwksData);
        self::$jwksCache[$cacheKey] = [
            'jwks' => $jwks,
            '_fetched_at' => time(),
        ];

        return $jwks;
    }

    /**
     * Clear the JWKS cache (for testing).
     */
    public static function clearJwksCache(): void
    {
        self::$jwksCache = [];
    }
}
