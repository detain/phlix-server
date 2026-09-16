<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\QuickConnectPair;
use Phlix\Auth\QuickConnectStateStore;
use Phlix\Auth\QuickConnectStateStoreInterface;
use Phlix\Server\Http\Controllers\Auth\QuickConnectController;
use Phlix\Server\Http\Request;
use Phlix\Stats\ClientHeartbeatStoreInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * S518 (AD-25 + AD-27): every handler of {@see QuickConnectController} against
 * doubled stores — request/response CONTRACTS and the auth decisions, per the
 * class docblock's placement law. The SQL semantics behind the store doubles
 * are the sibling unit file and the real-MySQL integration pair's jobs; here
 * the question at every arm is "what does the client see".
 */
final class QuickConnectControllerTest extends TestCase
{
    /** The step's collision sentinel executes through this assertion, never through prose. */
    public function testSurvivalTokenIsCodeResident(): void
    {
        self::assertSame(13, strlen(QuickConnectController::SURVIVAL_TOKEN));
        self::assertMatchesRegularExpression('/^S518[A-Z0-9]{9}$/', QuickConnectController::SURVIVAL_TOKEN);
    }

    // -----------------------------------------------------------------
    // initiate
    // -----------------------------------------------------------------

    public function testInitiateReturnsCodeSecretAndRemainingWindowOnce(): void
    {
        $pair = new QuickConnectPair('ACDFGH', 'sec-value', QuickConnectPair::STATE_PENDING, null, time() + 600);
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->method('issue')->willReturn($pair);
        $controller = $this->controller(pairs: $store);

        $response = $controller->initiate($this->request(), []);

        self::assertSame(200, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        self::assertSame('ACDFGH', $body['code']);
        // The secret is returned to the initiating device exactly here; no
        // other endpoint ever shows it again.
        self::assertSame('sec-value', $body['secret']);
        self::assertIsInt($body['expiresIn']);
        self::assertLessThanOrEqual(600, $body['expiresIn']);
        self::assertGreaterThan(500, $body['expiresIn']);
    }

    public function testInitiateFailClosesWhenStoreCannotPersist(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->method('issue')->willThrowException(new RuntimeException('db down'));
        $controller = $this->controller(pairs: $store);

        self::assertSame(503, $controller->initiate($this->request(), [])->statusCode);
    }

    public function testInitiateWithoutAnyStoreIs503NotAFabricatedPairing(): void
    {
        $controller = $this->controller();

        $response = $controller->initiate($this->request(), []);

        self::assertSame(503, $response->statusCode);
        self::assertSame('storage_unavailable', $this->jsonBody($response)['code']);
    }

    // -----------------------------------------------------------------
    // status
    // -----------------------------------------------------------------

    public function testStatusPassesThroughLiveStateVerbatim(): void
    {
        foreach (['pending', 'approved', 'denied'] as $state) {
            $pair = new QuickConnectPair('ACDFGH', 's', $state, null, time() + 300);
            $store = $this->createMock(QuickConnectStateStoreInterface::class);
            $store->method('find')->with('ACDFGH')->willReturn($pair);
            $controller = $this->controller(pairs: $store);

            $body = $this->jsonBody($controller->status($this->request(), ['code' => 'acdfgh']));
            self::assertSame($state, $body['state']);
        }
    }

    public function testStatusReportsExpiredForPastWindowRows(): void
    {
        $pair = new QuickConnectPair('ACDFGH', 's', QuickConnectPair::STATE_PENDING, null, time() - 1);
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->method('find')->willReturn($pair);
        $controller = $this->controller(pairs: $store);

        $body = $this->jsonBody($controller->status($this->request(), ['code' => 'ACDFGH']));
        self::assertSame('expired', $body['state']);
    }

    public function testStatusNeverConsultsSqlForOffShapeCodes(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::never())->method('find');
        $controller = $this->controller(pairs: $store);

        foreach (['nope', 'ACDFG1', 'ACDFGO', ''] as $raw) {
            $response = $controller->status($this->request(), ['code' => $raw]);
            self::assertSame(404, $response->statusCode);
            self::assertSame('pairing_not_found', $this->jsonBody($response)['code']);
        }
    }

    // -----------------------------------------------------------------
    // approve
    // -----------------------------------------------------------------

    public function testApproveRefusesAnonymousEvenIfMiddlewareRegressed(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::never())->method('approve');
        $controller = $this->controller(pairs: $store);

        $response = $controller->approve($this->request(body: ['secret' => 's']), ['code' => 'ACDFGH']);

