<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlex\Dlna\CdsControlHandler;
use Phlex\Dlna\ContentDirectory;
use Phlex\Dlna\DlnaServer;

/**
 * Tests for CdsControlHandler class.
 *
 * @since 0.12.0
 */
class CdsControlHandlerTest extends TestCase
{
    private CdsControlHandler $handler;
    private MockObject $contentDirectoryMock;
    private MockObject $dlnaServerMock;

    protected function setUp(): void
    {
        $this->contentDirectoryMock = $this->createMock(ContentDirectory::class);
        $this->dlnaServerMock = $this->createMock(DlnaServer::class);

        $this->handler = new CdsControlHandler(
            $this->contentDirectoryMock,
            $this->dlnaServerMock
        );
    }

    /**
     * @since 0.12.0
     */
    public function testHandleParsesBrowseAction(): void
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

        $browseResult = [
            'Result' => '<DIDL-Lite>test</DIDL-Lite>',
            'NumberReturned' => 1,
            'TotalMatches' => 1,
            'UpdateID' => 1,
        ];

        $this->contentDirectoryMock
            ->expects($this->once())
            ->method('browse')
            ->with('0', 'BrowseDirectChildren', '*', 0, 0, '')
            ->willReturn($browseResult);

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapResponse')
            ->with('Browse', $browseResult)
            ->willReturn($this->buildBrowseResponse($browseResult));

        $response = $this->handler->handle($soapBody);

