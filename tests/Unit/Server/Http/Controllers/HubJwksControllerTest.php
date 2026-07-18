<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\HttpClientInterface;
use Phlix\Hub\HubClient;
use Phlix\Hub\KeyPair;
use Phlix\Server\Http\Controllers\HubJwksController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

class HubJwksControllerTest extends TestCase
{
    public function test_returns_jwks_json_with_valid_structure(): void
    {
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('getPublicKeysJwk')->willReturn([
            [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => '11qYjhK5HRVDum2bHqDQD0gRNYVWg0Wmg2TTKJSbZ-g',
                'kid' => '2026-05-17T00:00:00Z',
                'use' => 'sig',
                'alg' => 'EdDSA',
            ],
        ]);

        $controller = new HubJwksController($hubClient);
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/.well-known/jwks.json';

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals('application/json', $response->headers['Content-Type'] ?? null);
        $this->assertEquals('public, max-age=3600', $response->headers['Cache-Control'] ?? null);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('keys', $body);
        $this->assertCount(1, $body['keys']);

        $key = $body['keys'][0];
        $this->assertEquals('OKP', $key['kty']);
        $this->assertEquals('Ed25519', $key['crv']);
        $this->assertEquals('sig', $key['use']);
        $this->assertEquals('EdDSA', $key['alg']);
    }

    public function test_returns_empty_keys_when_no_keys_configured(): void
    {
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('getPublicKeysJwk')->willReturn([]);

        $controller = new HubJwksController($hubClient);
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/.well-known/jwks.json';

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('keys', $body);
        $this->assertEmpty($body['keys']);
    }

    public function test_serves_empty_keyset_at_200_when_key_load_fails(): void
    {
        // SV-4.16 end-to-end degrade: with a REAL HubClient whose signing key
        // fails to load (malformed PKCS#8 file), the JWKS endpoint must still
        // return a valid RFC 7517 {"keys":[]} at HTTP 200 — never a 500.
        $tmpDir = sys_get_temp_dir() . '/phlix-jwks-degrade-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $keyPath = $tmpDir . '/key.pem';
        file_put_contents(
            $keyPath,
            "-----BEGIN PRIVATE KEY-----\n!!garbage!!\n-----END PRIVATE KEY-----\n"
        );

        try {
            $hubClient = new HubClient(
                new Ed25519KeyManager($keyPath),
                $this->createMock(HttpClientInterface::class),
                new StructuredLogger('hub', []),
                $tmpDir,
            );

            $controller = new HubJwksController($hubClient);
            $request = new Request();
            $request->method = 'GET';
            $request->path = '/.well-known/jwks.json';

            $response = $controller->handle($request, []);

            $this->assertEquals(200, $response->statusCode);

            $body = json_decode($response->body, true);
            if (!is_array($body)) {
                self::fail('JWKS response body is not a JSON object');
            }
            $this->assertArrayHasKey('keys', $body);
            $this->assertSame([], $body['keys']);
        } finally {
            @unlink($keyPath);
            @rmdir($tmpDir);
        }
    }
}
