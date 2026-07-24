<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Github\Controller\GithubCallbackController;
use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\OAuth2\InMemoryOAuth2StateStore;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use Phlix\Tests\Unit\Plugins\OAuth2\FakeOAuth2HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Covers the GitHub OAuth2 login flow hardening (mirrors OidcPkceTest): PKCE +
 * CSRF state, same-origin redirect allowlist, cookie session delivery, and
 * provider-scoped account resolution.
 *
 * @covers \Phlix\Plugins\Github\Controller\GithubCallbackController
 */
final class GithubCallbackControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    private function registryWithProvider(FakeOAuth2HttpClient $http): AuthProviderRegistry
    {
        $registry = new AuthProviderRegistry();
        $registry->registerProvider(
            new GithubOAuthProvider('client-id', 'client-secret', GithubOAuthProvider::DEFAULT_SCOPES, $http),
        );

        return $registry;
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

        $request = new Request();
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

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

        $request = new Request();
        $request->query = ['redirect_uri' => 'https://evil.example/callback'];

        $response = $controller->authorize($request, []);

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

        $request = new Request();
        $request->query = ['redirect_uri' => '/app'];

        $response = $controller->authorize($request, []);

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

        $state = base64_encode((string) json_encode(['sid' => 'never', 'redirect_uri' => '/app']));
        $request = new Request();
        $request->query = ['code' => 'x', 'state' => $state];

        $response = $controller->callback($request, []);

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
        $store->put('sid-1', 'verifier-1', null);

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

        $state = base64_encode((string) json_encode(['sid' => 'sid-1', 'redirect_uri' => '/app']));
        $request = new Request();
        $request->query = ['code' => 'auth-code', 'state' => $state];

        $response = $controller->callback($request, []);

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
        $store->put('sid-2', 'verifier-2', null);

        $controller = new GithubCallbackController(
            $this->registryWithProvider($http),
            $this->createMock(UserRepository::class),
            $this->createMock(JwtHandler::class),
            $store,
        );

        $state = base64_encode((string) json_encode(['sid' => 'sid-2', 'redirect_uri' => '/app']));
        $request = new Request();
        $request->query = ['code' => 'bad', 'state' => $state];

        $response = $controller->callback($request, []);

        $this->assertSame(302, $response->statusCode);
        $this->assertStringContainsString('/app?error=', $response->headers['Location'] ?? '');
    }
}
