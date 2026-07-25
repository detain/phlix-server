<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer as JwsCompactSerializer;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Plugins\Oidc\Controller\OidcCallbackController;
use Phlix\Plugins\Oidc\IdTokenValidator;
use Phlix\Plugins\Oidc\OidcHttpClient;
use Phlix\Plugins\Oidc\OidcProvider;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Plugins\Oidc\DiscoveryDocument;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Response as WorkermanResponse;

/**
 * @covers \Phlix\Plugins\Oidc\Controller\OidcCallbackController
 */
final class OidcCallbackControllerTest extends TestCase
{
    /**
     * Review r2 — the raw browser-binding secret seeded into hand-built state
     * entries. The authorize leg issues a random one; the callback only ever sees
     * its SHA-256 in the state context.
     */
    private const string CORRELATION = 'oidc-correlation-secret-for-tests';

    /** The cookie the OIDC flow binds its issued state to (distinct from GitHub's). */
    private const string CORRELATION_COOKIE = 'phlix_oauth_oidc';

    /** The Host every request in this file sends, and the configured domain. */
    private const string HOST = 'phlix.test';

    private string $cacheDir;

    /** The ambient PHLIX_DOMAIN, restored after every test. */
    private string|false $originalDomain = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlix_oidc_callback_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        DiscoveryDocument::clearMemoryCache();
        // The JWKS cache is a static keyed on provider URL and would otherwise
        // leak a signing key between the happy-path tests here.
        IdTokenValidator::clearJwksCache();
        // Review r3 — a Host-derived callback is only produced when the operator
        // configured a public hostname (PHLIX_DOMAIN); with it unset the flow fails
        // CLOSED. These tests exercise a CONFIGURED box, so set it to the Host they
        // send. The fail-closed behaviour has its own tests, which unset it.
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
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->cacheDir);
        }
        DiscoveryDocument::clearMemoryCache();
        IdTokenValidator::clearJwksCache();
    }

    public function test_authorize_redirect_without_redirect_uri_returns_error(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = [];

        $response = $controller->authorize($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('missing_redirect_uri', $body['error']);
    }

    public function test_authorize_redirect_without_provider_returns_error(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        // Relative, same-origin target so it passes the redirect allowlist and
        // the request reaches the provider-registration check this test asserts.
        $request->query = ['redirect_uri' => '/callback'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('provider_not_configured', $body['error']);
    }

    public function test_authorize_redirect_with_provider_returns_302(): void
    {
        $registry = new AuthProviderRegistry();
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $cachedData = [
            'issuer' => 'https://example.com',
            'authorization_endpoint' => 'https://example.com/authorize',
            'token_endpoint' => 'https://example.com/token',
            'jwks_uri' => 'https://example.com/jwks',
            '_cached_at' => time(),
        ];
        $cacheFile = $this->cacheDir . '/discovery_' . md5('https://example.com') . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $registry->registerProvider($provider);

        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => '/callback'];

        $response = $controller->authorize($request, []);

        $this->assertSame(302, $response->statusCode);
        $this->assertArrayHasKey('Location', $response->headers);
        $this->assertStringContainsString('https://example.com/authorize', $response->headers['Location']);
    }

    /**
     * S48 review r1 Finding 1 (HIGH) — the outbound `redirect_uri` must be an
     * ABSOLUTE URL. The S44 flow sent the relative `/auth/oidc/callback`, which no
     * IdP can match against its registered redirect URI, so the authorize page
     * answered `redirect_uri_mismatch` and the browser never came back. This test
     * fails if anyone reverts to a path-only callback.
     */
    public function test_authorize_sends_an_absolute_redirect_uri(): void
    {
        $registry = $this->makeRegistryWithCachedDiscovery();
        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
        );

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(302, $response->statusCode);
        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $redirectUri = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';

        $this->assertTrue(
            \Phlix\Plugins\OAuth2\CallbackUrl::isAbsolute($redirectUri),
            'the authorize redirect_uri must be absolute, not a path',
        );
        $this->assertSame('https://phlix.test/auth/oidc/callback', $redirectUri);
    }

    /**
     * The authorize-time redirect_uri is captured server-side so the token
     * exchange replays the IDENTICAL value (the IdP rejects a mismatch).
     */
    public function test_authorize_binds_the_callback_url_into_the_server_side_state(): void
    {
        $registry = $this->makeRegistryWithCachedDiscovery();
        $stateStore = new InMemoryOidcStateStore();
        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
        );

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $clientState = is_string($params['state'] ?? null) ? $params['state'] : '';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) base64_decode($clientState, true), true);
        $sid = is_string($decoded['sid'] ?? null) ? $decoded['sid'] : '';

        $stored = $stateStore->consume($sid);
        $this->assertIsArray($stored);
        $this->assertSame(
            is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '',
            $stored['context']['callback_url'] ?? null,
        );
    }

    /**
     * An operator-configured absolute redirect_uri (the OIDC plugin setting) wins
     * over the request-derived one.
     */
    public function test_configured_absolute_redirect_uri_is_used(): void
    {
        $store = new \Phlix\Tests\Unit\Plugins\Github\InMemoryPluginSettingsRepository();
        $store->save('oidc', [
            'provider_url' => 'https://example.com',
            'client_id' => 'client-id',
            'redirect_uri' => 'https://sso.example.org/auth/oidc/callback',
        ]);

        $controller = new OidcCallbackController(
            $this->makeRegistryWithCachedDiscovery(),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            new InMemoryOidcStateStore(),
            null,
            null,
            null,
            new \Phlix\Plugins\Oidc\Plugin($store),
        );

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame('https://sso.example.org/auth/oidc/callback', $params['redirect_uri'] ?? null);
    }

    /**
     * With neither a configured redirect_uri nor a Host header no absolute
     * callback can be built — answer 503 instead of sending a value the IdP is
     * guaranteed to reject.
     */
    public function test_authorize_503_when_callback_url_cannot_be_resolved(): void
    {
        $controller = new OidcCallbackController(
            $this->makeRegistryWithCachedDiscovery(),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
        );

        $request = new Request(); // no Host
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('callback_url_not_configured', $body['error'] ?? null);
    }

    /**
     * The state context an authorize() would have written: the browser-binding
     * fingerprint (review r2) plus whatever else the flow adds.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function boundContext(array $extra = []): array
    {
        return array_merge(
            [\Phlix\Plugins\OAuth2\StateCorrelation::CONTEXT_KEY => hash('sha256', self::CORRELATION)],
            $extra,
        );
    }

    /**
     * Attach the correlation cookie the authorize step set, so the callback is
     * recognised as coming from the browser that started the flow.
     */
    private static function bindBrowser(Request $request, string $secret = self::CORRELATION): Request
    {
        $request->cookies[self::CORRELATION_COOKIE] = $secret;

        return $request;
    }

    /**
     * No SESSION credential may ride this response. Stricter than the
     * `assertSame([], $response->cookies)` it replaces: the only tolerated cookie
     * is the review-r2 NEW-10 EXPIRY of the correlation cookie.
     */
    private function assertNoSessionCookies(Response $response, string $message = ''): void
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
     * A registry holding an OidcProvider whose discovery document is already
     * cached on disk (so authorize() performs no network I/O).
     */
    private function makeRegistryWithCachedDiscovery(): AuthProviderRegistry
    {
        $registry = new AuthProviderRegistry();
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $cachedData = [
            'issuer' => 'https://example.com',
            'authorization_endpoint' => 'https://example.com/authorize',
            'token_endpoint' => 'https://example.com/token',
            'jwks_uri' => 'https://example.com/jwks',
            '_cached_at' => time(),
        ];
        file_put_contents(
            $this->cacheDir . '/discovery_' . md5('https://example.com') . '.json',
            (string) json_encode($cachedData),
        );
        $registry->registerProvider(new OidcProvider($discovery, 'client-id', 'client-secret'));

        return $registry;
    }

    public function test_callback_without_code_returns_error(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = [];

        $response = $controller->callback($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('missing_code', $body['error']);
    }

    public function test_callback_without_state_returns_error(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'some-code'];

        $response = $controller->callback($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('missing_state', $body['error']);
    }

    public function test_callback_with_invalid_state_returns_error(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = [
            'code' => 'some-code',
            'state' => 'invalid-state-that-is-not-base64',
        ];

        $response = $controller->callback($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('invalid_state', $body['error']);
    }

    public function test_callback_with_error_from_provider_returns_error(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = [
            'error' => 'access_denied',
            'error_description' => 'The user denied the request',
        ];

        $response = $controller->callback($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('access_denied', $body['error']);
        $this->assertSame('The user denied the request', $body['message']);
    }

    public function test_callback_without_provider_returns_503(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);

        // Pre-seed the state store with the sid we'll embed in the
        // state envelope so the request gets past the PKCE state check
        // and reaches the provider-registration check (which is what
        // this test is asserting on).
        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-xyz', 'verifier-abc', 'test-nonce', self::boundContext());

        $controller = new OidcCallbackController(
            $registry,
            $userRepository,
            $jwtHandler,
            $stateStore,
        );

        $stateData = json_encode([
            'sid' => 'sid-xyz',
            // Relative, same-origin target so it passes the redirect allowlist and
            // the request reaches the provider check this test asserts.
            'redirect_uri' => '/callback',
        ], JSON_THROW_ON_ERROR);
        $state = base64_encode($stateData);

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = [
            'code' => 'some-code',
            'state' => $state,
        ];

        $response = $controller->callback($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('provider_not_configured', $body['error']);
    }

    // -----------------------------------------------------------------------
    // S44 Finding 1 (HIGH) — redirect_uri open-redirect allowlist.
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeRedirectProvider(): array
    {
        return [
            'absolute https'      => ['https://evil.example/steal'],
            'absolute http'       => ['http://evil.example'],
            'protocol relative'   => ['//evil.example'],
            'backslash bypass'    => ['/\\evil.example'],
            'javascript scheme'   => ['javascript:alert(1)'],
            'crlf header inject'  => ["/ok\r\nSet-Cookie: x=y"],
            'not rooted'          => ['app/home'],
        ];
    }

    /**
     * authorize() must reject any non-same-origin / non-relative return target
     * BEFORE binding it into the state (so it can never be exfiltrated in the
     * callback's 302).
     *
     * @dataProvider unsafeRedirectProvider
     */
    public function test_authorize_rejects_unsafe_redirect_uri(string $redirectUri): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => $redirectUri];

        $response = $controller->authorize($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('invalid_redirect_uri', $body['error']);
    }

    /**
     * callback() must re-validate the return target read back from the state
     * envelope (defence-in-depth) and reject a foreign origin BEFORE any token
     * is minted — no access/refresh token may ever be created for an attacker's
     * redirect target.
     */
    public function test_callback_rejects_cross_origin_redirect_uri_before_minting_tokens(): void
    {
        $registry = new AuthProviderRegistry();

        $userRepository = $this->createMock(UserRepository::class);
        // No user must be created and NO token minted for a hostile redirect.
        $userRepository->expects($this->never())->method('findOrCreateByExternalId');

        $jwtHandler = $this->createMock(JwtHandler::class);
        $jwtHandler->expects($this->never())->method('createAccessToken');
        $jwtHandler->expects($this->never())->method('createRefreshToken');

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-evil', 'verifier', 'nonce');
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler, $stateStore);

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-evil',
            'redirect_uri' => 'https://evil.example/steal',
        ], JSON_THROW_ON_ERROR));

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('invalid_redirect_uri', $body['error']);
        $this->assertSame([], $response->cookies);
    }

    // -----------------------------------------------------------------------
    // S44 Finding 4 (MEDIUM) — OIDC callback happy path.
    // -----------------------------------------------------------------------

    /**
     * Full success path: valid state consume() → real OidcProvider authenticates
     * an RS256-signed ID token → findOrCreateByExternalId('oidc', …) →
     * token mint → 302. Asserts (a) the provider arg threaded to the user repo is
     * 'oidc' (the S46/S47 provider-column foundation), (b) the return target is
     * the allowlisted same-origin path with NO tokens in the query string, and
     * (c) the minted tokens are delivered as HttpOnly SameSite=Lax cookies.
     *
     * This test goes red if the redirect allowlist OR the provider-threading is
     * reverted.
     */
    public function test_callback_happy_path_threads_provider_sets_cookies_same_origin(): void
    {
        // Default (non-insecure) env: the session/refresh cookies MUST carry the
        // Secure attribute. Force the default deterministically so a leaked
        // PHLIX_COOKIE_INSECURE=1 from another test cannot mask a regression.
        putenv('PHLIX_COOKIE_INSECURE');

        $providerUrl = 'https://idp.happy.test';
        $clientId = 'client-id';
        $nonce = 'happy-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())
            ->method('findOrCreateByExternalId')
            // The provider MUST be threaded as 'oidc', never 'external'.
            ->with('oidc', 'oidc.oidc-subject-123', 'oidc-user@example.com', 'OIDC User')
            ->willReturn('user-777');

        $jwtHandler = $this->createMock(JwtHandler::class);
        $jwtHandler->method('createAccessToken')->with('user-777')->willReturn('access-token-xyz');
        $jwtHandler->method('createRefreshToken')->with('user-777')->willReturn('refresh-token-xyz');
        $jwtHandler->method('accessTtl')->willReturn(3600);
        $jwtHandler->method('refreshTtl')->willReturn(604800);

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-happy', 'verifier-happy', $nonce, self::boundContext());
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler, $stateStore);

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-happy',
            'redirect_uri' => '/app/home',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        // 302 to the allowlisted same-origin path, with NO tokens in the URL.
        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app/home', $response->headers['Location']);
        $this->assertStringNotContainsString('token=', $response->headers['Location']);
        $this->assertStringNotContainsString('refresh=', $response->headers['Location']);

        // Tokens delivered as HttpOnly SameSite=Lax cookies (Finding 1).
        $byName = [];
        foreach ($response->cookies as $cookie) {
            $byName[$cookie['name']] = $cookie;
        }
        $this->assertArrayHasKey('phlix_session', $byName);
        $this->assertSame('access-token-xyz', $byName['phlix_session']['value']);
        $this->assertTrue($byName['phlix_session']['httpOnly']);
        $this->assertSame('Lax', $byName['phlix_session']['sameSite']);
        $this->assertSame(3600, $byName['phlix_session']['maxAge']);
        // Secure MUST be set under the default env so the credential never
        // rides plain HTTP (only PHLIX_COOKIE_INSECURE=1 drops it).
        $this->assertTrue($byName['phlix_session']['secure']);

        $this->assertArrayHasKey('phlix_refresh', $byName);
        $this->assertSame('refresh-token-xyz', $byName['phlix_refresh']['value']);
        $this->assertTrue($byName['phlix_refresh']['httpOnly']);
        $this->assertSame('Lax', $byName['phlix_refresh']['sameSite']);
        $this->assertSame(604800, $byName['phlix_refresh']['maxAge']);
        $this->assertTrue($byName['phlix_refresh']['secure']);
    }

    // -----------------------------------------------------------------------
    // S44 Finding 3 (MEDIUM) — request-path self-heal of the worker registry.
    // -----------------------------------------------------------------------

    /**
     * With the persisted flag ON but the provider not yet registered in THIS
     * worker (it booted before OIDC was enabled), the request lazily registers
     * the provider via the bootstrapper and succeeds instead of 503-ing.
     */
    public function test_authorize_lazily_registers_provider_via_bootstrapper(): void
    {
        $providerUrl = 'https://idp.lazy.test';
        $registry = new AuthProviderRegistry();
        $provider = $this->realOidcProviderForAuthorize($providerUrl);

        $bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $bootstrapper->method('ensureProviderRegistered')->willReturnCallback(
            static function (string $name) use ($registry, $provider): bool {
                if ($name === 'oidc' && !$registry->hasProvider('oidc')) {
                    $registry->registerProvider($provider);
                }
                return true;
            }
        );

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            null,
            null,
            $bootstrapper,
        );

        // Provider absent at the start — only the self-heal can make this work.
        $this->assertFalse($registry->hasProvider('oidc'));

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(302, $response->statusCode);
        $this->assertStringContainsString($providerUrl . '/authorize', $response->headers['Location']);
        $this->assertTrue($registry->hasProvider('oidc'));
    }

    /**
     * With the persisted flag OFF but a stale registration still present in THIS
     * worker, the request-path self-heal drops it and the request is refused
     * (503) rather than serving a since-disabled provider.
     */
    public function test_authorize_refuses_provider_when_flag_off_via_bootstrapper(): void
    {
        $providerUrl = 'https://idp.stale.test';
        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->realOidcProviderForAuthorize($providerUrl));
        $this->assertTrue($registry->hasProvider('oidc'));

        $bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $bootstrapper->method('ensureProviderRegistered')->willReturnCallback(
            static function (string $name) use ($registry): bool {
                if ($name === 'oidc' && $registry->hasProvider('oidc')) {
                    $registry->unregisterProvider('oidc');
                }
                return false;
            }
        );

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            null,
            null,
            $bootstrapper,
        );

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('provider_not_configured', $body['error']);
        $this->assertFalse($registry->hasProvider('oidc'));
    }

    // -----------------------------------------------------------------------
    // S45 — account-link flow (authorizeLink + callback link branch).
    // -----------------------------------------------------------------------

    public function test_authorize_link_requires_authenticated_user(): void
    {
        $controller = new OidcCallbackController(
            new AuthProviderRegistry(),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
        );

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => '/app/settings'];
        // No userId → unauthenticated.

        $response = $controller->authorizeLink($request, []);

        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.required', $body['code']);
    }

    /**
     * The initiating user id is bound into the SERVER-SIDE state store, NOT the
     * client-visible `state` query parameter — the linchpin that stops a client
     * from linking an external identity onto someone else's account.
     */
    public function test_authorize_link_binds_user_into_server_side_state_only(): void
    {
        $providerUrl = 'https://idp.link.test';
        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->realOidcProviderForAuthorize($providerUrl));

        $stateStore = new InMemoryOidcStateStore();
        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
        );

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->userId = 'link-user-42';
        $request->query = ['redirect_uri' => '/app/settings/account'];

        $response = $controller->authorizeLink($request, []);

        $this->assertSame(302, $response->statusCode);
        $location = $response->headers['Location'];
        $this->assertStringContainsString($providerUrl . '/authorize', $location);

        // Recover the client-visible `state` param from the authorize URL.
        $queryString = (string) parse_url($location, PHP_URL_QUERY);
        parse_str($queryString, $params);
        $clientState = is_string($params['state'] ?? null) ? $params['state'] : '';
        $this->assertNotSame('', $clientState);

        // The client `state` must carry the opaque sid + redirect_uri ONLY — never
        // the initiating user id.
        $decoded = json_decode((string) base64_decode($clientState, true), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('sid', $decoded);
        $this->assertStringNotContainsString('link-user-42', $clientState);
        $this->assertArrayNotHasKey('link_user_id', $decoded);

        // But the SERVER-SIDE store DOES carry the trusted link context.
        $sid = is_string($decoded['sid']) ? $decoded['sid'] : '';
        $stored = $stateStore->consume($sid);
        $this->assertIsArray($stored);
        $this->assertArrayHasKey('context', $stored);
        $this->assertSame('link', $stored['context']['intent']);
        $this->assertSame('link-user-42', $stored['context']['link_user_id']);
    }

    /**
     * Callback link branch: a valid link-intent state links the IdP-verified
     * identity to the state's link_user_id and does NOT mint a login session or
     * create a user.
     */
    public function test_callback_link_branch_links_verified_identity_no_tokens(): void
    {
        $providerUrl = 'https://idp.linkcb.test';
        $clientId = 'client-id';
        $nonce = 'link-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        // No login side effects may occur on a link.
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->never())->method('findOrCreateByExternalId');
        $jwtHandler = $this->createMock(JwtHandler::class);
        $jwtHandler->expects($this->never())->method('createAccessToken');
        $jwtHandler->expects($this->never())->method('createRefreshToken');

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn(null);
        $identities->expects($this->once())
            ->method('create')
            // The VERIFIED sub-derived external id, linked to the STATE's user.
            ->with('link-user-7', 'oidc', '', 'oidc.oidc-subject-123', $this->anything())
            ->willReturn('id-new');

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-link', 'verifier', $nonce, self::boundContext([
            'intent' => 'link',
            'link_user_id' => 'link-user-7',
        ]));

        $controller = new OidcCallbackController(
            $registry,
            $userRepository,
            $jwtHandler,
            $stateStore,
            null,
            null,
            $identities,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-link',
            'redirect_uri' => '/app/settings/account',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(302, $response->statusCode);
        $this->assertStringContainsString('/app/settings/account', $response->headers['Location']);
        $this->assertStringContainsString('linked=oidc', $response->headers['Location']);
        // No login cookies/tokens on a link.
        $this->assertNoSessionCookies($response);
        $this->assertStringNotContainsString('token', $response->headers['Location']);
    }

    /**
     * SECURITY LINCHPIN: a client-supplied `external_id` in the callback query is
     * IGNORED — the linked identity is the IdP-verified one.
     */
    public function test_callback_link_branch_ignores_client_supplied_external_id(): void
    {
        $providerUrl = 'https://idp.linksec.test';
        $clientId = 'client-id';
        $nonce = 'link-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn(null);
        $identities->expects($this->once())
            ->method('create')
            // Must be the VERIFIED id, never the attacker's 'oidc.victim'.
            ->with('link-user-7', 'oidc', '', 'oidc.oidc-subject-123', $this->anything())
            ->willReturn('id-new');

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-link', 'verifier', $nonce, self::boundContext([
            'intent' => 'link',
            'link_user_id' => 'link-user-7',
        ]));

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
            null,
            null,
            $identities,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-link',
            'redirect_uri' => '/app',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = [
            'code' => 'auth-code',
            'state' => $state,
            // Hostile injected identity — must be ignored.
            'external_id' => 'oidc.victim',
            'link_user_id' => 'victim-user',
        ];

        $response = $controller->callback($request, []);

        $this->assertSame(302, $response->statusCode);
        $this->assertStringContainsString('linked=oidc', $response->headers['Location']);
    }

    public function test_callback_link_branch_conflict_when_owned_by_another_user(): void
    {
        $providerUrl = 'https://idp.linkconf.test';
        $clientId = 'client-id';
        $nonce = 'link-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        $identities = $this->createMock(UserIdentityRepository::class);
        // The verified identity already belongs to a different account.
        $identities->method('findByProviderExternalId')->willReturn([
            'id' => 'existing',
            'user_id' => 'another-user',
            'external_id' => 'oidc.oidc-subject-123',
        ]);
        $identities->expects($this->never())->method('create');

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-link', 'verifier', $nonce, self::boundContext([
            'intent' => 'link',
            'link_user_id' => 'link-user-7',
        ]));

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
            null,
            null,
            $identities,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-link',
            'redirect_uri' => '/app',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(409, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('identity_already_linked', $body['error']);
    }

    public function test_callback_link_branch_idempotent_when_owned_by_same_user(): void
    {
        $providerUrl = 'https://idp.linkidem.test';
        $clientId = 'client-id';
        $nonce = 'link-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn([
            'id' => 'existing',
            'user_id' => 'link-user-7',
            'external_id' => 'oidc.oidc-subject-123',
        ]);
        $identities->expects($this->never())->method('create');

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-link', 'verifier', $nonce, self::boundContext([
            'intent' => 'link',
            'link_user_id' => 'link-user-7',
        ]));

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
            null,
            null,
            $identities,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-link',
            'redirect_uri' => '/app',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(302, $response->statusCode);
        $this->assertStringContainsString('linked=oidc', $response->headers['Location']);
    }

    /**
     * Round-1 Finding 1 (MEDIUM) — a NON-duplicate create() failure on the link
     * path must NOT be mislabeled as a 409 conflict. When create() throws AND the
     * post-throw re-read STILL returns null (the INSERT did not land for a
     * non-duplicate reason), completeLink() re-throws; callback()'s central catch
     * then surfaces it as a genuine server error (302 ?error=internal), never 409.
     *
     * RED against the pre-fix broad-409 logic (which returned a 409
     * `identity_already_linked` on this null re-read).
     */
    public function test_callback_link_branch_create_failure_with_empty_reread_is_not_409(): void
    {
        $providerUrl = 'https://idp.linkfail.test';
        $clientId = 'client-id';
        $nonce = 'link-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        $identities = $this->createMock(UserIdentityRepository::class);
        // Pre-check AND post-throw re-read both null → NOT a duplicate race.
        $identities->method('findByProviderExternalId')->willReturn(null);
        $identities->method('create')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000] [2002] transient connection error'),
        );

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-link', 'verifier', $nonce, self::boundContext([
            'intent' => 'link',
            'link_user_id' => 'link-user-7',
        ]));

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
            null,
            null,
            $identities,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-link',
            'redirect_uri' => '/app',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        // A non-duplicate failure is a server error, NOT a 409 conflict.
        $this->assertNotSame(409, $response->statusCode);
        $this->assertSame(302, $response->statusCode);
        $this->assertStringContainsString('error=internal', $response->headers['Location']);
        $this->assertStringNotContainsString('linked=oidc', $response->headers['Location']);
        // No login side effects on the failure path.
        $this->assertNoSessionCookies($response);
    }

    /**
     * Round-1 Finding 2 (mode-confusion) — a normal LOGIN-mode callback where the
     * attacker injects intent=link / link_user_id into the CLIENT-visible state
     * envelope AND the query must IGNORE them (intent/link_user_id are read ONLY
     * from the server-side state store context). It performs a normal login
     * (findOrCreate + token mint) and creates NO user_identities link.
     *
     * RED if a regression ever merged client `state` into the server `$context`.
     */
    public function test_callback_login_mode_ignores_client_injected_link_intent(): void
    {
        // Force the default (Secure) cookie env deterministically.
        putenv('PHLIX_COOKIE_INSECURE');

        $providerUrl = 'https://idp.modeconf.test';
        $clientId = 'client-id';
        $nonce = 'login-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        // A normal login MUST run: findOrCreate is called with the IdP-verified id.
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())
            ->method('findOrCreateByExternalId')
            ->with('oidc', 'oidc.oidc-subject-123', 'oidc-user@example.com', 'OIDC User')
            ->willReturn('login-user-1');

        $jwtHandler = $this->createMock(JwtHandler::class);
        $jwtHandler->method('createAccessToken')->with('login-user-1')->willReturn('access-xyz');
        $jwtHandler->method('createRefreshToken')->with('login-user-1')->willReturn('refresh-xyz');
        $jwtHandler->method('accessTtl')->willReturn(3600);
        $jwtHandler->method('refreshTtl')->willReturn(604800);

        // No link may EVER be created on a login-mode callback.
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        // Server-side state has NO link context → this is LOGIN mode.
        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-login', 'verifier', $nonce, self::boundContext());

        $controller = new OidcCallbackController(
            $registry,
            $userRepository,
            $jwtHandler,
            $stateStore,
            null,
            null,
            $identities,
        );

        // The attacker injects link intent into the CLIENT-visible state envelope.
        $state = base64_encode((string) json_encode([
            'sid' => 'sid-login',
            'redirect_uri' => '/app/home',
            'intent' => 'link',
            'link_user_id' => 'victim-user',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = [
            'code' => 'auth-code',
            'state' => $state,
            // …and via the raw query string too — both must be ignored.
            'intent' => 'link',
            'link_user_id' => 'victim-user',
        ];

        $response = $controller->callback($request, []);

        // Normal login: 302 to the clean same-origin path with NO `linked` marker,
        // and the session cookies set — the injected link intent was ignored.
        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app/home', $response->headers['Location']);
        $this->assertStringNotContainsString('linked=', $response->headers['Location']);

        $byName = [];
        foreach ($response->cookies as $cookie) {
            $byName[$cookie['name']] = $cookie;
        }
        $this->assertArrayHasKey('phlix_session', $byName);
        $this->assertSame('access-xyz', $byName['phlix_session']['value']);
        $this->assertArrayHasKey('phlix_refresh', $byName);
    }

    /**
     * Round-1 Finding 3 (fail-closed) — when the server-side state says
     * intent=link but link_user_id is missing/empty, completeLink() returns 400
     * `invalid_link_state` and creates NO identity and mints NO tokens.
     */
    public function test_callback_link_branch_fails_closed_on_empty_link_user_id(): void
    {
        $providerUrl = 'https://idp.failclosed.test';
        $clientId = 'client-id';
        $nonce = 'link-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        // No login OR link side effects may occur.
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->never())->method('findOrCreateByExternalId');
        $jwtHandler = $this->createMock(JwtHandler::class);
        $jwtHandler->expects($this->never())->method('createAccessToken');
        $jwtHandler->expects($this->never())->method('createRefreshToken');

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        // intent=link but link_user_id deliberately ABSENT → fail closed.
        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-link', 'verifier', $nonce, self::boundContext([
            'intent' => 'link',
        ]));

        $controller = new OidcCallbackController(
            $registry,
            $userRepository,
            $jwtHandler,
            $stateStore,
            null,
            null,
            $identities,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-link',
            'redirect_uri' => '/app',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('invalid_link_state', $body['error']);
        $this->assertNoSessionCookies($response);
    }

    // -----------------------------------------------------------------------
    // Review r2 — OIDC session fixation: the issued state must be bound to the
    // browser that started the flow (the GitHub Finding-2 fix, propagated).
    //
    // Neither PKCE nor the nonce covers this: both bind the exchange to an
    // authorize request the ATTACKER owns.
    // -----------------------------------------------------------------------

    public function test_authorize_issues_a_browser_binding_cookie_and_stores_only_its_hash(): void
    {
        putenv('PHLIX_COOKIE_INSECURE');

        $stateStore = new InMemoryOidcStateStore();
        $controller = new OidcCallbackController(
            $this->makeRegistryWithCachedDiscovery(),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
        );

        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(302, $response->statusCode);

        $byName = [];
        foreach ($response->cookies as $cookie) {
            $byName[$cookie['name']] = $cookie;
        }
        $this->assertArrayHasKey(self::CORRELATION_COOKIE, $byName, 'the authorize 302 must carry the binding');
        $cookie = $byName[self::CORRELATION_COOKIE];
        $secret = is_string($cookie['value']) ? $cookie['value'] : '';
        // 32 CSPRNG bytes, hex-encoded.
        $this->assertSame(64, strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertTrue($cookie['httpOnly']);
        $this->assertTrue($cookie['secure']);
        $this->assertSame('Lax', $cookie['sameSite']);
        // Max-Age matches the state-row TTL, so no slow-user expiry window.
        $this->assertSame(600, $cookie['maxAge']);

        // Only the HASH is persisted — a DB read cannot forge a matching cookie.
        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) base64_decode(
            is_string($params['state'] ?? null) ? $params['state'] : '',
            true,
        ), true);
        $stored = $stateStore->consume(is_string($decoded['sid'] ?? null) ? $decoded['sid'] : '');
        $this->assertIsArray($stored);
        $this->assertSame(hash('sha256', $secret), $stored['context']['correlation'] ?? null);
        $this->assertStringNotContainsString($secret, (string) json_encode($stored));
    }

    /**
     * The attack this closes: the attacker completes the whole flow with their OWN
     * IdP account and then makes the VICTIM's browser issue the callback. That
     * browser has no correlation cookie → 403, with NO token exchange, no user
     * lookup and no session cookie.
     */
    public function test_callback_without_the_correlation_cookie_is_403_and_exchanges_nothing(): void
    {
        $providerUrl = 'https://idp.fixation.test';
        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->oidcProviderThatMustNotBeUsed($providerUrl));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->never())->method('findOrCreateByExternalId');
        $jwtHandler = $this->createMock(JwtHandler::class);
        $jwtHandler->expects($this->never())->method('createAccessToken');
        $jwtHandler->expects($this->never())->method('createRefreshToken');

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-fix', 'verifier', 'nonce', self::boundContext());

        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler, $stateStore);

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-fix',
            'redirect_uri' => '/app/home',
        ], JSON_THROW_ON_ERROR));

        // The victim's browser: no correlation cookie for this state.
        $request = new Request();
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'attacker-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('invalid_state', $body['error'] ?? null);
        $this->assertArrayNotHasKey('Location', $response->headers);
        $this->assertNoSessionCookies($response);
    }

    public function test_callback_with_a_mismatched_correlation_cookie_is_403(): void
    {
        $providerUrl = 'https://idp.fixation2.test';
        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->oidcProviderThatMustNotBeUsed($providerUrl));

        $jwtHandler = $this->createMock(JwtHandler::class);
        $jwtHandler->expects($this->never())->method('createAccessToken');

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-fix2', 'verifier', 'nonce', self::boundContext());

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $jwtHandler,
            $stateStore,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-fix2',
            'redirect_uri' => '/app/home',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request(), 'some-other-browsers-secret');
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'attacker-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(403, $response->statusCode);
        $this->assertNoSessionCookies($response);
    }

    /**
     * The LINK leg is gated by the SAME check: an unbound link callback must not
     * write a `user_identities` row either.
     */
    public function test_link_callback_without_the_correlation_cookie_links_nothing(): void
    {
        $providerUrl = 'https://idp.fixation3.test';
        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->oidcProviderThatMustNotBeUsed($providerUrl));

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-fix3', 'verifier', 'nonce', self::boundContext([
            'intent' => 'link',
            'link_user_id' => 'link-user-7',
        ]));

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
            null,
            null,
            $identities,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-fix3',
            'redirect_uri' => '/app/settings/account',
        ], JSON_THROW_ON_ERROR));

        $request = new Request(); // no cookie
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(403, $response->statusCode);
        $this->assertStringNotContainsString('linked=oidc', $response->headers['Location'] ?? '');
    }

    /**
     * A state row that predates the binding (no `correlation` in its context) fails
     * CLOSED — the check must not be satisfiable by simply omitting the field.
     */
    public function test_callback_fails_closed_when_the_state_carries_no_correlation(): void
    {
        $providerUrl = 'https://idp.fixation4.test';
        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->oidcProviderThatMustNotBeUsed($providerUrl));

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-legacy', 'verifier', 'nonce'); // no context at all

        $controller = new OidcCallbackController(
            $registry,
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
        );

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-legacy',
            'redirect_uri' => '/app',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(403, $response->statusCode);
        $this->assertNoSessionCookies($response);
    }

    /**
     * Review r2 NEW-10 — the spent correlation cookie is expired on the successful
     * callback, so a stale cookie is self-healing.
     */
    public function test_successful_callback_expires_the_correlation_cookie(): void
    {
        putenv('PHLIX_COOKIE_INSECURE');

        $providerUrl = 'https://idp.clearcookie.test';
        $clientId = 'client-id';
        $nonce = 'clear-nonce';

        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            $this->realOidcProviderReturningSuccess($providerUrl, $clientId, $nonce)
        );

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findOrCreateByExternalId')->willReturn('user-clear');
        $jwtHandler = $this->createMock(JwtHandler::class);
        $jwtHandler->method('createAccessToken')->willReturn('access-clear');
        $jwtHandler->method('createRefreshToken')->willReturn('refresh-clear');
        $jwtHandler->method('accessTtl')->willReturn(3600);
        $jwtHandler->method('refreshTtl')->willReturn(604800);

        $stateStore = new InMemoryOidcStateStore();
        $stateStore->put('sid-clear', 'verifier', $nonce, self::boundContext());

        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler, $stateStore);

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-clear',
            'redirect_uri' => '/app/home',
        ], JSON_THROW_ON_ERROR));

        $request = self::bindBrowser(new Request());
        $request->headers['Host'] = 'phlix.test';
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(302, $response->statusCode);
        $byName = [];
        foreach ($response->cookies as $cookie) {
            $byName[$cookie['name']] = $cookie;
        }
        $this->assertArrayHasKey(self::CORRELATION_COOKIE, $byName);
        $this->assertSame('', $byName[self::CORRELATION_COOKIE]['value']);
        $this->assertSame(0, $byName[self::CORRELATION_COOKIE]['maxAge']);
        // …and the session still lands.
        $this->assertSame('access-clear', $byName['phlix_session']['value'] ?? null);
    }

    // -----------------------------------------------------------------------
    // Review r2 NEW-1 (MED) — the Host-derived redirect_uri needs a host
    // ALLOWLIST. This is the OIDC half of the exploit chain: a wildcard-
    // registered IdP would deliver a victim's `code` to the forged host, and the
    // correlation cookie cannot defend it (the attacker runs authorize).
    // -----------------------------------------------------------------------

    public function test_authorize_refuses_to_derive_a_callback_from_a_foreign_host(): void
    {
        // setUp() has PHLIX_DOMAIN = self::HOST.
        $stateStore = new InMemoryOidcStateStore();
        $controller = new OidcCallbackController(
            $this->makeRegistryWithCachedDiscovery(),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
        );

        $request = new Request();
        $request->headers['Host'] = 'evil.example';
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('callback_url_not_configured', $body['error'] ?? null);
        $this->assertArrayNotHasKey('Location', $response->headers);
        $this->assertSame([], $response->cookies, 'no state may be issued for a forged Host');
        $this->assertSame(0, $stateStore->puts, 'no state row may be issued for a forged Host');

        // The operator's real hostname still derives normally.
        $ok = new Request();
        $ok->headers['Host'] = self::HOST;
        $ok->query = ['redirect_uri' => '/app'];
        $okResponse = $controller->authorize($ok, []);
        $this->assertSame(302, $okResponse->statusCode);
        $params = [];
        parse_str((string) parse_url($okResponse->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame('https://phlix.test/auth/oidc/callback', $params['redirect_uri'] ?? null);
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

        $stateStore = new InMemoryOidcStateStore();
        $controller = new OidcCallbackController(
            $this->makeRegistryWithCachedDiscovery(),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
        );

        $request = new Request();
        $request->headers['Host'] = self::HOST;
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('callback_url_not_configured', $body['error'] ?? null);
        $this->assertArrayNotHasKey('Location', $response->headers);
        $this->assertSame([], $response->cookies, 'no correlation cookie may be issued');
        $this->assertSame(0, $stateStore->puts, 'no state row may be issued');

        // The 503 must be ACTIONABLE — it is the operator's only clue.
        $message = is_string($body['message'] ?? null) ? $body['message'] : '';
        $this->assertStringContainsString('PHLIX_DOMAIN', $message);
        $this->assertStringContainsString('redirect_uri', $message);
    }

    /**
     * The escape hatch: an explicit absolute `redirect_uri` setting keeps FIRST
     * priority and still works with PHLIX_DOMAIN unset, so fail-closed can never
     * lock an operator out.
     */
    public function test_configured_redirect_uri_still_works_without_a_configured_domain(): void
    {
        putenv('PHLIX_DOMAIN');

        $configured = 'https://media.example.org:8443/auth/oidc/callback';
        $settings = new \Phlix\Tests\Unit\Plugins\Github\InMemoryPluginSettingsRepository();
        $settings->save('oidc', [
            'provider_url' => 'https://example.com',
            'client_id' => 'client-id',
            'redirect_uri' => $configured,
        ]);

        $stateStore = new InMemoryOidcStateStore();
        $controller = new OidcCallbackController(
            $this->makeRegistryWithCachedDiscovery(),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $stateStore,
            null,
            null,
            null,
            new \Phlix\Plugins\Oidc\Plugin($settings),
        );

        $request = new Request();
        $request->headers['Host'] = self::HOST;
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(302, $response->statusCode);
        $params = [];
        parse_str((string) parse_url($response->headers['Location'] ?? '', PHP_URL_QUERY), $params);
        $this->assertSame($configured, $params['redirect_uri'] ?? null);
        $this->assertSame(1, $stateStore->puts, 'the flow must still start normally');
    }

    // -----------------------------------------------------------------------
    // Test fixtures.
    // -----------------------------------------------------------------------

    /**
     * A real OidcProvider whose HTTP client must NEVER be touched — used to prove
     * a rejected callback performs no token exchange / JWKS fetch.
     */
    private function oidcProviderThatMustNotBeUsed(string $providerUrl): OidcProvider
    {
        $this->seedDiscoveryCache($providerUrl, [
            'issuer' => $providerUrl,
            'authorization_endpoint' => $providerUrl . '/authorize',
            'token_endpoint' => $providerUrl . '/token',
            'jwks_uri' => $providerUrl . '/jwks',
        ]);

        $httpClient = $this->createMock(OidcHttpClient::class);
        $httpClient->expects($this->never())->method('post');
        $httpClient->expects($this->never())->method('get');

        return new OidcProvider(
            new DiscoveryDocument($providerUrl, $this->cacheDir),
            'client-id',
            'client-secret',
            'openid profile email',
            $httpClient,
        );
    }

    /**
     * A real (final) OidcProvider whose discovery cache is seeded so authorize()
     * can build an authorization URL without any network I/O.
     */
    private function realOidcProviderForAuthorize(string $providerUrl): OidcProvider
    {
        $this->seedDiscoveryCache($providerUrl, [
            'issuer' => $providerUrl,
            'authorization_endpoint' => $providerUrl . '/authorize',
            'token_endpoint' => $providerUrl . '/token',
            'jwks_uri' => $providerUrl . '/jwks',
        ]);

        return new OidcProvider(
            new DiscoveryDocument($providerUrl, $this->cacheDir),
            'client-id',
            'client-secret',
        );
    }

    /**
     * A real (final) OidcProvider wired with a mocked {@see OidcHttpClient} so
     * the authorization-code exchange returns an RS256-signed ID token and the
     * JWKS endpoint returns the matching public key — i.e. authenticate() with a
     * `code` succeeds without any live network or a fake final-class double.
     */
    private function realOidcProviderReturningSuccess(
        string $providerUrl,
        string $clientId,
        string $nonce,
    ): OidcProvider {
        $key = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-kid']);

        $claims = [
            'iss' => $providerUrl,
            'aud' => $clientId,
            'sub' => 'oidc-subject-123',
            'exp' => time() + 3600,
            'iat' => time(),
            'nonce' => $nonce,
            'email' => 'oidc-user@example.com',
            'name' => 'OIDC User',
        ];

        $jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))
            ->create()
            ->withPayload((string) json_encode($claims, JSON_THROW_ON_ERROR))
            ->addSignature($key, ['alg' => 'RS256', 'kid' => 'test-kid'])
            ->build();
        $idToken = (new JwsCompactSerializer())->serialize($jws, 0);

        $jwksJson = (string) json_encode(
            ['keys' => [$key->toPublic()->jsonSerialize()]],
            JSON_THROW_ON_ERROR,
        );

        $this->seedDiscoveryCache($providerUrl, [
            'issuer' => $providerUrl,
            'authorization_endpoint' => $providerUrl . '/authorize',
            'token_endpoint' => $providerUrl . '/token',
            'jwks_uri' => $providerUrl . '/jwks',
        ]);

        $httpClient = $this->createMock(OidcHttpClient::class);
        $httpClient->method('post')->willReturn($this->httpResponse(
            (string) json_encode([
                'id_token' => $idToken,
                'access_token' => 'provider-access-token',
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)
        ));
        $httpClient->method('get')->willReturn($this->httpResponse($jwksJson));

        return new OidcProvider(
            new DiscoveryDocument($providerUrl, $this->cacheDir),
            $clientId,
            'client-secret',
            'openid profile email',
            $httpClient,
        );
    }

    private function httpResponse(string $body): ResponseInterface
    {
        return new WorkermanResponse(200, [], $body);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function seedDiscoveryCache(string $providerUrl, array $document): void
    {
        $document['_cached_at'] = time();
        $cacheFile = $this->cacheDir . '/discovery_' . md5(rtrim($providerUrl, '/')) . '.json';
        file_put_contents($cacheFile, json_encode($document, JSON_THROW_ON_ERROR));
    }
}

/**
 * Lightweight in-memory implementation of {@see \Phlix\Plugins\Oidc\OidcStateStore}
 * used by the OIDC callback tests in this file.
 *
 * @internal Test fixture only.
 */
final class InMemoryOidcStateStore implements \Phlix\Plugins\Oidc\OidcStateStore
{
    /**
     * How many state rows were written — the positive assertion behind the review
     * r3 fail-closed tests ("no state row is issued when the flow is refused").
     */
    public int $puts = 0;

    /** @var array<string, array{code_verifier: string, nonce: string, context?: array<string, mixed>}> */
    private array $entries = [];

    public function put(string $state, string $codeVerifier, string $nonce, ?array $context = null): void
    {
        $this->puts++;
        $entry = [
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
        ];
        if ($context !== null && $context !== []) {
            $entry['context'] = $context;
        }
        $this->entries[$state] = $entry;
    }

    public function consume(string $state): ?array
    {
        if (!isset($this->entries[$state])) {
            return null;
        }
        $entry = $this->entries[$state];
        unset($this->entries[$state]);
        return $entry;
    }
}
