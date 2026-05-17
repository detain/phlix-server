<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\ClaimInitiateResult;
use Phlex\Hub\ClaimStatusResult;
use Phlex\Hub\Ed25519KeyManager;
use Phlex\Hub\HeartbeatResult;
use Phlex\Hub\HubClient;
use Phlex\Hub\HubClientException;
use Phlex\Hub\HttpClient;
use Phlex\Hub\HttpClientInterface;
use Phlex\Hub\HttpResponse;
use Phlex\Hub\StoredEnrollment;
use Phlex\Common\Logger\StructuredLogger;

class HubClientTest extends TestCase
{
    private string $tmpDir;
    private string $keyPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlex-hub-client-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->keyPath = $this->tmpDir . '/key.pem';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*');
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

        $httpClient->method('post')->willReturn(new HttpResponse(200, [], [
            'claim_code' => 'ABCD-1234',
            'expires_in' => 600,
            'claim_id' => 'claim-uuid-123',
            'hub_base_url' => 'https://hub.example.com',
        ]));

        $client = new HubClient($keyManager, $httpClient, $logger, $this->tmpDir);
        $result = $client->initiatePairing('https://hub.example.com', 'Test Server');

        $this->assertInstanceOf(ClaimInitiateResult::class, $result);
        $this->assertEquals('ABCD-1234', $result->claimCode);
        $this->assertEquals(600, $result->expiresIn);
        $this->assertEquals('claim-uuid-123', $result->claimId);
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

        $data = json_decode(file_get_contents($enrollmentPath), true);
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

    public function test_reEnrollIfNeeded_re_enrolls_when_expired(): void
    {
        $keyManager = new Ed25519KeyManager($this->keyPath);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = new StructuredLogger('hub', []);

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

        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);
        $this->assertEquals('OKP', $keys[0]['kty']);
        $this->assertEquals('EdDSA', $keys[0]['alg']);
    }
}
