<?php

declare(strict_types=1);

namespace Phlex\Dlna;

use Phlex\Common\Logger\StructuredLogger;

/**
 * CdsControlHandler processes HTTP SOAP requests for the ContentDirectory Service.
 *
 * This handler receives SOAP requests over HTTP, parses the SOAP envelope to extract
 * the service name, action, and parameters, then delegates to the appropriate
 * ContentDirectory method and formats the response.
 *
 * @since 0.12.0
 * @see ContentDirectory For the CDS implementation
 * @see DlnaServer For the parent server that uses this handler
 */
class CdsControlHandler
{
    /** @var ContentDirectory The ContentDirectory service instance */
    private ContentDirectory $contentDirectory;

    /** @var DlnaServer The parent DLNA server */
    private DlnaServer $server;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger;

    /**
     * @param ContentDirectory $contentDirectory The ContentDirectory service
     * @param DlnaServer $server The parent DLNA server for SOAP response building
     * @param StructuredLogger|null $logger Optional logger for diagnostics
     *
     * @since 0.12.0
     */
    public function __construct(
        ContentDirectory $contentDirectory,
        DlnaServer $server,
        ?StructuredLogger $logger = null
    ) {
        $this->contentDirectory = $contentDirectory;
        $this->server = $server;
        $this->logger = $logger;
    }

    /**
     * Handle a CDS SOAP POST request.
     *
     * Parses the SOAP body, extracts the action and parameters, calls the appropriate
     * ContentDirectory method, and returns a SOAP XML response.
     *
     * @param string $soapBody The raw SOAP request body
     * @return string SOAP XML response body
     *
     * @since 0.12.0
     */
    public function handle(string $soapBody): string
    {
        $this->logger?->debug('CdsControlHandler: Handling SOAP request', [
            'body_length' => strlen($soapBody),
        ]);

        $parsed = $this->parseSoapEnvelope($soapBody);

        if ($parsed === null) {
            $this->logger?->warning('CdsControlHandler: Failed to parse SOAP envelope');
            return $this->buildSoapFault('Client', 'Invalid SOAP envelope');
        }

        /** @var string $service */
        $service = $parsed['service'];
        /** @var string $action */
        $action = $parsed['action'];
        /** @var array<string, string> $params */
        $params = is_array($parsed['params'] ?? null) ? $parsed['params'] : [];

        $this->logger?->debug('CdsControlHandler: Processing action', [
            'service' => $service,
            'action' => $action,
        ]);

        // Only handle ContentDirectory service
        if ($service !== 'ContentDirectory') {
            return $this->buildSoapFault('Client', 'Invalid service');
        }

        // Route to appropriate handler
        $result = match ($action) {
            'Browse' => $this->handleBrowse($params),
            'Search' => $this->handleSearch($params),
            'GetSearchCapabilities' => $this->handleGetSearchCapabilities(),
            'GetSortCapabilities' => $this->handleGetSortCapabilities(),
            'GetSystemUpdateID' => $this->handleGetSystemUpdateId(),
            default => $this->createErrorResult(401, 'Invalid action'),
        };

        // Check for errors
        if (isset($result['Error']) && is_array($result['Error'])) {
            /** @var string $errorDesc */
            $errorDesc = $result['Error']['description'] ?? 'Unknown error';
            return $this->buildSoapFault('Client', $errorDesc);
        }

        /** @var array<string, mixed> $resultArray */
        $resultArray = $result;
        return $this->server->buildSoapResponse($action, $resultArray);
    }

    /**
     * Handle the Browse action.
     *
     * @param array<string, string|int> $params Browse parameters:
     *   - ObjectID: string
     *   - BrowseFlag: string (BrowseMetadata or BrowseDirectChildren)
     *   - Filter: string
     *   - StartingIndex: int
     *   - RequestedCount: int
     *   - SortCriteria: string
     *
     * @return array<string, mixed> Result array with Result, NumberReturned, TotalMatches, UpdateID
     *
     * @since 0.12.0
     */
    private function handleBrowse(array $params): array
    {
        $objectId = $params['ObjectID'] ?? '0';
        $browseFlag = $params['BrowseFlag'] ?? 'BrowseDirectChildren';
        $filter = is_string($params['Filter'] ?? null) ? $params['Filter'] : '*';
        $startingIndex = isset($params['StartingIndex']) ? (int)$params['StartingIndex'] : 0;
        $requestedCount = isset($params['RequestedCount']) ? (int)$params['RequestedCount'] : 0;
        $sortCriteria = is_string($params['SortCriteria'] ?? null) ? $params['SortCriteria'] : '';

        return $this->contentDirectory->browse(
            (string)$objectId,
            (string)$browseFlag,
            $filter,
            $startingIndex,
            $requestedCount,
            $sortCriteria
        );
    }

