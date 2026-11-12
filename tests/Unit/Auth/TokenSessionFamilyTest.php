<?php

/**
 * CP-029 / D-1a — session-family store convictions in AuthManager.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\TokenSessionRepository;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * The D-1a adjudication law, executed.
 *
 * ## What every leg shares
 *
 *  - REAL {@see JwtHandler} — every token is one THIS server would sign; the
 *    crafted fixtures carry claims produced by the production mint path, so no
 *    leg can pass on a fixture that lies about its own premise (a token
 *    "claiming" a revoked family must actually be a well-formed sid-bearing
 *    HS256 token, or a signature failure would be convicting for the wrong
 *    reason);
 *  - MOCKED {@see TokenSessionRepository} — the store's SQL truth is pinned
 *    separately against REAL MySQL (tests/Integration/Auth/TokenSessionFamilyMigration127RealDbTest.php);
 *    here we pin the DECISION AUTHORITY makes, arm by arm, each arm uniquely
 *    convicted by exactly one test (strip-arm table in the CP-029 register);
 *  - MOCKED {@see UserRepository} with the shipped RefreshRotationGraceTest
 *    auth-state shape (active account, null watermark) so every leg's ONLY
 *    moving part is the family store's answer.
 *
 * ## The envelope law every refusing arm pins
 *
 * All refusals surface the EXISTING shipped message 'Token has been revoked'
 * byte-identical — CP-029 added ZERO wire codes; distinguishing detail lives
 * in audit reason names, which several legs assert as the arm identity.
 */
final class TokenSessionFamilyTest extends TestCase
{
    private const USER_ID = 'user-1';

    private JwtHandler $jwt;

    /** @var \PHPUnit\Framework\MockObject\MockObject&UserRepository */
    private $users;

    /** @var \PHPUnit\Framework\MockObject\MockObject&AuditLogger */
    private $audit;

