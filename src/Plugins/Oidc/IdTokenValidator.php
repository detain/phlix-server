<?php

/**
 * Phlix media server component: Oidc.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Plugins\Oidc;

use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSTokenSupport;
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
    /**
     * RSA signature algorithms accepted for OIDC ID tokens. Both the
     * verification {@see \Jose\Component\Core\AlgorithmManager} and the
     * protected-header {@see AlgorithmChecker} are restricted to this set so a
     * token can never be verified under an algorithm we did not opt into.
     */
    private const ALLOWED_ALGORITHMS = ['RS256', 'RS384', 'RS512'];

    /**
     * minors M-SERVER-39 (W-min108): forced (kid-miss) refetches are throttled
     * to one NETWORK ATTEMPT per provider per this window. An IdP key rotation
     * must recover in minutes, not in up to 24 h of stale-cache serves; a
     * flood of unknown-kid tokens must not become a refetch amplification
     * lever against the IdP. Inside the window a forced call degrades to the
     * cached behavior — the awaited kid can only have arrived via the
     * window's earlier attempt anyway.
     */
    private const KID_MISS_REFETCH_MIN_SECONDS = 60;

    private DiscoveryDocument $discovery;
    private ?OidcHttpClient $httpClient;
    private JWKSet $jwkSet;
    private ?JWSVerifier $verifier = null;
    private ?JWSSerializerManager $serializerManager = null;
    private ?HeaderCheckerManager $headerCheckerManager = null;

    /** @var array<string, mixed> Cached JWKS by provider URL hash */
    private static array $jwksCache = [];

    public function __construct(
        DiscoveryDocument $discovery,
        JWKSet $jwkSet,
        ?OidcHttpClient $httpClient = null
    ) {
        $this->discovery = $discovery;
        $this->jwkSet = $jwkSet;
        // Kept so the kid-miss refetch (M-SERVER-39) rides the SAME client
        // (and its test seam) the provider bootstrapped with; null lets
        // fetchJwks() mint a fresh real client, exactly as before.
        $this->httpClient = $httpClient;
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

        // Defence-in-depth against JWS algorithm-confusion (web-token
        // GHSA-jc38-x7x8-2xc8, unpatched in the 3.4.x line we run): require the
        // signing algorithm to be declared in the token's PROTECTED header and
        // to be one of our RSA algorithms BEFORE the signature is trusted —
        // never accept an `alg` taken from an unprotected header.
        try {
            $this->getHeaderCheckerManager()->check($jws, $signatureIndex, ['alg']);
        } catch (\Throwable $e) {
            throw new OidcValidationException('Token header rejected: ' . $e->getMessage(), 0, $e);
        }

        // minors M-SERVER-39 (W-min108): a token naming a kid the loaded set
        // lacks is the IdP key-ROTATION signal — the 24 h freshness serve used
        // to strand exactly this case. One throttled refetch; adopt the fresh
        // set ONLY when it actually carries the awaited kid. Every failure
        // degrades to the historical path below: the same generic
        // 'Token signature verification failed' copy, zero new wire states.
        // An absent/empty kid is NOT a rotation signal (no lookup is possible)
        // and never triggers a refetch.
        $kid = null;
        try {
            $candidate = $jws->getSignature($signatureIndex)->getProtectedHeaderParameter('kid');
            if (is_string($candidate) && $candidate !== '') {
                $kid = $candidate;
            }
        } catch (\Throwable $e) {
            $kid = null;
        }

        if ($kid !== null && !$this->jwkSet->has($kid)) {
            try {
                $fresh = self::fetchJwks($this->discovery, $this->httpClient, true);
                if ($fresh->has($kid)) {
                    $this->jwkSet = $fresh;
                }
            } catch (\Throwable $e) {
                error_log('oidc: kid-miss JWKS refetch failed: ' . $e->getMessage());
            }
        }

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
     * Header-checker manager that enforces the token's `alg` lives in the
     * PROTECTED header and is one of {@see self::ALLOWED_ALGORITHMS}. The
     * `true` flag makes {@see AlgorithmChecker} reject an `alg` that appears
     * only in an unprotected header — the crux of the algorithm-confusion
     * mitigation.
     *
     * @return HeaderCheckerManager
     */
    private function getHeaderCheckerManager(): HeaderCheckerManager
    {
        if ($this->headerCheckerManager === null) {
            $this->headerCheckerManager = new HeaderCheckerManager(
                [new AlgorithmChecker(self::ALLOWED_ALGORITHMS, true)],
                [new JWSTokenSupport()],
            );
        }
        return $this->headerCheckerManager;
    }

    /**
     * Fetch and cache JWKS from the discovery document using non-blocking HTTP.
     *
     * The fetch goes through {@see OidcHttpClient}, which yields to the event
     * loop (cooperative-wait) instead of stalling the resident worker with a
     * blocking `curl_exec()`. The optional client parameter is a test seam:
     * production leaves it null and a real client is created.
     *
     * @param DiscoveryDocument   $discovery
     * @param OidcHttpClient|null $httpClient Injected client (tests supply a double).
     * @return JWKSet
     * @param bool $force minors M-SERVER-39: bypass the freshness serve for a
     *                      rotation-recovery (kid-miss) fetch, throttled to
     *                      one network attempt per KID_MISS_REFETCH_MIN_SECONDS.
     * @throws RuntimeException
     */
    public static function fetchJwks(
        DiscoveryDocument $discovery,
        ?OidcHttpClient $httpClient = null,
        bool $force = false
    ): JWKSet {
        $providerUrl = $discovery->getProviderUrl();
        $cacheKey = md5($providerUrl);
        $now = time();

        $forced = $force;
        if ($forced) {
            $slot = self::$jwksCache[$cacheKey] ?? null;
            $lastRefetch = is_array($slot) ? ($slot['_last_refetch_at'] ?? 0) : 0;
            if (
                (is_int($lastRefetch) || is_numeric($lastRefetch))
                && (int) $lastRefetch !== 0
                && ($now - (int) $lastRefetch) < self::KID_MISS_REFETCH_MIN_SECONDS
            ) {
                $forced = false;
            }
        }

        if (isset(self::$jwksCache[$cacheKey]) && !$forced) {
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
        if ($jwksUri === '') {
            throw new RuntimeException('JWKS URI is empty');
        }

        if ($force) {
            // Stamp BEFORE the request: a failing IdP costs one retry per
            // window, not one per token — and a successful-but-empty round
            // trip still consumed network, so the window owns it either way.
            $stamped = self::$jwksCache[$cacheKey] ?? [];
            if (is_array($stamped)) {
                $stamped['_last_refetch_at'] = $now;
                self::$jwksCache[$cacheKey] = $stamped;
            }
        }

        $client = $httpClient ?? new OidcHttpClient();
        $response = $client->get($jwksUri, [
            'User-Agent' => 'PhlixMediaServer/1.0',
            'Accept' => 'application/json',
        ]);

        if ($response === null) {
            throw new RuntimeException('Failed to fetch JWKS from: ' . $jwksUri);
        }

        $content = (string) $response->getBody();
        $jwksData = json_decode($content, true);
        if (!is_array($jwksData) || !isset($jwksData['keys'])) {
            throw new RuntimeException('Invalid JWKS format');
        }

        $jwks = JWKSet::createFromKeyData($jwksData);
        $finalSlot = self::$jwksCache[$cacheKey] ?? [];
        $priorRefetch = is_array($finalSlot) ? ($finalSlot['_last_refetch_at'] ?? 0) : 0;
        self::$jwksCache[$cacheKey] = [
            'jwks' => $jwks,
            '_fetched_at' => time(),
            '_last_refetch_at' => $force
                ? $now
                : ((is_int($priorRefetch) || is_numeric($priorRefetch)) ? (int) $priorRefetch : 0),
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
