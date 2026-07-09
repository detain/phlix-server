<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Dlna;

use Phlix\Dlna\ContentDirectory;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use XMLReader;

/**
 * DlnaContentDirectoryController handles UPnP ContentDirectory SOAP actions.
 *
 * Implements the UPnP ContentDirectory:1 service specification, handling
 * Browse and Search actions via SOAP. This controller is mounted at
 * POST /dlna/content_directory and extracts the SOAPACTION header to
 * determine which action to perform.
 *
 * @since 0.12.0
 * @see ContentDirectory For the actual content directory implementation
 */
class DlnaContentDirectoryController
{
    /** @var ContentDirectory The content directory service */
    private ContentDirectory $contentDirectory;

    /**
     * Create a new DlnaContentDirectoryController.
     *
     * @param ContentDirectory $contentDirectory The content directory service
     *
     * @since 0.12.0
     */
    public function __construct(ContentDirectory $contentDirectory)
    {
        $this->contentDirectory = $contentDirectory;
    }

    /**
     * Handle POST /dlna/content_directory request.
     *
     * Processes the SOAP request and returns a SOAP response.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response SOAP XML response
     *
     * @since 0.12.0
     */
    public function handle(Request $request, array $params): Response
    {
        $soapBody = $request->rawBody;

        if (empty($soapBody)) {
            return $this->buildFaultResponse(400, 'Client', 'Empty SOAP body');
        }

        // Extract SOAPACTION header to determine the action
        $soapAction = $this->extractSoapAction($request);

        // Parse the SOAP body to extract action and parameters
        $parsed = $this->parseSoapBody($soapBody);

        if ($parsed === null) {
            return $this->buildFaultResponse(400, 'Client', 'Invalid SOAP body');
        }

        $actionName = $parsed['action'];
        $arguments = $parsed['arguments'];

        // Handle the action
        $result = $this->dispatchAction($actionName, $arguments);

        // Build SOAP response
        return $this->buildSoapResponse($actionName, $result);
    }

    /**
     * Extract the SOAPACTION header from the request.
     *
     * @param Request $request The HTTP request
     * @return string|null SOAPACTION header value
     *
     * @since 0.12.0
     */
    private function extractSoapAction(Request $request): ?string
    {
        // Check SOAPACTION header (case-insensitive)
        foreach ($request->headers as $key => $value) {
            if (strtolower($key) === 'soapaction') {
                return is_string($value) ? trim($value, '"') : null;
            }
        }

        return null;
    }

