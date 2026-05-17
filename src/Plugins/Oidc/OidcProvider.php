<?php

declare(strict_types=1);

namespace Phlex\Plugins\Oidc;

use Jose\Component\Core\JWKSet;
use Phlex\Shared\Auth\AuthResult;
use Phlex\Shared\Auth\ProviderInterface;
use Phlex\Shared\Auth\UserInfo;
use Phlex\Plugins\Oidc\DiscoveryDocument;
use Phlex\Plugins\Oidc\IdTokenValidator;
use Phlex\Plugins\Oidc\OidcUserInfo;
use Phlex\Plugins\Oidc\OidcValidationException;
use RuntimeException;

/**
 * OIDC/OAuth2 authentication provider.
 *
 * Supports two flows:
 * - Authorization Code flow: exchanges authorization code for tokens
 * - Direct API token: validates bearer token and fetches userinfo
 *
 * @package Phlex\Plugins\Oidc
 * @since 0.11.0
 */
final class OidcProvider implements ProviderInterface
{
    private DiscoveryDocument $discovery;
    private string $clientId;
    private string $clientSecret;
    private string $scopes;
    private ?JWKSet $jwkSet = null;

    public function __construct(
        DiscoveryDocument $discovery,
        string $clientId,
        string $clientSecret,
        string $scopes = 'openid profile email',
    ) {
        $this->discovery = $discovery;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->scopes = $scopes;
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'oidc';
    }

