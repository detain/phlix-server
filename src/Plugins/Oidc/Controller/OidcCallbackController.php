<?php

/**
 * Phlix media server component: Controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Oidc\Controller;

use Phlix\Plugins\OAuth2\CallbackUrl;
use Phlix\Plugins\OAuth2\StateCorrelation;
use Phlix\Plugins\Oidc\DbOidcStateStore;
use Phlix\Plugins\Oidc\InMemoryOidcStateStore;
use Phlix\Plugins\Oidc\OidcProvider;
use Phlix\Plugins\Oidc\OidcStateStore;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Shared\Auth\AuthResult;
use Workerman\MySQL\Connection;

/**
 * Handles OIDC authentication callback endpoints.
 *
 * Routes:
 * - GET  /auth/oidc/authorize  → redirect to provider authorization endpoint
 * - GET  /auth/oidc/callback   → handle callback from provider
 *
 * ## Browser binding (S48 review r2)
 *
 * The issued `state` is bound to the INITIATING BROWSER by a short-lived HttpOnly
 * correlation cookie ({@see StateCorrelation}, cookie {@see self::CORRELATION_COOKIE}),
 * exactly as the GitHub flow does. Neither PKCE nor the `nonce` provides this:
 * both bind the exchange to an authorize request that, in the session-fixation
 * attack, the ATTACKER owns — so both match for them. Without the cookie an
 * attacker could complete the whole flow with their own IdP account and then have
 * a victim's browser issue the callback, silently handing the victim a session for
 * the attacker's identity. The check runs BEFORE the token exchange, before any
 * user creation, before the link branch and before any session cookie is minted.
 *
 * @package Phlix\Plugins\Oidc\Controller
 * @since 0.11.0
 */
final class OidcCallbackController
{
    private AuthProviderRegistry $registry;
    private UserRepository $userRepository;
    private JwtHandler $jwtHandler;
    private OidcStateStore $stateStore;

    /**
     * Request-path self-heal for the per-worker provider registry (Finding 3).
     * Optional so existing direct-construction call sites (and the tests) keep
     * working; the DI factory binds it explicitly. Null = no self-heal (the
     * registry is used exactly as boot left it).
     */
    private ?AuthProviderBootstrapper $bootstrapper;

    /**
     * S45 account-linking store (migration 092). Optional so existing
     * direct-construction call sites keep working; the DI factory binds it
     * explicitly. When null the OIDC callback's link branch cannot persist a
     * link and refuses with 503 rather than silently no-op'ing — but the plain
     * login flow is unaffected (it never touches this repository).
     */
    private ?UserIdentityRepository $identities;

    /**
     * S48 review r1 Finding 1 — settings source for the operator-configured
     * ABSOLUTE `redirect_uri` ({@see resolveCallbackUrl()}). Optional so existing
     * direct-construction call sites keep working; production DI binds it.
     */
    private ?OidcPlugin $plugin;

    /** This server's OIDC callback path (the tail of the absolute redirect_uri). */
    private const string CALLBACK_PATH = '/auth/oidc/callback';

    /**
     * Browser-binding cookie for the issued OIDC state (review r2). DISTINCT from
     * the GitHub flow's `phlix_oauth_github` so two concurrent flows cannot clobber
     * each other's binding. HttpOnly + Secure + SameSite=Lax, TTL = the state-row
     * TTL ({@see StateCorrelation}).
     */
    private const string CORRELATION_COOKIE = 'phlix_oauth_oidc';

    /** The state-context key holding the authorize-time absolute redirect_uri. */
    private const string CALLBACK_URL_KEY = 'callback_url';

    /**
     * S45: the state-context `intent` marker for an account-link authorize flow.
     * When the consumed state carries this intent the callback links the verified
     * external identity to `link_user_id` instead of minting a login session.
     */
    private const string LINK_INTENT = 'link';

