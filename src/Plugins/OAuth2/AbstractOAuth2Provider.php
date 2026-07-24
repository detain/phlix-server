<?php

/**
 * Phlix media server component: OAuth2.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\OAuth2;

use Phlix\Shared\Auth\ProviderInterface;
use RuntimeException;

/**
 * Shared base for plain-OAuth2 authorization-code providers.
 *
 * Factors the common OAuth2 authorization-code + PKCE machinery that a concrete
 * provider (e.g. {@see \Phlix\Plugins\Github\GithubOAuthProvider}) would
 * otherwise duplicate:
 *
 *  - {@see buildAuthorizationUrl()} — the redirect to the provider's authorize
 *    endpoint, with optional RFC 7636 S256 `code_challenge`;
 *  - {@see exchangeCode()} — the `grant_type=authorization_code` token exchange,
 *    performed over the non-blocking {@see OAuth2HttpClient}.
 *
 * A subclass supplies only the endpoint URLs ({@see authorizationEndpoint()} /
 * {@see tokenEndpoint()}), its family {@see name()}, and how a token maps to an
 * {@see \Phlix\Shared\Auth\AuthResult} ({@see authenticate()}).
 *
 * PKCE is delegated to {@see Pkce} (shared with OIDC). This base is deliberately
 * NOT OIDC-aware: it has no discovery, no `id_token`, and no nonce — those are
 * OIDC concerns that stay on {@see \Phlix\Plugins\Oidc\OidcProvider} (left
 * untouched by S48 so its tests stay green).
 *
 * @package Phlix\Plugins\OAuth2
 * @since 0.102.0
 */
abstract class AbstractOAuth2Provider implements ProviderInterface
{
    protected OAuth2HttpClient $httpClient;

    /**
     * @param string                $clientId     OAuth2 client id.
     * @param string                $clientSecret OAuth2 client secret.
     * @param string                $scopes       Space-separated scope list.
     * @param OAuth2HttpClient|null $httpClient   Injected for tests; a real
     *                                            non-blocking client is created
     *                                            when omitted.
     */
    public function __construct(
        protected readonly string $clientId,
        protected readonly string $clientSecret,
        protected readonly string $scopes,
        ?OAuth2HttpClient $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new OAuth2HttpClient();
    }

    /**
     * The provider's authorization endpoint (where the browser is redirected).
     */
    abstract protected function authorizationEndpoint(): string;

    /**
     * The provider's token endpoint (where the code is exchanged).
     */
    abstract protected function tokenEndpoint(): string;

    /**
     * The configured OAuth2 client id.
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Build the authorization-code redirect URL.
     *
     * @param string      $redirectUri   The provider callback URL.
     * @param string|null $state         CSRF protection state token.
     * @param string|null $codeChallenge RFC 7636 S256 challenge. When supplied the
     *                                   URL includes `code_challenge` and
     *                                   `code_challenge_method=S256`.
     */
    public function buildAuthorizationUrl(
        string $redirectUri,
        ?string $state = null,
        ?string $codeChallenge = null,
    ): string {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes,
        ];
        if ($state !== null) {
            $params['state'] = $state;
        }
        if ($codeChallenge !== null && $codeChallenge !== '') {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return $this->authorizationEndpoint() . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for the provider's token response.
     *
     * Non-blocking (yields to the event loop) via {@see OAuth2HttpClient}; TLS
     * verification is enforced there. `Accept: application/json` is sent so
     * providers that default to a form-encoded token body (GitHub) return JSON.
     *
     * @param string $code         The authorization code from the callback.
     * @param string $redirectUri  The callback URL (must match the authorize step).
     * @param string $codeVerifier RFC 7636 PKCE verifier; empty disables PKCE.
     *
     * @return array<string, mixed> The decoded token response.
     *
     * @throws RuntimeException On transport failure, an invalid body, or an
     *                          `error` field in the token response.
     */
    protected function exchangeCode(string $code, string $redirectUri, string $codeVerifier = ''): array
    {
        $postParams = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
        if ($codeVerifier !== '') {
            $postParams['code_verifier'] = $codeVerifier;
        }

        $response = $this->httpClient->post(
            $this->tokenEndpoint(),
            http_build_query($postParams),
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
        );
        if ($response === null) {
            throw new RuntimeException('Failed to connect to token endpoint: ' . $this->tokenEndpoint());
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid token response from ' . $this->tokenEndpoint());
        }

        if (isset($decoded['error'])) {
            $error = is_string($decoded['error']) ? $decoded['error'] : 'unknown_error';
            $errorDesc = is_string($decoded['error_description'] ?? null) ? $decoded['error_description'] : '';
            throw new RuntimeException("Token endpoint error: {$error} - {$errorDesc}");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
