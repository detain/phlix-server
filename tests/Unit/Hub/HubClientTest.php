<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\ClaimInitiateResult;
use Phlix\Hub\ClaimStatusResult;
use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\HeartbeatResult;
use Phlix\Hub\HubClient;
use Phlix\Hub\HubClientException;
use Phlix\Hub\HttpClient;
use Phlix\Hub\HttpClientInterface;
use Phlix\Hub\HttpResponse;
use Phlix\Hub\StoredEnrollment;
use Phlix\Common\Logger\StructuredLogger;

class HubClientTest extends TestCase
{
    private string $tmpDir;
    private string $keyPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-client-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->keyPath = $this->tmpDir . '/key.pem';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function test_initiatePairing_returns_claim_code_and_id(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        // The hub replies in the shared ClaimResponse wire format (camelCase).
        $httpClient->method('post')->willReturn(new HttpResponse(200, [], [
            'claimCode' => 'ABCD-1234',
            'expiresIn' => 600,
            'claimId' => 'claim-uuid-123',
            'hubBaseUrl' => 'https://hub.example.com',
        ]));

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $result = $client->initiatePairing('https://hub.example.com', 'Test Server');

        $this->assertInstanceOf(ClaimInitiateResult::class, $result);
        $this->assertEquals('ABCD-1234', $result->claimCode);
        $this->assertEquals(600, $result->expiresIn);
        $this->assertEquals('claim-uuid-123', $result->claimId);
    }

    public function test_initiatePairing_advertises_configured_public_url_as_hostname_candidate(): void
    {
        // Under the Workerman daemon $_SERVER is empty, so the configured public
        // URL (from PHLIX_DOMAIN via config/hub.php) is what tells the hub a
        // reachable hostname. It must appear in the claim's hostnameCandidates.
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $capturedPayload = null;
        $httpClient->method('post')->willReturnCallback(
            function (string $url, array $payload) use (&$capturedPayload): HttpResponse {
                $capturedPayload = $payload;
                return new HttpResponse(200, [], [
                    'claimCode' => 'ABCD-1234',
                    'expiresIn' => 600,
                    'claimId' => 'claim-uuid-123',
                    'hubBaseUrl' => 'https://hub.example.com',
                ]);
            }
        );

        $client = new HubClient(
            $keyManager,
            $httpClient,
            $logger,
            $this->tmpDir,
            '0.11.0',
            null,
            'https://intertainer.phlix.interserver.net',
        );
        $client->initiatePairing('https://hub.example.com', 'Test Server');

        $this->assertIsArray($capturedPayload);
        $candidates = $capturedPayload['hostnameCandidates'] ?? null;
        $this->assertIsArray($candidates);
        $this->assertContains('https://intertainer.phlix.interserver.net', $candidates);
    }

    public function test_initiatePairing_posts_to_the_absolute_hub_url(): void
    {
        // Regression: the pre-enrollment client has an empty placeholder base, so
        // a bare path made cURL fail with "URL rejected: No host part in the URL".
        // The call must carry the operator-supplied hub URL (trailing slash trimmed).
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $httpClient->expects($this->once())
            ->method('post')
            ->with('https://hub.example.com/api/v1/server-claims/new', $this->anything())
            ->willReturn(new HttpResponse(200, [], [
                'claimCode' => 'X', 'expiresIn' => 1, 'claimId' => 'c', 'hubBaseUrl' => 'https://hub.example.com',
            ]));

        (new HubClient($keyManager, $httpClient, $logger, $this->tmpDir))
            ->initiatePairing('https://hub.example.com/', 'Test Server');
    }

    public function test_pollClaimStatus_gets_the_absolute_hub_url(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $httpClient->expects($this->once())
            ->method('get')
            ->with('https://hub.example.com/api/v1/server-claims/claim-7')
            ->willReturn(new HttpResponse(200, [], ['status' => 'pending']));

        (new HubClient($keyManager, $httpClient, $logger, $this->tmpDir))
            ->pollClaimStatus('claim-7', 'https://hub.example.com/');
    }

