<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\HubClient;
use Phlex\Hub\KeyPair;
use Phlex\Server\Http\Controllers\HubJwksController;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

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
}