    /**
     * {@inheritdoc}
     */
    public function supportsAuthentication(array $credentials): bool
    {
        return isset($credentials['code']) || isset($credentials['access_token']);
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(array $credentials): AuthResult
    {
        if (isset($credentials['code'])) {
            return $this->authenticateWithCode($credentials);
        }

        if (isset($credentials['access_token'])) {
            $token = is_string($credentials['access_token']) ? $credentials['access_token'] : '';
            return $this->authenticateWithAccessToken($token);
        }

        return new AuthResult(
            success: false,
            error: 'no_supported_credentials',
        );
    }

    /**
     * Authenticate using an authorization code.
     *
     * @param array $credentials Must contain 'code' and 'redirect_uri'
     * @return AuthResult
     */
    /**
     * @param array<string, mixed> $credentials
     */
    private function authenticateWithCode(array $credentials): AuthResult
    {
        $code = is_string($credentials['code'] ?? null) ? $credentials['code'] : '';
        $redirectUri = is_string($credentials['redirect_uri'] ?? null) ? $credentials['redirect_uri'] : '';
        $nonce = is_string($credentials['nonce'] ?? null) ? $credentials['nonce'] : '';

        try {
            $tokenResponse = $this->exchangeCode($code, $redirectUri);

            if (!isset($tokenResponse['id_token'])) {
                return new AuthResult(
                    success: false,
                    error: 'missing_id_token',
                );
            }

            $idTokenRaw = $tokenResponse['id_token'];
            $idToken = is_string($idTokenRaw) ? $idTokenRaw : '';

            $validator = $this->createValidator();
            $claims = $validator->validate($idToken, $this->clientId, $nonce);

            $userInfo = OidcUserInfo::fromIdTokenClaims($claims);

            $externalId = 'oidc.' . $claims->sub;

            $attributes = [
                'email' => $claims->email,
                'name' => $claims->name ?? $userInfo->getDisplayName(),
                'avatarUrl' => $claims->picture,
                'provider' => 'oidc',
            ];

            return new AuthResult(
                success: true,
                externalId: $externalId,
                attributes: $attributes,
            );
        } catch (OidcValidationException $e) {
            return new AuthResult(
                success: false,
                error: 'token_validation_failed: ' . $e->getMessage(),
            );
        } catch (RuntimeException $e) {
            return new AuthResult(
                success: false,
                error: 'auth_error: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Authenticate using an access token directly.
     *
     * @param string $accessToken
     * @return AuthResult
     */
    private function authenticateWithAccessToken(string $accessToken): AuthResult
    {
        try {
            $validator = $this->createValidator();
            $jwks = $this->getJwks();

            $userinfoEndpoint = $this->discovery->userinfoEndpoint();
            if ($userinfoEndpoint === null) {
                return new AuthResult(
                    success: false,
                    error: 'userinfo_endpoint_not_supported',
                );
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'Authorization: Bearer ' . $accessToken,
                    'timeout' => 10,
                ],
            ]);

            $content = @file_get_contents($userinfoEndpoint, false, $context);
            if ($content === false) {
                return new AuthResult(
                    success: false,
                    error: 'userinfo_request_failed',
                );
            }

            $userinfo = json_decode($content, true);
            if (!is_array($userinfo)) {
                return new AuthResult(
                    success: false,
                    error: 'invalid_userinfo_response',
                );
            }

            $sub = $userinfo['sub'] ?? null;
            if ($sub === null || !is_string($sub)) {
                return new AuthResult(
                    success: false,
                    error: 'missing_sub_in_userinfo',
                );
            }

            $externalId = 'oidc.' . $sub;

            /** @var array<string, mixed> $userinfo */
            $attributes = [
                'email' => $userinfo['email'] ?? null,
                'name' => $userinfo['name'] ?? null,
                'avatarUrl' => $userinfo['picture'] ?? null,
                'provider' => 'oidc',
            ];

            return new AuthResult(
                success: true,
                externalId: $externalId,
                attributes: $attributes,
            );
        } catch (OidcValidationException $e) {
            return new AuthResult(
                success: false,
                error: 'token_validation_failed: ' . $e->getMessage(),
            );
        } catch (RuntimeException $e) {
            return new AuthResult(
                success: false,
                error: 'auth_error: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @param string $code
     * @param string $redirectUri
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    private function exchangeCode(string $code, string $redirectUri): array
    {
        $tokenEndpoint = $this->discovery->tokenEndpoint();

        $postData = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                'content' => $postData,
                'timeout' => 10,
            ],
        ]);

        $content = @file_get_contents($tokenEndpoint, false, $context);
        if ($content === false) {
            throw new RuntimeException('Failed to connect to token endpoint: ' . $tokenEndpoint);
        }

        $response = json_decode($content, true);
        if (!is_array($response)) {
            throw new RuntimeException('Invalid token response');
        }

        if (isset($response['error'])) {
            $error = is_string($response['error']) ? $response['error'] : 'unknown_error';
            $errorDesc = is_string($response['error_description'] ?? null) ? $response['error_description'] : '';
            throw new RuntimeException("Token endpoint error: {$error} - {$errorDesc}");
        }

        /** @var array<string, mixed> */
        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserInfo(string $externalId): ?UserInfo
    {
        if (!str_starts_with($externalId, 'oidc.')) {
            return null;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function linkAccount(string $localUserId, array $externalIds): void
    {
    }

    /**
     * Get the discovery document.
     *
     * @return DiscoveryDocument
     */
    public function getDiscovery(): DiscoveryDocument
    {
        return $this->discovery;
    }

    /**
     * Get the client ID.
     *
     * @return string
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Create an ID token validator.
     *
     * @return IdTokenValidator
     */
    private function createValidator(): IdTokenValidator
    {
        $jwks = $this->getJwks();

        return new IdTokenValidator($this->discovery, $jwks);
    }

    /**
     * Get or fetch the JWKS.
     *
     * @return JWKSet
     */
    private function getJwks(): JWKSet
    {
        if ($this->jwkSet === null) {
            $this->jwkSet = IdTokenValidator::fetchJwks($this->discovery);
        }
        return $this->jwkSet;
    }

    /**
     * Build the authorization URL.
     *
     * @param string $redirectUri
     * @param string|null $state
     * @param string|null $nonce
     * @return string
     */
    public function buildAuthorizationUrl(string $redirectUri, ?string $state = null, ?string $nonce = null): string
    {
        $authEndpoint = $this->discovery->authorizationEndpoint();
        $nonceValue = $nonce ?? bin2hex(random_bytes(16));

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes,
            'state' => $state,
            'nonce' => $nonceValue,
        ]);

        return $authEndpoint . '?' . $params;
    }
}