    /**
     * Parse the SOAP body to extract action name and arguments.
     *
     * @param string $soapBody Raw SOAP body
     * @return array{action: string, arguments: array<string, mixed>}|null Parsed data or null
     *
     * @since 0.12.0
     */
    private function parseSoapBody(string $soapBody): ?array
    {
        $reader = new XMLReader();
        $reader->xml($soapBody, null, LIBXML_NOCDATA);

        $action = null;
        /** @var array<string, mixed> $arguments */
        $arguments = [];

        $elementPath = [];

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                $elementPath[] = $reader->localName;

                // Detect action name from the action namespace
                if ($reader->localName === 'action' && count($elementPath) >= 2) {
                    // Inside Body/action
                    $parent = $elementPath[count($elementPath) - 2] ?? '';
                    if ($parent === 'Body' || $parent === 'Envelope') {
                        // The first child element inside Body is the action
                        if ($action === null) {
                            $action = $reader->localName;
                        }
                    }
                }

                // Extract all element values
                if (!$reader->isEmptyElement) {
                    // Track element path for argument context
                }
            } elseif ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                if (count($elementPath) >= 2) {
                    $value = trim($reader->value);
                    if ($value !== '') {
                        $argName = end($elementPath);
                        if (is_string($argName) && !isset($arguments[$argName])) {
                            $arguments[$argName] = $value;
                        }
                    }
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                array_pop($elementPath);
            }
        }

        if ($action === null) {
            return null;
        }

        return [
            'action' => $action,
            'arguments' => $arguments,
        ];
    }

    /**
     * Dispatch the SOAP action to the appropriate handler.
     *
     * @param string $actionName SOAP action name
     * @param array<string, mixed> $arguments Action arguments
     * @return array<string, mixed> Action result
     *
     * @since 0.12.0
     */
    private function dispatchAction(string $actionName, array $arguments): array
    {
        return match ($actionName) {
            'Browse' => $this->handleBrowse($arguments),
            'Search' => $this->handleSearch($arguments),
            'GetSearchCapabilities' => $this->handleGetSearchCapabilities(),
            'GetSortCapabilities' => $this->handleGetSortCapabilities(),
            'GetSystemUpdateID' => $this->handleGetSystemUpdateId(),
            default => [
                'Error' => ['code' => 401, 'description' => 'Unknown action: ' . $actionName],
            ],
        };
    }

    /**
     * Handle Browse action.
     *
     * @param array<string, mixed> $arguments Browse arguments
     * @return array<string, mixed> Browse result
     *
     * @since 0.12.0
     */
    private function handleBrowse(array $arguments): array
    {
        $objectId = is_string($arguments['ObjectID'] ?? null) ? $arguments['ObjectID'] : '0';
        $browseFlag = is_string($arguments['BrowseFlag'] ?? null) ? $arguments['BrowseFlag'] : 'BrowseDirectChildren';
        $filter = is_string($arguments['Filter'] ?? null) ? $arguments['Filter'] : '*';
        $startingIndex = is_numeric($arguments['StartingIndex'] ?? null) ? (int) $arguments['StartingIndex'] : 0;
        $requestedCount = is_numeric($arguments['RequestedCount'] ?? null) ? (int) $arguments['RequestedCount'] : 0;
        $sortCriteria = is_string($arguments['SortCriteria'] ?? null) ? $arguments['SortCriteria'] : '';

        return $this->contentDirectory->browse(
            $objectId,
            $browseFlag,
            $filter,
            $startingIndex,
            $requestedCount,
            $sortCriteria
        );
    }

    /**
     * Handle Search action.
     *
     * @param array<string, mixed> $arguments Search arguments
     * @return array<string, mixed> Search result
     *
     * @since 0.12.0
     */
    private function handleSearch(array $arguments): array
    {
        $containerId = is_string($arguments['ContainerID'] ?? null) ? $arguments['ContainerID'] : '0';
        $searchCriteria = is_string($arguments['SearchCriteria'] ?? null) ? $arguments['SearchCriteria'] : '*';
        $filter = is_string($arguments['Filter'] ?? null) ? $arguments['Filter'] : '*';
        $startingIndex = is_numeric($arguments['StartingIndex'] ?? null) ? (int) $arguments['StartingIndex'] : 0;
        $requestedCount = is_numeric($arguments['RequestedCount'] ?? null) ? (int) $arguments['RequestedCount'] : 0;
        $sortCriteria = is_string($arguments['SortCriteria'] ?? null) ? $arguments['SortCriteria'] : '';

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
     * @return array<string, mixed> Search capabilities
     *
     * @since 0.12.0
     */
    private function handleGetSearchCapabilities(): array
    {
        return [
            'SearchCaps' => 'dc:title,dc:creator,upnp:artist,upnp:album',
        ];
    }

    /**
     * Handle GetSortCapabilities action.
     *
     * @return array<string, mixed> Sort capabilities
     *
     * @since 0.12.0
     */
    private function handleGetSortCapabilities(): array
    {
        return [
            'SortCaps' => 'dc:title,dc:date,dc:creator',
        ];
    }

    /**
     * Handle GetSystemUpdateID action.
     *
     * @return array<string, mixed> System update ID
     *
     * @since 0.12.0
     */
    private function handleGetSystemUpdateId(): array
    {
        return [
            'Id' => $this->contentDirectory->getSystemUpdateId(),
        ];
    }

    /**
     * Build a SOAP response for a successful action.
     *
     * @param string $actionName The action name
     * @param array<string, mixed> $result The action result
     * @return Response SOAP XML response
     *
     * @since 0.12.0
     */
    private function buildSoapResponse(string $actionName, array $result): Response
    {
        // Check for error
        if (isset($result['Error']) && is_array($result['Error'])) {
            $error = $result['Error'];
            $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : 500;
            $description = is_string($error['description'] ?? null) ? $error['description'] : 'Unknown error';
            return $this->buildFaultResponse(500, 'UPnPError', $description, $code);
        }

        $responseBody = $this->buildResponseBody($actionName, $result);

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->text($responseBody);
    }

    /**
     * Build the SOAP response body for an action result.
     *
     * @param string $actionName The action name
     * @param array<string, mixed> $result The action result
     * @return string SOAP XML body
     *
     * @since 0.12.0
     */
    private function buildResponseBody(string $actionName, array $result): string
    {
        $envelopeNs = 'http://schemas.xmlsoap.org/soap/envelope/';
        $encodingStyle = 'http://schemas.xmlsoap.org/soap/encoding/';

        $actionResponse = $this->buildActionResponse($actionName, $result);

        return sprintf(
            '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="%s" s:encodingStyle="%s">
    <s:Body>
        %s
    </s:Body>
</s:Envelope>',
            $envelopeNs,
            $encodingStyle,
            $actionResponse
        );
    }

    /**
     * Build the action-specific response element.
     *
     * @param string $actionName The action name
     * @param array<string, mixed> $result The action result
     * @return string XML element string
     *
     * @since 0.12.0
     */
    private function buildActionResponse(string $actionName, array $result): string
    {
        $responseName = $actionName . 'Response';
        $parts = [];

        foreach ($result as $key => $value) {
            if ($key === 'Error' || $key === 'UpdateID') {
                continue;
            }
            $escapedValue = is_scalar($value) ? htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') : '';
            $parts[] = sprintf('<%s>%s</%s>', $key, $escapedValue, $key);
        }

        // Add UpdateID if present
        if (isset($result['UpdateID'])) {
            $updateId = is_numeric($result['UpdateID']) ? (int) $result['UpdateID'] : 1;
            $parts[] = sprintf('<UpdateID>%d</UpdateID>', $updateId);
        }

        return sprintf('<%s>%s</%s>', $responseName, implode("\n        ", $parts), $responseName);
    }

    /**
     * Build a SOAP fault response.
     *
     * @param int $status HTTP status code
     * @param string $faultCode SOAP fault code
     * @param string $faultString Fault description
     * @param int|null $upnpErrorCode Optional UPnP error code
     * @return Response SOAP fault response
     *
     * @since 0.12.0
     */
    private function buildFaultResponse(
        int $status,
        string $faultCode,
        string $faultString,
        ?int $upnpErrorCode = null
    ): Response {
        $envelopeNs = 'http://schemas.xmlsoap.org/soap/envelope/';
        $encodingStyle = 'http://schemas.xmlsoap.org/soap/encoding/';

        $faultContent = sprintf(
            '<faultcode>s:%s</faultcode>
            <faultstring>%s</faultstring>',
            htmlspecialchars($faultCode, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($faultString, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );

        if ($upnpErrorCode !== null) {
            $faultContent .= sprintf(
                '<detail><UPnPError xmlns="urn:schemas-upnp-org:control-1-0"><errorCode>%d</errorCode><errorDescription>%s</errorDescription></UPnPError></detail>',
                $upnpErrorCode,
                htmlspecialchars($faultString, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            );
        }

        $body = sprintf(
            '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="%s" s:encodingStyle="%s">
    <s:Body>
        <s:Fault>
            %s
        </s:Fault>
    </s:Body>
</s:Envelope>',
            $envelopeNs,
            $encodingStyle,
            $faultContent
        );

        return (new Response())
            ->status($status)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->text($body);
    }
}