    /** S45: provider family + default single-instance sentinel for OIDC links. */
    private const string OIDC_PROVIDER = 'oidc';
    private const string DEFAULT_INSTANCE = '';

    /**
     * S47: the registry INSTANCE KEY for the built-in OIDC provider. The bundled
     * OIDC login flow uses the family's DEFAULT instance, whose registry key is
     * the family name verbatim ({@see AuthProviderRegistry::instanceKey()} with
     * the '' sentinel), so this resolves to `'oidc'`. Routing to a named instance
     * is an S48 config concern; centralising the key here means the callback no
     * longer hard-codes the literal `'oidc'` in four places.
     */
    private static function oidcInstanceKey(): string
    {
        return AuthProviderRegistry::instanceKey(self::OIDC_PROVIDER, self::DEFAULT_INSTANCE);
    }

    /** S45: safe same-origin fallback when a link flow omits redirect_uri. */
    private const string DEFAULT_LINK_REDIRECT = '/app';

    /**
     * The ACTIONABLE body of the `callback_url_not_configured` 503 (review r3).
     *
     * The flow fails CLOSED whenever no absolute callback URL can be produced, so
     * this text is the operator's only clue — it must name both remedies. The log
     * line beside it says WHICH of the two conditions fired.
     */
    private const string CALLBACK_URL_HELP = 'No OIDC callback URL is available. '
        . 'Set PHLIX_DOMAIN to this server\'s public hostname (scripts/install.sh --domain '
        . '<domain>) so the callback can be derived from the request, or set an absolute '
        . 'redirect_uri (e.g. https://<domain>/auth/oidc/callback) in the OIDC provider '
        . 'settings — it must match a redirect URI registered with the identity provider.';

