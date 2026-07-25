<?php

/**
 * Phlix media server component: Github.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Github;

use Phlix\Plugins\OAuth2\AbstractOAuth2Provider;
use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;
use Phlix\Shared\Auth\UserInfo;
use RuntimeException;

/**
 * GitHub OAuth2 authentication provider (plain OAuth2 — NOT OIDC).
 *
 * The first concrete non-OIDC provider on the S46/S47 foundation (S48). GitHub
 * has no OIDC discovery document and issues no `id_token`, so this provider:
 *
 *  1. builds the authorize redirect + exchanges the code via the shared
 *     {@see AbstractOAuth2Provider} base (hardcoded GitHub URLs);
 *  2. calls the REST profile endpoint `GET https://api.github.com/user` with the
 *     access token to identify the user (GitHub requires a `User-Agent` and the
 *     `application/vnd.github+json` Accept header).
 *
 * The external id is `github.<numeric user id>` — GitHub's numeric account id is
 * STABLE, unlike the `login` name which a user can change. Email may be null when
 * the user keeps it private; {@see fetchPrimaryEmail()} makes a best-effort
 * follow-up to `/user/emails` and, failing that, the S44 create path tolerates a
 * null email (it derives a deterministic placeholder).
 *
 * All GitHub URLs are hardcoded constants (admin config only supplies the client
 * id/secret/scopes), so there is no user-influenced URL and no SSRF surface. All
 * HTTP is non-blocking with TLS verification on ({@see \Phlix\Plugins\OAuth2\OAuth2HttpClient}).
 *
 * @package Phlix\Plugins\Github
 * @since 0.102.0
 */
final class GithubOAuthProvider extends AbstractOAuth2Provider implements ProviderInterface
{
    /** GitHub OAuth2 authorize endpoint (web application flow). */
    public const string AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

    /** GitHub OAuth2 token endpoint. */
    public const string TOKEN_URL = 'https://github.com/login/oauth/access_token';

    /** GitHub REST profile endpoint. */
    public const string USER_API_URL = 'https://api.github.com/user';

    /** GitHub REST email-list endpoint (needs the `user:email` scope). */
    public const string USER_EMAILS_API_URL = 'https://api.github.com/user/emails';

    /** Default scopes: read profile + read the account's email addresses. */
    public const string DEFAULT_SCOPES = 'read:user user:email';

