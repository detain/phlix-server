<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\PasswordChangeRequiredException;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Hub\HubJwtValidatorInterface;
use Phlix\Hub\HubUserClaims;
use Phlix\Hub\RelayIdentityResolver;
use Phlix\Server\Http\Controllers\HubTokenController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * SRV-M18 RW2 (review server__pm round-2 Major): the hold guard keys the
 * RESOLVED local identity. Every fixture here honors the estate's real
 * id-space separation — HUB_UUID and SERVER_UUID are DISTINCT values, and
 * the must_change_password row lives under the SERVER id ONLY — so no mock
 * can answer "held" for the foreign hub key the way the original vacuous
 * pin did. Stripping the resolution leg (handing the guard the raw claim)
 * reddens the held leg, the consulted-key leg, and the unlinked leg.
 */
class HubTokenControllerTest extends TestCase
{
    /** The hub-side principal: the value in the verified claims. */
    private const HUB_UUID = '7b7b7b7b-7777-4777-8777-777777777777';

    /** The server-side account id: only ever present in user_identities/users. */
    private const SERVER_UUID = '5a5a5a5a-5555-4555-8555-555555555555';

    private JwtHandler $jwtHandler;
    private string $jwtSecret = 'test-secret-key-for-jwt-at-least-32-bytes';

    protected function setUp(): void
    {
        $this->jwtHandler = new JwtHandler($this->jwtSecret, 'HS256', 3600, 604800);
    }

    public function test_handle_with_valid_hub_token_returns_server_session_token(): void
    {
        $validator = $this->validatorReturningClaims();

        $controller = new HubTokenController(
            $validator,
            $this->jwtHandler,
            $this->unheldRepo(),
            $this->resolverMapping(self::SERVER_UUID),
        );

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
        $this->assertEquals(self::HUB_UUID, $payload['hub_user_id']);
        $this->assertEquals('server-456', $payload['server_id']);

        // RW2 wire-invariance pin: the cure moved the GUARD's key, never the
        // token's identity — the subject stays the hub-side UUID exactly as
        // the endpoint shipped before (and after) this fix.
        $this->assertSame(self::HUB_UUID, $payload['sub']);
    }

    public function test_handle_with_hub_token_lacking_token_claim_still_succeeds(): void
    {
        $validator = $this->validatorReturningClaims(token: '');

        $controller = new HubTokenController(
            $validator,
            $this->jwtHandler,
            $this->unheldRepo(),
            $this->resolverMapping(self::SERVER_UUID),
        );

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
        $controller = new HubTokenController(
            null,
            $this->jwtHandler,
            $this->unheldRepo(),
            $this->resolverMapping(null),
        );

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
        $controller = new HubTokenController(
            $this->createMock(HubJwtValidatorInterface::class),
            $this->jwtHandler,
            $this->unheldRepo(),
            $this->resolverMapping(null),
        );

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

        $controller = new HubTokenController(
            $validator,
            $this->jwtHandler,
            $this->unheldRepo(),
            $this->resolverMapping(null),
        );

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

        $controller = new HubTokenController(
            $validator,
            $this->jwtHandler,
            $this->unheldRepo(),
            $this->resolverMapping(null),
        );

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

        $validator = $this->validatorReturningClaims(
            self::HUB_UUID,
            'server-999',
            $opaqueTokenValue,
        );

        $controller = new HubTokenController(
            $validator,
            $this->jwtHandler,
            $this->unheldRepo(),
            $this->resolverMapping(self::SERVER_UUID),
        );

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
     * SRV-M18 REWORK leg (review server__pm Major "third ungated minter"),
     * RW2-recut (review round-2 Major "the mock could answer true for a
     * foreign key"): a held server account must NOT receive a server bearer
     * token from the hub exchange. The flag now lives where it really lives —
     * the users row under the SERVER id — and only the resolution leg can
     * reach it. Red at 1da9f7b1 (the parent): there the guard keys the raw
     * hub claim, the recorder answers "no such row" exactly like a real
     * database must, and the held account is minted its token.
     */
    public function test_handle_held_account_is_refused_before_any_mint(): void
    {
        $validator = $this->validatorReturningClaims(token: '');

        $usersQueries = [];
        $repo = $this->recordingRepo($usersQueries, held: true);

        $jwtSpy = $this->createMock(JwtHandler::class);
        $jwtSpy->expects($this->never())->method('createAccessToken');

        $controller = new HubTokenController(
            $validator,
            $jwtSpy,
            $repo,
            $this->resolverMapping(self::SERVER_UUID),
        );

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'valid-hub-jwt-token'];

        $this->expectException(PasswordChangeRequiredException::class);
        try {
            $controller->handle($request, []);
        } finally {
            // The resolution pin (recorder idiom): the users table was
            // consulted EXACTLY ONCE, bound under the resolved SERVER id —
            // the hub UUID never enters a users query. Strip the resolver leg
            // and this binding is the foreign key the row cannot answer for.
            $this->assertCount(1, $usersQueries, 'the hold check must run exactly once');
            [$sql, $params] = $usersQueries[0];
            $this->assertStringContainsString('must_change_password FROM users', $sql);
            $this->assertSame([self::SERVER_UUID], $params);
        }
    }

    /**
     * RW2 per-leg resolution pin (the review's Info note "future rotation
     * could pin per-leg resolution calls so a wrong-keyed guard reddens"):
     * the identity lookup runs with the hub provider family keyed on the
     * verified claim, and the flag consult runs ONLY under the id it
     * resolved. An unheld linked account still receives its token.
     */
    public function test_the_guard_consults_users_only_under_the_resolved_server_id(): void
    {
        $validator = $this->validatorReturningClaims();

        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->once())
            ->method('findByProviderExternalId')
            ->with('hub', '', self::HUB_UUID)
            ->willReturn(['user_id' => self::SERVER_UUID]);

        $repo = $this->createMock(UserRepository::class);
        // ->with(SERVER_UUID): a guard re-keyed to the raw hub claim violates
        // this parameter expectation at call time — the exact vacuity class.
        $repo->expects($this->once())
            ->method('mustChangePassword')
            ->with(self::SERVER_UUID)
            ->willReturn(false);

        $controller = new HubTokenController(
            $validator,
            $this->jwtHandler,
            $repo,
            new RelayIdentityResolver($identities),
        );

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'valid-hub-jwt-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    /**
     * RW2 UNLINKED-SEMANTICS PIN: a hub principal with no user_identities
     * row is the ratified REFUSE-OPEN arm (the controller docblock rules it,
     * mirroring RelayIdentityResolver's S301 "no row → honest pass-through,
     * not a hole" law). Two teeth: the exchange still mints (no new wire
     * shape was invented), and the users table is NEVER consulted — the hub
     * UUID cannot reach a users query even as a vacuous miss, which is what
     * the round-1 guard did wrong.
     */
    public function test_an_unlinked_hub_principal_mints_without_users_consultation(): void
    {
        $validator = $this->validatorReturningClaims(token: '');

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->never())->method('mustChangePassword');

        $controller = new HubTokenController(
            $validator,
            $this->jwtHandler,
            $repo,
            $this->resolverMapping(null),
        );

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => 'valid-hub-jwt-token'];

