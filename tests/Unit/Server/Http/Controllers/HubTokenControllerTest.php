<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\PasswordChangeRequiredException;
use Phlix\Auth\UserRepository;
use Phlix\Hub\HubJwtValidatorInterface;
use Phlix\Hub\HubUserClaims;
use Phlix\Server\Http\Controllers\HubTokenController;
use Phlix\Server\Http\Request;

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

        $controller = new HubTokenController($validator, $this->jwtHandler, $this->unheldRepo());

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'valid-hub-jwt-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{server_session_token: string} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('server_session_token', $body);
        $this->assertNotEmpty($body['server_session_token']);

        // Verify the JWT contains the opaque_token claim
        $tokenParts = explode('.', $body['server_session_token']);
        /** @var array<array-key, mixed> $payload */
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

        $controller = new HubTokenController($validator, $this->jwtHandler, $this->unheldRepo());

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'valid-hub-jwt-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{server_session_token: string} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('server_session_token', $body);
        $this->assertNotEmpty($body['server_session_token']);

        // Verify the JWT does NOT contain opaque_token when hub token was empty
        $tokenParts = explode('.', $body['server_session_token']);
        /** @var array<array-key, mixed> $payload */
        $payload = json_decode(base64_decode(strtr($tokenParts[1], '-_', '+/')), true);

        $this->assertArrayNotHasKey('opaque_token', $payload);
    }

    public function test_handle_with_null_validator_returns_503(): void
    {
        $controller = new HubTokenController(null, $this->jwtHandler, $this->unheldRepo());

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'some-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(503, $response->statusCode);

        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals('hub.not_enrolled', $body['code'] ?? null);
    }

    public function test_handle_without_hub_token_returns_400(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);

        $controller = new HubTokenController($validator, $this->jwtHandler, $this->unheldRepo());

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = [];

        $response = $controller->handle($request, []);

        $this->assertEquals(400, $response->statusCode);

        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals('hub.token_required', $body['code'] ?? null);
    }

    public function test_handle_with_invalid_hub_token_returns_401(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(null);

        $controller = new HubTokenController($validator, $this->jwtHandler, $this->unheldRepo());

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'invalid-hub-jwt'];

        $response = $controller->handle($request, []);

        $this->assertEquals(401, $response->statusCode);

        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertEquals('hub.jwt_invalid', $body['code'] ?? null);
    }

    public function test_handle_with_expired_hub_token_returns_401(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(null); // null = invalid/expired

        $controller = new HubTokenController($validator, $this->jwtHandler, $this->unheldRepo());

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

        $controller = new HubTokenController($validator, $this->jwtHandler, $this->unheldRepo());

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'hub-jwt-with-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{server_session_token: string} $body */
        $body = json_decode($response->body, true);
        $serverToken = $body['server_session_token'];

        // Decode the JWT and verify opaque_token is present
        $tokenParts = explode('.', $serverToken);
        /** @var array<array-key, mixed> $payload */
        $payload = json_decode(base64_decode(strtr($tokenParts[1], '-_', '+/')), true);

        $this->assertEquals($opaqueTokenValue, $payload['opaque_token']);
    }

    /**
     * A UserRepository double answering the SRV-M18 hold check "not held" —
     * the shape every pre-rework success leg now has to state explicitly,
     * because the mint consults the flag.
     */
    private function unheldRepo(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('mustChangePassword')->willReturn(false);

        return $repo;
    }

    /**
     * SRV-M18 REWORK leg (review server__pm Major "third ungated minter"):
     * a server account carrying the must-change-password hold must NOT
     * receive a server bearer token from the hub exchange, whatever
     * freshness the hub-side JWT follows. The refusal is the typed
     * exception bubbling toward the router's central 500 (the documented
     * SRV-M17 interim shape — same as the passkey/quick-connect legs); the
     * mint never runs.
     *
     * This test goes RED at 3e3e47a8 (the parent): there the exchange mints
     * a 200 with a real token for the held account.
     */
    public function test_handle_held_account_is_refused_before_any_mint(): void
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(new HubUserClaims(
            userId: 'hub-user-held',
            serverId: 'server-456',
            subject: 'hub-user-held',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: [],
            token: '',
        ));

        $repo = $this->createMock(UserRepository::class);
        $repo->method('mustChangePassword')
            ->with('hub-user-held')
            ->willReturn(true);

        $jwtSpy = $this->createMock(JwtHandler::class);
        $jwtSpy->expects($this->never())->method('createAccessToken');

        $controller = new HubTokenController($validator, $jwtSpy, $repo);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'valid-hub-jwt-token'];

        $this->expectException(PasswordChangeRequiredException::class);
        $controller->handle($request, []);
    }
}