    /** Required GitHub API request headers. */
    private const string USER_AGENT = 'Phlix';
    private const string API_VERSION = '2022-11-28';

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'github';
    }

    protected function authorizationEndpoint(): string
    {
        return self::AUTHORIZE_URL;
    }

    protected function tokenEndpoint(): string
    {
        return self::TOKEN_URL;
    }

    /**
     * {@inheritdoc}
     *
     * ONLY an authorization `code` is accepted. A caller-supplied `access_token`
     * is deliberately NOT a credential (review r1 Finding 8): `GET
     * api.github.com/user` happily accepts a token minted for ANY other OAuth
     * app, so honouring one would let whoever holds such a token impersonate that
     * GitHub user — an OAuth token-substitution hole. The token this provider uses
     * is only ever the one IT exchanged the code for, inside
     * {@see authenticateWithCode()}.
     */
    public function supportsAuthentication(array $credentials): bool
    {
        return isset($credentials['code']);
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(array $credentials): AuthResult
    {
        if (isset($credentials['code'])) {
            return $this->authenticateWithCode($credentials);
        }

        return new AuthResult(success: false, error: 'no_supported_credentials');
    }

    /**
     * Exchange the authorization code, then fetch the profile.
     *
     * @param array<string, mixed> $credentials Must contain `code`; `redirect_uri`
     *                                          and `code_verifier` are threaded
     *                                          from the callback controller.
     */
    private function authenticateWithCode(array $credentials): AuthResult
    {
        $code = is_string($credentials['code'] ?? null) ? $credentials['code'] : '';
        $redirectUri = is_string($credentials['redirect_uri'] ?? null) ? $credentials['redirect_uri'] : '';
        $codeVerifier = is_string($credentials['code_verifier'] ?? null) ? $credentials['code_verifier'] : '';

        try {
            $tokenResponse = $this->exchangeCode($code, $redirectUri, $codeVerifier);
        } catch (RuntimeException $e) {
            return new AuthResult(success: false, error: 'auth_error: ' . $e->getMessage());
        }

        $accessToken = is_string($tokenResponse['access_token'] ?? null) ? $tokenResponse['access_token'] : '';
        if ($accessToken === '') {
            return new AuthResult(success: false, error: 'missing_access_token');
        }

        return $this->fetchProfile($accessToken);
    }

    /**
     * Fetch the GitHub profile with the access token and map it to an AuthResult.
     *
     * Callback-internal ONLY: the token must be one this provider just exchanged
     * a code for (see {@see supportsAuthentication()} on why a caller-supplied
     * token is never accepted).
     */
    private function fetchProfile(string $accessToken): AuthResult
    {
        if ($accessToken === '') {
            return new AuthResult(success: false, error: 'missing_access_token');
        }

        $response = $this->httpClient->get(self::USER_API_URL, $this->apiHeaders($accessToken));
        if ($response === null) {
            return new AuthResult(success: false, error: 'profile_request_failed');
        }

        /** @var mixed $profile */
        $profile = json_decode((string) $response->getBody(), true);
        if (!is_array($profile)) {
            return new AuthResult(success: false, error: 'invalid_profile_response');
        }

        // The GitHub numeric account id is stable; the login name can change.
        $githubId = $this->extractUserId($profile['id'] ?? null);
        if ($githubId === '') {
            return new AuthResult(success: false, error: 'missing_user_id');
        }
        $externalId = 'github.' . $githubId;

        $email = is_string($profile['email'] ?? null) && $profile['email'] !== '' ? $profile['email'] : null;
        if ($email === null) {
            // GitHub returns null when the primary email is private — best-effort
            // fallback to the email-list endpoint (needs the user:email scope).
            $email = $this->fetchPrimaryEmail($accessToken);
        }

        $login = is_string($profile['login'] ?? null) && $profile['login'] !== '' ? $profile['login'] : null;
        $name = is_string($profile['name'] ?? null) && $profile['name'] !== '' ? $profile['name'] : $login;
        $avatarUrl = is_string($profile['avatar_url'] ?? null) && $profile['avatar_url'] !== ''
            ? $profile['avatar_url']
            : null;

        return new AuthResult(
            success: true,
            externalId: $externalId,
            attributes: [
                'email' => $email,
                'name' => $name,
                'avatarUrl' => $avatarUrl,
                'login' => $login,
                'provider' => 'github',
            ],
        );
    }

    /**
     * Coerce the GitHub `id` field (a JSON number, possibly a numeric string) to a
     * decimal-digit string, or '' when it is neither.
     *
     * @param mixed $idRaw The raw `id` value from the profile JSON.
     */
    private function extractUserId(mixed $idRaw): string
    {
        if (is_int($idRaw)) {
            return (string) $idRaw;
        }
        if (is_string($idRaw) && $idRaw !== '' && ctype_digit($idRaw)) {
            return $idRaw;
        }

        return '';
    }

    /**
     * Best-effort lookup of the account's primary, verified email when the
     * profile's `email` is private/null. Never throws and never fails the login —
     * returns null on any error so the caller falls back to a placeholder email.
     */
    private function fetchPrimaryEmail(string $accessToken): ?string
    {
        try {
            $response = $this->httpClient->get(self::USER_EMAILS_API_URL, $this->apiHeaders($accessToken));
        } catch (\Throwable) {
            return null;
        }
        if ($response === null) {
            return null;
        }

        /** @var mixed $emails */
        $emails = json_decode((string) $response->getBody(), true);
        if (!is_array($emails)) {
            return null;
        }

        $verifiedFallback = null;
        foreach ($emails as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $address = is_string($entry['email'] ?? null) ? $entry['email'] : '';
            if ($address === '') {
                continue;
            }
            $verified = ($entry['verified'] ?? false) === true;
            $primary = ($entry['primary'] ?? false) === true;
            if ($primary && $verified) {
                return $address;
            }
            if ($verifiedFallback === null && $verified) {
                $verifiedFallback = $address;
            }
        }

        return $verifiedFallback;
    }

    /**
     * The headers GitHub's REST API requires for a token-authenticated request.
     *
     * @return array<string, string>
     */
    private function apiHeaders(string $accessToken): array
    {
        return [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => self::USER_AGENT,
            'X-GitHub-Api-Version' => self::API_VERSION,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUserInfo(string $externalId): ?UserInfo
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function linkAccount(string $localUserId, array $externalIds): void
    {
    }
}
