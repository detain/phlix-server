<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Dlna;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * CdsControlController handles CDS SOAP control requests at /cds/control.
 *
 * This endpoint receives SOAP requests for ContentDirectory actions (Browse, Search, etc.)
 * and returns SOAP responses with the appropriate data.
 *
 * @since 0.12.0
 * @see CdsServer For the CDS server that processes control requests
 */
class CdsControlController
{
    /** @var \Phlex\Dlna\CdsServer The CDS server instance */
    private \Phlex\Dlna\CdsServer $cdsServer;

    /**
     * @param \Phlex\Dlna\CdsServer $cdsServer The CDS server instance
     *
     * @since 0.12.0
     */
    public function __construct(\Phlex\Dlna\CdsServer $cdsServer)
    {
        $this->cdsServer = $cdsServer;
    }

    /**
     * Handle POST /cds/control request.
     *
     * Processes the SOAP request and returns a SOAP response.
     *
     * @param Request $request The HTTP request containing SOAP body
     * @param array $params Route parameters (unused)
     * @return Response SOAP XML response
     *
     * @since 0.12.0
     */
    public function handle(Request $request, array $params): Response
    {
        $soapBody = $request->rawBody;

        if (empty($soapBody)) {
            return (new Response())
                ->status(400)
                ->header('Content-Type', 'application/xml; charset=utf-8')
                ->text($this->buildFaultResponse('Client', 'Empty SOAP body'));
        }

        $responseBody = $this->cdsServer->processControl($soapBody);

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->text($responseBody);
    }

    /**
     * Build a SOAP fault response for error cases.
     *
     * @param string $faultCode SOAP fault code
     * @param string $faultString Fault description
     * @return string SOAP fault XML
     *
     * @since 0.12.0
     */
    private function buildFaultResponse(string $faultCode, string $faultString): string
    {
        $envelopeNs = 'http://schemas.xmlsoap.org/soap/envelope/';
        $encodingStyle = 'http://schemas.xmlsoap.org/soap/encoding/';

        return sprintf(
            '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="%s" s:encodingStyle="%s">
    <s:Body>
        <s:Fault>
            <faultcode>s:%s</faultcode>
            <faultstring>%s</faultstring>
        </s:Fault>
    </s:Body>
</s:Envelope>',
            $envelopeNs,
            $encodingStyle,
            htmlspecialchars($faultCode, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($faultString, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }
}
