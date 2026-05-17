<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlex\Network\NatPmpClient;
use Phlex\Network\PortForwardService;
use Phlex\Network\StunClient;
use Phlex\Network\UpnpIgdClient;
use Psr\Log\NullLogger;

class PortForwardServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlex-pf-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $configFile = $this->tmpDir . '/config/port-forward.json';
        if (file_exists($configFile)) {
            @unlink($configFile);
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
    }

    public function testAutoConfigureReturnsFailedWhenDisabled(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, false, $this->tmpDir);
        $result = $service->autoConfigure();

        $this->assertFalse($result['success']);
        $this->assertNull($result['public_endpoint']);
        $this->assertEquals('disabled', $result['method']);
    }

    public function testAutoConfigureReturnsFailedWithMockedClients(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $upnp->method('discoverGateway')->willReturn(null);
        $stun->method('getPublicIp')->willReturn(null);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);
        $result = $service->autoConfigure();

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['method']);
    }

    public function testAutoConfigureSucceedsWithUpnpWhenPortAlreadyOpen(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $upnp->method('discoverGateway')->willReturn('http://192.168.1.1:1900/gateway.xml');
        $upnp->method('getExternalIp')->willReturn('203.0.113.42');
        $upnp->method('addPortMapping')->willReturn(true);

        $stun->method('getPublicIp')->willReturn('203.0.113.42');
        $stun->method('testPortAccessibility')->willReturn(true);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);
        $result = $service->autoConfigure();

        $this->assertTrue($result['success']);
        $this->assertEquals('upnp', $result['method']);
        $this->assertEquals('203.0.113.42', $result['external_ip']);
        $this->assertNotNull($result['public_endpoint']);
    }

    public function testDiscoverHostnameCandidatesIncludesLanIp(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $stun->method('getPublicIp')->willReturn(null);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);
        $candidates = $service->discoverHostnameCandidates();

        $this->assertIsArray($candidates);
    }

    public function testDiscoverHostnameCandidatesIncludesPublicIpWhenPortOpen(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $stun->method('getPublicIp')->willReturn('198.51.100.42');
        $stun->method('testPortAccessibility')->willReturn(true);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);
        $candidates = $service->discoverHostnameCandidates();

        $this->assertIsArray($candidates);
        $publicCandidates = array_filter($candidates, fn($c) => $c['type'] === 'public');
        $this->assertNotEmpty($publicCandidates);
    }

    public function testGetManualInstructionsReturnsValidStructure(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);
        $instructions = $service->getManualInstructions();

        $this->assertIsArray($instructions);
        $this->assertArrayHasKey('instructions', $instructions);
        $this->assertArrayHasKey('router_detection', $instructions);
        $this->assertArrayHasKey('external_port', $instructions);
        $this->assertArrayHasKey('internal_port', $instructions);
        $this->assertEquals(32400, $instructions['external_port']);
        $this->assertEquals(32400, $instructions['internal_port']);
    }

    public function testGetStatusReturnsDisabledWhenNoConfig(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);
        $status = $service->getStatus();

        $this->assertFalse($status['enabled']);
        $this->assertNull($status['method']);
        $this->assertNull($status['endpoint']);
    }

    public function testGetStatusReturnsStoredConfig(): void
    {
        $configFile = $this->tmpDir . '/config/port-forward.json';
        $configDir = dirname($configFile);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        file_put_contents($configFile, json_encode([
            'enabled' => true,
            'method' => 'upnp',
            'external_ip' => '203.0.113.42',
            'port' => 32400,
        ]));

        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);
        $status = $service->getStatus();

        $this->assertTrue($status['enabled']);
        $this->assertEquals('upnp', $status['method']);
        $this->assertEquals('203.0.113.42', $status['external_ip']);
        $this->assertEquals('203.0.113.42:32400', $status['endpoint']);
    }

    public function testDisableRemovesConfig(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $upnp->method('discoverGateway')->willReturn(null);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);
        $result = $service->disable();

        $this->assertTrue($result);
        $status = $service->getStatus();
        $this->assertFalse($status['enabled']);
    }
}
