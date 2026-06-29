<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;
use Phlix\Hub\HubJwtValidatorInterface;
use Phlix\Hub\HubUserClaims;
use Phlix\Server\Http\Controllers\HubTokenController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

class HubTokenControllerTest extends TestCase
{
    private JwtHandler $jwtHandler;
    private string $jwtSecret = 'test-secret-key-for-jwt-at-least-32-bytes';

    protected function setUp(): void
    {
        $this->jwtHandler = new JwtHandler($this->jwtSecret, 'HS256', 3600, 604800);
    }

    public function test_handle_with_valid_hub_token_returns_server_session_token(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(new HubUserClaims(
            userId: 'hub-user-123',
            serverId: 'server-456',
            subject: 'hub-user-123',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: ['media:read'],
            token: 'test-opaque-token-from-hub',
        ));

        $controller = new HubTokenController($validator, $this->jwtHandler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'valid-hub-jwt-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('server_session_token', $body);
        $this->assertNotEmpty($body['server_session_token']);

        // Verify the JWT contains the opaque_token claim
        $tokenParts = explode('.', $body['server_session_token']);
        $payload = json_decode(base64_decode(strtr($tokenParts[1], '-_', '+/')), true);

        $this->assertEquals('test-opaque-token-from-hub', $payload['opaque_token'] ?? null);
        $this->assertEquals('hub-user-123', $payload['hub_user_id']);
        $this->assertEquals('server-456', $payload['server_id']);
    }

    public function test_handle_with_hub_token_lacking_token_claim_still_succeeds(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(new HubUserClaims(
            userId: 'hub-user-123',
            serverId: 'server-456',
            subject: 'hub-user-123',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: ['media:read'],
            token: '', // No token claim
        ));

        $controller = new HubTokenController($validator, $this->jwtHandler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'valid-hub-jwt-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('server_session_token', $body);
        $this->assertNotEmpty($body['server_session_token']);

        // Verify the JWT does NOT contain opaque_token when hub token was empty
        $tokenParts = explode('.', $body['server_session_token']);
        $payload = json_decode(base64_decode(strtr($tokenParts[1], '-_', '+/')), true);

        $this->assertArrayNotHasKey('opaque_token', $payload);
    }

    public function test_handle_with_null_validator_returns_503(): void
    {
        $controller = new HubTokenController(null, $this->jwtHandler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'some-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(503, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals('hub.not_enrolled', $body['code'] ?? null);
    }

    public function test_handle_without_hub_token_returns_400(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);

        $controller = new HubTokenController($validator, $this->jwtHandler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = [];

        $response = $controller->handle($request, []);

        $this->assertEquals(400, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals('hub.token_required', $body['code'] ?? null);
    }

    public function test_handle_with_invalid_hub_token_returns_401(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(null);

        $controller = new HubTokenController($validator, $this->jwtHandler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'invalid-hub-jwt'];

        $response = $controller->handle($request, []);

        $this->assertEquals(401, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals('hub.jwt_invalid', $body['code'] ?? null);
    }

    public function test_handle_with_expired_hub_token_returns_401(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(null); // null = invalid/expired

        $controller = new HubTokenController($validator, $this->jwtHandler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'expired-hub-jwt'];

        $response = $controller->handle($request, []);

        $this->assertEquals(401, $response->statusCode);
    }

    public function test_handle_uses_token_claim_from_hub_jwt_when_present(): void
    {
        $opaqueTokenValue = 'invite-redemption-token-abc123';

        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(new HubUserClaims(
            userId: 'hub-user-789',
            serverId: 'server-999',
            subject: 'hub-user-789',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: [],
            token: $opaqueTokenValue,
        ));

        $controller = new HubTokenController($validator, $this->jwtHandler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'hub-jwt-with-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $serverToken = $body['server_session_token'];

        // Decode the JWT and verify opaque_token is present
        $tokenParts = explode('.', $serverToken);
        $payload = json_decode(base64_decode(strtr($tokenParts[1], '-_', '+/')), true);

        $this->assertEquals($opaqueTokenValue, $payload['opaque_token']);
    }
}
