<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayConfig;
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\RelayMessageFramer;
use Phlix\Hub\StoredEnrollment;
use Phlix\Common\Logger\StructuredLogger;

class RelayConsumerTest extends TestCase
{
    private string $tmpDir;
    private string $keyPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlix-relay-consumer-test-' . uniqid();
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

    private function createMockHubClient(): HubClient
    {
        $enrollment = new StoredEnrollment(
            enrollmentJwt: 'test-jwt',
            hubJwksUrl: 'https://hub.example.com/.well-known/jwks.json',
            serverId: 'server-uuid-123',
            hubBaseUrl: 'https://hub.example.com',
            enrolledAt: time(),
        );

        $mock = $this->createMock(HubClient::class);
        $mock->method('loadEnrollment')->willReturn($enrollment);

        return $mock;
    }

    private function createRelayConsumer(?RelayConfig $config = null): RelayConsumer
    {
        $config = $config ?? new RelayConfig(enabled: false);
        $hubClient = $this->createMockHubClient();
        $logger = new StructuredLogger('relay', []);

        return new RelayConsumer($config, $hubClient, $logger, 'server-uuid-123');
    }

    public function test_start_does_nothing_when_disabled(): void
    {
        $consumer = $this->createRelayConsumer(new RelayConfig(enabled: false));

        $consumer->start();

        $this->assertFalse($consumer->isConnected());
    }

    public function test_stop_does_nothing_when_not_running(): void
    {
        $consumer = $this->createRelayConsumer(new RelayConfig(enabled: false));

        $consumer->stop();

        $this->assertFalse($consumer->isConnected());
    }

    public function test_isConnected_returns_false_initially(): void
    {
        $consumer = $this->createRelayConsumer(new RelayConfig(enabled: false));

        $this->assertFalse($consumer->isConnected());
    }

    public function test_relay_config_from_env_disabled(): void
    {
        putenv('PHLIX_RELAY_ENABLED=false');
        $config = RelayConfig::fromEnv();
        $this->assertFalse($config->enabled);
        putenv('PHLIX_RELAY_ENABLED');
    }

    public function test_relay_config_from_env_enabled(): void
    {
        putenv('PHLIX_RELAY_ENABLED=true');
        putenv('PHLIX_RELAY_HUB_URL=wss://hub.example.com/api/v1/servers/{id}/relay');
        $config = RelayConfig::fromEnv();
        $this->assertTrue($config->enabled);
        $this->assertStringContainsString('hub.example.com', $config->hubWssUrl);
        putenv('PHLIX_RELAY_ENABLED');
        putenv('PHLIX_RELAY_HUB_URL');
    }

    public function test_relay_config_builds_wss_url_with_server_id(): void
    {
        $config = new RelayConfig(
            enabled: true,
            hubWssUrl: 'wss://hub.example.com/api/v1/servers/{id}/relay',
        );

        $url = $config->buildHubWssUrl('abc-123');
        $this->assertSame('wss://hub.example.com/api/v1/servers/abc-123/relay', $url);
    }

    public function test_relay_config_defaults(): void
    {
        $config = new RelayConfig();

        $this->assertFalse($config->enabled);
        $this->assertSame('', $config->hubWssUrl);
        $this->assertSame('127.0.0.1:0', $config->localAddress);
        $this->assertSame('', $config->tunnelHostname);
        $this->assertSame(5, $config->reconnectDelay);
        $this->assertSame(30, $config->pingInterval);
        $this->assertSame(10, $config->pingTimeout);
    }

    public function test_relay_frame_payload_structure(): void
    {
        $framer = new RelayMessageFramer();
        $frame = $framer->frameRequest(1, 'GET', '/', [], '');
        $parsed = $framer->parse($frame);

        $this->assertArrayHasKey('seq', $parsed->payload);
        $this->assertArrayHasKey('method', $parsed->payload);
        $this->assertArrayHasKey('path', $parsed->payload);
        $this->assertArrayHasKey('headers', $parsed->payload);
        $this->assertArrayHasKey('body', $parsed->payload);
    }

    public function test_relay_response_payload_structure(): void
    {
        $framer = new RelayMessageFramer();
        $frame = $framer->frameResponse(42, 200, ['Content-Type' => 'application/json'], '[]');
        $parsed = $framer->parse($frame);

        $this->assertArrayHasKey('seq', $parsed->payload);
        $this->assertArrayHasKey('status', $parsed->payload);
        $this->assertArrayHasKey('headers', $parsed->payload);
        $this->assertArrayHasKey('body', $parsed->payload);
        $this->assertSame(42, $parsed->payload['seq']);
        $this->assertSame(200, $parsed->payload['status']);
    }
}
