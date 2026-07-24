<?php

/**
 * Phlix media server component: Controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Github\Controller;

use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\OAuth2\DbOAuth2StateStore;
use Phlix\Plugins\OAuth2\InMemoryOAuth2StateStore;
use Phlix\Plugins\OAuth2\OAuth2StateStore;
use Phlix\Plugins\OAuth2\Pkce;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Shared\Auth\AuthResult;
use Workerman\MySQL\Connection;

/**
 * Handles the GitHub OAuth2 login + account-link browser flow.
 *
 * Routes:
 * - GET /auth/github/authorize        → redirect to GitHub's authorize endpoint (login)
 * - GET /auth/github/callback         → handle GitHub's redirect (login OR link)
 * - GET /auth/identities/link/github  → start a link (authenticated; {@see authorizeLink()})
 *
 * The login flow is hardened to the SAME level as the OIDC callback (S44/S45):
 *  - CSRF/replay protection via a one-shot server-side {@see OAuth2StateStore}
 *    (PKCE `code_verifier` kept server-side, keyed by an opaque `sid`);
 *  - a same-origin redirect ALLOWLIST on the return target ({@see isSafeRedirectTarget()});
 *  - session delivery as HttpOnly+Secure cookies, never tokens in the URL;
 *  - provider-scoped account resolution
 *    ({@see UserRepository::findOrCreateByExternalId()} with provider `github`);
 *  - request-path self-heal of the per-worker provider registry.
 *
 * The account-link branch mirrors the OIDC one: `link_user_id` comes ONLY from
 * the server-side state context (written by the authenticated {@see authorizeLink()}),
 * and the linked identity is ONLY the GitHub-verified `github.<id>` — never a
 * client-supplied value.
 *
 * @package Phlix\Plugins\Github\Controller
 * @since 0.102.0
 */
final class GithubCallbackController
{
    private AuthProviderRegistry $registry;
    private UserRepository $userRepository;
    private JwtHandler $jwtHandler;
    private OAuth2StateStore $stateStore;
    private ?AuthProviderBootstrapper $bootstrapper;
    private ?UserIdentityRepository $identities;

    /** Provider family + default single-instance sentinel for GitHub. */
    private const string GITHUB_PROVIDER = 'github';
    private const string DEFAULT_INSTANCE = '';

    /** The state-context `intent` marker for an account-link authorize flow. */
    private const string LINK_INTENT = 'link';

    /** Safe same-origin fallback when a link flow omits redirect_uri. */
    private const string DEFAULT_LINK_REDIRECT = '/app';

    public function __construct(
        AuthProviderRegistry $registry,
        UserRepository $userRepository,
        JwtHandler $jwtHandler,
        ?OAuth2StateStore $stateStore = null,
        ?Connection $db = null,
        ?AuthProviderBootstrapper $bootstrapper = null,
        ?UserIdentityRepository $identities = null,
    ) {
        $this->registry = $registry;
        $this->userRepository = $userRepository;
        $this->jwtHandler = $jwtHandler;
        $this->bootstrapper = $bootstrapper;
        $this->identities = $identities;

        if ($stateStore !== null) {
            $this->stateStore = $stateStore;
        } elseif ($db !== null) {
            $this->stateStore = new DbOAuth2StateStore($db, self::GITHUB_PROVIDER);
        } else {
            // Test-only fallback. Production DI injects Connection so the shared
            // oauth_state_store table is used (see AuthServicesProvider).
            $this->stateStore = new InMemoryOAuth2StateStore();
        }
    }

    /**
     * The registry INSTANCE KEY for the built-in GitHub provider (default
     * instance → the family name verbatim, `github`).
     */
    private static function githubInstanceKey(): string
    {
        return AuthProviderRegistry::instanceKey(self::GITHUB_PROVIDER, self::DEFAULT_INSTANCE);
    }

