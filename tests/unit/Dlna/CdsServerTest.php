<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlex\Dlna\AvTransport;
use Phlex\Dlna\CdsServer;
use Phlex\Dlna\ContentDirectory;
use Phlex\Dlna\DlnaDevice;
use Phlex\Dlna\DlnaServer;
use Phlex\Dlna\DeviceRegistry;
use Phlex\Media\Library\ItemRepository;

/**
 * Tests for CdsServer class.
 *
 * @since 0.12.0
 */
class CdsServerTest extends TestCase
{
    private CdsServer $cdsServer;
    private DlnaServer $dlnaServer;
    private MockObject $itemRepositoryMock;

    protected function setUp(): void
    {
        $this->itemRepositoryMock = $this->createMock(ItemRepository::class);

        $this->dlnaServer = new DlnaServer(
            'test-cds-server',
            'Phlex CDS Test Server',
            '192.168.1.100',
            8200,
            $this->itemRepositoryMock
        );

        $this->cdsServer = new CdsServer($this->dlnaServer);
    }

    /**
     * @since 0.12.0
     */
    public function testGetDeviceDescriptionXmlIsValid(): void
    {
        $xml = $this->cdsServer->getDeviceDescriptionXml();

        $this->assertIsString($xml);
        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('urn:schemas-upnp-org:device:MediaServer:1', $xml);
        $this->assertStringContainsString('Phlex CDS Test Server', $xml);
        $this->assertStringContainsString('uuid:phlex-server-test-cds-server', $xml);
    }

    /**
     * @since 0.12.0
     */
    public function testGetScpdXmlReturnsContentDirectoryScpd(): void
    {
        $scpd = $this->cdsServer->getScpdXml('ContentDirectory');

        $this->assertIsString($scpd);
        $this->assertStringContainsString('scpd', $scpd);
        $this->assertStringContainsString('Browse', $scpd);
        $this->assertStringContainsString('Search', $scpd);
    }

    /**
     * @since 0.12.0
     */
    public function testGetScpdXmlReturnsAvTransportScpd(): void
    {
        $scpd = $this->cdsServer->getScpdXml('AVTransport');

        $this->assertIsString($scpd);
        $this->assertStringContainsString('scpd', $scpd);
        $this->assertStringContainsString('SetAVTransportURI', $scpd);
        $this->assertStringContainsString('Play', $scpd);
    }

    /**
     * @since 0.12.0
     */
    public function testGetScpdXmlReturnsNullForUnknownService(): void
    {
        $scpd = $this->cdsServer->getScpdXml('UnknownService');

        $this->assertNull($scpd);
    }

    /**
     * @since 0.12.0
     */
    public function testProcessControlReturnsBrowseResponse(): void
    {
        $soapBody = '<?xml version="1.0"?>
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

        $response = $this->cdsServer->processControl($soapBody);

        $this->assertIsString($response);
        $this->assertStringContainsString('Envelope', $response);
        $this->assertStringContainsString('BrowseResponse', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleRequestRoutesToCorrectEndpoint(): void
    {
        // Test /description.xml
        $response = $this->cdsServer->handleRequest('/description.xml', 'GET', [], '');
        $this->assertStringContainsString('MediaServer', $response);

        // Test /scpd/ContentDirectory.xml
        $response = $this->cdsServer->handleRequest('/scpd/ContentDirectory.xml', 'GET', [], '');
        $this->assertStringContainsString('Browse', $response);

        // Test /cds/control (POST)
        $soapBody = '<?xml version="1.0"?>
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
        $response = $this->cdsServer->handleRequest('/cds/control', 'POST', [], $soapBody);
        $this->assertStringContainsString('BrowseResponse', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleRequestReturnsNullForUnknownPath(): void
    {
        $response = $this->cdsServer->handleRequest('/unknown/path', 'GET', [], '');

        $this->assertNull($response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleRequestReturnsNullForWrongMethod(): void
    {
        // POST to /description.xml should return null (only GET is supported)
        $response = $this->cdsServer->handleRequest('/description.xml', 'POST', [], 'body');

        $this->assertNull($response);
    }

    /**
     * @since 0.12.0
     */
    public function testProcessControlParsesSearchAction(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <Search xmlns="urn:schemas-upnp-org:service:ContentDirectory:1">
            <ContainerID>0</ContainerID>
            <SearchCriteria>*</SearchCriteria>
            <Filter>*</Filter>
            <StartingIndex>0</StartingIndex>
            <RequestedCount>0</RequestedCount>
            <SortCriteria></SortCriteria>
        </Search>
    </s:Body>
</s:Envelope>';

        $response = $this->cdsServer->processControl($soapBody);

        $this->assertStringContainsString('BrowseResponse', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testGetDlnaServerReturnsServer(): void
    {
        $server = $this->cdsServer->getDlnaServer();

        $this->assertSame($this->dlnaServer, $server);
    }

    /**
     * @since 0.12.0
     */
    public function testGetServerUdnReturnsCorrectUdn(): void
    {
        $udn = $this->cdsServer->getServerUdn();

        $this->assertEquals('uuid:phlex-server-test-cds-server', $udn);
    }

    /**
     * @since 0.12.0
     */
    public function testGetBaseUrlReturnsCorrectUrl(): void
    {
        $url = $this->cdsServer->getBaseUrl();

        $this->assertEquals('http://192.168.1.100:8200', $url);
    }

    /**
     * @since 0.12.0
     */
    public function testGetPortReturnsCorrectPort(): void
    {
        $port = $this->cdsServer->getPort();

        $this->assertEquals(8200, $port);
    }

    /**
     * @since 0.12.0
     */
    public function testIsRunningReflectsDlnaServerState(): void
    {
        $this->assertFalse($this->cdsServer->isRunning());

        $this->dlnaServer->start();
        $this->assertTrue($this->cdsServer->isRunning());

        $this->dlnaServer->stop();
        $this->assertFalse($this->cdsServer->isRunning());
    }

    /**
     * @since 0.12.0
     */
    public function testStartAndStop(): void
    {
        $this->assertFalse($this->cdsServer->isRunning());

        $this->cdsServer->start();
        // CdsServer itself doesn't track running state - it's a wrapper
        // The start() method announces via discovery but doesn't change running state

        $this->cdsServer->stop();
        // Same for stop - it just stops announcements
    }

    /**
     * @since 0.12.0
     */
    public function testProcessControlWithGetSearchCapabilities(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <GetSearchCapabilities xmlns="urn:schemas-upnp-org:service:ContentDirectory:1"/>
    </s:Body>
</s:Envelope>';

        $response = $this->cdsServer->processControl($soapBody);

        $this->assertStringContainsString('GetSearchCapabilitiesResponse', $response);
        $this->assertStringContainsString('SearchCaps', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testProcessControlWithInvalidAction(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <InvalidAction xmlns="urn:schemas-upnp-org:service:ContentDirectory:1"/>
    </s:Body>
</s:Envelope>';

        $response = $this->cdsServer->processControl($soapBody);

        $this->assertStringContainsString('Fault', $response);
    }
}
