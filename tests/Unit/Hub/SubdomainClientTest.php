<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\HeartbeatResult;
use Phlix\Hub\HubClient;
use Phlix\Hub\HttpClient;
use Phlix\Hub\HttpResponse;
use Phlix\Hub\StoredEnrollment;
use Phlix\Hub\SubdomainClient;
use Phlix\Hub\SubdomainResult;
use Phlix\Common\Logger\StructuredLogger;

class SubdomainClientTest extends TestCase
{
    private string $tmpDir;
    private StructuredLogger $logger;
    private HubClient $hubClient;
    private SubdomainClient $subdomainClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-subdomain-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->logger = new StructuredLogger('test', []);

        $keyPath = $this->tmpDir . '/key.pem';
        $keyManager = new Ed25519KeyManager($keyPath);

        $httpClient = $this->createMock(HttpClient::class);
        $this->hubClient = new HubClient($keyManager, $httpClient, $this->logger, $this->tmpDir);

        $this->subdomainClient = new SubdomainClient(
            $this->hubClient,
            'server-test-123',
            $this->logger,
            $this->tmpDir,
        );
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
        parent::tearDown();
    }

    public function test_claimSubdomain_returns_null_when_not_enrolled(): void
    {
        $result = $this->subdomainClient->claimSubdomain();

        $this->assertNull($result);
    }

    public function test_getCurrentSubdomain_returns_null_when_not_claimed(): void
    {
        $subdomain = $this->subdomainClient->getCurrentSubdomain();

        $this->assertNull($subdomain);
    }

    public function test_getCurrentSubdomain_returns_stored_subdomain(): void
    {
        $configFile = $this->tmpDir . '/hub-subdomain.json';
        $configData = [
            'subdomain' => 'abc12345',
            'fqdn' => 'abc12345.phlix.media',
            'tls_cert_path' => '/certs/fullchain.pem',
            'tls_key_path' => '/certs/privkey.pem',
        ];
        file_put_contents($configFile, json_encode($configData));

        $subdomain = $this->subdomainClient->getCurrentSubdomain();

        $this->assertSame('abc12345', $subdomain);
    }

    public function test_releaseSubdomain_returns_false_when_not_enrolled(): void
    {
        $result = $this->subdomainClient->releaseSubdomain();

        $this->assertFalse($result);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->recursiveDelete($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
