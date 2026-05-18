<?php

namespace Phlex\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlex\Dlna\DlnaServer;
use Phlex\Media\Library\ItemRepository;

class DlnaServerTest extends TestCase
{
    private DlnaServer $server;
    private MockObject $itemRepositoryMock;

    protected function setUp(): void
    {
        $this->itemRepositoryMock = $this->createMock(ItemRepository::class);
        $this->server = new DlnaServer(
            'test-server-001',
            'Phlex Test Server',
            '192.168.1.100',
            8200,
            $this->itemRepositoryMock
        );
    }

    public function testCanCreateDlnaServer(): void
    {
        $this->assertInstanceOf(DlnaServer::class, $this->server);
    }

    public function testServerId(): void
    {
        $this->assertEquals('uuid:phlex-server-test-server-001', $this->server->getServerUdn());
    }

    public function testServerFriendlyName(): void
    {
        $this->assertEquals('Phlex Test Server', $this->server->getFriendlyName());
    }

    public function testServerBaseUrl(): void
    {
        $this->assertEquals('http://192.168.1.100:8200', $this->server->getBaseUrl());
    }

    public function testServerPort(): void
    {
        $this->assertEquals(8200, $this->server->getPort());
    }

    public function testServerDevice(): void
    {
        $device = $this->server->getServerDevice();

        $this->assertEquals('uuid:phlex-server-test-server-001', $device->getUdn());
        $this->assertEquals('Phlex Test Server', $device->getFriendlyName());
        $this->assertEquals('192.168.1.100', $device->getBaseUrl());
    }

    public function testDeviceDescriptionXml(): void
    {
        $xml = $this->server->getDeviceDescriptionXml();

        $this->assertStringContainsString('uuid:phlex-server-test-server-001', $xml);
        $this->assertStringContainsString('Phlex Test Server', $xml);
        $this->assertStringContainsString('urn:schemas-upnp-org:device:MediaServer:1', $xml);
    }

    public function testGetScpdXml(): void
    {
        $contentDirScpd = $this->server->getScpdXml('ContentDirectory');
        $avTransportScpd = $this->server->getScpdXml('AVTransport');
        $unknownScpd = $this->server->getScpdXml('UnknownService');

        $this->assertStringContainsString('scpd', $contentDirScpd);
        $this->assertStringContainsString('Browse', $contentDirScpd);

        $this->assertStringContainsString('scpd', $avTransportScpd);
        $this->assertStringContainsString('SetAVTransportURI', $avTransportScpd);

        $this->assertNull($unknownScpd);
    }

    public function testProcessSoapRequestBrowse(): void
    {
        $body = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <Browse xmlns="urn:schemas-upnp-org:service:ContentDirectory:1">
            <ObjectID>0</ObjectID>
            <BrowseFlag>BrowseDirectChildren</BrowseFlag>
            <Filter>*</Filter>
            <StartingIndex>0</StartingIndex>
            <RequestedCount>0</RequestedCount>
            <SortCriteria></SortCriteria>
        </Browse>
    </s:Body>
</s:Envelope>';

        $result = $this->server->processSoapRequest('ContentDirectory', 'Browse', $body);

        $this->assertArrayHasKey('Result', $result);
        $this->assertArrayHasKey('TotalMatches', $result);
    }

    public function testProcessSoapRequestGetSearchCapabilities(): void
    {
        $body = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <GetSearchCapabilities xmlns="urn:schemas-upnp-org:service:ContentDirectory:1"/>
    </s:Body>
</s:Envelope>';

        $result = $this->server->processSoapRequest('ContentDirectory', 'GetSearchCapabilities', $body);

        $this->assertArrayHasKey('SearchCaps', $result);
        $this->assertStringContainsString('dc:title', $result['SearchCaps']);
    }

    public function testProcessSoapRequestInvalidService(): void
    {
        $result = $this->server->processSoapRequest('InvalidService', 'SomeAction', '');

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(401, $result['error']);
    }

    public function testProcessSoapRequestInvalidAction(): void
    {
        $result = $this->server->processSoapRequest('ContentDirectory', 'InvalidAction', '');

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(401, $result['error']);
    }

