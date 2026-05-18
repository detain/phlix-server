<?php

declare(strict_types=1);

namespace Phlex\Plugins\Oidc\Controller;

use Phlex\Plugins\Oidc\OidcProvider;
use Phlex\Plugins\Oidc\OidcStateStore;
use Phlex\Plugins\Oidc\SessionOidcStateStore;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Auth\AuthProviderRegistry;
use Phlex\Auth\JwtHandler;
use Phlex\Auth\UserRepository;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

/**
 * Handles OIDC authentication callback endpoints.
 *
 * Routes:
 * - GET  /auth/oidc/authorize  → redirect to provider authorization endpoint
 * - GET  /auth/oidc/callback   → handle callback from provider
 *
 * @package Phlex\Plugins\Oidc\Controller
 * @since 0.11.0
 */
final class OidcCallbackController
{
    private AuthProviderRegistry $registry;
    private UserRepository $userRepository;
    private JwtHandler $jwtHandler;
    private OidcStateStore $stateStore;

    public function __construct(
        AuthProviderRegistry $registry,
        UserRepository $userRepository,
        JwtHandler $jwtHandler,
        ?OidcStateStore $stateStore = null,
    ) {
        $this->registry = $registry;
        $this->userRepository = $userRepository;
        $this->jwtHandler = $jwtHandler;
        $this->stateStore = $stateStore ?? new SessionOidcStateStore();
    }

    /**
     * Handle /auth/oidc/authorize
     *
     * Redirects the user to the OIDC provider's authorization endpoint.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function authorize(Request $request, array $params): Response
    {
        $query = $request->query;

        $redirectUri = is_string($query['redirect_uri'] ?? null) ? $query['redirect_uri'] : '';
        if ($redirectUri === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_redirect_uri',
                'message' => 'redirect_uri query parameter is required',
            ]);
        }

        if (!$this->registry->hasProvider('oidc')) {
            return (new Response())->status(503)->json([
                'error' => 'provider_not_configured',
                'message' => 'OIDC provider is not enabled',
            ]);
        }

        $provider = $this->registry->getProvider('oidc');
        if (!$provider instanceof OidcProvider) {
            return (new Response())->status(503)->json([
                'error' => 'invalid_provider_type',
                'message' => 'OIDC provider is not an OidcProvider instance',
            ]);
        }

        // RFC 7636 PKCE — generate a per-request code_verifier (S256).
        $codeVerifier = OidcProvider::generateCodeVerifier();
        $codeChallenge = OidcProvider::computeCodeChallenge($codeVerifier);

        // Cryptographically-random CSRF state + ID-token replay nonce.
        $nonce = bin2hex(random_bytes(16));
        $stateId = bin2hex(random_bytes(16));

        // Bundle the redirect_uri into the state envelope so the
        // callback knows where to land. The opaque `sid` is the lookup
        // key for the server-side store that holds the code_verifier.
        $stateData = [
            'sid' => $stateId,
            'redirect_uri' => $redirectUri,
        ];
        $stateValue = base64_encode((string) json_encode($stateData));

        $this->stateStore->put($stateId, $codeVerifier, $nonce);

        $authorizationUrl = $provider->buildAuthorizationUrl(
            $this->getCallbackUrl(),
            $stateValue,
            $nonce,
            $codeChallenge,
        );

        return (new Response())
            ->status(302)
            ->header('Location', $authorizationUrl);
    }

    /**
     * Handle /auth/oidc/callback
     *
     * Exchanges the authorization code for tokens, validates the ID token,
     * and creates or updates the local user account.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function callback(Request $request, array $params): Response
    {
        $query = $request->query;

        $code = is_string($query['code'] ?? null) ? $query['code'] : null;
        $state = is_string($query['state'] ?? null) ? $query['state'] : null;
        $error = is_string($query['error'] ?? null) ? $query['error'] : null;

        if ($error !== null) {
            $errorDescription = is_string($query['error_description'] ?? null)
                ? $query['error_description']
                : 'Authorization failed';
            return (new Response())->status(400)->json([
                'error' => $error,
                'message' => $errorDescription,
            ]);
        }

        if ($code === null) {
            return (new Response())->status(400)->json([
                'error' => 'missing_code',
                'message' => 'Authorization code is required',
            ]);
        }

        if ($state === null) {
            return (new Response())->status(400)->json([
                'error' => 'missing_state',
                'message' => 'State parameter is required',
            ]);
        }

        $stateDecoded = base64_decode($state, true);
        if ($stateDecoded === false || !is_array(json_decode($stateDecoded, true))) {
            return (new Response())->status(400)->json([
                'error' => 'invalid_state',
                'message' => 'State parameter is invalid',
            ]);
        }

        /** @var array<string, mixed> */
        $stateArray = json_decode($stateDecoded, true);
        $redirectUri = is_string($stateArray['redirect_uri'] ?? null) ? $stateArray['redirect_uri'] : '';
        $stateId = is_string($stateArray['sid'] ?? null) ? $stateArray['sid'] : '';