    /**
     * Handle the Search action.
     *
     * @param array<string, string|int> $params Search parameters:
     *   - ContainerID: string
     *   - SearchCriteria: string
     *   - Filter: string
     *   - StartingIndex: int
     *   - RequestedCount: int
     *   - SortCriteria: string
     *
     * @return array<string, mixed> Search result array
     *
     * @since 0.12.0
     */
    private function handleSearch(array $params): array
    {
        $containerId = is_string($params['ContainerID'] ?? null) ? $params['ContainerID'] : '0';
        $searchCriteria = is_string($params['SearchCriteria'] ?? null) ? $params['SearchCriteria'] : '*';
        $filter = is_string($params['Filter'] ?? null) ? $params['Filter'] : '*';
        $startingIndex = isset($params['StartingIndex']) ? (int)$params['StartingIndex'] : 0;
        $requestedCount = isset($params['RequestedCount']) ? (int)$params['RequestedCount'] : 0;
        $sortCriteria = is_string($params['SortCriteria'] ?? null) ? $params['SortCriteria'] : '';

        return $this->contentDirectory->search(
            $containerId,
            $searchCriteria,
            $filter,
            $startingIndex,
            $requestedCount,
            $sortCriteria
        );
    }

    /**
     * Handle GetSearchCapabilities action.
     *
     * @return array<string, string> Search capabilities result
     *
     * @since 0.12.0
     */
    private function handleGetSearchCapabilities(): array
    {
        return ['SearchCaps' => 'dc:title,dc:creator,upnp:artist,upnp:album'];
    }

    /**
     * Handle GetSortCapabilities action.
     *
     * @return array<string, string> Sort capabilities result
     *
     * @since 0.12.0
     */
    private function handleGetSortCapabilities(): array
    {
        return ['SortCaps' => 'dc:title,dc:date,dc:creator'];
    }

    /**
     * Handle GetSystemUpdateID action.
     *
     * @return array<string, int> System update ID result
     *
     * @since 0.12.0
     */
    private function handleGetSystemUpdateId(): array
    {
        return ['Id' => $this->contentDirectory->getSystemUpdateId()];
    }

    /**
     * Extract service name and action from SOAP Envelope.
     *
     * Parses the XML SOAP envelope to extract the service type, action name,
     * and all parameters.
     *
     * @param string $body Raw SOAP XML body
     * @return array<string, mixed>|null Parsed data ['service' => string, 'action' => string, 'params' => array<string, string>]
     *                   or null if parsing fails
     *
     * @since 0.12.0
     */
    private function parseSoapEnvelope(string $body): ?array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($body);

        if ($doc === false) {
            return null;
        }

        // Use XPath to find the action element regardless of namespace
        $xmlString = $doc->asXML();
        if ($xmlString === false) {
            return null;
        }
        $xpath = new \SimpleXMLElement($xmlString);
        $xpath->registerXPathNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');

        // Find any element inside Body that is the action
        $actionElements = $xpath->xpath('//s:Body/*[namespace-uri()!="http://schemas.xmlsoap.org/soap/envelope/"]');

        if (empty($actionElements)) {
            // Try without namespace filtering (some implementations don't use namespace on action)
            $actionElements = $xpath->xpath('//s:Body/*');
            if (empty($actionElements)) {
                return null;
            }
        }

        $actionElement = $actionElements[0];
        $actionName = (string)$actionElement->getName();

        // Extract namespace from the action element to determine service
        // For now, default to ContentDirectory
        $service = 'ContentDirectory';

        // Parse action-specific parameters
        /** @var array<string, string> $params */
        $params = [];
        foreach ($actionElement->children() as $param) {
            $paramName = (string)$param->getName();
            $paramValue = (string)$param;
            $params[$paramName] = $paramValue;
        }

        $this->logger?->debug('CdsControlHandler: Parsed SOAP envelope', [
            'action' => $actionName,
            'param_count' => count($params),
        ]);

        return [
            'service' => $service,
            'action' => $actionName,
            'params' => $params,
        ];
    }

    /**
     * Build a SOAP fault response.
     *
     * @param string $faultCode SOAP fault code (Client, Server, etc.)
     * @param string $faultString Human-readable fault description
     * @return string SOAP fault XML response
     *
     * @since 0.12.0
     */
    private function buildSoapFault(string $faultCode, string $faultString): string
    {
        return $this->server->buildSoapFault($faultCode, $faultString);
    }

    /**
     * Create an error result array.
     *
     * @param int $code UPnP error code
     * @param string $description Error description
     * @return array<string, mixed> Error result array
     *
     * @since 0.12.0
     */
    private function createErrorResult(int $code, string $description): array
    {
        $this->logger?->warning('CdsControlHandler: Error', [
            'code' => $code,
            'description' => $description,
        ]);

        return [
            'Result' => '',
            'NumberReturned' => 0,
            'TotalMatches' => 0,
            'UpdateID' => $this->contentDirectory->getSystemUpdateId(),
            'Error' => ['code' => $code, 'description' => $description],
        ];
    }
}