    public function testProcessSoapRequestSetAvTransportUri(): void
    {
        $body = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <SetAVTransportURI xmlns="urn:schemas-upnp-org:service:AVTransport:1">
            <InstanceID>0</InstanceID>
            <CurrentURI>http://example.com/media.mp4</CurrentURI>
            <CurrentURIMetaData></CurrentURIMetaData>
        </SetAVTransportURI>
    </s:Body>
</s:Envelope>';

        $result = $this->server->processSoapRequest('AVTransport', 'SetAVTransportURI', $body);

        $this->assertArrayHasKey('CurrentState', $result);
        $this->assertEquals('STOPPED', $result['CurrentState']);
    }

    public function testProcessSoapRequestPlay(): void
    {
        // First set URI
        $setUriBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <SetAVTransportURI xmlns="urn:schemas-upnp-org:service:AVTransport:1">
            <InstanceID>0</InstanceID>
            <CurrentURI>http://example.com/media.mp4</CurrentURI>
        </SetAVTransportURI>
    </s:Body>
</s:Envelope>';
        $this->server->processSoapRequest('AVTransport', 'SetAVTransportURI', $setUriBody);

        // Then play
        $playBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <Play xmlns="urn:schemas-upnp-org:service:AVTransport:1">
            <InstanceID>0</InstanceID>
            <Speed>1</Speed>
        </Play>
    </s:Body>
</s:Envelope>';

        $result = $this->server->processSoapRequest('AVTransport', 'Play', $playBody);

        $this->assertArrayHasKey('CurrentState', $result);
        $this->assertEquals('PLAYING', $result['CurrentState']);
    }

    public function testBuildSoapFault(): void
    {
        $fault = $this->server->buildSoapFault('Client', 'Invalid action');

        $this->assertStringContainsString('Fault', $fault);
        $this->assertStringContainsString('Client', $fault);
        $this->assertStringContainsString('Invalid action', $fault);
    }

    public function testBuildSoapResponse(): void
    {
        $result = [
            'Result' => '<DIDL-Lite>test</DIDL-Lite>',
            'NumberReturned' => 1,
            'TotalMatches' => 1,
            'UpdateID' => 1,
        ];

        $response = $this->server->buildSoapResponse('Browse', $result);

        $this->assertStringContainsString('Envelope', $response);
        $this->assertStringContainsString('BrowseResponse', $response);
        // The DIDL content is XML-encoded since it's embedded in XML
        $this->assertStringContainsString('&lt;DIDL-Lite&gt;test&lt;/DIDL-Lite&gt;', $response);
    }

    public function testDeviceRegistry(): void
    {
        $registry = $this->server->getDeviceRegistry();

        $this->assertInstanceOf(\Phlex\Dlna\DeviceRegistry::class, $registry);
    }

    public function testContentDirectory(): void
    {
        $contentDir = $this->server->getContentDirectory();

        $this->assertInstanceOf(\Phlex\Dlna\ContentDirectory::class, $contentDir);
    }

    public function testAvTransport(): void
    {
        $avTransport = $this->server->getAvTransport();

        $this->assertInstanceOf(\Phlex\Dlna\AvTransport::class, $avTransport);
    }

    public function testToArray(): void
    {
        $array = $this->server->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('test-server-001', $array['server_id']);
        $this->assertEquals('Phlex Test Server', $array['friendly_name']);
        $this->assertEquals('192.168.1.100', $array['base_url']);
        $this->assertEquals(8200, $array['port']);
        $this->assertFalse($array['is_running']);
    }

    public function testStartAndStop(): void
    {
        $this->assertFalse($this->server->isRunning());

        $this->server->start();
        $this->assertTrue($this->server->isRunning());

        // Start again should not cause issues
        $this->server->start();
        $this->assertTrue($this->server->isRunning());

        $this->server->stop();
        $this->assertFalse($this->server->isRunning());

        // Stop again should not cause issues
        $this->server->stop();
        $this->assertFalse($this->server->isRunning());
    }
}
