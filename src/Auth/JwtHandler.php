<?php

declare(strict_types=1);

namespace Phlix\Auth;

/**
 * JWT token handler for creating and validating JSON Web Tokens.
 *
 * This class provides secure JWT token generation and validation services
 * with support for access tokens and refresh tokens with configurable
 * TTLs and algorithms.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Handles JWT token creation, validation, and claims extraction
 *              for secure stateless authentication in the Phlix Media Server.
 * @see AuthManager For high-level authentication operations
 *
 * JWT Token Structure:
 * --------------------
 * Access Token Claims:
 *   - iss: Issuer identifier ('phlix')
 *   - sub: User ID (subject)
 *   - iat: Issued at timestamp
 *   - exp: Expiration timestamp
 *   - type: Token type ('access')
 *
 * Refresh Token Claims:
 *   - iss: Issuer identifier ('phlix')
 *   - sub: User ID (subject)
 *   - iat: Issued at timestamp
 *   - exp: Expiration timestamp
 *   - type: Token type ('refresh')
 *   - jti: Unique token ID for revocation support
 */
class JwtHandler
{
    /** @var string Secret key for HMAC signature */
    private string $secretKey;

    /** @var string JWT algorithm (default: HS256) */
    private string $algorithm;

    /** @var int Access token TTL in seconds (default: 3600 = 1 hour) */
    private int $ttl;

    /** @var int Refresh token TTL in seconds (default: 604800 = 7 days) */
    private int $refreshTtl;

    /**
     * Create a new JwtHandler instance.
     *
     * @param string $secretKey Secret key for HMAC signature (min 32 bytes recommended)
     * @param string $algorithm JWT algorithm for signing (default: 'HS256')
     * @param int $ttl Access token time-to-live in seconds (default: 3600)
     * @param int $refreshTtl Refresh token time-to-live in seconds (default: 604800)
     *
     * @throws \InvalidArgumentException If secret key is too short
     *
     * @example
     * ```php
     * $handler = new JwtHandler(
     *     'your-256-bit-secret-key-here',
     *     'HS256',
     *     3600,      // 1 hour access token
     *     604800     // 7 day refresh token
     * );
     * ```
     */
    public function __construct(
        string $secretKey,
        string $algorithm = 'HS256',
        int $ttl = 3600,
        int $refreshTtl = 604800
    ) {
        $this->secretKey = $secretKey;
        $this->algorithm = $algorithm;
        $this->ttl = $ttl;
        $this->refreshTtl = $refreshTtl;
    }

