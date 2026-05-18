<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Dlna;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * DeviceDescriptionController serves the UPnP device description XML at /description.xml.
 *
 * This endpoint is called by DLNA renderers (TVs, etc.) during device discovery to
 * get the device's capabilities and service list.
 *
 * @since 0.12.0
 * @see CdsServer For the CDS server that provides the description
 */
class DeviceDescriptionController
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
     * Handle GET /description.xml request.
     *
     * Returns the UPnP device description XML document.
     *
     * @param Request $request The HTTP request
     * @param array $params Route parameters (unused)
     * @return Response XML response with device description
     *
     * @since 0.12.0
     */
    public function handle(Request $request, array $params): Response
    {
        $descriptionXml = $this->cdsServer->getDeviceDescriptionXml();

        return (new Response())
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->text($descriptionXml);
    }
}