        self::assertSame(401, $response->statusCode);
    }

    public function testApproveGrantsTheSessionIdentityNeverAClaimedOne(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::once())->method('approve')
            ->with('ACDFGH', 'the-secret', 'session-user-7')
            ->willReturn(QuickConnectStateStore::RESULT_APPROVED);
        $controller = $this->controller(pairs: $store);

        // The body lies about who is approving; the session is the only authority.
        $response = $controller->approve(
            $this->request(userId: 'session-user-7', body: ['secret' => 'the-secret', 'user_id' => 'attacker']),
            ['code' => 'ACDFGH']
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame('approved', $this->jsonBody($response)['state']);
    }

    public function testApproveMapsStoreOutcomesToDistinctStatuses(): void
    {
        $cases = [
            [QuickConnectStateStore::RESULT_BAD_SECRET, 403],
            [QuickConnectStateStore::RESULT_NOT_READY, 409],
            [QuickConnectStateStore::RESULT_UNKNOWN, 404],
        ];
        foreach ($cases as [$result, $status]) {
            $store = $this->createMock(QuickConnectStateStoreInterface::class);
            $store->method('approve')->willReturn($result);
            $controller = $this->controller(pairs: $store);

            $response = $controller->approve(
                $this->request(userId: 'u', body: ['secret' => 'x']),
                ['code' => 'ACDFGH']
            );
            self::assertSame($status, $response->statusCode);
        }
    }

    public function testApproveRequiresASecretInTheBody(): void
    {
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::never())->method('approve');
        $controller = $this->controller(pairs: $store);

        self::assertSame(400, $controller->approve($this->request(userId: 'u'), ['code' => 'ACDFGH'])->statusCode);
    }

    // -----------------------------------------------------------------
    // token
    // -----------------------------------------------------------------

    public function testTokenRedeemsIntoCamelCaseContractWithFullSessionPayload(): void
    {
        $pair = new QuickConnectPair('ACDFGH', 's', QuickConnectPair::STATE_APPROVED, 'user-1', time() + 300);
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->method('consumeApproved')->willReturn([QuickConnectStateStore::RESULT_APPROVED, $pair]);

        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects(self::once())->method('buildAuthResponse')->with('user-1')->willReturn([
            'access_token' => 'AT',
            'refresh_token' => 'RT',
            'profile_id' => 'P1',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => ['id' => 'user-1'],
        ]);
        $controller = $this->controller(pairs: $store, authManager: $authManager, serverUrl: 'https://media.example.com');

        $body = $this->jsonBody($controller->token($this->request(body: ['secret' => 's']), ['code' => 'ACDFGH']));

        // survey-d's camelCase contract, verbatim…
        self::assertSame('AT', $body['accessToken']);
        self::assertSame('RT', $body['refreshToken']);
        self::assertSame('https://media.example.com', $body['serverUrl']);
        // …carrying the same token material `login` mints.
        self::assertSame('Bearer', $body['tokenType']);
        self::assertSame(3600, $body['expiresIn']);
        self::assertSame('P1', $body['profileId']);
        self::assertSame(['id' => 'user-1'], $body['user']);
    }

    public function testTokenCollapsesEveryNonRedemptionToTheUniform404(): void
    {
        foreach (
            [
                QuickConnectStateStore::RESULT_UNKNOWN,
                QuickConnectStateStore::RESULT_BAD_SECRET,
                QuickConnectStateStore::RESULT_NOT_READY,
                QuickConnectStateStore::RESULT_STORAGE,
            ] as $result
        ) {
            $store = $this->createMock(QuickConnectStateStoreInterface::class);
            $store->method('consumeApproved')->willReturn([$result, null]);
            $authManager = $this->createMock(AuthManager::class);
            $authManager->expects(self::never())->method('buildAuthResponse');
            $controller = $this->controller(pairs: $store, authManager: $authManager);

            $response = $controller->token($this->request(body: ['secret' => 'x']), ['code' => 'ACDFGH']);

            self::assertSame(404, $response->statusCode);
            self::assertSame('pairing_not_found', $this->jsonBody($response)['code']);
        }
    }

    public function testTokenRefusesToMintForAnExpiredApprovedRow(): void
    {
        $pair = new QuickConnectPair('ACDFGH', 's', QuickConnectPair::STATE_APPROVED, 'user-1', time() - 1);
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->method('consumeApproved')->willReturn([QuickConnectStateStore::RESULT_APPROVED, $pair]);
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects(self::never())->method('buildAuthResponse');
        $controller = $this->controller(pairs: $store, authManager: $authManager);

        self::assertSame(404, $controller->token($this->request(body: ['secret' => 's']), ['code' => 'ACDFGH'])->statusCode);
    }

    // -----------------------------------------------------------------
    // heartbeat (AD-27)
    // -----------------------------------------------------------------

    public function testHeartbeatRefusesWithoutExplicitConsentTrue(): void
    {
        foreach ([[], ['consent' => false], ['consent' => 'yes'], ['consent' => 1]] as $body) {
            $store = $this->createMock(ClientHeartbeatStoreInterface::class);
            $store->expects(self::never())->method('record');
            $controller = $this->controller(heartbeats: $store);

            $response = $controller->heartbeat($this->request(body: $body), []);

            self::assertSame(400, $response->statusCode);
            self::assertSame('consent_required', $this->jsonBody($response)['code']);
        }
    }

    public function testHeartbeatRejectsUnboundedOrMisshapenPayloads(): void
    {
        $valid = ['consent' => true, 'instance_id' => 'inst-0123456789ab', 'version' => '1.2.3', 'client_type' => 'tizen'];
        $cases = [
            'missing instance' => array_diff_key($valid, ['instance_id' => 1]),
            'short instance' => ['instance_id' => 'tiny'] + $valid,
            'long instance' => ['instance_id' => str_repeat('x', 65)] + $valid,
            'punctuated instance' => ['instance_id' => 'inst/../etc'] + $valid,
            'missing version' => array_diff_key($valid, ['version' => 1]),
            'long version' => ['version' => str_repeat('v', 33)] + $valid,
            'long client type' => ['client_type' => str_repeat('c', 33)] + $valid,
            'oversized build token' => ['build_token' => str_repeat('b', 65)] + $valid,
            'array build token' => ['build_token' => ['x']] + $valid,
        ];

        foreach ($cases as $label => $body) {
            $store = $this->createMock(ClientHeartbeatStoreInterface::class);
            $store->expects(self::never())->method('record');
            $controller = $this->controller(heartbeats: $store);

            $response = $controller->heartbeat($this->request(body: $body), []);
            self::assertSame(400, $response->statusCode, $label);
            self::assertSame('invalid_payload', $this->jsonBody($response)['code'], $label);
        }
    }

    public function testHeartbeatRecordsTheExactBoundedQuartet(): void
    {
        $store = $this->createMock(ClientHeartbeatStoreInterface::class);
        $store->expects(self::once())->method('record')
            ->with('inst-0123456789ab', '1.2.3', 'tizen', 'build-77')
            ->willReturn(true);
        $controller = $this->controller(heartbeats: $store);

        $response = $controller->heartbeat($this->request(body: [
            'consent' => true,
            'instance_id' => 'inst-0123456789ab',
            'version' => '1.2.3',
            'client_type' => 'tizen',
            'build_token' => 'build-77',
            // unknown extras must be dropped, never persisted:
            'email' => ' pii@example.com',
        ]), []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(['success' => true, 'recorded' => true], $this->jsonBody($response));
    }

    public function testHeartbeatSwallowsStorageFailureIntoRecordedFalse(): void
    {
        // "every failure swallowed": the store says it could not land, and the
        // consenting client still gets a 200 — the census is best-effort by
        // contract, never a client-visible outage.
        $store = $this->createMock(ClientHeartbeatStoreInterface::class);
        $store->method('record')->willReturn(false);
        $controller = $this->controller(heartbeats: $store);

        $response = $controller->heartbeat($this->request(body: [
            'consent' => true,
            'instance_id' => 'inst-0123456789ab',
            'version' => '1.2.3',
            'client_type' => 'roku',
        ]), []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(['success' => true, 'recorded' => false], $this->jsonBody($response));
    }

    public function testHeartbeatWithoutAnyStoreIs503(): void
    {
        $controller = $this->controller();

        $response = $controller->heartbeat($this->request(body: [
            'consent' => true,
            'instance_id' => 'inst-0123456789ab',
            'version' => '1.2.3',
            'client_type' => 'tizen',
        ]), []);

        self::assertSame(503, $response->statusCode);
    }

    // -----------------------------------------------------------------
    // admin census read
    // -----------------------------------------------------------------

    public function testAdminClientsReturnsTotalAndRows(): void
    {
        $rows = [['instance_id' => 'a', 'version' => '1', 'client_type' => 'tizen',
            'build_token' => '', 'first_seen_at' => 'x', 'last_seen_at' => 'y']];
        $store = $this->createMock(ClientHeartbeatStoreInterface::class);
        $store->method('countInstances')->willReturn(1);
        $store->method('recent')->with(200)->willReturn($rows);
        $controller = $this->controller(heartbeats: $store);

        $body = $this->jsonBody($controller->adminClients($this->request(), []));

        self::assertTrue($body['success']);
        self::assertSame(1, $body['data']['total']);
        self::assertSame($rows, $body['data']['instances']);
    }

    public function testAdminClientsHonoursLimitQuery(): void
    {
        $store = $this->createMock(ClientHeartbeatStoreInterface::class);
        $store->expects(self::once())->method('recent')->with(50)->willReturn([]);
        $store->method('countInstances')->willReturn(0);
        $controller = $this->controller(heartbeats: $store);

        $request = $this->request();
        $request->query = ['limit' => '50'];

        self::assertSame(200, $controller->adminClients($request, [])->statusCode);
    }

    // -----------------------------------------------------------------
    // serverUrl resolution
    // -----------------------------------------------------------------

    public function testServerUrlPrefersTheConfiguredAbsoluteUrl(): void
    {
        $body = $this->redeemWithUrl('https://media.example.com/');

        // trailing slash trimmed; request headers irrelevant once configured.
        self::assertSame('https://media.example.com', $body['serverUrl']);
    }

    public function testServerUrlFallsBackToTheRequestersOwnHost(): void
    {
        // An unconfigured box pairs on the LAN; echoing back the Host the TV
        // itself dialed is self-pointing (see resolveServerUrl docblock).
        $request = $this->request(headers: ['Host' => '192.168.1.50:8096']);

        $body = $this->redeemWithUrl('', $request);

        self::assertSame('http://192.168.1.50:8096', $body['serverUrl']);
    }

    public function testServerUrlTrustsForwardedProtoOnlyForHttps(): void
    {
        $request = $this->request(headers: ['Host' => 'media.local', 'X-Forwarded-Proto' => 'https']);
        self::assertSame('https://media.local', $this->redeemWithUrl('', $request)['serverUrl']);

        $request = $this->request(headers: ['Host' => 'media.local', 'X-Forwarded-Proto' => 'gopher://evil']);
        self::assertSame('http://media.local', $this->redeemWithUrl('', $request)['serverUrl']);
    }

    public function testServerUrlDropsHostsWithUserInfoOrJunk(): void
    {
        $request = $this->request(headers: ['Host' => 'evil@media.example']);
        self::assertSame('', $this->redeemWithUrl('', $request)['serverUrl']);

        $request = $this->request(headers: []);
        self::assertSame('', $this->redeemWithUrl('', $request)['serverUrl']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function redeemWithUrl(string $serverUrl, ?Request $request = null): array
    {
        $pair = new QuickConnectPair('ACDFGH', 's', QuickConnectPair::STATE_APPROVED, 'user-1', time() + 300);
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->method('consumeApproved')->willReturn([QuickConnectStateStore::RESULT_APPROVED, $pair]);
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('buildAuthResponse')->willReturn([
            'access_token' => 'AT', 'refresh_token' => 'RT', 'profile_id' => null,
            'token_type' => 'Bearer', 'expires_in' => 3600, 'user' => [],
        ]);
        $controller = $this->controller(pairs: $store, authManager: $authManager, serverUrl: $serverUrl);

        if ($request === null) {
            $request = $this->request(body: ['secret' => 's']);
        } else {
            // Custom-header requests still need the redeeming secret.
            $request->body = ['secret' => 's'];
        }
        $response = $controller->token($request, ['code' => 'ACDFGH']);

        return $this->jsonBody($response);
    }

    private function controller(
        ?QuickConnectStateStoreInterface $pairs = null,
        ?ClientHeartbeatStoreInterface $heartbeats = null,
        ?AuthManager $authManager = null,
        string $serverUrl = '',
    ): QuickConnectController {
        return new QuickConnectController(
            $authManager ?? $this->createMock(AuthManager::class),
            $pairs,
            $heartbeats,
            serverUrl: $serverUrl,
        );
    }

    /**
     * Direct declared-member assignment only (S427 license; census-visible).
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function request(?string $userId = null, array $body = [], array $headers = []): Request
    {
        $request = new Request();
        $request->userId = $userId;
        $request->body = $body;
        $request->headers = $headers;
        $request->remoteIp = '203.0.113.9';

        return $request;
    }

    /** @return array<string, mixed> */
    private function jsonBody(\Phlix\Server\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