    /** @var list<string> log files this test created (S439 zero-residue law). */
    private array $tempLogs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempLogs as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempLogs = [];
        parent::tearDown();
    }

    /**
     * Build a manager with the family store wired (and, optionally, the legacy
     * device-session manager for the logout legs).
     *
     * @param ?SessionManager $sessionManager legacy device-session collaborator.
     */
    private function manager(?TokenSessionRepository $store, ?SessionManager $sessionManager = null): AuthManager
    {
        return new AuthManager(
            $this->users,
            $this->jwt,
            $this->audit,
            $this->silentLogger(),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $sessionManager,
            null,
            $store
        );
    }

    /**
     * Fresh active-state mocks: the manager's ONLY uncertainty is the store.
     */
    private function harness(): void
    {
        $this->jwt = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
        $this->users = $this->createMock(UserRepository::class);
        $this->users->method('getAuthState')->willReturnCallback(
            fn (string $id): ?array => $id === self::USER_ID
                ? ['status' => 'active', 'tokensNotValidAfter' => 0]
                : null
        );
        $this->users->method('findById')->willReturn([
            'id' => self::USER_ID,
            'username' => 'nina',
            'status' => 'active',
        ]);
        $this->users->method('mustChangePassword')->willReturn(false);
        $this->audit = $this->createMock(AuditLogger::class);
    }

    // ---------------------------------------------------------------------------
    // Mint legs — what a family actually IS in a signed token
    // ---------------------------------------------------------------------------

    /**
     * A store-wired rotation opens a FAMILY when the presented token predates
     * one (no sid claim): createSession runs, BOTH fresh tokens carry the new
     * sid, and the successor grant is registered under the jti the mint
     * actually produced (decoded independently of the mock — the premise-
     * falsification arm: had recordGrant received any other jti, the very next
     * refresh of what we just handed the client would die 'refresh_grant_unknown',
     * so the pin must read the mint's own bytes).
     */
    public function testLegacyRotationOpensAFamilyAndRegistersTheMintedJti(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('a', 35);

        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects($this->once())
            ->method('createSession')
            ->with($this->identicalTo(self::USER_ID), $this->isNull(), $this->isNull())
            ->willReturn($family);
        $store->expects($this->never())
            ->method('findGrant');
        $recorded = null;
        $store->expects($this->once())
            ->method('recordGrant')
            ->willReturnCallback(
                function (string $jti, string $sid, mixed $expiresAt = null) use (&$recorded): void {
                    $recorded = ['jti' => $jti, 'sid' => $sid, 'exp' => $expiresAt];
                }
            );
        $store->expects($this->never())->method('touchSession');
        $store->expects($this->never())->method('markGrantConsumed');

        $refresh = $this->jwt->createRefreshToken(self::USER_ID);
        $response = $this->manager($store)->refreshToken($refresh);

        $accessPayload = $this->jwt->validateToken((string) $response['access_token']);
        $refreshPayload = $this->jwt->validateToken((string) $response['refresh_token']);
        $this->assertIsArray($accessPayload);
        $this->assertIsArray($refreshPayload);
        $this->assertSame($family, $accessPayload['sid'] ?? null, 'access token must carry the family');
        $this->assertSame($family, $refreshPayload['sid'] ?? null, 'refresh token must carry the family');
        $this->assertSame(
            [(string) $refreshPayload['jti'], $family, (int) $refreshPayload['exp']],
            array_values((array) $recorded),
            'the grant row must be registered under the EXACT jti the client received, '
            . 'carrying the EXACT exp that token bakes (D1a parity cure: the stamped sweep '
            . 'arm is only honest if the row stores the lifetime claim of its own mint)'
        );
    }

    /**
     * D1a parity cure, dedicated conviction: the mint hands recordGrant the
     * successor token's OWN baked `exp` as a third argument — an integer, and
     * exactly the value the presented-to-client token carries. Raw variadic
     * capture so the arity itself is evidence (the parent's 2-arg call cannot
     * fill slot three); the legacy first-contact path must additionally keep
     * consulting findGrant zero times (this cure changes no ceremony order).
     */
    public function testTheSuccessorGrantCarriesTheMintsOwnBakedExpiry(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('c', 35);

        $captured = [];
        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects($this->once())
            ->method('createSession')
            ->with($this->identicalTo(self::USER_ID), $this->isNull(), $this->isNull())
            ->willReturn($family);
        $store->expects($this->never())->method('findGrant');
        $store->expects($this->once())
            ->method('recordGrant')
            ->willReturnCallback(
                function (...$args) use (&$captured): void {
                    $captured = $args;
                }
            );

        $response = $this->manager($store)->refreshToken($this->jwt->createRefreshToken(self::USER_ID));

        $payload = $this->jwt->validateToken((string) $response['refresh_token']);
        $this->assertIsArray($payload);

        $this->assertCount(
            3,
            $captured,
            'recordGrant must be handed a lifetime alongside jti and session'
        );
        $this->assertSame((string) $payload['jti'], $captured[0] ?? null);
        $this->assertSame($family, $captured[1] ?? null);
        $this->assertIsInt($captured[2] ?? null, 'the expiry is the typed token claim, not a config guess');
        $this->assertSame((int) $payload['exp'], $captured[2], 'the row stores the EXACT exp the token bakes');
    }

    /**
     * Rotation inside a family: no second createSession, the presented grant is
     * consumed BEFORE the re-mint returns, the family survives into both fresh
     * tokens, the successor jti gets its own grant, and the session is touched
     * (the list's last_used_at is honest).
     */
    public function testRotationContinuesInsideTheFamilyAndConsumesThePresentedGrant(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('b', 35);
        $presented = $this->jwt->createRefreshToken(self::USER_ID, [JwtHandler::CLAIM_SESSION_ID => $family]);
        $presentedPayload = $this->jwt->validateToken($presented);
        $this->assertIsArray($presentedPayload);
        $presentedJti = (string) $presentedPayload['jti'];

        $consumedOrder = [];
        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects($this->never())->method('createSession');
        $store->expects($this->once())
            ->method('findGrant')
            ->with($this->identicalTo($presentedJti))
            ->willReturn([
                'jti' => $presentedJti,
                'sessionId' => $family,
                'sessionStatus' => 'active',
                'consumed' => false,
                'withinGrace' => false,
            ]);
        $store->expects($this->once())
            ->method('markGrantConsumed')
            ->with($this->identicalTo($presentedJti))
            ->willReturnCallback(function () use (&$consumedOrder): void {
                $consumedOrder[] = 'consumed';
            });
        $store->expects($this->once())
            ->method('touchSession')
            ->with($this->identicalTo($family))
            ->willReturnCallback(function () use (&$consumedOrder): void {
                $consumedOrder[] = 'touched';
            });
        $successor = null;
        $store->expects($this->once())
            ->method('recordGrant')
            ->willReturnCallback(
                function (string $jti, string $sid) use (&$successor, &$consumedOrder): void {
                    $consumedOrder[] = 'granted';
                    $successor = ['jti' => $jti, 'sid' => $sid];
                }
            );

        $response = $this->manager($store)->refreshToken($presented);

        $fresh = $this->jwt->validateToken((string) $response['refresh_token']);
        $this->assertIsArray($fresh);
        $this->assertSame($family, $fresh['sid'] ?? null);
        $this->assertNotSame($presentedJti, (string) ($fresh['jti'] ?? ''));
        $this->assertSame([(string) $fresh['jti'], $family], array_values((array) $successor));
        $this->assertSame(
            ['consumed', 'granted', 'touched'],
            $consumedOrder,
            'consume must precede the successor grant (a mint that dies mid-way leaves the client '
            . 'retry inside the grace window of ITS OWN presentation, not a reuse crime scene)'
        );
    }

    /**
     * Unwired store = byte-identical legacy mint: no sid claim anywhere, no
     * store call to even make (there is no store) — the zero-breakage posture
     * for the 4-arg composition every hand-built test (and any deployment that
     * never names the parameter) produces.
     */
    public function testUnwiredStoreMintsTheLegacyShapeExactly(): void
    {
        $this->harness();
        $refresh = $this->jwt->createRefreshToken(self::USER_ID);

        $response = $this->manager(null)->refreshToken($refresh);

        $payload = $this->jwt->validateToken((string) $response['refresh_token']);
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('sid', $payload, 'an unwired mint must not fabricate a family claim');
        $access = $this->jwt->validateToken((string) $response['access_token']);
        $this->assertIsArray($access);
        $this->assertArrayNotHasKey('sid', $access);
    }

    // ---------------------------------------------------------------------------
    // Refresh adjudication — the decision table, arm by arm
    // ---------------------------------------------------------------------------

    /**
     * REUSE (the headline capability): a rotated token replayed outside the
     * grace window revokes its OWN family — and only that family: the
     * user-wide nuclear primitives (revokeAllForUser / revokeTokensBeforeNow)
     * must stay untouched, or D-1a would have silently widened every other
     * device's blast radius. Envelope: shipped 'Token has been revoked' text.
     * Audit reason: 'refresh_token_reuse' with user/session/jti context.
     */
    public function testReuseOutsideGraceRevokesTheFamilyOnlyAndRefuses(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('c', 35);
        $stolen = $this->jwt->createRefreshToken(self::USER_ID, [JwtHandler::CLAIM_SESSION_ID => $family]);
        $payload = $this->jwt->validateToken($stolen);
        $this->assertIsArray($payload);
        $jti = (string) $payload['jti'];

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => $jti,
            'sessionId' => $family,
            'sessionStatus' => 'active',
            'consumed' => true,
            'withinGrace' => false,
        ]);
        $store->expects($this->once())
            ->method('revokeSession')
            ->with($this->identicalTo($family), $this->identicalTo('refresh_token_reuse'));
        $store->expects($this->never())->method('revokeAllForUser');
        $store->expects($this->never())->method('markGrantConsumed');
        $store->expects($this->never())->method('recordGrant');

        $this->audit->expects($this->once())
            ->method('logFailedAuth')
            ->with(
                $this->identicalTo('refresh_token_reuse'),
                $this->callback(function (array $ctx) use ($jti, $family): bool {
                    return ($ctx['user_id'] ?? null) === self::USER_ID
                        && ($ctx['session_id'] ?? null) === $family
                        && ($ctx['jti'] ?? null) === $jti
                        && ($ctx['context'] ?? null) === 'refresh';
                })
            );

        $this->users->expects($this->never())->method('revokeTokensBeforeNow');

        $threw = null;
        try {
            $this->manager($store)->refreshToken($stolen);
        } catch (\InvalidArgumentException $e) {
            $threw = $e->getMessage();
        }
        $this->assertSame('Token has been revoked', $threw, 'reuse refusal is the shipped envelope text, byte-ident');
    }

    /**
     * BENIGN RACE (the CP-005 grace rule at store scope): a replay inside the
     * window rotates ON — same family, fresh pair, nothing revoked, and the
     * replayed jti is NOT re-consumed (its consumed_at row is the evidence the
     * next replay reads).
     */
    public function testReplayInsideGraceRotatesOnWithoutRevocation(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('d', 35);
        $token = $this->jwt->createRefreshToken(self::USER_ID, [JwtHandler::CLAIM_SESSION_ID => $family]);
        $payload = $this->jwt->validateToken($token);
        $this->assertIsArray($payload);
        $jti = (string) $payload['jti'];

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => $jti,
            'sessionId' => $family,
            'sessionStatus' => 'active',
            'consumed' => true,
            'withinGrace' => true,
        ]);
        $store->expects($this->never())->method('revokeSession');
        $store->expects($this->never())->method('markGrantConsumed');
        $store->expects($this->once())->method('recordGrant');

        $response = $this->manager($store)->refreshToken($token);

        $fresh = $this->jwt->validateToken((string) $response['refresh_token']);
        $this->assertIsArray($fresh);
        $this->assertSame($family, $fresh['sid'] ?? null, 'the race rotates INSIDE the family, not a new one');
        $this->assertNotSame($jti, (string) ($fresh['jti'] ?? ''));
    }

    /**
     * A revoked family cannot refresh, and the refusal must NOT re-revoke
     * (the audit trail stays one crime scene, not a repeat write).
     */
    public function testRevokedFamilyRefusesRefreshWithoutReRevoking(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('e', 35);
        $token = $this->jwt->createRefreshToken(self::USER_ID, [JwtHandler::CLAIM_SESSION_ID => $family]);
        $payload = $this->jwt->validateToken($token);
        $this->assertIsArray($payload);

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => (string) $payload['jti'],
            'sessionId' => $family,
            'sessionStatus' => 'revoked',
            'consumed' => true,
            'withinGrace' => false,
        ]);
        $store->expects($this->never())->method('revokeSession');
        $store->expects($this->never())->method('markGrantConsumed');
        $this->audit->expects($this->once())
            ->method('logFailedAuth')
            ->with($this->identicalTo('token_session_revoked'), $this::anything());

        $this->expectExceptionObject(new \InvalidArgumentException('Token has been revoked'));
        $this->manager($store)->refreshToken($token);
    }

    /**
     * UNKNOWN GRANT fails closed: a signed sid-carrying token whose lineage is
     * not in the store (pruned, foreign, or the DB moved under the token) is
     * refused — never silently upgraded, which would hand a stolen
     * pre-pruning token a fresh family.
     */
    public function testUnknownGrantFailsClosed(): void
    {
        $this->harness();
        $token = $this->jwt->createRefreshToken(self::USER_ID, [
            JwtHandler::CLAIM_SESSION_ID => 'f' . str_repeat('f', 35),
        ]);

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn(null);
        $store->expects($this->never())->method('markGrantConsumed');
        $store->expects($this->never())->method('recordGrant');
        $this->audit->expects($this->once())
            ->method('logFailedAuth')
            ->with($this->identicalTo('refresh_grant_unknown'), $this::anything());

        $this->expectExceptionObject(new \InvalidArgumentException('Token has been revoked'));
        $this->manager($store)->refreshToken($token);
    }

    /**
     * CLAIM/ROW DISAGREEMENT dies: grant.session_id != token.claim.sid is an
     * impossible composition from buildAuthResponse (both are written from one
     * value) — if it ever presents, the payload and the ledger name different
     * families and no adjudication can be trusted. Pin the arm independently
     * of the unknown-grant leg (strip this comparison and ONLY this test turns
     * green-red).
     */
    public function testGrantSessionMismatchDies(): void
    {
        $this->harness();
        $claimed = 'f' . str_repeat('1', 35);
        $token = $this->jwt->createRefreshToken(self::USER_ID, [JwtHandler::CLAIM_SESSION_ID => $claimed]);
        $payload = $this->jwt->validateToken($token);
        $this->assertIsArray($payload);

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => (string) $payload['jti'],
            'sessionId' => 'f' . str_repeat('2', 35),
            'sessionStatus' => 'active',
            'consumed' => false,
            'withinGrace' => false,
        ]);
        $store->expects($this->never())->method('markGrantConsumed');
        $this->audit->expects($this->once())
            ->method('logFailedAuth')
            ->with($this->identicalTo('refresh_grant_session_mismatch'), $this::anything());

        $this->expectExceptionObject(new \InvalidArgumentException('Token has been revoked'));
        $this->manager($store)->refreshToken($token);
    }

    /**
     * sid-CARRYING token against an UNWIRED store fails closed on BOTH faces
     * (access → null, refresh → revoked + 'token_store_unwired' audit): the
     * claim says some server instance with a store minted this, so guessing
     * "legacy" here is how a stolen-token hole is born.
     */
    public function testSidClaimAgainstUnwiredStoreFailsClosedBothFaces(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('3', 35);

        $access = $this->jwt->createAccessToken(self::USER_ID, [JwtHandler::CLAIM_SESSION_ID => $family]);
        $this->assertNull($this->manager(null)->validateAccessToken($access));

        $refresh = $this->jwt->createRefreshToken(self::USER_ID, [JwtHandler::CLAIM_SESSION_ID => $family]);
        $this->audit->expects($this->once())
            ->method('logFailedAuth')
            ->with($this->identicalTo('token_store_unwired'), $this::anything());
        $this->expectExceptionObject(new \InvalidArgumentException('Token has been revoked'));
        $this->manager(null)->refreshToken($refresh);
    }

    /**
     * A sid-carrying refresh token with NO jti is an impossible composition
     * (createRefreshToken merges jti UNCONDITIONALLY into every mint), so the
     * arm guarding it must be provably live: a hand-signed HS256 token wearing
     * this test's own server secret, structurally valid (isRefreshToken TRUE,
     * validateToken returns the payload — the premise-falsification asserts
     * below, so no leg can pass on a signature failure convicting for the
     * wrong reason), stripped of exactly one reserved claim. Refused with
     * 'refresh_grant_jti_missing'; nothing touched in the store.
     */
    public function testJtilessFamilyTokenFailsClosed(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('4', 35);

        // Mirror JwtHandler::encode() byte-for-byte with the test secret — the
        // fixture's premise is "THIS server's key signed this", and the asserts
        // under the mock prove the adjudicator actually READ the payload
        // (unknown-jti would be the arm that fires if the token were not
        // structurally refresh-valid).
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $now = time();
        $payload = [
            'iss' => 'phlix',
            'sub' => self::USER_ID,
            'iat' => $now,
            'exp' => $now + 600,
            'type' => 'refresh',
            JwtHandler::CLAIM_SESSION_ID => $family,
        ];
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $b64(json_encode($payload, JSON_THROW_ON_ERROR));
        $forged = $head . '.' . $body . '.'
            . $b64(hash_hmac('sha256', $head . '.' . $body, 'test-secret-key-12345', true));

        $decoded = $this->jwt->validateToken($forged);
        $this->assertIsArray($decoded, 'premise: the forged token must pass signature+shape validation');
        $this->assertTrue($this->jwt->isRefreshToken($forged), 'premise: it is a refresh-shaped token');
        $this->assertArrayNotHasKey('jti', $decoded, 'premise: exactly one claim is absent');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects($this->never())->method('findGrant');
        $store->expects($this->never())->method('markGrantConsumed');
        $store->expects($this->never())->method('revokeSession');
        $this->audit->expects($this->once())
            ->method('logFailedAuth')
            ->with($this->identicalTo('refresh_grant_jti_missing'), $this::anything());

        $this->expectExceptionObject(new \InvalidArgumentException('Token has been revoked'));
        $this->manager($store)->refreshToken($forged);
    }

    /**
     * ADJUDICATION RUNS BEFORE THE CP-005 GRACE MEMO can serve it, proven on
     * ONE manager instance across three presentations — the memo can only be
     * hit by a jti that was itself consumed on this worker, so the replay leg
     * must present the SAME token twice after it rotated once:
     *
     *  1. legacy (no-sid) refresh → opens family F, hands out S (memo fills
     *     under the LEGACY jti — S's own jti is not memoized yet);
     *  2. S → the store says live → S's grant is consumed, F continues, S2 is
     *     minted, and the memo now holds an entry keyed by S's jti;
     *  3. S AGAIN inside the 10s window, with the store now reporting F
     *     REVOKED (another worker ruled reuse in between): the memo lookup
     *     WOULD answer — adjudication runs first and refuses.
     *
     * Strip the order (memo lookup before adjudicateRefreshFamily) and
     * presentation 3 re-delivers the cached pair: grace memoizes a MINT, never
     * an authorization decision, and this is the leg that says so in code.
     */
    public function testGraceMemoCannotServeARefreshThatTheFamilyStoreRefuses(): void
    {
        $this->harness();

        $family = 'f' . str_repeat('5', 35);
        $finds = 0;
        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('createSession')->willReturn($family);
        $store->method('findGrant')->willReturnCallback(
            function (string $jti, int $grace) use (&$finds, $family): array {
                $finds++;
                if ($finds === 1) {
                    // Presentation 2: living lineage.
                    return [
                        'jti' => $jti, 'sessionId' => $family,
                        'sessionStatus' => 'active', 'consumed' => false, 'withinGrace' => false,
                    ];
                }
                // Presentation 3: the family died between the two windows.
                return [
                    'jti' => $jti, 'sessionId' => $family,
                    'sessionStatus' => 'revoked', 'consumed' => true, 'withinGrace' => true,
                ];
            }
        );

        $manager = $this->manager($store);
        $opened = $manager->refreshToken($this->jwt->createRefreshToken(self::USER_ID, []));
        $this->assertSame(0, $finds, 'premise: the family-opening leg carries no sid, so nothing adjudicates');

        $sToken = (string) $opened['refresh_token'];
        $decoded = $this->jwt->validateToken($sToken);
        $this->assertIsArray($decoded);
        $family = JwtHandler::sessionIdClaim($decoded);
        $this->assertNotNull($family, 'premise: presentation token carries the opened family');

        $rotated = $manager->refreshToken($sToken);
        $this->assertSame(1, $finds, 'premise: presentation 2 reached the store exactly once');
        $this->assertNotSame($sToken, $rotated['refresh_token'], 'premise: presentation 2 rotated inside the family');

        $this->audit->expects($this->once())
            ->method('logFailedAuth')
            ->with($this->identicalTo('token_session_revoked'), $this::anything());
        $store->expects($this->never())->method('revokeSession');

        $this->expectExceptionObject(new \InvalidArgumentException('Token has been revoked'));
        $manager->refreshToken($sToken);
    }

    // ---------------------------------------------------------------------------
    // Access path — live read, no cache, legacy untouched
    // ---------------------------------------------------------------------------

    /**
     * The family read is PER REQUEST, never memoized (de573d6e law): the same
     * unmodified token authenticates while the store says active and dies the
     * moment it says revoked — a resident worker that cached liveness would
     * reinvent the exact stale window this capability exists to close.
     */
    public function testAccessFamilyReadIsLiveEveryRequest(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('4', 35);
        $token = $this->jwt->createAccessToken(self::USER_ID, [JwtHandler::CLAIM_SESSION_ID => $family]);

        $answers = ['active' => true, 'revoked' => false];
        $order = [];
        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('isSessionActive')
            ->willReturnCallback(function (string $sid) use (&$answers, &$order): bool {
                $order[] = $sid;
                $next = array_shift($answers);
                return (bool) $next;
            });

        $manager = $this->manager($store);
        $first = $manager->validateAccessToken($token);
        $second = $manager->validateAccessToken($token);

        $this->assertIsArray($first, 'first presentation (store active) must authenticate');
        $this->assertNull(
            $second,
            'second presentation (store revoked) must die — a memo would have returned the first answer'
        );
        $this->assertSame([$family, $family], $order);
    }

    /**
     * A LEGACY token (no sid) never touches the family store on the access
     * path and its auth array keeps EXACTLY the pre-wave shape (zero
     * breakage arm — strip the null-guard and this reds on a store call).
     */
    public function testLegacyAccessNeverConsultsTheStoreAndShapeIsUntouched(): void
    {
        $this->harness();
        $token = $this->jwt->createAccessToken(self::USER_ID);

        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects($this->never())->method('isSessionActive');

        $auth = $this->manager($store)->validateAccessToken($token);
        $this->assertIsArray($auth);
        $this->assertSame([self::USER_ID, null, null], [
            $auth['user_id'],
            $auth['session_id'],
            $auth['profile_id'],
        ], 'a legacy token authenticates with a null family and the shipped profile degradation');
    }

    // ---------------------------------------------------------------------------
    // Nuclear semantics — watermark stays the user-wide option
    // ---------------------------------------------------------------------------

    /**
     * The choke-point regression pin: revokeUserTokens bumps the watermark AND
     * revokes the user's families in ONE call, with the SAME reason reaching
     * both — password change, admin disable, and forced logout all keep their
     * shipped last-man-standing behavior, now immediate on the family path
     * too. (The watermark leg must still decide legacy tokens on its own: the
     * old token dies without the store ever being consulted.)
     */
    public function testNuclearBumpRevokesWatermarkAndFamiliesWithOneReason(): void
    {
        $this->harness();
        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects($this->once())
            ->method('revokeAllForUser')
            ->with($this->identicalTo(self::USER_ID), $this->identicalTo('password_changed'));
        $store->expects($this->never())->method('isSessionActive');

        $this->users->expects($this->once())
            ->method('revokeTokensBeforeNow')
            ->with($this->identicalTo(self::USER_ID));

        $this->manager($store)->revokeUserTokens(self::USER_ID, 'password_changed');
    }

    /**
     * Per-device sign-out is the NARROW blade: revokeSession on exactly the
     * named family, audit logLogout, and the watermark + every other family
     * untouched — if a future edit "helpfully" widened it to the nuclear
     * primitive, this test is where it dies.
     */
    public function testPerDeviceRevokeNarrowsToExactlyOneFamily(): void
    {
        $this->harness();
        $family = 'f' . str_repeat('5', 35);
        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects($this->once())
            ->method('sessionBelongsToUser')
            ->with($this->identicalTo($family), $this->identicalTo(self::USER_ID))
            ->willReturn(true);
        $store->expects($this->once())
            ->method('revokeSession')
            ->with($this->identicalTo($family), $this->identicalTo('logout'));
        $store->expects($this->never())->method('revokeAllForUser');

        $this->users->expects($this->never())->method('revokeTokensBeforeNow');
        $this->audit->expects($this->once())
            ->method('logLogout')
            ->with($this->identicalTo(self::USER_ID), $this->identicalTo($family));

        $this->assertTrue($this->manager($store)->revokeTokenSession(self::USER_ID, $family));
    }

    /**
     * Foreign and unknown ids answer the SAME shape (false → the controller's
     * 404): no probe oracle for other accounts' family ids, and the store is
     * never written for a rejected id.
     */
    public function testForeignAndUnknownIdsBothRefuseWithoutWriting(): void
    {
        $this->harness();
        foreach (['missing', 'belongs-to-someone-else'] as $case) {
            $store = $this->createMock(TokenSessionRepository::class);
            $store->method('sessionBelongsToUser')->willReturn(false);
            $store->expects($this->never())->method('revokeSession');
            $this->assertFalse(
                $this->manager($store)->revokeTokenSession(self::USER_ID, 'f' . str_repeat('6', 35)),
                $case . ' must report not-found'
            );
        }
        $this->users->expects($this->never())->method('revokeTokensBeforeNow');
    }

    /**
     * Explicit logout keeps EVERY shipped duty (watermark bump → which now
     * carries the family revoke via the choke) AND still clears the legacy
     * device registry; REASON_EXPIRED stays pure bookkeeping with zero
     * revocation — the pre-wave asymmetry, pinned unchanged.
     */
    public function testLogoutKeepsTheReasonAsymmetryByteIdent(): void
    {
        $this->harness();
        $store = $this->createMock(TokenSessionRepository::class);
        $sessionManager = $this->createMock(SessionManager::class);

        $store->expects($this->once())
            ->method('revokeAllForUser')
            ->with($this->identicalTo(self::USER_ID), $this->identicalTo('logout:explicit'));
        $sessionManager->expects($this->once())->method('endAllUserSessions')->with(self::USER_ID);
        $this->users->expects($this->once())->method('revokeTokensBeforeNow')->with(self::USER_ID);

        $this->manager($store, $sessionManager)->logout(self::USER_ID, '', 'explicit');

        $quiet = $this->manager($store, $sessionManager);
        $quiet->logout(self::USER_ID, '', 'expired');
        // once() constraints above already prove the EXPIRED leg added no call.
    }

    // ---------------------------------------------------------------------------
    // List surface
    // ---------------------------------------------------------------------------

    /**
     * listTokenSessions is a thin live delegation (no filtering cleverness to
     * rot): unwired → [], wired → exactly the store's rows.
     */
    public function testListTokenSessionsDelegatesAndDegradates(): void
    {
        $this->harness();
        $this->assertSame([], $this->manager(null)->listTokenSessions(self::USER_ID));

        $rows = [[
            'id' => 'f' . str_repeat('7', 35),
            'created_at' => '2026-11-10 20:00:00',
            'last_used_at' => '2026-11-10 20:05:00',
            'device_type' => null,
            'device_name' => null,
        ]];
        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects($this->once())->method('listActiveSessions')->with(self::USER_ID)->willReturn($rows);
        $this->assertSame($rows, $this->manager($store)->listTokenSessions(self::USER_ID));
    }

    // ---------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------

    /**
     * File-backed silent logger (house test venue: keeps error_log clean
     * without faking the collaborator out of the composition). Every path is
     * registered for teardown — S439 zero-residue census runs after this suite.
     */
    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_d1a_' . uniqid('', true) . '.log';
        $this->tempLogs[] = $path;

        return new StructuredLogger('test', [
            'handlers' => ['stream' => [
                'type' => 'stream',
                'path' => $path,
                'level' => 'debug',
            ]],
            'processors' => ['context' => true, 'request_id' => false, 'user_id' => false],
        ]);
    }
}