    /**
     * GET /auth/github/authorize — begin the login flow.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function authorize(Request $request, array $params): Response
    {
        return $this->beginAuthorization($request, null);
    }

    /**
     * GET /auth/identities/link/github (AUTHENTICATED) — begin a link flow.
     *
     * The initiating user id is read from the validated session ({@see Request::$userId},
     * gated by AuthMiddleware) and bound into the SERVER-SIDE state context — never
     * the client-visible `state` — so a client can neither forge nor swap it. This
     * is the defence against linking an external identity onto a victim's account.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function authorizeLink(Request $request, array $params): Response
    {
        $userId = $request->userId;
        if (!is_string($userId) || $userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        return $this->beginAuthorization($request, [
            'intent' => self::LINK_INTENT,
            'link_user_id' => $userId,
        ]);
    }

    /**
     * Shared authorize machinery for the login + link flows.
     *
     * @param array<string, mixed>|null $linkContext Server-side link envelope, or
     *                                              null for a plain login.
     */
    private function beginAuthorization(Request $request, ?array $linkContext): Response
    {
        $query = $request->query;
        $isLink = $linkContext !== null;

        $redirectUri = is_string($query['redirect_uri'] ?? null) ? $query['redirect_uri'] : '';
        if ($redirectUri === '' && $isLink) {
            $redirectUri = self::DEFAULT_LINK_REDIRECT;
        }
        if ($redirectUri === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_redirect_uri',
                'message' => 'redirect_uri query parameter is required',
            ]);
        }

        // Allowlist the return target BEFORE it is bound into the state envelope:
        // the callback mints a real session, so a foreign origin here would be an
        // account-takeover phishing vector.
        if (!self::isSafeRedirectTarget($redirectUri)) {
            return (new Response())->status(400)->json([
                'error' => 'invalid_redirect_uri',
                'message' => 'redirect_uri must be a same-origin relative path',
            ]);
        }

        // Reconcile THIS worker's registry with the persisted `auth.github.enabled`
        // flag (settings-only, no network I/O), so a worker that booted before
        // GitHub was enabled doesn't 503 here.
        $this->bootstrapper?->ensureProviderRegistered(AuthProviderBootstrapper::GITHUB);

        $provider = $this->resolveProvider();
        if ($provider === null) {
            return (new Response())->status(503)->json([
                'error' => 'provider_not_configured',
                'message' => 'GitHub provider is not enabled',
            ]);
        }

        // RFC 7636 PKCE — generated per request (GitHub ignores it today, but it
        // is harmless and future-proof); the CSRF `state` is the real protection.
        $codeVerifier = Pkce::generateCodeVerifier();
        $codeChallenge = Pkce::computeCodeChallenge($codeVerifier);

        $stateId = bin2hex(random_bytes(16));
        $stateData = [
            'sid' => $stateId,
            'redirect_uri' => $redirectUri,
        ];
        $stateValue = base64_encode((string) json_encode($stateData));

        $this->stateStore->put($stateId, $codeVerifier, $linkContext);

        $authorizationUrl = $provider->buildAuthorizationUrl(
            $this->getCallbackUrl(),
            $stateValue,
            $codeChallenge,
        );

        return (new Response())->status(302)->header('Location', $authorizationUrl);
    }

    /**
     * GET /auth/github/callback — exchange the code and log in (or link).
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

        /** @var array<string, mixed> $stateArray */
        $stateArray = json_decode($stateDecoded, true);
        $redirectUri = is_string($stateArray['redirect_uri'] ?? null) ? $stateArray['redirect_uri'] : '';
        $stateId = is_string($stateArray['sid'] ?? null) ? $stateArray['sid'] : '';

        // Re-validate the return target read back from the state envelope before it
        // is used in ANY redirect below.
        if (!self::isSafeRedirectTarget($redirectUri)) {
            return (new Response())->status(400)->json([
                'error' => 'invalid_redirect_uri',
                'message' => 'redirect_uri must be a same-origin relative path',
            ]);
        }

        if ($stateId === '') {
            return (new Response())->status(403)->json([
                'error' => 'invalid_state',
                'message' => 'State parameter is missing the session identifier',
            ]);
        }

        // One-shot consume of the PKCE verifier issued alongside this state. A
        // missing/replayed entry is a CSRF/replay attempt → 403.
        $stored = $this->stateStore->consume($stateId);
        if ($stored === null) {
            LoggerFactory::get(LogChannels::AUTH)->warning('GitHub state mismatch', ['sid' => $stateId]);
            return (new Response())->status(403)->json([
                'error' => 'invalid_state',
                'message' => 'State parameter does not match an issued request',
            ]);
        }

        $codeVerifier = $stored['code_verifier'];
        $context = isset($stored['context']) && is_array($stored['context']) ? $stored['context'] : [];
        $isLinkFlow = ($context['intent'] ?? null) === self::LINK_INTENT;

        // Request-path self-heal (see beginAuthorization()).
        $this->bootstrapper?->ensureProviderRegistered(AuthProviderBootstrapper::GITHUB);

        $provider = $this->resolveProvider();
        if ($provider === null) {
            return (new Response())->status(503)->json([
                'error' => 'provider_not_configured',
                'message' => 'GitHub provider is not enabled',
            ]);
        }

        try {
            $result = $provider->authenticate([
                'code' => $code,
                'redirect_uri' => $this->getCallbackUrl(),
                'code_verifier' => $codeVerifier,
            ]);

            if ($result->isFailure()) {
                $errorValue = is_string($result->error) ? $result->error : 'auth_failed';
                $redirectUrl = $redirectUri . '?error=' . urlencode($errorValue);
                return (new Response())->status(302)->header('Location', $redirectUrl);
            }

            if ($isLinkFlow) {
                return $this->completeLink($result, $context, $redirectUri);
            }

            $userId = $result->userId;
            $externalId = is_string($result->externalId) ? $result->externalId : '';
            $email = $result->getEmail();
            $displayName = $result->getDisplayName();
            $providerName = is_string($result->attributes['provider'] ?? null)
                ? $result->attributes['provider']
                : self::GITHUB_PROVIDER;

            if ($userId === null) {
                $userId = $this->userRepository->findOrCreateByExternalId(
                    $providerName,
                    $externalId,
                    $email,
                    $displayName,
                );
            }

            $tokens = $this->createTokensForUser($userId);

            // Deliver the session as HttpOnly cookies and 302 to the clean,
            // allowlisted same-origin path with NO token query string.
            $response = (new Response())->status(302)->header('Location', $redirectUri);
            $this->attachAuthCookies($response, $tokens);

            return $response;
        } catch (\Throwable $e) {
            LoggerFactory::get(LogChannels::AUTH)->error('GitHub callback failed', [
                'error' => $e->getMessage(),
            ]);

            $redirectUrl = $redirectUri . '?error=internal';
            return (new Response())->status(302)->header('Location', $redirectUrl);
        }
    }

    /**
     * Resolve THIS worker's registered GitHub provider, or null when it is not
     * registered / of the wrong type.
     */
    private function resolveProvider(): ?GithubOAuthProvider
    {
        if (!$this->registry->hasProvider(self::githubInstanceKey())) {
            return null;
        }
        $provider = $this->registry->getProvider(self::githubInstanceKey());

        return $provider instanceof GithubOAuthProvider ? $provider : null;
    }

    /**
     * Persist a verified GitHub identity as a link on the initiating user's
     * account, then 302 back to the same-origin app path. Same invariants +
     * conflict handling as the OIDC link branch.
     *
     * @param array<string, mixed> $context Server-side link context.
     */
    private function completeLink(AuthResult $result, array $context, string $redirectUri): Response
    {
        $linkUserId = is_string($context['link_user_id'] ?? null) ? $context['link_user_id'] : '';
        if ($linkUserId === '') {
            return (new Response())->status(400)->json([
                'error' => 'invalid_link_state',
                'message' => 'Link request is missing the initiating user',
            ]);
        }

        if ($this->identities === null) {
            LoggerFactory::get(LogChannels::AUTH)->error('GitHub link attempted without identity repository');
            return (new Response())->status(503)->json([
                'error' => 'link_unavailable',
                'message' => 'Account linking is not available',
            ]);
        }

        $externalId = is_string($result->externalId) ? $result->externalId : '';
        if ($externalId === '') {
            return (new Response())->status(400)->json([
                'error' => 'invalid_identity',
                'message' => 'Provider did not return a verified identity',
            ]);
        }

        $existing = $this->identities->findByProviderExternalId(
            self::GITHUB_PROVIDER,
            self::DEFAULT_INSTANCE,
            $externalId,
        );
        if ($existing !== null) {
            $ownerId = is_string($existing['user_id'] ?? null) ? $existing['user_id'] : '';
            if ($ownerId === $linkUserId) {
                return $this->linkSuccessRedirect($redirectUri);
            }
            return $this->identityConflict();
        }

        $providerData = $this->buildLinkProviderData($result);

        try {
            $this->identities->create(
                $linkUserId,
                self::GITHUB_PROVIDER,
                self::DEFAULT_INSTANCE,
                $externalId,
                $providerData,
            );
        } catch (\Throwable $e) {
            // Classify the failure by re-reading (the row's presence is the
            // authoritative signal — the MySQL wrapper flattens dup-key/FK/NOT-NULL
            // to SQLSTATE 23000 alike).
            $raced = $this->identities->findByProviderExternalId(
                self::GITHUB_PROVIDER,
                self::DEFAULT_INSTANCE,
                $externalId,
            );
            if ($raced === null) {
                LoggerFactory::get(LogChannels::AUTH)->error('GitHub identity link create failed', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
            if (($raced['user_id'] ?? null) === $linkUserId) {
                return $this->linkSuccessRedirect($redirectUri);
            }
            LoggerFactory::get(LogChannels::AUTH)->warning('GitHub identity link conflict', [
                'error' => $e->getMessage(),
            ]);
            return $this->identityConflict();
        }

        return $this->linkSuccessRedirect($redirectUri);
    }

    /**
     * Build the (non-secret) provider metadata stored on a linked identity row.
     *
     * @return array<string, string>|null
     */
    private function buildLinkProviderData(AuthResult $result): ?array
    {
        $data = [];
        $email = $result->getEmail();
        if (is_string($email) && $email !== '') {
            $data['email'] = $email;
        }
        $name = $result->getDisplayName();
        if (is_string($name) && $name !== '') {
            $data['name'] = $name;
        }

        return $data === [] ? null : $data;
    }

    /**
     * 302 back to the allowlisted same-origin path with a `linked=github` marker.
     */
    private function linkSuccessRedirect(string $redirectUri): Response
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $separator . 'linked=' . self::GITHUB_PROVIDER;

        return (new Response())->status(302)->header('Location', $location);
    }

    /**
     * 409 when the verified identity is already linked to a DIFFERENT account.
     */
    private function identityConflict(): Response
    {
        return (new Response())->status(409)->json([
            'error' => 'identity_already_linked',
            'message' => 'This external identity is already linked to another account',
        ]);
    }

    /**
     * The GitHub callback URL for this server.
     */
    private function getCallbackUrl(): string
    {
        return '/auth/github/callback';
    }

    /**
     * Mint an access + refresh token pair for a user.
     *
     * @return array<string, string>
     */
    private function createTokensForUser(string $userId): array
    {
        return [
            'access_token' => $this->jwtHandler->createAccessToken($userId),
            'refresh_token' => $this->jwtHandler->createRefreshToken($userId),
        ];
    }

    /**
     * Attach the minted session as HttpOnly cookies (never URL tokens), matching
     * {@see AuthController::attachAuthCookies()} / the OIDC callback.
     *
     * @param array<string, string> $tokens access_token + refresh_token pair.
     */
    private function attachAuthCookies(Response $response, array $tokens): void
    {
        $secure = getenv('PHLIX_COOKIE_INSECURE') !== '1';

        $response->cookie(
            AuthController::SESSION_COOKIE,
            $tokens['access_token'],
            maxAge: $this->jwtHandler->accessTtl(),
            secure: $secure,
            httpOnly: true,
            sameSite: 'Lax',
        );
        $response->cookie(
            AuthController::REFRESH_COOKIE,
            $tokens['refresh_token'],
            maxAge: $this->jwtHandler->refreshTtl(),
            secure: $secure,
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    /**
     * Same-origin relative-path allowlist for the post-login return target — the
     * same rule the OIDC callback enforces (rejects absolute/scheme-relative URLs
     * and CR/LF header injection).
     */
    private static function isSafeRedirectTarget(string $uri): bool
    {
        if ($uri === '') {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            return false;
        }
        if ($uri[0] !== '/') {
            return false;
        }
        if (str_starts_with($uri, '//') || str_starts_with($uri, '/\\')) {
            return false;
        }

        return true;
    }
}
