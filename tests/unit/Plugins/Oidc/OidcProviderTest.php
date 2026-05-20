<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Oidc\DiscoveryDocument;
use Phlix\Plugins\Oidc\OidcProvider;
use Phlix\Plugins\Oidc\OidcValidationException;
use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;
use Phlix\Shared\Auth\UserInfo;
use RuntimeException;

/**
 * @covers \Phlix\Plugins\Oidc\OidcProvider
 */
final class OidcProviderTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlix_oidc_provider_test_' . uniqid();
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

    public function test_implements_provider_interface(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $this->assertInstanceOf(ProviderInterface::class, $provider);
    }

    public function test_name_returns_oidc(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $this->assertSame('oidc', $provider->name());
    }

    public function test_supports_authentication_with_code(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $this->assertTrue($provider->supportsAuthentication(['code' => 'abc123']));
        $this->assertTrue($provider->supportsAuthentication(['code' => 'xyz', 'redirect_uri' => 'http://example.com']));
    }

    public function test_supports_authentication_with_access_token(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $this->assertTrue($provider->supportsAuthentication(['access_token' => 'abc123']));
    }

    public function test_does_not_support_authentication_without_credentials(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $this->assertFalse($provider->supportsAuthentication([]));
        $this->assertFalse($provider->supportsAuthentication(['other' => 'value']));
    }

    public function test_authenticate_with_invalid_code_throws(): void
    {
        $discovery = new DiscoveryDocument('https://invalid.example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $result = $provider->authenticate([
            'code' => 'invalid-code',
            'redirect_uri' => 'http://localhost/callback',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->error);
    }

    public function test_authenticate_with_empty_credentials_returns_failure(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $result = $provider->authenticate([]);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('no_supported_credentials', $result->error);
    }

    public function test_get_user_info_returns_null(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $this->assertNull($provider->getUserInfo('oidc.user123'));
        $this->assertNull($provider->getUserInfo('invalid-prefix.user123'));
    }

    public function test_link_account_does_nothing(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $provider->linkAccount('local-user-id', ['oidc' => 'external-id']);
        $this->assertTrue(true);
    }

    public function test_get_discovery(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $this->assertSame($discovery, $provider->getDiscovery());
    }

    public function test_get_client_id(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'my-client-id', 'client-secret');

        $this->assertSame('my-client-id', $provider->getClientId());
    }

    public function test_build_authorization_url(): void
    {
        $cachedData = [
            'issuer' => 'https://example.com',
            'authorization_endpoint' => 'https://example.com/authorize',
            'token_endpoint' => 'https://example.com/token',
            'jwks_uri' => 'https://example.com/jwks',
            '_cached_at' => time(),
        ];
        $cacheFile = $this->cacheDir . '/discovery_' . md5('https://example.com') . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret', 'openid profile email');

        $url = $provider->buildAuthorizationUrl('http://localhost/callback');

        $this->assertStringContainsString('https://example.com/authorize', $url);
        $this->assertStringContainsString('client_id=client-id', $url);
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%2Fcallback', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('scope=openid+profile+email', $url);
        $this->assertStringContainsString('nonce=', $url);
    }

    public function test_build_authorization_url_with_custom_state_and_nonce(): void
    {
        $cachedData = [
            'issuer' => 'https://example.com',
            'authorization_endpoint' => 'https://example.com/authorize',
            'token_endpoint' => 'https://example.com/token',
            'jwks_uri' => 'https://example.com/jwks',
            '_cached_at' => time(),
        ];
        $cacheFile = $this->cacheDir . '/discovery_' . md5('https://example.com') . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $url = $provider->buildAuthorizationUrl(
            'http://localhost/callback',
            'custom-state-value',
            'custom-nonce-value'
        );

        $this->assertStringContainsString('state=custom-state-value', $url);
        $this->assertStringContainsString('nonce=custom-nonce-value', $url);
    }

    public function test_auth_result_failure_with_no_supported_credentials(): void
    {
        $discovery = new DiscoveryDocument('https://example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $result = $provider->authenticate(['unsupported' => 'credential']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('no_supported_credentials', $result->error);
    }

    public function test_authenticate_unknown_provider_returns_false(): void
    {
        $discovery = new DiscoveryDocument('https://unknown.example.com', $this->cacheDir);
        $provider = new OidcProvider($discovery, 'client-id', 'client-secret');

        $result = $provider->authenticate(['unknown' => 'credentials']);

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->error);
    }
}