    public function test_pollClaimStatus_pending_when_not_yet_claimed(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $httpClient->method('get')->willReturn(new HttpResponse(200, [], [
            'status' => 'pending',
        ]));

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $result = $client->pollClaimStatus('claim-uuid', 'https://hub.example.com');

        $this->assertInstanceOf(ClaimStatusResult::class, $result);
        $this->assertEquals(ClaimStatusResult::STATUS_PENDING, $result->status);
        $this->assertNull($result->enrollmentJwt);
    }

    public function test_pollClaimStatus_claimed_stores_enrollment(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $httpClient->method('get')->willReturn(new HttpResponse(200, [], [
            'status' => 'claimed',
            'enrollment_jwt' => 'eyJ.enrollment.jwt',
            'hub_jwks_url' => 'https://hub.example.com/.well-known/jwks.json',
            'server_id' => 'server-uuid-456',
        ]));

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $result = $client->pollClaimStatus('claim-uuid', 'https://hub.example.com');

        $this->assertEquals(ClaimStatusResult::STATUS_CLAIMED, $result->status);
        $this->assertEquals('eyJ.enrollment.jwt', $result->enrollmentJwt);
        $this->assertEquals('https://hub.example.com/.well-known/jwks.json', $result->hubJwksUrl);
        $this->assertEquals('server-uuid-456', $result->serverId);
    }

    public function test_pollClaimStatus_expired_returns_expired_status(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $httpClient->method('get')->willReturn(new HttpResponse(200, [], [
            'status' => 'expired',
        ]));

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $result = $client->pollClaimStatus('claim-uuid', 'https://hub.example.com');

        $this->assertEquals(ClaimStatusResult::STATUS_EXPIRED, $result->status);
    }

