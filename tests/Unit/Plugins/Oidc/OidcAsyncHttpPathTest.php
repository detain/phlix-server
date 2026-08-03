<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use Jose\Component\Core\JWKSet;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Oidc\DiscoveryDocument;
use Phlix\Plugins\Oidc\IdTokenValidator;
use Phlix\Plugins\Oidc\OidcHttpClient;
use Phlix\Plugins\Oidc\OidcProvider;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Workerman\Http\Response as WorkermanResponse;

/**
 * Exercises the S44 async-I/O conversion: DiscoveryDocument, OidcProvider and
 * IdTokenValidator::fetchJwks() all route their outbound HTTP through an
 * injected {@see OidcHttpClient}. These tests inject a DOUBLE so the flow is
 * proven end-to-end with NO real network.
 *
 */
final class OidcAsyncHttpPathTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlix_oidc_async_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        DiscoveryDocument::clearMemoryCache();
        IdTokenValidator::clearJwksCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->cacheDir);
        }
        DiscoveryDocument::clearMemoryCache();
        IdTokenValidator::clearJwksCache();
    }

    private function response(string $body): ResponseInterface
    {
        return new WorkermanResponse(200, [], $body);
    }

    public function test_discovery_document_fetches_through_injected_client(): void
    {
        $providerUrl = 'https://idp.async.test';
        $discoveryJson = json_encode([
            'issuer' => $providerUrl,
            'authorization_endpoint' => $providerUrl . '/authorize',
            'token_endpoint' => $providerUrl . '/token',
            'jwks_uri' => $providerUrl . '/jwks',
        ], JSON_THROW_ON_ERROR);

        $client = $this->createMock(OidcHttpClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->stringContains('/.well-known/openid-configuration'),
                $this->anything(),
            )
            ->willReturn($this->response($discoveryJson));

        // Fresh cacheDir → no cache file → the fetch path runs through the client.
        $discovery = new DiscoveryDocument($providerUrl, $this->cacheDir, $client);

        $this->assertSame($providerUrl . '/authorize', $discovery->authorizationEndpoint());
        $this->assertSame($providerUrl . '/token', $discovery->tokenEndpoint());
    }

    public function test_discovery_document_throws_when_client_returns_null(): void
    {
        $client = $this->createMock(OidcHttpClient::class);
        $client->method('get')->willReturn(null);

        $discovery = new DiscoveryDocument('https://down.async.test', $this->cacheDir, $client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch OIDC discovery document');
        $discovery->getDocument();
    }

    public function test_fetch_jwks_uses_injected_client(): void
    {
        $providerUrl = 'https://jwks.async.test';
        $this->seedDiscoveryCache($providerUrl, [
            'issuer' => $providerUrl,
            'jwks_uri' => $providerUrl . '/keys',
        ]);
        $discovery = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $jwksJson = json_encode([
            'keys' => [
                ['kty' => 'oct', 'k' => 'c2VjcmV0LWtleS1tYXRlcmlhbA'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createMock(OidcHttpClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with($providerUrl . '/keys', $this->anything())
            ->willReturn($this->response($jwksJson));

        $jwks = IdTokenValidator::fetchJwks($discovery, $client);

        $this->assertInstanceOf(JWKSet::class, $jwks);
        $this->assertSame(1, $jwks->count());
    }

    public function test_fetch_jwks_throws_on_invalid_body(): void
    {
        $providerUrl = 'https://badjwks.async.test';
        $this->seedDiscoveryCache($providerUrl, [
            'issuer' => $providerUrl,
            'jwks_uri' => $providerUrl . '/keys',
        ]);
        $discovery = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $client = $this->createMock(OidcHttpClient::class);
        $client->method('get')->willReturn($this->response('{"not":"keys"}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JWKS format');
        IdTokenValidator::fetchJwks($discovery, $client);
    }

    public function test_userinfo_flow_authenticates_through_injected_client(): void
    {
        $providerUrl = 'https://userinfo.async.test';
        $this->seedDiscoveryCache($providerUrl, [
            'issuer' => $providerUrl,
            'jwks_uri' => $providerUrl . '/keys',
            'userinfo_endpoint' => $providerUrl . '/userinfo',
        ]);
        $discovery = new DiscoveryDocument($providerUrl, $this->cacheDir);

        $jwksJson = json_encode([
            'keys' => [['kty' => 'oct', 'k' => 'c2VjcmV0LWtleS1tYXRlcmlhbA']],
        ], JSON_THROW_ON_ERROR);
        $userinfoJson = json_encode([
            'sub' => 'user-async-123',
            'email' => 'async@example.com',
            'name' => 'Async User',
        ], JSON_THROW_ON_ERROR);

        $client = $this->createMock(OidcHttpClient::class);
        $client->method('get')->willReturnCallback(
            function (string $url) use ($jwksJson, $userinfoJson): ResponseInterface {
                if (str_contains($url, '/keys')) {
                    return $this->response($jwksJson);
                }
                if (str_contains($url, '/userinfo')) {
                    return $this->response($userinfoJson);
                }
                return $this->response('{}');
            }
        );

        $provider = new OidcProvider($discovery, 'client-id', 'client-secret', 'openid profile email', $client);

        $result = $provider->authenticate(['access_token' => 'opaque-access-token']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('oidc.user-async-123', $result->externalId);
        $this->assertSame('oidc', $result->attributes['provider']);
        $this->assertSame('async@example.com', $result->getEmail());
    }

    public function test_exchange_code_posts_through_injected_client(): void
    {
        $providerUrl = 'https://token.async.test';
        $this->seedDiscoveryCache($providerUrl, [
            'issuer' => $providerUrl,
            'token_endpoint' => $providerUrl . '/token',
            'jwks_uri' => $providerUrl . '/keys',
        ]);
        $discovery = new DiscoveryDocument($providerUrl, $this->cacheDir);

        // Token endpoint returns a 2xx JSON body with NO id_token: this proves
        // the async POST path ran and the body was parsed, without needing a
        // fully signed RS256 token + matching JWKS.
        $client = $this->createMock(OidcHttpClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $providerUrl . '/token',
                $this->stringContains('grant_type=authorization_code'),
                $this->anything(),
            )
            ->willReturn($this->response(json_encode(['access_token' => 'at-only'], JSON_THROW_ON_ERROR)));

        $provider = new OidcProvider($discovery, 'client-id', 'client-secret', 'openid profile email', $client);

        $result = $provider->authenticate([
            'code' => 'auth-code',
            'redirect_uri' => 'https://app.test/callback',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('missing_id_token', $result->error);
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
