<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Hub\HubJwtValidatorInterface;
use Phlix\Hub\HubUserClaims;
use Phlix\Hub\RelayIdentityResolver;
use Phlix\Server\Http\Controllers\AccountLinkController;
use Phlix\Server\Http\Controllers\HubTokenController;
use Phlix\Server\Http\Request;

/**
 * SRV-M22 — the wire shape of hub-enroll refusal [review server__secnet].
 *
 * Every hub-enrollment refusal leg — POST /api/v1/auth/hub-token
 * (HubTokenController) and POST /auth/identities/link/hub
 * (AccountLinkController::linkHub) — answers the repo's canonical FLAT
 * {error, code} envelope (Response::error): the human-readable sentence
 * lives in `error`, the registered dotted code in `code`, and there is NO
 * third `message` key. The codes are pre-existing contracts registry
 * members — hub.not_enrolled, hub.token_required, hub.jwt_invalid — zero
 * new wire codes (contract law); only the shape was off-contract.
 *
 * The teeth are exact full-body assertSame: it pins key presence, key
 * ORDER, exact values and the absence of any stray key (re-adding
 * `message`, or restoring an HTTP-phrase/snake-token `error`, reddens).
 *
 * The HubTokenController resolver argument is the seam-consolidated
 * wiring; a refusal leg never reaches the identity lookup (each returns
 * BEFORE the mint consults it), so an unmapped resolver is sufficient
 * here — the resolver's own mapping law is pinned by the sibling
 * HubTokenControllerTest, which rotates in lockstep with the ctor shape.
 */
final class HubEnrollmentRefusalEnvelopeTest extends TestCase
{
    private const NOT_ENROLLED_MESSAGE =
        "This server isn't enrolled with a Phlix Hub yet. " . 'Go to Admin > Hub to enroll it.';
    private const TOKEN_REQUIRED_MESSAGE = 'Hub token is required';
    private const JWT_INVALID_MESSAGE = 'Invalid or expired hub token';

    private JwtHandler $jwtHandler;

    protected function setUp(): void
    {
        $this->jwtHandler = new JwtHandler('test-secret-key-for-jwt-at-least-32-bytes', 'HS256', 3600, 604800);
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/auth/hub-token — HubTokenController refusal legs
    // -----------------------------------------------------------------------

    public function test_hub_token_unenrolled_server_answers_flat_error_code_503(): void
    {
        $controller = $this->hubTokenController(null);

        $response = $controller->handle($this->hubTokenRequest('some-token'), []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame(
            ['error' => self::NOT_ENROLLED_MESSAGE, 'code' => 'hub.not_enrolled'],
            json_decode($response->body, true),
        );
    }

    public function test_hub_token_missing_token_answers_flat_error_code_400(): void
    {
        $controller = $this->hubTokenController($this->hubValidator(null));

        $response = $controller->handle($this->hubTokenRequest(''), []);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame(
            ['error' => self::TOKEN_REQUIRED_MESSAGE, 'code' => 'hub.token_required'],
            json_decode($response->body, true),
        );
    }

    public function test_hub_token_invalid_token_answers_flat_error_code_401(): void
    {
        $controller = $this->hubTokenController($this->hubValidator(null));

        $response = $controller->handle($this->hubTokenRequest('forged-token'), []);

        $this->assertSame(401, $response->statusCode);
        $this->assertSame(
            ['error' => self::JWT_INVALID_MESSAGE, 'code' => 'hub.jwt_invalid'],
            json_decode($response->body, true),
        );
    }

    // -----------------------------------------------------------------------
    // POST /auth/identities/link/hub — AccountLinkController::linkHub legs
    // -----------------------------------------------------------------------

    public function test_link_hub_unenrolled_server_answers_flat_error_code_503(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        $controller = $this->linkHubController($identities, null);

        $response = $controller->linkHub($this->linkHubRequest('eyJ...'), []);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame(
            ['error' => self::NOT_ENROLLED_MESSAGE, 'code' => 'hub.not_enrolled'],
            json_decode($response->body, true),
        );
    }

    public function test_link_hub_missing_token_answers_flat_error_code_400(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        $controller = $this->linkHubController($identities, $this->hubValidator(null));

        $response = $controller->linkHub($this->linkHubRequest(null), []);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame(
            ['error' => self::TOKEN_REQUIRED_MESSAGE, 'code' => 'hub.token_required'],
            json_decode($response->body, true),
        );
    }

    public function test_link_hub_invalid_token_answers_flat_error_code_401(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('findByProviderExternalId');
        $identities->expects($this->never())->method('create');

        $controller = $this->linkHubController($identities, $this->hubValidator(null));

        $response = $controller->linkHub($this->linkHubRequest('forged-token'), []);

        $this->assertSame(401, $response->statusCode);
        $this->assertSame(
            ['error' => self::JWT_INVALID_MESSAGE, 'code' => 'hub.jwt_invalid'],
            json_decode($response->body, true),
        );
    }

    /**
     * The second generic-401 leg: a token that validates but carries an
     * EMPTY hub user id is refused with the same envelope — no oracle into
     * which claim failed, and nothing links.
     */
    public function test_link_hub_empty_claim_subject_answers_flat_error_code_401(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('findByProviderExternalId');
        $identities->expects($this->never())->method('create');

        $controller = $this->linkHubController($identities, $this->hubValidator(new HubUserClaims(
            userId: '',
            serverId: 'srv-1',
            subject: '',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
        )));

        $response = $controller->linkHub($this->linkHubRequest('claims-without-subject'), []);

        $this->assertSame(401, $response->statusCode);
        $this->assertSame(
            ['error' => self::JWT_INVALID_MESSAGE, 'code' => 'hub.jwt_invalid'],
            json_decode($response->body, true),
        );
    }

    // -----------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------

    private function hubTokenController(?HubJwtValidatorInterface $validator): HubTokenController
    {
        return new HubTokenController(
            $validator,
            $this->jwtHandler,
            $this->unheldRepo(),
            new RelayIdentityResolver($this->createMock(UserIdentityRepository::class)),
        );
    }

    private function linkHubController(
        UserIdentityRepository $identities,
        ?HubJwtValidatorInterface $validator
    ): AccountLinkController {
        return new AccountLinkController(
            $identities,
            new AuthProviderRegistry(),
            null,
            $this->existingUser(),
            $validator,
        );
    }

    private function hubValidator(?HubUserClaims $claims): HubJwtValidatorInterface
    {
        $validator = $this->createMock(HubJwtValidatorInterface::class);
        $validator->method('validate')->willReturn($claims);

        return $validator;
    }

    private function hubTokenRequest(string $hubToken): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/auth/hub-token';
        $request->body = ['hub_token' => $hubToken];

        return $request;
    }

    private function linkHubRequest(?string $hubToken): Request
    {
        $request = new Request();
        $request->userId = 'user-1';
        $request->body = $hubToken === null ? [] : ['hub_token' => $hubToken];

        return $request;
    }

    private function unheldRepo(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('mustChangePassword')->willReturn(false);

        return $repo;
    }

    /** The link endpoints require the principal to be a REAL server account. */
    private function existingUser(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn(['id' => 'user-1', 'username' => 'user-1']);

        return $repo;
    }
}
