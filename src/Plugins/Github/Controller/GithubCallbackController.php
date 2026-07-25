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
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\OAuth2\CallbackUrl;
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
 *  - the issued state is additionally BOUND TO THE INITIATING BROWSER by a
 *    short-lived HttpOnly correlation cookie, so a third party cannot feed a
 *    completed callback to a victim's browser and hand them a session for
 *    someone else's account ({@see attachCorrelationCookie()}, review r1
 *    Finding 2);
 *  - a same-origin redirect ALLOWLIST on the return target ({@see isSafeRedirectTarget()});
 *  - session delivery as HttpOnly+Secure cookies, never tokens in the URL;
 *  - provider-scoped account resolution
 *    ({@see UserRepository::findOrCreateByExternalId()} with provider `github`);
 *  - request-path self-heal of the per-worker provider registry;
 *  - an ABSOLUTE `redirect_uri` ({@see resolveCallbackUrl()}) which is captured
 *    into the server-side state at authorize time and replayed verbatim at token
 *    exchange, so the two values can never disagree (review r1 Finding 1).
 *
 * Error codes handed back to the SPA come from a FIXED internal set
 * ({@see normalizeProviderError()}/{@see classifyAuthFailure()}); provider-supplied
 * error text is never reflected. `error=email_already_registered` means a local
 * account already owns the GitHub e-mail: the user must sign in with that account
 * and link GitHub through `GET /auth/identities/link/github` (we deliberately do
 * NOT match accounts on e-mail — GitHub e-mails are not necessarily verified and
 * that would be an account-takeover primitive).
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

    /**
     * Settings source for the operator-configured absolute `redirect_uri`
     * ({@see resolveCallbackUrl()}). Optional so direct-construction call sites
     * (and unit tests) keep working; production DI binds it.
     */
    private ?GithubPlugin $plugin;

    /** Provider family + default single-instance sentinel for GitHub. */
    private const string GITHUB_PROVIDER = 'github';
    private const string DEFAULT_INSTANCE = '';

    /** The state-context `intent` marker for an account-link authorize flow. */
    private const string LINK_INTENT = 'link';

    /** Safe same-origin fallback when a link flow omits redirect_uri. */
    private const string DEFAULT_LINK_REDIRECT = '/app';

    /** This server's GitHub callback path (the tail of the absolute redirect_uri). */
    private const string CALLBACK_PATH = '/auth/github/callback';

    /**
     * Browser-binding cookie for the issued OAuth state (review r1 Finding 2).
     * HttpOnly + Secure + SameSite=Lax, and short-lived: it only has to survive
     * the round trip to GitHub.
     */
    private const string CORRELATION_COOKIE = 'phlix_oauth_github';
    private const int CORRELATION_TTL_SECONDS = 600;

    /** The state-context key holding the hash of the correlation cookie value. */
    private const string CORRELATION_KEY = 'correlation';

    /** The state-context key holding the authorize-time absolute redirect_uri. */
    private const string CALLBACK_URL_KEY = 'callback_url';

    public function __construct(
        AuthProviderRegistry $registry,
        UserRepository $userRepository,
        JwtHandler $jwtHandler,
        ?OAuth2StateStore $stateStore = null,
        ?Connection $db = null,
        ?AuthProviderBootstrapper $bootstrapper = null,
        ?UserIdentityRepository $identities = null,
        ?GithubPlugin $plugin = null,
    ) {
        $this->registry = $registry;
        $this->userRepository = $userRepository;
        $this->jwtHandler = $jwtHandler;
        $this->bootstrapper = $bootstrapper;
        $this->identities = $identities;
        $this->plugin = $plugin;

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

        // Finding 1 (HIGH) — GitHub matches the redirect_uri's scheme/host/port
        // against the registered OAuth-App callback, so it MUST be absolute. The
        // resolved value is bound into the server-side state below and replayed
        // verbatim at token exchange.
        $callbackUrl = $this->resolveCallbackUrl($request);
        if ($callbackUrl === null) {
            return (new Response())->status(503)->json([
                'error' => 'callback_url_not_configured',
                'message' => 'Set an absolute redirect_uri in the GitHub provider settings',
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

        // Finding 2 (MED) — bind the state to THIS browser. Only the hash of the
        // correlation secret is persisted, so a database read cannot forge a
        // matching cookie.
        $correlation = bin2hex(random_bytes(32));

        $context = $linkContext ?? [];
        $context[self::CORRELATION_KEY] = hash('sha256', $correlation);
        $context[self::CALLBACK_URL_KEY] = $callbackUrl;

        $this->stateStore->put($stateId, $codeVerifier, $context);

        $authorizationUrl = $provider->buildAuthorizationUrl(
            $callbackUrl,
            $stateValue,
            $codeChallenge,
        );

        $response = (new Response())->status(302)->header('Location', $authorizationUrl);
        $this->attachCorrelationCookie($response, $correlation);

        return $response;
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
            // Finding 9 — never reflect provider-supplied error text; map it onto
            // a known internal code instead.
            return (new Response())->status(400)->json([
                'error' => self::normalizeProviderError($error),
                'message' => 'Authorization failed',
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

        // Finding 2 (MED) — the state must have been issued to THIS browser.
        // Without this an attacker could complete the flow with their own GitHub
        // account and then have a victim's browser issue the resulting callback,
        // which would hand the victim a session for the ATTACKER's account
        // (SameSite=Lax does not restrict Set-Cookie).
        if (!self::correlationMatches($request, $context)) {
            LoggerFactory::get(LogChannels::AUTH)->warning('GitHub state not bound to this browser', [
                'sid' => $stateId,
            ]);
            return (new Response())->status(403)->json([
                'error' => 'invalid_state',
                'message' => 'State parameter was not issued to this browser session',
            ]);
        }

        // Finding 1 (HIGH) — replay the EXACT absolute redirect_uri the authorize
        // step sent (GitHub rejects the exchange when the two differ). Falling
        // back to a fresh resolve only matters for a state row issued before this
        // field existed.
        $callbackUrl = is_string($context[self::CALLBACK_URL_KEY] ?? null)
            && $context[self::CALLBACK_URL_KEY] !== ''
                ? $context[self::CALLBACK_URL_KEY]
                : $this->resolveCallbackUrl($request);
        if ($callbackUrl === null) {
            return (new Response())->status(503)->json([
                'error' => 'callback_url_not_configured',
                'message' => 'Set an absolute redirect_uri in the GitHub provider settings',
            ]);
        }

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
                'redirect_uri' => $callbackUrl,
                'code_verifier' => $codeVerifier,
            ]);

            if ($result->isFailure()) {
                return $this->errorRedirect($redirectUri, self::classifyAuthFailure($result->error));
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
                try {
                    $userId = $this->userRepository->findOrCreateByExternalId(
                        $providerName,
                        $externalId,
                        $email,
                        $displayName,
                    );
                } catch (\Throwable $e) {
                    // Finding 4 (MED) — `users.username`/`users.email` are NOT NULL
                    // UNIQUE, so an existing local/OIDC account holding the GitHub
                    // e-mail makes the INSERT fail. Classify it by re-reading (the
                    // row's presence is the authoritative signal) and hand back an
                    // ACTIONABLE code instead of an opaque `internal`, so the user
                    // is told to sign in and link at /auth/identities/link/github.
                    // We never resolve the login onto that account by e-mail.
                    if ($this->emailAlreadyRegistered($email)) {
                        LoggerFactory::get(LogChannels::AUTH)->warning(
                            'GitHub login blocked: e-mail already belongs to a local account',
                            ['external_id' => $externalId],
                        );
                        return $this->errorRedirect($redirectUri, 'email_already_registered');
                    }

                    throw $e;
                }
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

            return $this->errorRedirect($redirectUri, 'internal');
        }
    }

    /**
     * Whether the consumed state was issued to the browser making this callback.
     *
     * The correlation secret is delivered as an HttpOnly cookie at authorize time
     * and only its SHA-256 lives in the state row, so neither a leaked state blob
     * nor a database read lets a third party satisfy this check.
     *
     * @param array<string, mixed> $context The trusted server-side state context.
     */
    private static function correlationMatches(Request $request, array $context): bool
    {
        $expected = is_string($context[self::CORRELATION_KEY] ?? null)
            ? $context[self::CORRELATION_KEY]
            : '';
        if ($expected === '') {
            return false;
        }

        $presented = $request->getCookie(self::CORRELATION_COOKIE);
        if (!is_string($presented) || $presented === '') {
            return false;
        }

        return hash_equals($expected, hash('sha256', $presented));
    }

    /**
     * Attach the short-lived, HttpOnly browser-binding cookie to the authorize
     * redirect. Same Secure/SameSite policy as the session cookies.
     */
    private function attachCorrelationCookie(Response $response, string $correlation): void
    {
        $response->cookie(
            self::CORRELATION_COOKIE,
            $correlation,
            maxAge: self::CORRELATION_TTL_SECONDS,
            secure: getenv('PHLIX_COOKIE_INSECURE') !== '1',
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    /**
     * Whether a local account already owns this e-mail — the classification for
     * Finding 4. Read-only and failure-tolerant: any error here means "not the
     * duplicate case", so the caller re-throws the original create failure.
     */
    private function emailAlreadyRegistered(?string $email): bool
    {
        if (!is_string($email) || $email === '') {
            return false;
        }

        try {
            return $this->userRepository->emailExists($email);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 302 back to the allowlisted same-origin target with a fixed internal error
     * code, using the correct query separator (Finding 9 — the old code appended
     * `'?error='` unconditionally, producing `/app?a=1?error=…`).
     */
    private function errorRedirect(string $redirectUri, string $code): Response
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return (new Response())
            ->status(302)
            ->header('Location', $redirectUri . $separator . 'error=' . urlencode($code));
    }

    /**
     * Map a provider-supplied `error` query value onto the known OAuth2/GitHub
     * code set, or `provider_error` for anything else (Finding 9: the raw value
     * is never reflected back to the client).
     */
    private static function normalizeProviderError(string $error): string
    {
        $known = [
            'access_denied',
            'application_suspended',
            'redirect_uri_mismatch',
            'bad_verification_code',
            'incorrect_client_credentials',
            'unverified_user_email',
            'invalid_scope',
            'unsupported_response_type',
            'invalid_request',
            'server_error',
            'temporarily_unavailable',
        ];

        return in_array(strtolower($error), $known, true) ? strtolower($error) : 'provider_error';
    }

    /**
     * Collapse an {@see AuthResult::$error} (which may embed provider text) onto a
     * fixed internal code for the SPA (Finding 9).
     */
    private static function classifyAuthFailure(?string $error): string
    {
        $error = is_string($error) ? $error : '';

        if (str_starts_with($error, 'auth_error') || $error === 'missing_access_token') {
            return 'token_exchange_failed';
        }
        if (
            $error === 'profile_request_failed'
            || $error === 'invalid_profile_response'
            || $error === 'missing_user_id'
        ) {
            return 'profile_unavailable';
        }

        return 'auth_failed';
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
     * The ABSOLUTE GitHub callback URL to advertise as `redirect_uri`, or null
     * when neither the operator setting nor the request yields one.
     *
     * GitHub compares the value's scheme/host/port with the registered OAuth-App
     * callback URL, so a path-only value (what this returned before review r1
     * Finding 1) always fails with `redirect_uri_mismatch`. Precedence:
     * the operator-configured `redirect_uri` plugin setting, else the request's
     * own scheme+Host. See {@see CallbackUrl}.
     */
    private function resolveCallbackUrl(Request $request): ?string
    {
        $configured = '';
        if ($this->plugin !== null) {
            $settings = $this->plugin->getSettings();
            $configured = is_string($settings['redirect_uri'] ?? null) ? $settings['redirect_uri'] : '';
        }

        return CallbackUrl::resolve(
            $configured,
            $request->getHeader('Host'),
            $request->getHeader('X-Forwarded-Proto'),
            self::CALLBACK_PATH,
        );
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
