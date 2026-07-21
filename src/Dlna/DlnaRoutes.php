<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

/**
 * The canonical DLNA endpoint paths — ONE definition, used by both the code
 * that REGISTERS the routes and the code that ADVERTISES them.
 *
 * ## Why this class exists
 *
 * These paths were previously written twice, and the two copies did not agree.
 * {@see DlnaDevice::initializeServices()} advertised `/ctl/ContentDirectory`
 * while {@see \Phlix\Server\Core\Application::loadCdsRoutes()} registered
 * `/dlna/content_directory`. A control point fetches the device description and
 * then POSTs its Browse request to the advertised `controlURL`, so **every
 * browse would have 404'd** — the description was pointing at a route that has
 * never existed.
 *
 * That went unnoticed because the CDS was itself unreachable until 1.3.0 (see
 * `config/dlna.php`), so nobody ever got far enough to be broken by it.
 *
 * A drift-proof arrangement is the fix: both sides read these constants, and
 * {@see \Phlix\Tests\Unit\Dlna\DlnaDescriptionRoutesTest} asserts that every
 * URL the description advertises is one this class defines.
 *
 * @package Phlix\Dlna
 * @since 1.3.0
 */
final class DlnaRoutes
{
    /** Device description document (the SSDP `LOCATION` target). */
    public const DESCRIPTION = '/dlna/description.xml';

    /** ContentDirectory SOAP control endpoint (Browse/Search). */
    public const CONTENT_DIRECTORY_CONTROL = '/dlna/content_directory';

    /** Generic CDS SOAP control endpoint, used for ConnectionManager. */
    public const CDS_CONTROL = '/cds/control';

    /** SCPD service-description documents, `{service}` being the service name. */
    public const SCPD_TEMPLATE = '/scpd/%s.xml';

    /**
     * Eventing (GENA SUBSCRIBE) is NOT implemented.
     *
     * UPnP allows an empty `eventSubURL` to signal that a service publishes no
     * events. Advertising `/evt/ContentDirectory` — a path with no route —
     * would instead invite every control point to attempt a SUBSCRIBE that then
     * fails. Empty is the honest signal, and it is what the spec provides for.
     */
    public const NO_EVENTING = '';

    /**
     * SCPD path for a named service.
     */
    public static function scpd(string $service): string
    {
        return sprintf(self::SCPD_TEMPLATE, $service);
    }

    /**
     * Every control/SCPD path this server actually serves.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::DESCRIPTION,
            self::CONTENT_DIRECTORY_CONTROL,
            self::CDS_CONTROL,
            self::scpd('ContentDirectory'),
            self::scpd('ConnectionManager'),
            self::scpd('AVTransport'),
        ];
    }
}
