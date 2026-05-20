<?php

declare(strict_types=1);

namespace Phlix\Dlna;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;

/**
 * HTTP SOAP client for AVTransport control of a remote DLNA renderer.
 *
 * Sends properly formatted SOAP requests to a DLNA renderer's AVTransport
 * service control URL. Handles all UPnP AVTransport:1 actions including
 * SetAVTransportURI, Play, Pause, Stop, Seek, and query actions.
 *
 * @since 0.12.0
 */
class RendererControlClient
{
    /** UPnP AVTransport service type */
    private const SERVICE_TYPE = 'urn:schemas-upnp-org:service:AVTransport:1';

    /** @var string Renderer control URL (e.g., http://192.168.1.50:8200/avtransport) */
    private string $rendererUrl;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /**
     * @param string $rendererUrl Renderer control URL (e.g., http://192.168.1.50:8200)
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        string $rendererUrl,
        ?StructuredLogger $logger = null
    ) {
        $this->rendererUrl = rtrim($rendererUrl, '/');
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Create a default logger for standalone/test operation.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlix_dlna_renderer_client_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/renderer_client.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::DLNA, $config);
    }

    /**
     * Set the transport URI (what to play).
     *
     * @param string $uri The media URI to set
     * @param string $metadata DIDL-Lite metadata for the URI
     *
     * @return array<string, mixed> Result with CurrentState
     *
     * @since 0.12.0
     */
    public function setAvTransportUri(string $uri, string $metadata = ''): array
    {
        $params = [
            'InstanceID' => 0,
            'CurrentURI' => $uri,
            'CurrentURIMetaData' => $metadata,
        ];

        return $this->sendSoapRequest('SetAVTransportURI', $params);
    }

    /**
     * Start playback.
     *
     * @param string $speed Playback speed (e.g., '1' for normal)
     *
     * @return array<string, mixed> Result with CurrentState
     *
     * @since 0.12.0
     */
    public function play(string $speed = '1'): array
    {
        $params = [
            'InstanceID' => 0,
            'Speed' => $speed,
        ];

        return $this->sendSoapRequest('Play', $params);
    }

    /**
     * Pause playback.
     *
     * @return array<string, mixed> Result with CurrentState
     *
     * @since 0.12.0
     */
    public function pause(): array
    {
        $params = [
            'InstanceID' => 0,
        ];

        return $this->sendSoapRequest('Pause', $params);
    }

    /**
     * Stop playback.
     *
     * @return array<string, mixed> Result with CurrentState
     *
     * @since 0.12.0
     */
    public function stop(): array
    {
        $params = [
            'InstanceID' => 0,
        ];

        return $this->sendSoapRequest('Stop', $params);
    }

    /**
     * Seek to position (REL_TIME format: HH:MM:SS).
     *
     * @param string $target Seek target in REL_TIME format (e.g., '00:05:30')
     *
     * @return array<string, mixed> Result with CurrentState
     *
     * @since 0.12.0
     */
    public function seek(string $target): array
    {
        $params = [
            'InstanceID' => 0,
            'Unit' => 'REL_TIME',
            'Target' => $target,
        ];

        return $this->sendSoapRequest('Seek', $params);
    }

    /**
     * Get current transport info.
     *
     * @return array<string, mixed> Transport information
     *
     * @since 0.12.0
     */
    public function getTransportInfo(): array
    {
        $params = [
            'InstanceID' => 0,
        ];

        return $this->sendSoapRequest('GetTransportInfo', $params);
    }

    /**
     * Get current position info.
     *
     * @return array<string, mixed> Position information
     *
     * @since 0.12.0
     */
    public function getPositionInfo(): array
    {
        $params = [
            'InstanceID' => 0,
        ];

        return $this->sendSoapRequest('GetPositionInfo', $params);
    }

    /**
     * Get media info.
     *
     * @return array<string, mixed> Media information
     *
     * @since 0.12.0
     */
    public function getMediaInfo(): array
    {
        $params = [
            'InstanceID' => 0,
        ];

        return $this->sendSoapRequest('GetMediaInfo', $params);
    }