    public function test_storeEnrollment_writes_json_file(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $client->storeEnrollment(
            'jwt-token',
            'https://hub.example.com/.well-known/jwks.json',
            'server-uuid',
            'https://hub.example.com',
        );

        $enrollmentPath = $this->tmpDir . '/hub-enrollment.json';
        $this->assertFileExists($enrollmentPath);

        $json = file_get_contents($enrollmentPath);
        $this->assertIsString($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertEquals('jwt-token', $data['enrollment_jwt']);
        $this->assertEquals('https://hub.example.com/.well-known/jwks.json', $data['hub_jwks_url']);
        $this->assertEquals('server-uuid', $data['server_id']);
    }

    public function test_loadEnrollment_returns_null_when_not_enrolled(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $result = $client->loadEnrollment();

        $this->assertNull($result);
    }

    public function test_loadEnrollment_returns_stored_enrollment(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $client->storeEnrollment(
            'jwt-token',
            'https://hub.example.com/.well-known/jwks.json',
            'server-uuid',
            'https://hub.example.com',
        );

        $result = $client->loadEnrollment();

        $this->assertInstanceOf(StoredEnrollment::class, $result);
        $this->assertEquals('jwt-token', $result->enrollmentJwt);
        $this->assertEquals('https://hub.example.com/.well-known/jwks.json', $result->hubJwksUrl);
        $this->assertEquals('server-uuid', $result->serverId);
        $this->assertEquals('https://hub.example.com', $result->hubBaseUrl);
    }

    public function test_sendHeartbeat_success(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $client->storeEnrollment(
            'jwt-token',
            'https://hub.example.com/.well-known/jwks.json',
            'server-uuid',
            'https://hub.example.com',
        );

        $httpClient->method('post')->willReturn(new HttpResponse(200, [], []));

        $result = $client->sendHeartbeat();

        $this->assertInstanceOf(HeartbeatResult::class, $result);
        $this->assertTrue($result->ok);
        $this->assertNull($result->error);
    }

    public function test_sendHeartbeat_advertises_libraries_from_provider(): void
    {
        // The hub caches heartbeat-reported libraries in server_libraries so the
        // owner's dashboard can list them; the payload must carry them.
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $capturedPayload = null;
        $httpClient->method('post')->willReturnCallback(
            function (string $url, array $payload) use (&$capturedPayload): HttpResponse {
                $capturedPayload = $payload;
                return new HttpResponse(200, [], []);
            }
        );

        $client = new HubClient(
            $keyManager,
            $httpClient,
            $logger,
            $this->tmpDir,
            '0.11.0',
            null,
            '',
            static fn (): array => [
                ['library_id' => 'lib-1', 'library_name' => 'Movies'],
                ['library_id' => 'lib-2', 'library_name' => 'TV'],
            ],
        );
        $client->storeEnrollment(
            'jwt-token',
            'https://hub.example.com/.well-known/jwks.json',
            'server-uuid',
            'https://hub.example.com',
        );

        $result = $client->sendHeartbeat();

        $this->assertTrue($result->ok);
        $this->assertIsArray($capturedPayload);
        $this->assertSame(
            [
                ['library_id' => 'lib-1', 'library_name' => 'Movies'],
                ['library_id' => 'lib-2', 'library_name' => 'TV'],
            ],
            $capturedPayload['libraries'] ?? null,
        );
    }

    public function test_sendHeartbeat_survives_a_failing_libraries_provider(): void
    {
        // A library-collection failure must never break the heartbeat itself.
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $capturedPayload = null;
        $httpClient->method('post')->willReturnCallback(
            function (string $url, array $payload) use (&$capturedPayload): HttpResponse {
                $capturedPayload = $payload;
                return new HttpResponse(200, [], []);
            }
        );

        $client = new HubClient(
            $keyManager,
            $httpClient,
            $logger,
            $this->tmpDir,
            '0.11.0',
            null,
            '',
            static function (): array {
                throw new \RuntimeException('db down');
            },
        );
        $client->storeEnrollment(
            'jwt-token',
            'https://hub.example.com/.well-known/jwks.json',
            'server-uuid',
            'https://hub.example.com',
        );

        $result = $client->sendHeartbeat();

        $this->assertTrue($result->ok);
        $this->assertSame([], $capturedPayload['libraries'] ?? null);
    }

    public function test_sendHeartbeat_unauthorized_re_enrolls(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $client->storeEnrollment(
            'jwt-token',
            'https://hub.example.com/.well-known/jwks.json',
            'server-uuid',
            'https://hub.example.com',
        );

        $httpClient->method('post')->willReturn(new HttpResponse(401, [], [
            'error' => 'ENROLLMENT_TOKEN_EXPIRED',
        ]));

        $result = $client->sendHeartbeat();

        $this->assertFalse($result->ok);
        $this->assertEquals('ENROLLMENT_TOKEN_EXPIRED', $result->errorCode);
    }

    public function test_reEnrollIfNeeded_noops_when_not_expired(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        // A fresh enrollment (enrolled_at = now) is well inside the 6-day
        // window, so no renew call must be made.
        $httpClient->expects($this->never())->method('post');

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $client->storeEnrollment(
            'jwt-token',
            'https://hub.example.com/.well-known/jwks.json',
            'server-uuid',
            'https://hub.example.com',
        );

        $reEnrolled = $client->reEnrollIfNeeded();

        $this->assertFalse($reEnrolled);
    }

    public function test_reEnrollIfNeeded_renews_within_six_to_seven_day_window(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        // Enrollment is 6.5 days old: inside the renewal window but the
        // current JWT is still valid (< 7 days), so renew should fire.
        $enrollmentPath = $this->tmpDir . '/hub-enrollment.json';
        $renewableData = [
            'enrollment_jwt' => 'old-jwt',
            'hub_jwks_url' => 'https://hub.example.com/.well-known/jwks.json',
            'server_id' => 'server-uuid',
            'hub_base_url' => 'https://hub.example.com',
            'enrolled_at' => time() - (int) (6.5 * 86400),
        ];
        file_put_contents($enrollmentPath, json_encode($renewableData));

        $httpClient->expects($this->once())
            ->method('post')
            ->with('/api/v1/servers/server-uuid/renew', [])
            ->willReturn(new HttpResponse(200, [], [
                'enrollment_jwt' => 'fresh-jwt',
                'expires_in' => 604800,
            ]));

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $reEnrolled = $client->reEnrollIfNeeded();

        $this->assertTrue($reEnrolled);

        // The fresh enrollment must be persisted with the new JWT and a
        // reset enrolled_at timestamp.
        $json = file_get_contents($enrollmentPath);
        $this->assertIsString($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertEquals('fresh-jwt', $data['enrollment_jwt']);
        $this->assertEquals('server-uuid', $data['server_id']);
        $this->assertEquals('https://hub.example.com', $data['hub_base_url']);
        $this->assertGreaterThanOrEqual(time() - 5, $data['enrolled_at']);
    }

    public function test_reEnrollIfNeeded_returns_false_when_renew_fails(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $enrollmentPath = $this->tmpDir . '/hub-enrollment.json';
        $renewableData = [
            'enrollment_jwt' => 'old-jwt',
            'hub_jwks_url' => 'https://hub.example.com/.well-known/jwks.json',
            'server_id' => 'server-uuid',
            'hub_base_url' => 'https://hub.example.com',
            'enrolled_at' => time() - (int) (6.5 * 86400),
        ];
        file_put_contents($enrollmentPath, json_encode($renewableData));

        $httpClient->expects($this->once())
            ->method('post')
            ->willReturn(new HttpResponse(401, [], [
                'error_code' => 'ENROLLMENT_TOKEN_EXPIRED',
            ]));

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $reEnrolled = $client->reEnrollIfNeeded();

        $this->assertFalse($reEnrolled);

        // The stored enrollment must be untouched on failure.
        $json = file_get_contents($enrollmentPath);
        $this->assertIsString($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertEquals('old-jwt', $data['enrollment_jwt']);
    }

    public function test_reEnrollIfNeeded_does_not_renew_when_already_expired(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        // Already fully expired (8 days): the JWT can no longer authenticate
        // a renew, so no renew call must be made and the method returns false.
        $httpClient->expects($this->never())->method('post');

        $enrollmentPath = $this->tmpDir . '/hub-enrollment.json';
        $expiredData = [
            'enrollment_jwt' => 'old-jwt',
            'hub_jwks_url' => 'https://hub.example.com/.well-known/jwks.json',
            'server_id' => 'server-uuid',
            'hub_base_url' => 'https://hub.example.com',
            'enrolled_at' => time() - (8 * 86400),
        ];
        file_put_contents($enrollmentPath, json_encode($expiredData));

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $reEnrolled = $client->reEnrollIfNeeded();

        $this->assertFalse($reEnrolled);
    }

    public function test_getPublicKeysJwk_returns_array_of_jwk(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $keys = $client->getPublicKeysJwk();

        $this->assertNotEmpty($keys);
        $this->assertEquals('OKP', $keys[0]['kty']);
        $this->assertEquals('EdDSA', $keys[0]['alg']);
    }

    public function test_getPublicKeysJwk_degrades_to_empty_keyset_and_logs_error_on_load_failure(): void
    {
        // SV-4.16 graceful degrade: a malformed/unreadable signing key must
        // never take down the public /.well-known/jwks.json surface with an
        // unhandled 500. getPublicKeysJwk() catches the load failure, logs at
        // ERROR, and returns an empty keyset so the controller can still serve
        // a valid RFC 7517 {"keys":[]} at HTTP 200.
        file_put_contents(
            $this->keyPath,
            "-----BEGIN PRIVATE KEY-----\n!!garbage!!\n-----END PRIVATE KEY-----\n"
        );

        $logFile = $this->tmpDir . '/hub-error.log';
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        // Route the logger to a temp stream so the ERROR record can be asserted.
        $logger = new StructuredLogger('hub', [
            'handlers' => [
                'err' => ['type' => 'stream', 'path' => $logFile, 'level' => 'error'],
            ],
        ]);

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $keys = $client->getPublicKeysJwk();

        $this->assertSame([], $keys);

        $logged = file_get_contents($logFile);
        if ($logged === false) {
            self::fail('Expected an ERROR log record but the log file was not written');
        }
        $this->assertStringContainsString('hub.ERROR', $logged);
        $this->assertStringContainsString('serving empty keyset', $logged);
    }
}
