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
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlix_oidc_callback_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        DiscoveryDocument::clearMemoryCache();
        // The JWKS cache is a static keyed on provider URL and would otherwise
        // leak a signing key between the happy-path tests here.
        IdTokenValidator::clearJwksCache();
    }

    protected function tearDown(): void
    {
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
        $request->query = ['redirect_uri' => '/callback'];

        $response = $controller->authorize($request, []);

        $this->assertSame(302, $response->statusCode);
        $this->assertArrayHasKey('Location', $response->headers);
        $this->assertStringContainsString('https://example.com/authorize', $response->headers['Location']);
    }

    public function test_callback_without_code_returns_error(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $request = new Request();
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
        $stateStore->put('sid-xyz', 'verifier-abc', 'test-nonce');

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

        $request = new Request();
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
        $stateStore->put('sid-happy', 'verifier-happy', $nonce);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler, $stateStore);

        $state = base64_encode((string) json_encode([
            'sid' => 'sid-happy',
            'redirect_uri' => '/app/home',
        ], JSON_THROW_ON_ERROR));

        $request = new Request();
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
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('provider_not_configured', $body['error']);
        $this->assertFalse($registry->hasProvider('oidc'));
    }

    // -----------------------------------------------------------------------
    // Test fixtures.
    // -----------------------------------------------------------------------

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
    /** @var array<string, array{code_verifier: string, nonce: string}> */
    private array $entries = [];

    public function put(string $state, string $codeVerifier, string $nonce): void
    {
        $this->entries[$state] = [
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
        ];
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
