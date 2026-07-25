<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Github\Controller\GithubCallbackController;
use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\OAuth2\InMemoryOAuth2StateStore;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use Phlix\Tests\Unit\Plugins\OAuth2\FakeOAuth2HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Covers the GitHub OAuth2 login + link flow hardening (mirrors OidcPkceTest and
 * the S45 OIDC link tests): PKCE + CSRF state, the browser-binding correlation
 * cookie, the same-origin redirect allowlist on BOTH legs, the absolute
 * `redirect_uri` contract, cookie session delivery, and provider-scoped account
 * resolution.
 *
 * @covers \Phlix\Plugins\Github\Controller\GithubCallbackController
 */
final class GithubCallbackControllerTest extends TestCase
{
    /** The raw correlation secret seeded into hand-built state entries. */
    private const string CORRELATION = 'correlation-secret-for-tests';

    /** The cookie the controller binds the issued state to. */
    private const string CORRELATION_COOKIE = 'phlix_oauth_github';

    private const string HOST = 'phlix.test';
    private const string DERIVED_CALLBACK = 'https://phlix.test/auth/github/callback';

    /** The ambient PHLIX_DOMAIN, restored after every test. */
    private string|false $originalDomain = false;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
        // Review r3 — a Host-derived callback is only produced when the operator
        // configured a public hostname (PHLIX_DOMAIN); with it unset the flow fails
        // CLOSED. These tests exercise a CONFIGURED box, so set it to the Host they
        // send. The fail-closed behaviour has its own test, which unsets it.
        $this->originalDomain = getenv('PHLIX_DOMAIN');
        putenv('PHLIX_DOMAIN=' . self::HOST);
    }

    protected function tearDown(): void
    {
        if (is_string($this->originalDomain) && $this->originalDomain !== '') {
            putenv('PHLIX_DOMAIN=' . $this->originalDomain);
        } else {
            putenv('PHLIX_DOMAIN');
        }
        parent::tearDown();
    }

    private function registryWithProvider(FakeOAuth2HttpClient $http): AuthProviderRegistry
    {
        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            new GithubOAuthProvider('client-id', 'client-secret', GithubOAuthProvider::DEFAULT_SCOPES, $http),
        );

        return $registry;
    }

    /**
     * A GitHub plugin whose DB-backed settings carry the given map.
     *
     * @param array<string, mixed> $settings
     */
    private function pluginWithSettings(array $settings): GithubPlugin
    {
        $store = new InMemoryPluginSettingsRepository();
        $store->save(GithubPlugin::PLUGIN_NAME, $settings);

        return new GithubPlugin($store);
    }

    /** An authorize request that carries a real Host (as every browser does). */
    private function authorizeRequest(string $redirectUri): Request
    {
        $request = new Request();
        $request->headers['Host'] = self::HOST;
        $request->query = ['redirect_uri' => $redirectUri];

        return $request;
    }

    /**
     * Seed a state entry the way {@see GithubCallbackController::authorize()}
     * would: PKCE verifier + the correlation hash + the absolute callback URL.
     *
     * @param array<string, mixed> $extraContext
     */
    private function seedState(
        InMemoryOAuth2StateStore $store,
        string $sid,
        array $extraContext = [],
    ): void {
        $store->put($sid, 'verifier-' . $sid, array_merge([
            'correlation' => hash('sha256', self::CORRELATION),
            'callback_url' => self::DERIVED_CALLBACK,
        ], $extraContext));
    }

    /**
     * A callback request carrying the correlation cookie the authorize step set.
     *
     * @param array<string, mixed> $extraQuery
     */
    private function callbackRequest(
        string $sid,
        string $redirectUri,
        array $extraQuery = [],
        ?string $correlationCookie = self::CORRELATION,
    ): Request {
        $state = base64_encode((string) json_encode(['sid' => $sid, 'redirect_uri' => $redirectUri]));

        $request = new Request();
        $request->headers['Host'] = self::HOST;
        if ($correlationCookie !== null) {
            $request->cookies[self::CORRELATION_COOKIE] = $correlationCookie;
        }
        $request->query = array_merge(['code' => 'auth-code', 'state' => $state], $extraQuery);

        return $request;
    }

    public function test_authorize_redirects_with_pkce_and_stores_state(): void
    {
        $store = new InMemoryOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->authorize($this->authorizeRequest('/app'), []);

        $this->assertSame(302, $response->statusCode);
        $location = $response->headers['Location'] ?? '';
        $this->assertStringContainsString(GithubOAuthProvider::AUTHORIZE_URL, $location);
        $this->assertStringContainsString('code_challenge=', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);

        // The verifier stored server-side must hash to the challenge in the URL.
        $params = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $stateRaw = base64_decode(is_string($params['state'] ?? null) ? $params['state'] : '', true);
        $this->assertIsString($stateRaw);
        /** @var array<string, mixed> $stateData */
        $stateData = json_decode($stateRaw, true);
        $sid = is_string($stateData['sid'] ?? null) ? $stateData['sid'] : '';
        $entry = $store->consume($sid);
        $this->assertNotNull($entry);
        $this->assertSame(
            \Phlix\Plugins\OAuth2\Pkce::computeCodeChallenge($entry['code_verifier']),
            is_string($params['code_challenge'] ?? null) ? $params['code_challenge'] : '',
        );
    }

    public function test_authorize_rejects_foreign_redirect_target(): void
    {
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            new InMemoryOAuth2StateStore(),
        );

        $response = $controller->authorize($this->authorizeRequest('https://evil.example/callback'), []);

        $this->assertSame(400, $response->statusCode);
    }

    public function test_authorize_503_when_provider_not_registered(): void
    {
        $controller = new GithubCallbackController(
            new AuthProviderRegistry(), // empty
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            new InMemoryOAuth2StateStore(),
        );

        $response = $controller->authorize($this->authorizeRequest('/app'), []);

        $this->assertSame(503, $response->statusCode);
    }

    public function test_callback_with_unknown_sid_returns_403(): void
    {
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            new InMemoryOAuth2StateStore(),
        );

        $response = $controller->callback($this->callbackRequest('never', '/app'), []);

        $this->assertSame(403, $response->statusCode);
    }

    public function test_callback_success_mints_cookie_session_scoped_to_github(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'access_token' => 'gho_test',
        ]));
        $http->queue('GET', GithubOAuthProvider::USER_API_URL, 200, (string) json_encode([
            'id' => 583231,
            'login' => 'octocat',
            'name' => 'The Octocat',
            'email' => 'octocat@github.com',
        ]));

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-1');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findOrCreateByExternalId')
            ->with('github', 'github.583231', 'octocat@github.com', 'The Octocat')
            ->willReturn('user-1');

        $jwt = $this->createMock(JwtHandler::class);
        $jwt->method('createAccessToken')->with('user-1')->willReturn('access-token');
        $jwt->method('createRefreshToken')->with('user-1')->willReturn('refresh-token');
        $jwt->method('accessTtl')->willReturn(3600);
        $jwt->method('refreshTtl')->willReturn(604800);

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $jwt,
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-1', '/app'), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app', $response->headers['Location'] ?? '');

        $cookieNames = array_column($response->cookies, 'value', 'name');
        $this->assertSame('access-token', $cookieNames[AuthController::SESSION_COOKIE] ?? null);
        $this->assertSame('refresh-token', $cookieNames[AuthController::REFRESH_COOKIE] ?? null);
        // No token ever rides the URL.
        $this->assertStringNotContainsString('access-token', $response->headers['Location'] ?? '');
    }

    public function test_callback_provider_auth_failure_redirects_with_error(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'error' => 'bad_verification_code',
        ]));

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-2');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-2', '/app'), []);

        $this->assertSame(302, $response->statusCode);
        // A FIXED internal code — provider text is never reflected (Finding 9).
        $this->assertSame('/app?error=token_exchange_failed', $response->headers['Location'] ?? '');
    }

    // -----------------------------------------------------------------------
    // Review r1 Finding 1 (HIGH) — the outbound redirect_uri must be ABSOLUTE
    // and IDENTICAL at authorize + token exchange.
    // -----------------------------------------------------------------------

    /**
     * The authorize redirect must carry an ABSOLUTE `redirect_uri`, and the token
     * exchange must post back the very same string. A relative value (the
     * pre-review behaviour) is rejected by GitHub with `redirect_uri_mismatch`, so
     * this test fails if anyone reverts to a path-only callback.
     */
    public function test_redirect_uri_is_absolute_and_identical_at_authorize_and_token_exchange(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'access_token' => 'gho_test',
        ]));
        $http->queue('GET', GithubOAuthProvider::USER_API_URL, 200, (string) json_encode([
            'id' => 99,
            'login' => 'abs',
            'email' => 'abs@example.com',
        ]));

        $store = new InMemoryOAuth2StateStore();
        $jwt = $this->createMock(JwtHandler::class);
        $jwt->method('createAccessToken')->willReturn('a');
        $jwt->method('createRefreshToken')->willReturn('r');
        $jwt->method('accessTtl')->willReturn(60);
        $jwt->method('refreshTtl')->willReturn(60);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOrCreateByExternalId')->willReturn('user-abs');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $jwt,
            $store,
        );

        // 1. authorize → capture the redirect_uri actually sent to GitHub.
        $authorize = $controller->authorize($this->authorizeRequest('/app'), []);
        $this->assertSame(302, $authorize->statusCode);
        $params = [];
        parse_str((string) parse_url($authorize->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $authorizeRedirectUri = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';

        $this->assertNotSame('', $authorizeRedirectUri);
        $this->assertTrue(
            \Phlix\Plugins\OAuth2\CallbackUrl::isAbsolute($authorizeRedirectUri),
            'the authorize redirect_uri must be an absolute http(s) URL, not a path',
        );
        $this->assertSame(self::DERIVED_CALLBACK, $authorizeRedirectUri);

        // The correlation cookie must ride the same response (Finding 2).
        $cookies = array_column($authorize->cookies, 'value', 'name');
        $correlation = $cookies[self::CORRELATION_COOKIE] ?? null;
        $this->assertIsString($correlation);
        $this->assertNotSame('', $correlation);

        // 2. callback with the state + cookie the authorize step issued.
        $state = is_string($params['state'] ?? null) ? $params['state'] : '';
        $request = new Request();
        $request->headers['Host'] = self::HOST;
        $request->cookies[self::CORRELATION_COOKIE] = $correlation;
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);
        $this->assertSame(302, $response->statusCode);

        // 3. the token POST must carry the IDENTICAL absolute redirect_uri.
        $post = null;
        foreach ($http->requests as $req) {
            if ($req['method'] === 'POST' && $req['url'] === GithubOAuthProvider::TOKEN_URL) {
                $post = $req;
                break;
            }
        }
        $this->assertNotNull($post);
        $this->assertIsString($post['body']);
        $posted = [];
        parse_str($post['body'], $posted);
        $tokenRedirectUri = is_string($posted['redirect_uri'] ?? null) ? $posted['redirect_uri'] : '';

        $this->assertTrue(\Phlix\Plugins\OAuth2\CallbackUrl::isAbsolute($tokenRedirectUri));
        $this->assertSame($authorizeRedirectUri, $tokenRedirectUri);
    }

    /**
     * An operator-configured absolute `redirect_uri` wins over the request-derived
     * one (the documented, explicit configuration path).
     */
    public function test_configured_absolute_redirect_uri_is_used(): void
    {
        $configured = 'https://media.example.org:8443/auth/github/callback';

        $store = new InMemoryOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
            null,
            null,
            null,
            $this->pluginWithSettings([
                'client_id' => 'cid',
                'client_secret' => 'sec',
                'redirect_uri' => $configured,
            ]),
        );

        $response = $controller->authorize($this->authorizeRequest('/app'), []);

        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame($configured, $params['redirect_uri'] ?? null);
    }

    /**
     * With neither a configured redirect_uri nor a Host header there is no way to
     * build an absolute callback — answer 503 rather than send a value the
     * provider is guaranteed to reject.
     */
    public function test_authorize_503_when_callback_url_cannot_be_resolved(): void
    {
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            new InMemoryOAuth2StateStore(),
        );

        $request = new Request(); // no Host
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame('callback_url_not_configured', $this->errorCode($response));
    }

    // -----------------------------------------------------------------------
    // Review r1 Finding 2 (MED) — the state must be bound to the browser that
    // started the flow (login CSRF / session fixation).
    // -----------------------------------------------------------------------

    public function test_callback_rejects_state_without_the_correlation_cookie(): void
    {
        $http = new FakeOAuth2HttpClient();
        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-corr');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->never())->method('findOrCreateByExternalId');
        $jwt = $this->createMock(JwtHandler::class);
        $jwt->expects($this->never())->method('createAccessToken');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $jwt,
            $store,
        );

        // The victim's browser has no correlation cookie for this state.
        $response = $controller->callback(
            $this->callbackRequest('sid-corr', '/app', [], null),
            [],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertSame('invalid_state', $this->errorCode($response));
        $this->assertNoSessionCookies($response);
        $this->assertSame([], $http->requests, 'no code must be exchanged for an unbound state');
    }

    public function test_callback_rejects_a_mismatched_correlation_cookie(): void
    {
        $http = new FakeOAuth2HttpClient();
        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-corr2');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->callback(
            $this->callbackRequest('sid-corr2', '/app', [], 'some-other-browsers-secret'),
            [],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertNoSessionCookies($response);
    }

    // -----------------------------------------------------------------------
    // Review r2 NEW-1 (MED) — the Host-derived redirect_uri needs a host
    // ALLOWLIST (PHLIX_DOMAIN). This asserts the CONTROLLER wiring; the rule
    // itself is covered by CallbackUrlTest.
    // -----------------------------------------------------------------------

    public function test_authorize_refuses_to_derive_a_callback_from_a_foreign_host(): void
    {
        // setUp() has PHLIX_DOMAIN = self::HOST.
        $store = new InMemoryOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $request = new Request();
        // A forged Host from a non-browser client.
        $request->headers['Host'] = 'evil.example';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame('callback_url_not_configured', $this->errorCode($response));
        $this->assertArrayNotHasKey('Location', $response->headers);
        $this->assertSame([], $response->cookies, 'no state may be issued for a forged Host');

        // …while the operator's real hostname still works.
        $ok = $controller->authorize($this->authorizeRequest('/app'), []);
        $this->assertSame(302, $ok->statusCode);
        $params = [];
        parse_str((string) parse_url($ok->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame(self::DERIVED_CALLBACK, $params['redirect_uri'] ?? null);
    }

    // -----------------------------------------------------------------------
    // Review r3 — FAIL CLOSED. r2 made an unset PHLIX_DOMAIN mean "no allowlist",
    // which left the NEW-1 phishing chain fully open on every unconfigured box.
    // With no public hostname configured and no explicit redirect_uri setting the
    // flow must refuse to start: 503, no state row, no correlation cookie.
    // -----------------------------------------------------------------------

    public function test_authorize_fails_closed_when_no_domain_and_no_redirect_uri_are_configured(): void
    {
        putenv('PHLIX_DOMAIN'); // unset (an install that never ran `--domain`)

        $store = new CountingOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->authorize($this->authorizeRequest('/app'), []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame('callback_url_not_configured', $this->errorCode($response));
        $this->assertArrayNotHasKey('Location', $response->headers);
        $this->assertSame([], $response->cookies, 'no correlation cookie may be issued');
        $this->assertSame(0, $store->puts, 'no state row may be issued');

        // Review r3 finding 6 — the route is UNAUTHENTICATED, so the public body must
        // stay generic: no PHLIX_DOMAIN, no install.sh, no hint at which condition
        // fired. The machine-readable `error` code above is the operator/UI contract
        // and the full remedy goes to the AUTH log.
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $message = is_string($body['message'] ?? null) ? $body['message'] : '';
        $this->assertStringNotContainsStringIgnoringCase('phlix_domain', $message);
        $this->assertStringNotContainsStringIgnoringCase('install.sh', $message);
        $this->assertStringNotContainsStringIgnoringCase('redirect_uri', $message);
        $this->assertStringContainsString('administrator', $message);
    }

    /**
     * The escape hatch: an explicit absolute `redirect_uri` setting keeps FIRST
     * priority and still works with PHLIX_DOMAIN unset, so fail-closed can never
     * lock an operator out.
     */
    public function test_configured_redirect_uri_still_works_without_a_configured_domain(): void
    {
        putenv('PHLIX_DOMAIN');

        $configured = 'https://media.example.org:8443/auth/github/callback';
        $store = new CountingOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
            null,
            null,
            null,
            $this->pluginWithSettings([
                'client_id' => 'cid',
                'client_secret' => 'sec',
                'redirect_uri' => $configured,
            ]),
        );

        $response = $controller->authorize($this->authorizeRequest('/app'), []);

        $this->assertSame(302, $response->statusCode);
        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame($configured, $params['redirect_uri'] ?? null);
        $this->assertSame(1, $store->puts, 'the flow must still start normally');
    }

    // -----------------------------------------------------------------------
    // S48 TestEngineer — the fail-closed 503's REACHABILITY and the port pinning,
    // end-to-end through the controller (CallbackUrlTest covers the resolver).
    // -----------------------------------------------------------------------

    /**
     * ORDERING, asserted rather than assumed: the `provider_not_configured` 503 is
     * answered BEFORE the callback-URL resolve, so an operator who never enabled
     * GitHub sees "not enabled" — not a confusing callback-configuration error — and
     * the fail-closed 503 is only reachable by someone actively setting the provider
     * up. This also bounds the log-amplification surface of the refusal path
     * (program follow-up 11) to boxes that enabled the provider.
     */
    public function test_provider_not_configured_is_answered_before_the_callback_url_503(): void
    {
        putenv('PHLIX_DOMAIN'); // the condition that would otherwise fail closed

        $store = new CountingOAuth2StateStore();
        $controller = new GithubCallbackController(
            new AuthProviderRegistry(), // provider NOT registered
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->authorize($this->authorizeRequest('/app'), []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame(
            'provider_not_configured',
            $this->errorCode($response),
            'a box that never enabled GitHub must not be told its callback URL is unconfigured',
        );
        $this->assertSame(0, $store->puts);
        $this->assertSame([], $response->cookies);
    }

    /**
     * A GARBAGE `PHLIX_DOMAIN` must fail closed exactly like an unset one — never as
     * an allowlist entry that can never match, and never as "no allowlist, derive
     * anything". A URL, a trailing dot, a trailing colon and an out-of-range port
     * are all normalised to "unconfigured".
     *
     * @dataProvider garbageDomains
     */
    public function test_a_garbage_phlix_domain_fails_closed_like_an_unset_one(string $domain): void
    {
        putenv('PHLIX_DOMAIN=' . $domain);

        $store = new CountingOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->authorize($this->authorizeRequest('/app'), []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame('callback_url_not_configured', $this->errorCode($response));
        $this->assertArrayNotHasKey('Location', $response->headers);
        $this->assertSame([], $response->cookies, 'no correlation cookie may be issued');
        $this->assertSame(0, $store->puts, 'no state row may be issued');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function garbageDomains(): array
    {
        return [
            'a URL' => ['https://phlix.test/'],
            'a path' => ['phlix.test/app'],
            'trailing colon' => ['phlix.test:'],
            'trailing dot' => ['phlix.test.'],
            'out of range port' => ['phlix.test:99999'],
            'non numeric port' => ['phlix.test:https'],
            'whitespace only' => ['   '],
        ];
    }

    /**
     * A client (or proxy) that includes the scheme's DEFAULT port in `Host` must
     * still start the flow, and must still bind the PORTLESS registered callback —
     * this is the case that would have turned the r4 port pinning into a 503 on
     * every login had `stripDefaultPort()` not been part of it.
     */
    public function test_a_host_carrying_the_default_https_port_still_starts_the_flow(): void
    {
        $store = new CountingOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $request = new Request();
        $request->headers['Host'] = self::HOST . ':443';
        $request->headers['X-Forwarded-Proto'] = 'https';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(302, $response->statusCode);
        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame(
            self::DERIVED_CALLBACK,
            $params['redirect_uri'] ?? null,
            'the default port must be normalised away, not leaked into the redirect_uri',
        );
        $this->assertSame(1, $store->puts);
    }

    /**
     * …while a NON-default port on the same hostname fails closed end-to-end: 503,
     * no `Location`, no correlation cookie, no state row. That is the documented
     * behaviour change of the r4 port pinning (reaching the app directly on its
     * listen port instead of through the reverse proxy), and it breaks no flow that
     * could have completed — the provider has the proxy-fronted origin registered.
     */
    public function test_a_host_on_a_non_default_port_fails_closed(): void
    {
        $store = new CountingOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $request = new Request();
        $request->headers['Host'] = self::HOST . ':8096';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame('callback_url_not_configured', $this->errorCode($response));
        $this->assertArrayNotHasKey('Location', $response->headers);
        $this->assertSame([], $response->cookies);
        $this->assertSame(0, $store->puts);

        // …and an operator who really does serve auth there sets the absolute
        // redirect_uri setting, which keeps first priority.
        $withSetting = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
            null,
            null,
            null,
            $this->pluginWithSettings([
                'client_id' => 'cid',
                'client_secret' => 'sec',
                'redirect_uri' => 'https://' . self::HOST . ':8096/auth/github/callback',
            ]),
        );
        $ok = $withSetting->authorize($request, []);
        $this->assertSame(302, $ok->statusCode);
        $params = [];
        parse_str((string) parse_url($ok->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame('https://' . self::HOST . ':8096/auth/github/callback', $params['redirect_uri'] ?? null);
    }

    /**
     * `PHLIX_DOMAIN` WITH a port is the mirror case: only that exact authority
     * starts the flow, and the port is carried into the derived callback.
     */
    public function test_a_configured_port_is_required_and_carried_into_the_callback(): void
    {
        putenv('PHLIX_DOMAIN=' . self::HOST . ':8443');

        $store = new CountingOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $ported = new Request();
        $ported->headers['Host'] = self::HOST . ':8443';
        $ported->query = ['redirect_uri' => '/app'];
        $response = $controller->authorize($ported, []);

        $this->assertSame(302, $response->statusCode);
        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame('https://' . self::HOST . ':8443/auth/github/callback', $params['redirect_uri'] ?? null);

        // A bare Host is now a DIFFERENT origin and fails closed.
        $bare = $controller->authorize($this->authorizeRequest('/app'), []);
        $this->assertSame(503, $bare->statusCode);
        $this->assertSame('callback_url_not_configured', $this->errorCode($bare));
    }

    // -----------------------------------------------------------------------
    // Review r2 NEW-10 — the spent correlation cookie is expired once the
    // one-shot state has been consumed.
    // -----------------------------------------------------------------------

    public function test_successful_callback_expires_the_correlation_cookie(): void
    {
        $http = $this->httpReturningProfile(9001, 'clear@example.com');

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-clear');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOrCreateByExternalId')->willReturn('user-clear');
        $jwt = $this->createMock(JwtHandler::class);
        $jwt->method('createAccessToken')->willReturn('a');
        $jwt->method('createRefreshToken')->willReturn('r');
        $jwt->method('accessTtl')->willReturn(60);
        $jwt->method('refreshTtl')->willReturn(60);

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $jwt,
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-clear', '/app'), []);

        $this->assertSame(302, $response->statusCode);
        $byName = [];
        foreach ($response->cookies as $cookie) {
            $byName[$cookie['name']] = $cookie;
        }
        $this->assertArrayHasKey(self::CORRELATION_COOKIE, $byName);
        $this->assertSame('', $byName[self::CORRELATION_COOKIE]['value']);
        $this->assertSame(0, $byName[self::CORRELATION_COOKIE]['maxAge']);
        // …and the session still lands.
        $this->assertSame('a', $byName[AuthController::SESSION_COOKIE]['value'] ?? null);
    }

    // -----------------------------------------------------------------------
    // Review r2 NEW-7 — the USERNAME twin of Finding 4 must be actionable too.
    // -----------------------------------------------------------------------

    /**
     * `findOrCreateByExternalId()` seeds `username = $email`, so a local account
     * whose USERNAME is the GitHub e-mail (with a different e-mail column) trips
     * UNIQUE(username). That must produce the same actionable code as the e-mail
     * collision, not an opaque `internal` dead end.
     */
    public function test_duplicate_username_returns_email_already_registered(): void
    {
        $http = $this->httpReturningProfile(883, 'taken-as-username@example.com');

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-dupe-username');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOrCreateByExternalId')
            ->willThrowException(new \RuntimeException("Duplicate entry '…' for key 'username'"));
        // The e-mail column is free — only the USERNAME collides.
        $userRepo->method('emailExists')->willReturn(false);
        $userRepo->expects($this->once())
            ->method('usernameExists')
            ->with('taken-as-username@example.com')
            ->willReturn(true);

        $jwt = $this->createMock(JwtHandler::class);
        $jwt->expects($this->never())->method('createAccessToken');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $jwt,
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-dupe-username', '/app'), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app?error=email_already_registered', $response->headers['Location'] ?? '');
        $this->assertNoSessionCookies($response);
    }

    // -----------------------------------------------------------------------
    // Review r1 Finding 5(a) — the callback-side re-validation of the
    // state-carried redirect_uri (the S44 open-redirect regression guard).
    // -----------------------------------------------------------------------

    /**
     * `redirect_uri` arrives inside the client-visible `state` blob, so it is
     * attacker-craftable: the callback MUST re-run the same-origin allowlist
     * before any Location header, and must not consume the state while doing so.
     *
     * @dataProvider foreignRedirectTargets
     */
    public function test_callback_rejects_foreign_redirect_uri_carried_in_state(string $target): void
    {
        $http = new FakeOAuth2HttpClient();
        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-open-redirect');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->never())->method('findOrCreateByExternalId');
        $jwt = $this->createMock(JwtHandler::class);
        $jwt->expects($this->never())->method('createAccessToken');
        $jwt->expects($this->never())->method('createRefreshToken');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $jwt,
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-open-redirect', $target), []);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('invalid_redirect_uri', $this->errorCode($response));
        // No Location, no cookies, and no code exchange.
        $this->assertArrayNotHasKey('Location', $response->headers);
        $this->assertSame([], $response->cookies);
        $this->assertSame([], $http->requests);
        // The one-shot state must NOT have been burned by a rejected request.
        $this->assertNotNull($store->consume('sid-open-redirect'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function foreignRedirectTargets(): array
    {
        return [
            'absolute https' => ['https://evil.example/steal'],
            'protocol relative' => ['//evil.example/steal'],
            'backslash host' => ['/\\evil.example/steal'],
            'javascript scheme' => ['javascript:alert(1)'],
            'crlf injection' => ["/app\r\nSet-Cookie: x=1"],
            'empty' => [''],
        ];
    }

    // -----------------------------------------------------------------------
    // Review r1 Finding 5(b) — the account-LINK branch.
    // -----------------------------------------------------------------------

    /**
     * The initiating user id must come ONLY from the authenticated server-side
     * context: it is never echoed into the client-visible `state`, and a
     * client-supplied `link_user_id` in the query is ignored.
     */
    public function test_authorize_link_binds_user_only_server_side(): void
    {
        $store = new InMemoryOAuth2StateStore();
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $request = new Request();
        $request->headers['Host'] = self::HOST;
        $request->userId = 'link-user-42';
        // A forged link_user_id in the query must have no effect.
        $request->query = ['redirect_uri' => '/app/settings/account', 'link_user_id' => 'victim-1'];

        $response = $controller->authorizeLink($request, []);

        $this->assertSame(302, $response->statusCode);
        $location = $response->headers['Location'] ?? '';
        $params = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $clientState = is_string($params['state'] ?? null) ? $params['state'] : '';

        $this->assertStringNotContainsString('link-user-42', $clientState);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) base64_decode($clientState, true), true);
        $this->assertArrayNotHasKey('link_user_id', $decoded);

        $sid = is_string($decoded['sid'] ?? null) ? $decoded['sid'] : '';
        $stored = $store->consume($sid);
        $this->assertIsArray($stored);
        $this->assertArrayHasKey('context', $stored);
        $this->assertSame('link', $stored['context']['intent'] ?? null);
        $this->assertSame('link-user-42', $stored['context']['link_user_id'] ?? null);
    }

    public function test_authorize_link_requires_authentication(): void
    {
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            new InMemoryOAuth2StateStore(),
        );

        $request = new Request();
        $request->headers['Host'] = self::HOST;
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorizeLink($request, []);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * A link must persist the GitHub-verified identity against the state's user
     * and must NOT mint a session (no cookies, no user creation, no tokens).
     */
    public function test_link_persists_verified_identity_and_never_mints_cookies(): void
    {
        $http = $this->httpReturningProfile(4711, 'linkme@example.com');

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-link', ['intent' => 'link', 'link_user_id' => 'link-user-7']);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->never())->method('findOrCreateByExternalId');
        $jwt = $this->createMock(JwtHandler::class);
        $jwt->expects($this->never())->method('createAccessToken');
        $jwt->expects($this->never())->method('createRefreshToken');

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn(null);
        $identities->expects($this->once())
            ->method('create')
            ->with('link-user-7', 'github', '', 'github.4711', $this->anything())
            ->willReturn('identity-1');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $jwt,
            $store,
            null,
            null,
            $identities,
        );

        $response = $controller->callback($this->callbackRequest('sid-link', '/app/settings/account'), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app/settings/account?linked=github', $response->headers['Location'] ?? '');
        $this->assertNoSessionCookies($response, 'a link must never mint session cookies');
    }

    public function test_link_conflict_returns_409(): void
    {
        $http = $this->httpReturningProfile(4712, null);

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-conflict', ['intent' => 'link', 'link_user_id' => 'link-user-7']);

        $identities = $this->createMock(UserIdentityRepository::class);
        // Already owned by SOMEONE ELSE.
        $identities->method('findByProviderExternalId')->willReturn([
            'user_id' => 'other-user-9',
        ]);
        $identities->expects($this->never())->method('create');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
            null,
            null,
            $identities,
        );

        $response = $controller->callback($this->callbackRequest('sid-conflict', '/app'), []);

        $this->assertSame(409, $response->statusCode);
        $this->assertSame('identity_already_linked', $this->errorCode($response));
        $this->assertNoSessionCookies($response);
    }

    public function test_link_idempotent_when_already_owned_by_the_same_user(): void
    {
        $http = $this->httpReturningProfile(4713, null);

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-idem', ['intent' => 'link', 'link_user_id' => 'link-user-7']);

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn(['user_id' => 'link-user-7']);
        $identities->expects($this->never())->method('create');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
            null,
            null,
            $identities,
        );

        $response = $controller->callback($this->callbackRequest('sid-idem', '/app'), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertStringContainsString('linked=github', $response->headers['Location'] ?? '');
    }

    /**
     * A create() failure whose re-read finds the row is a genuine duplicate → 409
     * (the DB UNIQUE index race backstop).
     */
    public function test_link_create_race_reread_owned_by_other_user_is_409(): void
    {
        $http = $this->httpReturningProfile(4714, null);

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-race', ['intent' => 'link', 'link_user_id' => 'link-user-7']);

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')
            ->willReturnOnConsecutiveCalls(null, ['user_id' => 'other-user-9']);
        $identities->method('create')->willThrowException(new \RuntimeException('duplicate key'));

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
            null,
            null,
            $identities,
        );

        $response = $controller->callback($this->callbackRequest('sid-race', '/app'), []);

        $this->assertSame(409, $response->statusCode);
    }

    /**
     * A create() failure whose re-read finds NOTHING is a real server error: it is
     * re-thrown and surfaces as `?error=internal`, never as a mislabeled 409.
     */
    public function test_link_create_failure_with_empty_reread_is_not_409(): void
    {
        $http = $this->httpReturningProfile(4715, null);

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-fail', ['intent' => 'link', 'link_user_id' => 'link-user-7']);

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn(null);
        $identities->method('create')->willThrowException(new \RuntimeException('connection lost'));

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
            null,
            null,
            $identities,
        );

        $response = $controller->callback($this->callbackRequest('sid-fail', '/app'), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app?error=internal', $response->headers['Location'] ?? '');
        $this->assertNoSessionCookies($response);
    }

    public function test_link_without_identity_repository_refuses_with_503(): void
    {
        $http = $this->httpReturningProfile(4716, null);

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-no-repo', ['intent' => 'link', 'link_user_id' => 'link-user-7']);

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-no-repo', '/app'), []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame('link_unavailable', $this->errorCode($response));
    }

    // -----------------------------------------------------------------------
    // Review r1 Finding 4 (MED) — a pre-existing account owning the GitHub
    // e-mail must produce an ACTIONABLE code, not `?error=internal`.
    // -----------------------------------------------------------------------

    public function test_duplicate_email_returns_email_already_registered(): void
    {
        $http = $this->httpReturningProfile(881, 'joe@example.com');

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-dupe');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOrCreateByExternalId')
            ->willThrowException(new \RuntimeException("Duplicate entry 'joe@example.com' for key 'email'"));
        $userRepo->expects($this->once())
            ->method('emailExists')
            ->with('joe@example.com')
            ->willReturn(true);

        $jwt = $this->createMock(JwtHandler::class);
        $jwt->expects($this->never())->method('createAccessToken');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $jwt,
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-dupe', '/app'), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app?error=email_already_registered', $response->headers['Location'] ?? '');
        $this->assertNoSessionCookies($response);
    }

    /**
     * A create failure that is NOT the duplicate-e-mail case stays `internal`.
     */
    public function test_non_duplicate_create_failure_stays_internal(): void
    {
        $http = $this->httpReturningProfile(882, 'fresh@example.com');

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-other-failure');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOrCreateByExternalId')
            ->willThrowException(new \RuntimeException('deadlock found'));
        $userRepo->method('emailExists')->willReturn(false);

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $userRepo,
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-other-failure', '/app'), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app?error=internal', $response->headers['Location'] ?? '');
    }

    // -----------------------------------------------------------------------
    // Review r1 Finding 9 — fixed error codes + the correct query separator.
    // -----------------------------------------------------------------------

    public function test_error_redirect_uses_ampersand_when_target_already_has_a_query(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'error' => 'bad_verification_code',
        ]));

        $store = new InMemoryOAuth2StateStore();
        $this->seedState($store, 'sid-sep');

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $response = $controller->callback($this->callbackRequest('sid-sep', '/app?tab=account'), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app?tab=account&error=token_exchange_failed', $response->headers['Location'] ?? '');
    }

    public function test_provider_error_query_is_not_reflected_verbatim(): void
    {
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            new InMemoryOAuth2StateStore(),
        );

        $request = new Request();
        $request->headers['Host'] = self::HOST;
        $request->query = [
            'error' => '<script>alert(1)</script>',
            'error_description' => 'attacker controlled text',
        ];

        $response = $controller->callback($request, []);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('provider_error', $this->errorCode($response));
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Authorization failed', $body['message'] ?? null);
    }

    public function test_known_provider_error_is_passed_through_from_the_fixed_set(): void
    {
        $controller = new GithubCallbackController(
            $this->registryWithProvider(new FakeOAuth2HttpClient()),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            new InMemoryOAuth2StateStore(),
        );

        $request = new Request();
        $request->headers['Host'] = self::HOST;
        $request->query = ['error' => 'access_denied'];

        $response = $controller->callback($request, []);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('access_denied', $this->errorCode($response));
    }

    /**
     * No SESSION credential may ride this response.
     *
     * Stricter than the `assertSame([], $response->cookies)` it replaces: the only
     * cookie tolerated is the review-r2 NEW-10 EXPIRY of the correlation cookie
     * (empty value + Max-Age 0), and a real session/refresh cookie still fails.
     */
    private function assertNoSessionCookies(\Phlix\Server\Http\Response $response, string $message = ''): void
    {
        foreach ($response->cookies as $cookie) {
            $this->assertSame(
                self::CORRELATION_COOKIE,
                $cookie['name'],
                $message !== '' ? $message : 'unexpected cookie on this response',
            );
            $this->assertSame('', $cookie['value'], 'the correlation cookie must be CLEARED, not re-set');
            $this->assertSame(0, $cookie['maxAge']);
        }
    }

    /**
     * The `error` code from a JSON response body.
     */
    private function errorCode(\Phlix\Server\Http\Response $response): ?string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return null;
        }

        return is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
    }

    /**
     * A FakeOAuth2HttpClient wired for a successful token exchange + profile fetch.
     */
    private function httpReturningProfile(int $githubId, ?string $email): FakeOAuth2HttpClient
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'access_token' => 'gho_test',
        ]));
        $http->queue('GET', GithubOAuthProvider::USER_API_URL, 200, (string) json_encode([
            'id' => $githubId,
            'login' => 'user' . $githubId,
            'name' => 'User ' . $githubId,
            'email' => $email,
        ]));

        return $http;
    }
}