    public function __construct(
        AuthProviderRegistry $registry,
        UserRepository $userRepository,
        JwtHandler $jwtHandler,
        ?OidcStateStore $stateStore = null,
        ?Connection $db = null,
        ?AuthProviderBootstrapper $bootstrapper = null,
        ?UserIdentityRepository $identities = null,
        ?OidcPlugin $plugin = null,
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
            $this->stateStore = new DbOidcStateStore($db);
        } else {
            // Fallback for test backwards compatibility. In production,
            // DI autowiring injects Connection automatically, so this
            // fallback should never be hit in normal operation.
            $this->stateStore = new InMemoryOidcStateStore();
        }
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
        // Plain login flow — no link context; the callback will mint a session.
        return $this->beginAuthorization($request, null);
    }

    /**
     * Handle GET /auth/identities/link/oidc (AUTHENTICATED — S45).
     *
     * Initiates the SAME OIDC authorization-code + PKCE flow as {@see authorize()}
     * but with the intent to LINK the verified external identity onto the
     * already-logged-in local account, rather than logging a user in.
     *
     * ## Security linchpin
     *
     * The initiating user's id is read from {@see Request::$userId} — populated by
     * the entry point from the validated Bearer token / session cookie and gated
     * by {@see \Phlix\Server\Http\Middleware\AuthMiddleware} on the route — and
     * bound into the SERVER-SIDE state context ({@see OidcStateStore::put()}),
     * NOT into the client-visible `state` query parameter. The callback later
     * reads `link_user_id` back from that server-side store (keyed by the one-shot
     * `sid`), so a client can neither forge nor swap it. This is what prevents an
     * attacker from linking their own external identity onto a victim's account.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function authorizeLink(Request $request, array $params): Response
    {
        // Defence-in-depth: the route sits behind AuthMiddleware, but re-check
        // here so a mis-wired route (or a direct unit-test call) can never begin a
        // link flow without a bound user.
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
     * Shared authorize machinery for both the login ({@see authorize()}) and the
     * account-link ({@see authorizeLink()}) flows.
     *
     * The ONLY difference is `$linkContext`: when non-null it is persisted into
     * the server-side state store (never the client `state`), so the callback can
     * distinguish a link from a login and recover the trusted `link_user_id`.
     *
     * @param Request $request
     * @param array<string, mixed>|null $linkContext Server-side link envelope, or
     *                                              null for a plain login flow.
     * @return Response
     */
    private function beginAuthorization(Request $request, ?array $linkContext): Response
    {
        $query = $request->query;
        $isLink = $linkContext !== null;

        $redirectUri = is_string($query['redirect_uri'] ?? null) ? $query['redirect_uri'] : '';
        // A link flow is initiated from within the SPA by an already-authenticated
        // user, so a missing return target is benign — default to a safe
        // same-origin app path rather than 400. The login flow still requires one.
        if ($redirectUri === '' && $isLink) {
            $redirectUri = self::DEFAULT_LINK_REDIRECT;
        }
        if ($redirectUri === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_redirect_uri',
                'message' => 'redirect_uri query parameter is required',
            ]);
        }

        // Finding 1 (HIGH) — allowlist the return target BEFORE it is bound into
        // the state envelope. The callback mints a real access + refresh token
        // pair; if `redirect_uri` could name a foreign origin an attacker could
        // phish this flow and have the victim's freshly-minted session 302'd to
        // their own host (account takeover). Only a same-origin relative path is
        // accepted (see isSafeRedirectTarget()).
        if (!self::isSafeRedirectTarget($redirectUri)) {
            return (new Response())->status(400)->json([
                'error' => 'invalid_redirect_uri',
                'message' => 'redirect_uri must be a same-origin relative path',
            ]);
        }

        // Finding 3 (MEDIUM) — reconcile THIS worker's registry with the
        // persisted `auth.oidc.enabled` flag on the request path. Without it,
        // right after an admin enables OIDC only the serving worker has the
        // provider; ~(N-1)/N of workers would 503 here until a restart. Reads
        // settings only (no network I/O), same as the boot registration.
        $this->bootstrapper?->ensureProviderRegistered(AuthProviderBootstrapper::OIDC);

        if (!$this->registry->hasProvider(self::oidcInstanceKey())) {
            return (new Response())->status(503)->json([
                'error' => 'provider_not_configured',
                'message' => 'OIDC provider is not enabled',
            ]);
        }

        $provider = $this->registry->getProvider(self::oidcInstanceKey());
        if (!$provider instanceof OidcProvider) {
            return (new Response())->status(503)->json([
                'error' => 'invalid_provider_type',
                'message' => 'OIDC provider is not an OidcProvider instance',
            ]);
        }

        // S48 review r1 Finding 1 (HIGH) — every IdP matches `redirect_uri`
        // against a REGISTERED ABSOLUTE redirect URI, so a path-only value can
        // never match and the flow dies at the authorize page. Resolve an
        // absolute URL and bind it into the state so the token exchange replays
        // the identical value.
        $callbackUrl = $this->resolveCallbackUrl($request);
        if ($callbackUrl === null) {
            // Fail CLOSED: no state row is written and no correlation cookie is
            // issued, so nothing about this request survives it (review r3).
            return $this->callbackUrlNotConfigured($request);
        }

        // RFC 7636 PKCE — generate a per-request code_verifier (S256).
        $codeVerifier = OidcProvider::generateCodeVerifier();
        $codeChallenge = OidcProvider::computeCodeChallenge($codeVerifier);

        // Cryptographically-random CSRF state + ID-token replay nonce.
        $nonce = bin2hex(random_bytes(16));
        $stateId = bin2hex(random_bytes(16));

        // Bundle the redirect_uri into the state envelope so the
        // callback knows where to land. The opaque `sid` is the lookup
        // key for the server-side store that holds the code_verifier
        // (and, for a link flow, the trusted link_user_id + intent).
        $stateData = [
            'sid' => $stateId,
            'redirect_uri' => $redirectUri,
        ];
        $stateValue = base64_encode((string) json_encode($stateData));

        // Review r2 — bind the state to THIS browser (session fixation). Only the
        // SHA-256 of the correlation secret is persisted, so a database read cannot
        // forge a matching cookie. Covers the LINK path too (this method serves
        // both legs).
        $correlation = StateCorrelation::issue();

        $context = $linkContext ?? [];
        $context[StateCorrelation::CONTEXT_KEY] = StateCorrelation::fingerprint($correlation);
        $context[self::CALLBACK_URL_KEY] = $callbackUrl;

        $this->stateStore->put($stateId, $codeVerifier, $nonce, $context);

        $authorizationUrl = $provider->buildAuthorizationUrl(
            $callbackUrl,
            $stateValue,
            $nonce,
            $codeChallenge,
        );

        $response = (new Response())
            ->status(302)
            ->header('Location', $authorizationUrl);
        StateCorrelation::attach($response, self::CORRELATION_COOKIE, $correlation);

        return $response;
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

        // Finding 1 (HIGH), defense-in-depth — never trust the return target read
        // back from the state envelope. Re-validate it against the same
        // same-origin allowlist BEFORE it is used in ANY redirect below (the
        // success 302 that carries the session cookies AND the error 302s).
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

        // S45 — recover the server-side link context (if any) that authorizeLink()
        // stored alongside the PKCE verifier. It is trusted precisely because it
        // came from the one-shot server-side store keyed by `sid`, NOT from the
        // client-supplied `state` envelope. A present `intent=link` switches this
        // callback from "mint a login session" to "link the verified identity to
        // the stored link_user_id".
        $context = isset($stored['context']) && is_array($stored['context']) ? $stored['context'] : [];
        $isLinkFlow = ($context['intent'] ?? null) === self::LINK_INTENT;

        // Review r2 — the state must have been issued to THIS browser. Runs BEFORE
        // the token exchange, findOrCreateByExternalId(), completeLink() and
        // attachAuthCookies(), so no session can be minted for a flow this browser
        // did not start. (The nonce does NOT cover this: it binds the id_token to
        // an authorize request the attacker owns.)
        if (!StateCorrelation::matches($request, self::CORRELATION_COOKIE, $context)) {
            LoggerFactory::get(LogChannels::AUTH)->warning('OIDC state not bound to this browser', [
                'sid' => $stateId,
            ]);
            return $this->clearCorrelation((new Response())->status(403)->json([
                'error' => 'invalid_state',
                'message' => 'State parameter was not issued to this browser session',
            ]));
        }

        // S48 review r1 Finding 1 — replay the EXACT absolute redirect_uri the
        // authorize step sent (the IdP rejects the exchange when they differ).
        // Review r2 NEW-8: re-validate it on the way out (defence in depth — only
        // server code writes that key); a value that no longer passes falls back to
        // a fresh resolve, the same path a state row issued before this field
        // existed takes.
        $configuredRedirectUri = $this->configuredRedirectUri();
        $callbackUrl = $this->replayCallbackUrl($context, $configuredRedirectUri)
            ?? $this->resolveCallbackUrl($request, $configuredRedirectUri);
        if ($callbackUrl === null) {
            return $this->clearCorrelation($this->callbackUrlNotConfigured($request));
        }

        // Finding 3 (MEDIUM) — request-path self-heal (see authorize()): register
        // an enabled+configured provider that this worker missed at boot, or drop
        // a stale registration when the flag is now off, before the check below.
        $this->bootstrapper?->ensureProviderRegistered(AuthProviderBootstrapper::OIDC);

        if (!$this->registry->hasProvider(self::oidcInstanceKey())) {
            return $this->clearCorrelation((new Response())->status(503)->json([
                'error' => 'provider_not_configured',
                'message' => 'OIDC provider is not enabled',
            ]));
        }

        try {
            $provider = $this->registry->getProvider(self::oidcInstanceKey());
            if (!$provider instanceof OidcProvider) {
                throw new \RuntimeException('OIDC provider is not configured correctly');
            }

            $result = $provider->authenticate([
                'code' => $code,
                'redirect_uri' => $callbackUrl,
                'nonce' => $expectedNonce,
                'code_verifier' => $codeVerifier,
            ]);

            if ($result->isFailure()) {
                // Generic error code only — the redirect target is same-origin
                // (allowlisted above) but the error string still rides the URL.
                $errorValue = is_string($result->error) ? $result->error : 'auth_failed';
                $redirectUrl = $redirectUri . '?error=' . urlencode($errorValue);
                return $this->clearCorrelation(
                    (new Response())->status(302)->header('Location', $redirectUrl),
                );
            }

            // S45 — LINK branch. The OIDC auth above ran with the SAME S44
            // validation (state + PKCE + id-token sig/iss/aud/exp/nonce), so
            // $result->externalId is the IdP-verified `sub`. We link THAT to the
            // trusted link_user_id from the server-side context — we do NOT mint
            // tokens, create a user, or touch the `users` table.
            if ($isLinkFlow) {
                return $this->completeLink($result, $context, $redirectUri);
            }

            $userId = $result->userId;
            $externalId = is_string($result->externalId) ? $result->externalId : '';
            $email = $result->getEmail();
            $displayName = $result->getDisplayName();
            // Thread the REAL provider that authenticated (OidcProvider sets
            // attributes['provider'] = 'oidc') so the user row records 'oidc',
            // never the old hardcoded 'external' — S46/S47 key on this column.
            $provider = is_string($result->attributes['provider'] ?? null)
                ? $result->attributes['provider']
                : 'oidc';

            if ($userId === null) {
                $userId = $this->userRepository->findOrCreateByExternalId(
                    $provider,
                    $externalId,
                    $email,
                    $displayName,
                );
            }

            $tokens = $this->createTokensForUser($userId);

            // Finding 1 (HIGH) — deliver the freshly-minted access + refresh
            // tokens as HttpOnly+Secure+SameSite=Lax cookies (never in the URL,
            // which would leak them via the Referer header, browser history and
            // access logs) and 302 to the clean, allowlisted same-origin path
            // WITHOUT any token query string. RequestAuthenticator reads the
            // phlix_session cookie, so the SPA is authenticated on the next
            // navigation with no client-side token handling.
            $response = $this->clearCorrelation(
                (new Response())->status(302)->header('Location', $redirectUri),
            );
            $this->attachAuthCookies($response, $tokens);

            return $response;
        } catch (\Throwable $e) {
            LoggerFactory::get(LogChannels::AUTH)->error('OIDC callback failed', [
                'error' => $e->getMessage(),
            ]);

            $redirectUrl = $redirectUri . '?error=internal';
            return $this->clearCorrelation(
                (new Response())->status(302)->header('Location', $redirectUrl),
            );
        }
    }

    /**
     * Expire the spent correlation cookie (review r2 NEW-10) on every response
     * returned after the one-shot state was consumed.
     */
    private function clearCorrelation(Response $response): Response
    {
        return StateCorrelation::clear($response, self::CORRELATION_COOKIE);
    }

    /**
     * S45 — persist a verified OIDC identity as a link on the initiating user's
     * account, then 302 back to the same-origin app path.
     *
     * Invariants (the account-takeover defences):
     *  - `link_user_id` comes ONLY from the server-side state context (written by
     *    the authenticated {@see authorizeLink()}), never from client input.
     *  - the linked `external_id` comes ONLY from the IdP-verified `$result`
     *    ({@see AuthResult::$externalId} = 'oidc.'+sub), never from a request
     *    parameter — the callback ignores any client-claimed identity entirely.
     *  - NO login tokens are minted and the `users` table is not mutated.
     *
     * Conflict handling:
     *  - identity already linked to the SAME user → idempotent success (302).
     *  - identity linked to ANOTHER user → 409 (the DB UNIQUE index from
     *    migration 092 is the race backstop: a duplicate-key throw on create() is
     *    re-classified by re-reading and mapped to 409/idempotent success ONLY
     *    when the identity genuinely exists).
     *  - a NON-duplicate create() failure (transient DB fault, FK/constraint,
     *    connection drop) is re-thrown so callback()'s central catch surfaces it
     *    as a genuine server error (?error=internal), never a mislabeled 409.
     *
     * @param AuthResult $result The verified OIDC authentication result.
     * @param array<string, mixed> $context The server-side link context.
     * @param string $redirectUri Allowlisted same-origin return target.
     * @return Response
     */
    private function completeLink(AuthResult $result, array $context, string $redirectUri): Response
    {
        $linkUserId = is_string($context['link_user_id'] ?? null) ? $context['link_user_id'] : '';
        if ($linkUserId === '') {
            // Should be unreachable — authorizeLink() always sets it. Refuse
            // rather than link to an empty user.
            return (new Response())->status(400)->json([
                'error' => 'invalid_link_state',
                'message' => 'Link request is missing the initiating user',
            ]);
        }

        if ($this->identities === null) {
            LoggerFactory::get(LogChannels::AUTH)->error('OIDC link attempted without identity repository');
            return (new Response())->status(503)->json([
                'error' => 'link_unavailable',
                'message' => 'Account linking is not available',
            ]);
        }

        // The identity to link is the IdP-verified subject — NEVER a client value.
        $externalId = is_string($result->externalId) ? $result->externalId : '';
        if ($externalId === '') {
            return (new Response())->status(400)->json([
                'error' => 'invalid_identity',
                'message' => 'Provider did not return a verified identity',
            ]);
        }

        // Already linked? (single-instance default sentinel '' for S45.)
        $existing = $this->identities->findByProviderExternalId(
            self::OIDC_PROVIDER,
            self::DEFAULT_INSTANCE,
            $externalId,
        );
        if ($existing !== null) {
            $ownerId = is_string($existing['user_id'] ?? null) ? $existing['user_id'] : '';
            if ($ownerId === $linkUserId) {
                // Idempotent: this user already owns this identity.
                return $this->linkSuccessRedirect($redirectUri);
            }
            // Owned by someone else — refuse (never re-home an identity).
            return $this->identityConflict();
        }

        $providerData = $this->buildLinkProviderData($result);

        try {
            $this->identities->create(
                $linkUserId,
                self::OIDC_PROVIDER,
                self::DEFAULT_INSTANCE,
                $externalId,
                $providerData,
            );
        } catch (\Throwable $e) {
            // create() failed — CLASSIFY the failure by re-reading. The row's
            // actual presence is the authoritative signal (more reliable than
            // parsing driver error codes, which the MySQL wrapper flattens to
            // SQLSTATE 23000 across dup-key/FK/NOT-NULL alike):
            //  - re-read non-null ⇒ a genuine duplicate raced the DB UNIQUE index
            //    (migration 092): same user ⇒ idempotent success, else 409;
            //  - re-read null ⇒ the INSERT did NOT land for a NON-duplicate reason
            //    (transient DB fault, FK/constraint, connection drop) — a real
            //    server error that must NOT be mislabeled as a 409 conflict.
            $raced = $this->identities->findByProviderExternalId(
                self::OIDC_PROVIDER,
                self::DEFAULT_INSTANCE,
                $externalId,
            );
            if ($raced === null) {
                // Not a conflict. Re-throw so callback()'s central catch surfaces
                // this as a genuine server error (error-level log + ?error=internal
                // redirect), rather than masking it as a 409 conflict.
                LoggerFactory::get(LogChannels::AUTH)->error('OIDC identity link create failed', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
            if (($raced['user_id'] ?? null) === $linkUserId) {
                return $this->linkSuccessRedirect($redirectUri);
            }
            LoggerFactory::get(LogChannels::AUTH)->warning('OIDC identity link conflict', [
                'error' => $e->getMessage(),
            ]);
            return $this->identityConflict();
        }

        return $this->linkSuccessRedirect($redirectUri);
    }

    /**
     * Build the (non-secret) provider metadata stored on a linked identity row.
     * Deliberately narrow: the user's own email + display name for the settings
     * UI. No tokens or secrets — and {@see \Phlix\Auth\UserIdentityRepository}'s
     * listing never returns provider_data anyway.
     *
     * @param AuthResult $result The verified OIDC authentication result.
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
     * 302 back to the allowlisted same-origin path with a `linked=oidc` marker so
     * the SPA can surface a success toast. No tokens ride the URL (the user is
     * already logged in) and no cookies are set.
     */
    private function linkSuccessRedirect(string $redirectUri): Response
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $separator . 'linked=' . self::OIDC_PROVIDER;

        return $this->clearCorrelation((new Response())->status(302)->header('Location', $location));
    }

    /**
     * 409 when the verified identity is already linked to a DIFFERENT account.
     * A single generic message — we do not reveal which account owns it.
     */
    private function identityConflict(): Response
    {
        return $this->clearCorrelation((new Response())->status(409)->json([
            'error' => 'identity_already_linked',
            'message' => 'This external identity is already linked to another account',
        ]));
    }

    /**
     * The ABSOLUTE OIDC callback URL to advertise as `redirect_uri`, or null when
     * neither the operator setting nor the request yields one.
     *
     * S48 review r1 Finding 1 (HIGH): this used to return the relative
     * `/auth/oidc/callback`, which no IdP can match against its registered
     * redirect URI — the S44 flow could not complete against a real provider.
     * Precedence: the operator-configured `redirect_uri` OIDC plugin setting,
     * else the request's own scheme+Host — the latter ONLY when that Host is the
     * operator's configured public hostname, and NOT AT ALL when no public hostname
     * is configured (review r3 — fail closed). That HOST ALLOWLIST is review r2
     * NEW-1: a wildcard-registered IdP (Keycloak et al) would happily deliver a
     * victim's `code` to an attacker-supplied Host, which the correlation cookie
     * cannot defend because the attacker runs the authorize leg. See
     * {@see CallbackUrl}.
     *
     * Accepted consequence, documented so it is not a surprise: with `PHLIX_DOMAIN`
     * set, hitting `/auth/oidc/authorize` over a LAN IP, `localhost` or the relay
     * hostname now answers {@see callbackUrlNotConfigured()} instead of deriving.
     * That flow could never have completed anyway — the IdP has the registered
     * domain, not the LAN IP — and the `redirect_uri` setting covers an operator who
     * really does serve auth on a second hostname.
     *
     * @param string|null $configured Pre-read `redirect_uri` setting, so the
     *        callback path performs ONE settings read instead of two.
     */
    private function resolveCallbackUrl(Request $request, ?string $configured = null): ?string
    {
        return CallbackUrl::resolve(
            $configured ?? $this->configuredRedirectUri(),
            $request->getHeader('Host'),
            $request->getHeader('X-Forwarded-Proto'),
            self::CALLBACK_PATH,
            CallbackUrl::configuredHost(),
        );
    }

    /**
     * The `503 callback_url_not_configured` answer, with a log line naming WHICH
     * condition fired so the operator knows which remedy applies (review r3).
     *
     * Emitted on both legs. On the authorize leg this is reached BEFORE the state
     * row and the correlation cookie are created, so a refused request leaves no
     * trace and cannot be resumed.
     */
    private function callbackUrlNotConfigured(Request $request): Response
    {
        $allowedHost = CallbackUrl::configuredHost();
        LoggerFactory::get(LogChannels::AUTH)->warning(
            'OIDC sign-in refused: no absolute callback URL could be resolved',
            [
                'reason' => $allowedHost === ''
                    ? 'PHLIX_DOMAIN is not set, so no callback may be derived from the request Host'
                    : 'the request Host is not the configured PHLIX_DOMAIN',
                'request_host' => CallbackUrl::sanitizeHostForLog($request->getHeader('Host')),
                'phlix_domain' => $allowedHost,
                'remedy' => 'set PHLIX_DOMAIN (scripts/install.sh --domain) or an absolute '
                    . 'redirect_uri in the OIDC provider settings',
            ],
        );

        return (new Response())->status(503)->json([
            'error' => 'callback_url_not_configured',
            'message' => self::CALLBACK_URL_HELP,
        ]);
    }

    /**
     * The state-carried authorize-time callback URL, re-validated (review r2
     * NEW-8), or null when the state carries none / it no longer passes.
     *
     * @param array<string, mixed> $context    The trusted server-side state context.
     * @param string               $configured The `redirect_uri` setting (pre-read).
     */
    private function replayCallbackUrl(array $context, string $configured): ?string
    {
        $stored = is_string($context[self::CALLBACK_URL_KEY] ?? null) ? $context[self::CALLBACK_URL_KEY] : '';
        if ($stored === '') {
            return null;
        }

        return CallbackUrl::isReplayable($stored, $configured, CallbackUrl::configuredHost())
            ? $stored
            : null;
    }

    /**
     * The operator-configured absolute `redirect_uri` plugin setting, or '' when
     * unset / no settings source is wired.
     */
    private function configuredRedirectUri(): string
    {
        if ($this->plugin === null) {
            return '';
        }
        $settings = $this->plugin->getSettings();

        return is_string($settings['redirect_uri'] ?? null) ? $settings['redirect_uri'] : '';
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

    /**
     * Queue the minted access + refresh tokens onto the 302 as auth cookies
     * (Finding 1). Mirrors
     * {@see \Phlix\Server\Http\Controllers\AuthController::attachAuthCookies()}
     * so the OIDC browser flow sets the SAME cookies the local login sets —
     * identical names ({@see AuthController::SESSION_COOKIE}/{@see
     * AuthController::REFRESH_COOKIE}), HttpOnly so XSS cannot read them, Secure
     * so they only ride HTTPS (opt-out via `PHLIX_COOKIE_INSECURE=1` for local
     * HTTP dev), SameSite=Lax so the top-level IdP → callback navigation still
     * carries them. Lifetimes come from the same {@see JwtHandler} that minted
     * the tokens, never a literal, so a cookie's Max-Age can't drift from its
     * JWT `exp`.
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
     * Allowlist for the post-login return target (Finding 1 — HIGH).
     *
     * The callback mints a real access + refresh token pair, so the return
     * target must never point at a foreign origin: an attacker who set
     * `redirect_uri=https://evil.example` could otherwise phish the IdP flow and
     * receive the victim's session (account takeover; the refresh token is
     * long-lived). We therefore accept ONLY a same-origin, relative path:
     *
     *   - non-empty, with no control characters (also blocks CR/LF injection
     *     into the `Location:` response header);
     *   - begins with a single '/'; and
     *   - does NOT begin with '//' or '/\', the protocol-relative and
     *     backslash-normalization forms that browsers resolve to an absolute,
     *     potentially foreign, host.
     *
     * Absolute URLs (`https://…`, `javascript:`, …) and scheme-relative URLs are
     * all rejected because their first character is not '/'. There is no
     * configured cross-origin redirect allowlist for OIDC (the plugin
     * settings.json only holds provider_url/client_id/scopes), so same-origin is
     * the only accepted target.
     */
    private static function isSafeRedirectTarget(string $uri): bool
    {
        if ($uri === '') {
            return false;
        }

        // Reject any control character (NUL/CR/LF/TAB/…): these enable Location
        // header injection and browser-specific parsing bypasses.
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            return false;
        }

        // Must be a path rooted at '/', but not the protocol-relative ('//host')
        // or backslash ('/\host') forms browsers treat as an absolute URL.
        if ($uri[0] !== '/') {
            return false;
        }
        if (str_starts_with($uri, '//') || str_starts_with($uri, '/\\')) {
            return false;
        }

        return true;
    }
}
