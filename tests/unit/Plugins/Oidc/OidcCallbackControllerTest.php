<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlex\Auth\AuthProviderRegistry;
use Phlex\Auth\JwtHandler;
use Phlex\Auth\UserRepository;
use Phlex\Plugins\Oidc\Controller\OidcCallbackController;
use Phlex\Plugins\Oidc\OidcProvider;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Plugins\Oidc\DiscoveryDocument;

/**
 * @covers \Phlex\Plugins\Oidc\Controller\OidcCallbackController
 */
final class OidcCallbackControllerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlex_oidc_callback_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        DiscoveryDocument::clearMemoryCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->cacheDir);
        }
        DiscoveryDocument::clearMemoryCache();
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
        $request->query = ['redirect_uri' => 'http://localhost/callback'];

        $response = $controller->authorize($request, []);

        $this->assertSame(503, $response->statusCode);
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
        $request->query = ['redirect_uri' => 'http://localhost/callback'];

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
        $body = json_decode($response->body, true);
        $this->assertSame('access_denied', $body['error']);
        $this->assertSame('The user denied the request', $body['message']);
    }

    public function test_callback_without_provider_returns_503(): void
    {
        $registry = new AuthProviderRegistry();
        $userRepository = $this->createMock(UserRepository::class);
        $jwtHandler = $this->createMock(JwtHandler::class);
        $controller = new OidcCallbackController($registry, $userRepository, $jwtHandler);

        $stateData = json_encode([
            'redirect_uri' => 'http://localhost/callback',
            'nonce' => 'test-nonce',
        ]);
        $state = base64_encode($stateData);

        $request = new Request();
        $request->query = [
            'code' => 'some-code',
            'state' => $state,
        ];

        $response = $controller->callback($request, []);

        $this->assertSame(503, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('provider_not_configured', $body['error']);
    }
}
