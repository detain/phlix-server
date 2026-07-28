<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\NatPmpClient;
use Phlix\Network\PortForwardService;
use Phlix\Network\StunClient;
use Phlix\Network\UpnpIgdClient;
use Psr\Log\NullLogger;

class PortForwardServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlix-pf-test-' . uniqid();
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

        $this->assertNotEmpty($candidates);
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

    // ---------------------------------------------------------------------
    // `port-forward.port_forwarding.upnp_enabled` — CONSEQUENCE tests.
    //
    // The setting was shipped, resolvable and overlaid correctly, and still
    // inert: NetworkServicesProvider computed the value into a local it never
    // passed to any definition, and this class had no UPnP switch at all, so
    // UPnP discovery ran unconditionally. These assert the OBSERVABLE EFFECT
    // (was the UPnP client actually consulted?), not that a flag is stored.
    // ---------------------------------------------------------------------

    public function testUpnpDisabledSkipsUpnpDiscoveryEntirely(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        // THE assertion: with the setting off, the UPnP client must never be
        // consulted. Before the wiring this ran regardless of the setting.
        $upnp->expects($this->never())->method('discoverGateway');
        $stun->method('getPublicIp')->willReturn(null);

        $service = new PortForwardService(
            $upnp,
            $stun,
            $natpmp,
            new NullLogger(),
            32400,
            true,
            $this->tmpDir,
            false // upnpEnabled
        );

        $service->autoConfigure();
    }

    public function testUpnpDisabledStillFallsThroughToNatPmp(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        $upnp->expects($this->never())->method('discoverGateway');
        // Turning UPnP off must not disable forwarding wholesale — that is what
        // `auto` does. NAT-PMP is still attempted.
        $natpmp->expects($this->atLeastOnce())->method('discoverGateway');
        $stun->method('getPublicIp')->willReturn(null);

        $service = new PortForwardService(
            $upnp,
            $stun,
            $natpmp,
            new NullLogger(),
            32400,
            true,
            $this->tmpDir,
            false // upnpEnabled
        );

        $result = $service->autoConfigure();

        // Distinct from the `auto` off path, which short-circuits to 'disabled'.
        $this->assertNotSame('disabled', $result['method']);
    }

    public function testUpnpEnabledByDefaultStillDiscovers(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        // Guard against over-correcting the fix into "UPnP is always skipped":
        // the default must remain enabled.
        $upnp->expects($this->atLeastOnce())->method('discoverGateway')->willReturn(null);
        $stun->method('getPublicIp')->willReturn(null);

        $service = new PortForwardService($upnp, $stun, $natpmp, new NullLogger(), 32400, true, $this->tmpDir);

        $service->autoConfigure();
    }

    public function testDisableStillTearsDownUpnpMappingWhenSettingIsOff(): void
    {
        $upnp = $this->createMock(UpnpIgdClient::class);
        $stun = $this->createMock(StunClient::class);
        $natpmp = $this->createMock(NatPmpClient::class);

        // Teardown is deliberately NOT gated: a mapping created while UPnP was
        // enabled must still be removable after the setting is turned off,
        // otherwise it leaks on the router.
        $upnp->expects($this->atLeastOnce())->method('discoverGateway')->willReturn('192.168.1.1');
        $upnp->expects($this->once())->method('removePortMapping');

        $service = new PortForwardService(
            $upnp,
            $stun,
            $natpmp,
            new NullLogger(),
            32400,
            true,
            $this->tmpDir,
            false // upnpEnabled
        );

        $this->assertTrue($service->disable());
    }
}