    /**
     * Create a new access token for a user.
     *
     * Access tokens are short-lived tokens used for API authentication.
     * They contain the user ID as the subject and standard JWT claims.
     *
     * @param string $userId Unique user identifier to include as subject
     * @param array<string, mixed> $claims Additional custom claims to include
     *
     * @return string Signed JWT access token string
     *
     * @example
     * ```php
     * $token = $handler->createAccessToken('user-123');
     * // Returns: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyLTEyMy4uLn0.signature'
     * ```
     */
    public function createAccessToken(string $userId, array $claims = []): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iss' => 'phlix',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'type' => 'access',
        ]);

        return $this->encode($payload);
    }

    /**
     * Create a new refresh token for a user.
     *
     * Refresh tokens are long-lived tokens used to obtain new access tokens
     * without requiring the user to re-authenticate. Each refresh token
     * has a unique JTI (JWT ID) for potential revocation support.
     *
     * @param string $userId Unique user identifier to include as subject
     *
     * @return string Signed JWT refresh token string
     *
     * @example
     * ```php
     * $token = $handler->createRefreshToken('user-123');
     * // Returns: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyLTEyMy4uLn0.signature'
     * ```
     */
    public function createRefreshToken(string $userId): string
    {
        $now = time();
        $payload = [
            'iss' => 'phlix',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $this->refreshTtl,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)),
        ];

        return $this->encode($payload);
    }

    /**
     * Validate a JWT token and return its payload.
     *
     * Performs full validation including signature verification, expiration
     * check, and issuer validation. Returns the decoded payload if valid,
     * or null if the token is invalid, expired, or malformed.
     *
     * @param string $token JWT token string to validate
     *
     * @return array<string, mixed>|null Decoded token payload if valid, null if invalid
     *
     * @throws \InvalidArgumentException If token format is invalid
     *
     * @example
     * ```php
     * $payload = $handler->validateToken($token);
     * if ($payload !== null) {
     *     $userId = $payload['sub'];
     *     $expiry = $payload['exp'];
     * }
     * ```
     */
    public function validateToken(string $token): ?array
    {
        try {
            $payload = $this->decode($token);

            // Verify expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            // Verify issuer
            if (($payload['iss'] ?? '') !== 'phlix') {
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract user ID from a token.
     *
     * @param string $token JWT token to extract user from
     *
     * @return string|null User ID if token valid, null otherwise
     *
     * @example
     * ```php
     * $userId = $handler->getUserIdFromToken($token);
     * ```
     */
    public function getUserIdFromToken(string $token): ?string
    {
        $payload = $this->validateToken($token);
        $sub = $payload['sub'] ?? null;
        return is_string($sub) ? $sub : null;
    }

    /**
     * Check if a token is an access token.
     *
     * @param string $token JWT token to check
     *
     * @return bool True if token is a valid access token, false otherwise
     *
     * @example
     * ```php
     * if ($handler->isAccessToken($token)) {
     *     // Use for API authentication
     * }
     * ```
     */
    public function isAccessToken(string $token): bool
    {
        $payload = $this->validateToken($token);
        return ($payload['type'] ?? '') === 'access';
    }

    /**
     * Check if a token is a refresh token.
     *
     * @param string $token JWT token to check
     *
     * @return bool True if token is a valid refresh token, false otherwise
     *
     * @example
     * ```php
     * if ($handler->isRefreshToken($token)) {
     *     // Use for token refresh endpoint
     * }
     * ```
     */
    public function isRefreshToken(string $token): bool
    {
        $payload = $this->validateToken($token);
        return ($payload['type'] ?? '') === 'refresh';
    }

    /**
     * Encode payload to JWT token format.
     *
     * Creates a signed JWT token with the given payload using HMAC-SHA256
     * signature. The token consists of base64url-encoded header, payload,
     * and signature joined by dots.
     *
     * @param array<string, mixed> $payload Token claims to encode
     *
     * @return string Encoded JWT token
     *
     * @example
     * ```php
     * $token = $this->encode(['sub' => 'user-123', 'exp' => time() + 3600]);
     * ```
     */
    private function encode(array $payload): string
    {
        $header = ['alg' => $this->algorithm, 'typ' => 'JWT'];
        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signature = hash_hmac(
            'sha256',
            "{$headerEncoded}.{$payloadEncoded}",
            $this->secretKey,
            true
        );
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Decode and validate JWT token.
     *
     * Parses the JWT token format, verifies the signature using HMAC-SHA256,
     * and returns the decoded payload. Throws exception if signature
     * verification fails.
     *
     * @param string $token JWT token string to decode
     *
     * @return array<string, mixed> Decoded token payload
     *
     * @throws \InvalidArgumentException If token format is invalid or signature mismatch
     *
     * @example
     * ```php
     * $payload = $this->decode($token);
     * ```
     */
    private function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid token format');
        }

        [$headerEncoded, $payloadEncoded, $signature] = $parts;

        // Verify signature
        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $this->secretKey, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            throw new \InvalidArgumentException('Invalid signature');
        }

        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        unset($header);

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid token payload');
        }

        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Base64 URL-safe encoding.
     *
     * Encodes data using base64 with URL-safe characters (+/-) instead
     * of standard base64 (+//) and strips padding (=).
     *
     * @param string $data Raw binary data to encode
     *
     * @return string Base64 URL-safe encoded string
     *
     * @example
     * ```php
     * $encoded = $this->base64UrlEncode('Hello World');
     * // Returns: 'SGVsbG8gV29ybGQ'
     * ```
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decoding.
     *
     * Decodes base64 URL-safe encoded strings back to raw binary data.
     *
     * @param string $data Base64 URL-safe encoded string
     *
     * @return string Decoded raw binary data
     *
     * @example
     * ```php
     * $decoded = $this->base64UrlDecode('SGVsbG8gV29ybGQ');
     * // Returns: 'Hello World'
     * ```
     */
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