        if ($stateId === '') {
            return (new Response())->status(403)->json([
                'error' => 'invalid_state',
                'message' => 'State parameter is missing the session identifier',
            ]);
        }

        // One-shot lookup of the PKCE verifier + nonce that were issued
        // alongside this state value. A missing or replayed entry is a
        // CSRF / replay attempt — reject with 403.
        $stored = $this->stateStore->consume($stateId);
        if ($stored === null) {
            LoggerFactory::get(LogChannels::AUTH)->warning('OIDC state mismatch', [
                'sid' => $stateId,
            ]);
            return (new Response())->status(403)->json([
                'error' => 'invalid_state',
                'message' => 'State parameter does not match an issued request',
            ]);
        }

        $codeVerifier = $stored['code_verifier'];
        $expectedNonce = $stored['nonce'];

        if (!$this->registry->hasProvider('oidc')) {
            return (new Response())->status(503)->json([
                'error' => 'provider_not_configured',
                'message' => 'OIDC provider is not enabled',
            ]);
        }

        try {
            $provider = $this->registry->getProvider('oidc');
            if (!$provider instanceof OidcProvider) {
                throw new \RuntimeException('OIDC provider is not configured correctly');
            }

            $result = $provider->authenticate([
                'code' => $code,
                'redirect_uri' => $this->getCallbackUrl(),
                'nonce' => $expectedNonce,
                'code_verifier' => $codeVerifier,
            ]);

            if ($result->isFailure()) {
                $errorValue = is_string($result->error) ? $result->error : 'auth_failed';
                $redirectUrl = $redirectUri . '?error=' . urlencode($errorValue);
                return (new Response())->status(302)->header('Location', $redirectUrl);
            }

            $userId = $result->userId;
            $externalId = is_string($result->externalId) ? $result->externalId : '';
            $email = $result->getEmail();
            $displayName = $result->getDisplayName();

            if ($userId === null) {
                $userId = $this->userRepository->findOrCreateByExternalId(
                    $externalId,
                    $email,
                    $displayName,
                );
            }

            $tokens = $this->createTokensForUser($userId);

            $queryParams = http_build_query([
                'token' => $tokens['access_token'],
                'refresh' => $tokens['refresh_token'],
            ]);
            $redirectUrl = $redirectUri === '' ? '/?' . $queryParams : $redirectUri . '?' . $queryParams;

            return (new Response())->status(302)->header('Location', $redirectUrl);
        } catch (\Throwable $e) {
            LoggerFactory::get(LogChannels::AUTH)->error('OIDC callback failed', [
                'error' => $e->getMessage(),
            ]);

            $redirectUrl = $redirectUri === '' ? '/?error=internal' : $redirectUri . '?error=internal';
            return (new Response())->status(302)->header('Location', $redirectUrl);
        }
    }

    /**
     * Get the callback URL for this server.
     *
     * @return string
     */
    private function getCallbackUrl(): string
    {
        return '/auth/oidc/callback';
    }

    /**
     * Create tokens for a user after OIDC authentication.
     *
     * @param string $userId
     * @return array<string, string>
     */
    private function createTokensForUser(string $userId): array
    {
        $accessToken = $this->jwtHandler->createAccessToken($userId);
        $refreshToken = $this->jwtHandler->createRefreshToken($userId);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
    }
}
