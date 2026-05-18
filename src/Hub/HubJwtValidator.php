<?php

declare(strict_types=1);

namespace Phlex\Hub;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates JWTs issued by the Phlex Hub using the hub's JWKS.
 *
 * This validator:
 * 1. Extracts the `kid` (key ID) from the JWT header.
 * 2. Fetches and caches JWKS from the hub's JWKS URL.
 * 3. Finds the signing key matching the `kid`; refetches once on cache miss (handles key rotation).
 * 4. Verifies the Ed25519 signature using `sodium_crypto_sign_verify_detached`.
 * 5. Validates `iss == 'phlex-hub'`, `aud == 'phlex-server'`, `server_id` matches the server's own ID.
 * 6. Returns `HubUserClaims` on success; `null` on any failure.
 *
 * @package Phlex\Hub
 * @since 0.11.0
 */
final class HubJwtValidator implements HubJwtValidatorInterface
{
    private const EXPECTED_ISSUER = 'phlex-hub';

    private JwksCache $jwksCache;

    /**
     * Creates a new HubJwtValidator.
     *
     * @param string $hubJwksUrl The hub's JWKS URL (e.g. `https://hub.example.com/.well-known/jwks.json`).
     * @param HttpClientFactoryInterface $httpClientFactory Factory for creating HTTP clients to fetch JWKS.
     * @param LoggerInterface  $logger           Logger instance for diagnostic messages.
     * @param string            $serverId         This server's unique ID (used for audience validation).
     * @param JwksCache|null    $jwksCache        Optional JWKS cache instance (creates default if null).
     * @param int               $cacheTtl         JWKS cache TTL in seconds (default 900).
     */
    public function __construct(
        private readonly string $hubJwksUrl,
        private readonly HttpClientFactoryInterface $httpClientFactory,
        private readonly LoggerInterface $logger,
        private readonly string $serverId,
        ?JwksCache $jwksCache = null,
        int $cacheTtl = 900,
    ) {
        $this->jwksCache = $jwksCache ?? new JwksCache($cacheTtl);
    }