        $response = $controller->handle($request, []);

        $this->assertEquals(200, $response->statusCode);

        /** @var array{server_session_token: string} $body */
        $body = json_decode($response->body, true);
        $tokenParts = explode('.', $body['server_session_token']);
        /** @var array<array-key, mixed> $payload */
        $payload = json_decode(base64_decode(strtr($tokenParts[1], '-_', '+/')), true);

        // The token's subject is the hub UUID — inert against every wired
        // gate's users-row liveness law until a link row exists.
        $this->assertSame(self::HUB_UUID, $payload['sub']);
    }

    /**
     * A validator double answering with the given verified claims.
     */
    private function validatorReturningClaims(
        ?string $userId = null,
        string $serverId = 'server-456',
        string $token = 'test-opaque-token-from-hub',
    ): HubJwtValidatorInterface {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn(new HubUserClaims(
            userId: $userId ?? self::HUB_UUID,
            serverId: $serverId,
            subject: $userId ?? self::HUB_UUID,
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: ['media:read'],
            token: $token,
        ));

        return $validator;
    }

    /**
     * A REAL RelayIdentityResolver over a mocked identities store — the
     * production mapping, not a re-stub of it: $serverUserId null models the
     * unmapped principal exactly like a missing user_identities row.
     */
    private function resolverMapping(?string $serverUserId): RelayIdentityResolver
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')
            ->with('hub', '', self::HUB_UUID)
            ->willReturn($serverUserId === null ? null : ['user_id' => $serverUserId]);

        return new RelayIdentityResolver($identities);
    }

    /**
     * A UserRepository double answering the SRV-M18 hold check "not held" —
     * the shape every success leg has to state explicitly, because a linked
     * mint consults the flag.
     */
    private function unheldRepo(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('mustChangePassword')->willReturn(false);

        return $repo;
    }

    /**
     * A REAL UserRepository over a recording connection double — the SQL-shape
     * idiom. The must_change_password row exists under SERVER_UUID ONLY, the
     * way the id spaces really separate; any other binding answers "no row"
     * (UserRow::int default 0 → false), so the flag is unreachable without the
     * resolver leg. Every users-table call is recorded as [sql, params].
     *
     * @param list<array{0: string, 1: list<string>}> $usersQueries
     */
    private function recordingRepo(array &$usersQueries, bool $held): UserRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('query')->willReturnCallback(
            /**
             * @param array<int, string>|null $params
             */
            static function (string $sql, $params = null, $fetchMode = null) use (&$usersQueries, $held) {
                $bound = is_array($params) ? array_values(array_map('strval', $params)) : [];
                $usersQueries[] = [$sql, $bound];
                if (str_contains($sql, 'must_change_password')) {
                    return $held && $bound === [self::SERVER_UUID]
                        ? [['must_change_password' => 1]]
                        : [];
                }

                return [];
            }
        );

        return new UserRepository($connection);
    }
}
