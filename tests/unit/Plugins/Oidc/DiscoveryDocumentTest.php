<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Oidc\DiscoveryDocument;
use RuntimeException;

/**
 * @covers \Phlix\Plugins\Oidc\DiscoveryDocument
 */
final class DiscoveryDocumentTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlix_oidc_test_' . uniqid();
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

    public function test_cached_discovery(): void
    {
        $providerUrl = 'https://example.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $cachedData = [
            'issuer' => 'https://example.com',
            'authorization_endpoint' => 'https://example.com/authorize',
            'token_endpoint' => 'https://example.com/token',
            'userinfo_endpoint' => 'https://example.com/userinfo',
            'jwks_uri' => 'https://example.com/jwks',
            '_cached_at' => time(),
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $result = $doc->getDocument();

        $this->assertSame('https://example.com', $result['issuer']);
        $this->assertSame('https://example.com/authorize', $result['authorization_endpoint']);
    }

    public function test_fetches_and_caches(): void
    {
        $providerUrl = 'https://oidc-provider.example.com';

        $discovery = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $this->assertSame($providerUrl, $discovery->getProviderUrl());

        $cachedData = [
            'issuer' => 'https://oidc-provider.example.com',
            'custom_key' => 'custom_value',
            '_cached_at' => time(),
        ];
        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $this->assertNull($discovery->get('nonexistent_key'));
        $this->assertSame('custom_value', $discovery->get('custom_key'));
    }

    public function test_get_returns_cached_value(): void
    {
        $providerUrl = 'https://test.example.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $cachedData = [
            'issuer' => 'https://test.example.com',
            'custom_key' => 'custom_value',
            '_cached_at' => time(),
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $this->assertSame('custom_value', $doc->get('custom_key'));
        $this->assertNull($doc->get('nonexistent'));
    }

    public function test_issuer_returns_value(): void
    {
        $providerUrl = 'https://issuer-test.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $cachedData = [
            'issuer' => 'https://issuer-test.com',
            '_cached_at' => time(),
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $this->assertSame('https://issuer-test.com', $doc->issuer());
    }

    public function test_issuer_throws_when_missing(): void
    {
        $providerUrl = 'https://missing-issuer.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $cachedData = [
            '_cached_at' => time(),
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OIDC discovery document missing issuer');
        $doc->issuer();
    }

    public function test_authorization_endpoint(): void
    {
        $providerUrl = 'https://authz.example.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $cachedData = [
            'issuer' => 'https://authz.example.com',
            'authorization_endpoint' => 'https://authz.example.com/oauth/authorize',
            '_cached_at' => time(),
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $this->assertSame('https://authz.example.com/oauth/authorize', $doc->authorizationEndpoint());
    }

    public function test_token_endpoint(): void
    {
        $providerUrl = 'https://token.example.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $cachedData = [
            'issuer' => 'https://token.example.com',
            'token_endpoint' => 'https://token.example.com/oauth/token',
            '_cached_at' => time(),
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $this->assertSame('https://token.example.com/oauth/token', $doc->tokenEndpoint());
    }

    public function test_userinfo_endpoint(): void
    {
        $providerUrl = 'https://userinfo.example.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $cachedData = [
            'issuer' => 'https://userinfo.example.com',
            'userinfo_endpoint' => 'https://userinfo.example.com/userinfo',
            '_cached_at' => time(),
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $this->assertSame('https://userinfo.example.com/userinfo', $doc->userinfoEndpoint());
    }

    public function test_jwks_uri(): void
    {
        $providerUrl = 'https://jwks.example.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $cachedData = [
            'issuer' => 'https://jwks.example.com',
            'jwks_uri' => 'https://jwks.example.com/.well-known/jwks.json',
            '_cached_at' => time(),
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($cachedData));

        $this->assertSame('https://jwks.example.com/.well-known/jwks.json', $doc->jwksUri());
    }

    public function test_clear_memory_cache(): void
    {
        DiscoveryDocument::clearMemoryCache();
        $this->assertTrue(true);
    }

    public function test_expired_cache_is_refreshed(): void
    {
        $providerUrl = 'https://expired.example.com';
        $doc = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $oldCachedData = [
            'issuer' => 'https://expired.example.com',
            '_cached_at' => time() - 172801,
        ];

        $cacheFile = $this->cacheDir . '/discovery_' . md5($providerUrl) . '.json';
        file_put_contents($cacheFile, json_encode($oldCachedData));

        DiscoveryDocument::clearMemoryCache();
        $this->assertSame($providerUrl, $doc->getProviderUrl());
    }
}