    /**
     * Validates a hub-issued JWT and returns the extracted claims.
     *
     * @param string $jwt The raw JWT string to validate.
     *
     * @return HubUserClaims|null The validated claims, or null if the token is invalid.
     */
    public function validate(string $jwt): ?HubUserClaims
    {
        try {
            $parts = $this->splitJwt($jwt);

            $header = $this->decodeJson($parts[0]);
            $payload = $this->decodeJson($parts[1]);
            $signature = $this->base64UrlDecode($parts[2]);

            $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
            if ($kid === null || $kid === '') {
                $this->logger->debug('Hub JWT validation failed: missing kid in header');
                return null;
            }

            $jwk = $this->resolveJwk($kid);
            if ($jwk === null) {
                $this->logger->debug('Hub JWT validation failed: could not resolve JWK', ['kid' => $kid]);
                return null;
            }

            $publicKey = $this->jwkToEd25519PublicKey($jwk);
            $signedMessage = $parts[0] . '.' . $parts[1];

            $isValidSignature = $signature !== ''
                && $publicKey !== ''
                && sodium_crypto_sign_verify_detached($signature, $signedMessage, $publicKey);
            if (!$isValidSignature) {
                $this->logger->debug('Hub JWT validation failed: invalid signature');
                return null;
            }

            $payloadIss = is_string($payload['iss'] ?? null) ? $payload['iss'] : '';
            if ($payloadIss !== self::EXPECTED_ISSUER) {
                $this->logger->debug('Hub JWT validation failed: wrong issuer', [
                    'expected' => self::EXPECTED_ISSUER,
                    'actual' => $payloadIss,
                ]);
                return null;
            }

            $payloadAud = is_string($payload['aud'] ?? null) ? $payload['aud'] : '';
            if ($payloadAud !== 'phlex-server') {
                $this->logger->debug('Hub JWT validation failed: wrong audience', [
                    'expected' => 'phlex-server',
                    'actual' => $payloadAud,
                ]);
                return null;
            }

            $payloadServerId = is_string($payload['server_id'] ?? null) ? $payload['server_id'] : '';
            if ($payloadServerId !== $this->serverId) {
                $this->logger->debug('Hub JWT validation failed: wrong server_id', [
                    'expected' => $this->serverId,
                    'actual' => $payloadServerId,
                ]);
                return null;
            }

            $payloadExp = is_int($payload['exp'] ?? null) ? $payload['exp'] : 0;
            if ($payloadExp < time()) {
                $this->logger->debug('Hub JWT validation failed: token expired');
                return null;
            }

            /** @var array<string> $scope */
            $scope = is_array($payload['scope'] ?? null) ? array_filter($payload['scope'], 'is_string') : [];

            $hubUserId = is_string($payload['hub_user_id'] ?? null)
                ? $payload['hub_user_id']
                : (is_string($payload['sub'] ?? null) ? $payload['sub'] : '');

            return new HubUserClaims(
                userId: $hubUserId,
                serverId: $payloadServerId,
                subject: is_string($payload['sub'] ?? null) ? $payload['sub'] : '',
                issuer: $payloadIss,
                expiresAt: $payloadExp,
                scope: $scope,
            );
        } catch (Throwable $e) {
            $this->logger->debug('Hub JWT validation failed: unexpected error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Forces a refresh of the cached JWKS.
     *
     * @return void
     */
    public function refreshJwks(): void
    {
        $this->jwksCache->invalidate();
        $this->fetchHubJwks();
    }

    /**
     * Splits a JWT into its three parts.
     *
     * @param string $jwt The JWT string.
     *
     * @return array{0: string, 1: string, 2: string} The header, payload, and signature as base64url strings.
     *
     * @throws InvalidArgumentException If the JWT does not have exactly 3 parts.
     */
    private function splitJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('JWT must have exactly 3 parts');
        }
        return $parts;
    }

    /**
     * Decodes a base64url-encoded JSON string.
     *
     * @param string $data The base64url-encoded data.
     *
     * @return array<string, mixed> The decoded JSON array.
     *
     * @throws InvalidArgumentException If decoding or JSON parsing fails.
     */
    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $data): array
    {
        $decoded = $this->base64UrlDecode($data);
        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new InvalidArgumentException('Invalid JSON in JWT part');
        }
        /** @var array<string, mixed> */
        return $json;
    }

    /**
     * Decodes a base64url-encoded string.
     *
     * @param string $data The base64url-encoded data.
     *
     * @return string The raw binary data.
     */
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }

    /**
     * Resolves a JWK by kid, fetching from hub if not in cache.
     *
     * Attempts to find the key in cache first. If not found, fetches
     * the full JWKS from the hub. If still not found after the fetch,
     * returns null.
     *
     * @param string $kid The key ID to resolve.
     *
     * @return array<string, mixed>|null The JWK array, or null if not found.
     */
    private function resolveJwk(string $kid): ?array
    {
        $jwk = $this->jwksCache->get($kid);
        if ($jwk !== null) {
            return $jwk;
        }

        $this->fetchHubJwks();
        $jwk = $this->jwksCache->get($kid);
        if ($jwk !== null) {
            return $jwk;
        }

        $this->jwksCache->invalidate();
        $this->fetchHubJwks();
        return $this->jwksCache->get($kid);
    }

    /**
     * Fetches JWKS from the hub and populates the cache.
     *
     * @return void
     */
    private function fetchHubJwks(): void
    {
        try {
            $scheme = parse_url($this->hubJwksUrl, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($this->hubJwksUrl, PHP_URL_HOST) ?: '';
            $port = parse_url($this->hubJwksUrl, PHP_URL_PORT);
            $path = parse_url($this->hubJwksUrl, PHP_URL_PATH) ?: '/.well-known/jwks.json';

            $baseUrl = $scheme . '://' . $host . ($port ? ':' . $port : '');

            $client = $this->httpClientFactory->create($baseUrl);
            $response = $client->get($path);

            if (!$response->isSuccess()) {
                $this->logger->warning('Failed to fetch hub JWKS', [
                    'status' => $response->statusCode,
                    'hub_jwks_url' => $this->hubJwksUrl,
                ]);
                return;
            }

            $body = $response->body;
            $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];

            foreach ($keys as $jwk) {
                if (!is_array($jwk) || !isset($jwk['kid']) || !is_string($jwk['kid'])) {
                    continue;
                }
                /** @var array<string, mixed> $jwkData */
                $jwkData = $jwk;
                $this->jwksCache->set($jwk['kid'], $jwkData);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Exception fetching hub JWKS', [
                'error' => $e->getMessage(),
                'hub_jwks_url' => $this->hubJwksUrl,
            ]);
        }
    }

    /**
     * Converts a JWK to an Ed25519 public key binary string.
     *
     * @param array<string, mixed> $jwk The JWK array with `kty: "OKP"`, `crv: "Ed25519"`, and `x` (base64url pubkey).
     *
     * @return string The 32-byte Ed25519 public key.
     *
     * @throws InvalidArgumentException If the JWK is not a valid Ed25519 key.
     */
    private function jwkToEd25519PublicKey(array $jwk): string
    {
        if (($jwk['kty'] ?? '') !== 'OKP' || ($jwk['crv'] ?? '') !== 'Ed25519') {
            throw new InvalidArgumentException('JWK is not an Ed25519 key');
        }

        $x = is_string($jwk['x'] ?? null) ? $jwk['x'] : '';
        if ($x === '') {
            throw new InvalidArgumentException('JWK missing "x" (public key) component');
        }

        $decoded = $this->base64UrlDecode($x);
        if (strlen($decoded) !== 32) {
            throw new InvalidArgumentException('Ed25519 public key must be 32 bytes');
        }

        return $decoded;
    }
}