    /**
     * Send a SOAP request to the renderer.
     *
     * @param string $action SOAP action name
     * @param array<string, mixed> $params Action parameters
     *
     * @return array<string, mixed> Parsed SOAP response
     *
     * @since 0.12.0
     */
    private function sendSoapRequest(string $action, array $params): array
    {
        $soapAction = self::SERVICE_TYPE . '#' . $action;
        $soapBody = $this->buildSoapBody($action, $params);

        $this->logger->debug('SOAP Request', [
            'action' => $action,
            'renderer' => $this->rendererUrl,
        ]);

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: text/xml; charset="utf-8"',
                    'SOAPACTION: "' . $soapAction . '"',
                    'User-Agent: Phlix/1.0 DLNA Renderer Client',
                    'Accept: text/xml',
                ]),
                'content' => $soapBody,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($this->rendererUrl, false, $context);

        if ($response === false) {
            $this->logger->error('SOAP request failed', [
                'action' => $action,
                'renderer' => $this->rendererUrl,
            ]);
            return ['error' => 1, 'description' => 'Connection failed'];
        }

        return $this->parseSoapResponse($response, $action);
    }

    /**
     * Build SOAP envelope body for an action.
     *
     * @param string $action Action name
     * @param array<string, mixed> $params Action parameters
     *
     * @return string SOAP envelope XML
     */
    private function buildSoapBody(string $action, array $params): string
    {
        $paramsXml = '';
        foreach ($params as $name => $value) {
            $stringValue = is_scalar($value) ? (string)$value : '';
            $paramsXml .= sprintf(
                '<%s>%s</%s>',
                $name,
                htmlspecialchars($stringValue, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                $name
            );
        }

        return '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
    <s:Body>
        <u:' . $action . ' xmlns:u="' . self::SERVICE_TYPE . '">
            ' . $paramsXml . '
        </u:' . $action . '>
    </s:Body>
</s:Envelope>';
    }

    /**
     * Parse SOAP response XML.
     *
     * @param string $response Raw SOAP response XML
     * @param string $action The action that was called
     *
     * @return array<string, mixed> Parsed response data
     */
    private function parseSoapResponse(string $response, string $action): array
    {
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($response);

        if ($xml === false) {
            $this->logger->warning('Failed to parse SOAP response', [
                'action' => $action,
            ]);
            return ['error' => 2, 'description' => 'Invalid XML response'];
        }

        // Register namespaces
        $namespaces = $xml->getNamespaces(true);

        // Try to find the response element
        $responseAction = $action . 'Response';
        $body = $xml->Body ?? $xml;

        // Try with namespace prefix first
        $responseElement = null;
        foreach ($namespaces as $prefix => $nsUri) {
            if (isset($body->{$prefix . ':' . $responseAction})) {
                $responseElement = $body->{$prefix . ':' . $responseAction};
                break;
            }
        }

        // Try without namespace
        if ($responseElement === null && isset($body->{$responseAction})) {
            $responseElement = $body->{$responseAction};
        }

        // Try with Any namespace (UPnP often uses different ns)
        if ($responseElement === null) {
            foreach ($body as $key => $value) {
                if (str_ends_with($key, ':' . $responseAction) || $key === $responseAction) {
                    $responseElement = $value;
                    break;
                }
            }
        }

        if ($responseElement === null) {
            // Check for SOAP fault
            $fault = $body->Fault ?? null;
            if ($fault !== null) {
                $faultString = (string)($fault->faultstring ?? 'Unknown fault');
                $this->logger->warning('SOAP Fault', [
                    'action' => $action,
                    'fault' => $faultString,
                ]);
                return ['error' => 3, 'description' => $faultString];
            }

            $this->logger->warning('No response element found', [
                'action' => $action,
            ]);
            return ['error' => 4, 'description' => 'No response element'];
        }

        // Convert response element to array
        return $this->xmlElementToArray($responseElement);
    }

    /**
     * Convert a SimpleXMLElement to a nested array.
     *
     * @param \SimpleXMLElement $element XML element to convert
     *
     * @return array<string, mixed> Converted array
     */
    private function xmlElementToArray(\SimpleXMLElement $element): array
    {
        $result = [];

        // Add attributes first
        $attributes = $element->attributes() ?? [];
        foreach ($attributes as $name => $value) {
            $result['@' . $name] = (string)$value;
        }

        // Add child elements
        foreach ($element as $name => $value) {
            $childArray = $this->xmlElementToArray($value);

            if (isset($result[$name])) {
                // Multiple children with same name -> convert to array
                if (!is_array($result[$name]) || isset($result[$name][0])) {
                    $result[$name] = [$result[$name]];
                }
                $result[$name][] = $childArray;
            } else {
                $result[$name] = $childArray;
            }
        }

        // If no children, return the text content
        if (empty($result)) {
            $text = trim((string)$element);
            if ($text === '') {
                return [];
            }
            // Try to parse as number and wrap in array
            if (is_numeric($text)) {
                $num = strpos($text, '.') !== false ? (float)$text : (int)$text;
                return ['value' => $num];
            }
            return ['value' => $text];
        }

        return $result;
    }
}