        $this->assertStringContainsString('BrowseResponse', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleParsesSearchAction(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <Search xmlns="urn:schemas-upnp-org:service:ContentDirectory:1">
            <ContainerID>0</ContainerID>
            <SearchCriteria>dc:title contains "test"</SearchCriteria>
            <Filter>*</Filter>
            <StartingIndex>0</StartingIndex>
            <RequestedCount>10</RequestedCount>
            <SortCriteria></SortCriteria>
        </Search>
    </s:Body>
</s:Envelope>';

        $searchResult = [
            'Result' => '<DIDL-Lite>search-results</DIDL-Lite>',
            'NumberReturned' => 2,
            'TotalMatches' => 2,
            'UpdateID' => 1,
        ];

        $this->contentDirectoryMock
            ->expects($this->once())
            ->method('search')
            ->with('0', 'dc:title contains "test"', '*', 0, 10, '')
            ->willReturn($searchResult);

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapResponse')
            ->with('Search', $searchResult)
            ->willReturn($this->buildBrowseResponse($searchResult));

        $response = $this->handler->handle($soapBody);

        $this->assertStringContainsString('BrowseResponse', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleReturnsSoapFaultOnInvalidAction(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <InvalidAction xmlns="urn:schemas-upnp-org:service:ContentDirectory:1">
        </InvalidAction>
    </s:Body>
</s:Envelope>';

        $faultResponse = '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <s:Fault>
            <faultcode>s:Client</faultcode>
            <faultstring>Invalid action</faultstring>
        </s:Fault>
    </s:Body>
</s:Envelope>';

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapFault')
            ->with('Client', 'Invalid action')
            ->willReturn($faultResponse);

        $response = $this->handler->handle($soapBody);

        $this->assertStringContainsString('Fault', $response);
        $this->assertStringContainsString('Client', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleReturnsSoapFaultOnInvalidEnvelope(): void
    {
        $invalidBody = 'not valid xml at all';

        $faultResponse = '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <s:Fault>
            <faultcode>s:Client</faultcode>
            <faultstring>Invalid SOAP envelope</faultstring>
        </s:Fault>
    </s:Body>
</s:Envelope>';

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapFault')
            ->with('Client', 'Invalid SOAP envelope')
            ->willReturn($faultResponse);

        $response = $this->handler->handle($invalidBody);

        $this->assertStringContainsString('Fault', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleGetSearchCapabilities(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <GetSearchCapabilities xmlns="urn:schemas-upnp-org:service:ContentDirectory:1"/>
    </s:Body>
</s:Envelope>';

        $result = ['SearchCaps' => 'dc:title,dc:creator,upnp:artist,upnp:album'];

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapResponse')
            ->with('GetSearchCapabilities', $result)
            ->willReturn('<?xml>response</xml>');

        $response = $this->handler->handle($soapBody);

        $this->assertStringContainsString('response', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleGetSortCapabilities(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <GetSortCapabilities xmlns="urn:schemas-upnp-org:service:ContentDirectory:1"/>
    </s:Body>
</s:Envelope>';

        $result = ['SortCaps' => 'dc:title,dc:date,dc:creator'];

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapResponse')
            ->with('GetSortCapabilities', $result)
            ->willReturn('<?xml>response</xml>');

        $response = $this->handler->handle($soapBody);

        $this->assertStringContainsString('response', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleGetSystemUpdateId(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <GetSystemUpdateID xmlns="urn:schemas-upnp-org:service:ContentDirectory:1"/>
    </s:Body>
</s:Envelope>';

        $this->contentDirectoryMock
            ->expects($this->once())
            ->method('getSystemUpdateId')
            ->willReturn(42);

        $result = ['Id' => 42];

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapResponse')
            ->with('GetSystemUpdateID', $result)
            ->willReturn('<?xml>response</xml>');

        $response = $this->handler->handle($soapBody);

        $this->assertStringContainsString('response', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleBrowseWithPagination(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <Browse xmlns="urn:schemas-upnp-org:service:ContentDirectory:1">
            <ObjectID>library-video</ObjectID>
            <BrowseFlag>BrowseDirectChildren</BrowseFlag>
            <Filter>*</Filter>
            <StartingIndex>10</StartingIndex>
            <RequestedCount>5</RequestedCount>
            <SortCriteria>dc:title</SortCriteria>
        </Browse>
    </s:Body>
</s:Envelope>';

        $browseResult = [
            'Result' => '<DIDL-Lite>test</DIDL-Lite>',
            'NumberReturned' => 5,
            'TotalMatches' => 100,
            'UpdateID' => 1,
        ];

        $this->contentDirectoryMock
            ->expects($this->once())
            ->method('browse')
            ->with('library-video', 'BrowseDirectChildren', '*', 10, 5, 'dc:title')
            ->willReturn($browseResult);

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapResponse')
            ->willReturn($this->buildBrowseResponse($browseResult));

        $response = $this->handler->handle($soapBody);

        $this->assertStringContainsString('BrowseResponse', $response);
    }

    /**
     * @since 0.12.0
     */
    public function testHandleSearchWithExistsCriteria(): void
    {
        $soapBody = '<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
    <s:Body>
        <Search xmlns="urn:schemas-upnp-org:service:ContentDirectory:1">
            <ContainerID>0</ContainerID>
            <SearchCriteria>dc:title exists "true"</SearchCriteria>
            <Filter>*</Filter>
            <StartingIndex>0</StartingIndex>
            <RequestedCount>0</RequestedCount>
            <SortCriteria></SortCriteria>
        </Search>
    </s:Body>
</s:Envelope>';

        $searchResult = [
            'Result' => '<DIDL-Lite>exists-results</DIDL-Lite>',
            'NumberReturned' => 5,
            'TotalMatches' => 5,
            'UpdateID' => 1,
        ];

        $this->contentDirectoryMock
            ->expects($this->once())
            ->method('search')
            ->willReturn($searchResult);

        $this->dlnaServerMock
            ->expects($this->once())
            ->method('buildSoapResponse')
            ->willReturn($this->buildBrowseResponse($searchResult));

        $response = $this->handler->handle($soapBody);

        $this->assertStringContainsString('BrowseResponse', $response);
    }

    /**
     * Helper to build a BrowseResponse SOAP envelope.
     */
    private function buildBrowseResponse(array $result): string
    {
        $resultXml = $result['Result'] ?? '';
        $numberReturned = $result['NumberReturned'] ?? 0;
        $totalMatches = $result['TotalMatches'] ?? 0;
        $updateId = $result['UpdateID'] ?? 1;

        return sprintf(
            '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
    <s:Body>
        <BrowseResponse xmlns="urn:schemas-upnp-org:service:ContentDirectory:1">
            <Result>%s</Result>
            <NumberReturned>%d</NumberReturned>
            <TotalMatches>%d</TotalMatches>
            <UpdateID>%d</UpdateID>
        </BrowseResponse>
    </s:Body>
</s:Envelope>',
            htmlspecialchars($resultXml, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $numberReturned,
            $totalMatches,
            $updateId
        );
    }
}
