<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

use Phlix\Config\EffectiveConfig;

/**
 * The ONE resolver for the host name DLNA advertises itself on.
 *
 * ## Why this class exists
 *
 * Three separate strings have to name the SAME host, or DLNA browse/playback
 * breaks in ways that look unrelated to each other:
 *
 * 1. the SSDP `LOCATION` header ({@see SsdpAdvertiser::getLocationUrl()}) — the
 *    URL a control point fetches the device description from;
 * 2. the `URLBase`/service URLs inside that description
 *    ({@see DlnaServer::getBaseUrl()});
 * 3. the `<res>` stream URL inside every Browse response
 *    ({@see LibraryBridge::getStreamUrl()}, wired in S53).
 *
 * Before this class, (2) honoured the `dlna.advertise_host` setting — resolved
 * inline in `DlnaServicesProvider` — while (1) did **not**: `start.php`
 * constructs `new SsdpAdvertiser(null, …)`, so `getIpAddress()` fell straight
 * through to {@see SsdpAdvertiser::detectLocalIp()} and ignored the setting
 * entirely. The two agreed only by coincidence under the shipped default
 * (`advertise_host => ''`, i.e. "auto-detect"). The moment an operator set the
 * key — which is exactly what the multi-homed/Docker-bridge case in
 * `config/dlna.php` tells them to do — the description would be fetched from
 * the detected address while every URL inside it named the configured one.
 *
 * So the resolution lives here once and all three sites read it.
 *
 * ## A BARE HOST, never a URL
 *
 * Every consumer composes `http://{host}:{port}` itself, so a value like
 * `http://192.168.1.10/` would yield `http://http://192.168.1.10/:8096`.
 * {@see self::sanitize()} strips a scheme and any trailing slash rather than
 * shipping that, because `config/dlna.php` names the key after a *host* while
 * `DlnaServer`'s constructor parameter is called `$baseUrl` — an inconsistency
 * that invites exactly this mistake.
 *
 * @package Phlix\Dlna
 * @since 1.7.0
 */
final class DlnaAdvertisedHost
{
    /**
     * Resolve the advertised host from the effective `dlna` config.
     *
     * @return string A bare host or IP — never a URL, never empty.
     */
    public static function host(): string
    {
        /** @var array<string, mixed> $dlna */
        $dlna = EffectiveConfig::file('dlna');

        return self::fromValue($dlna['advertise_host'] ?? null);
    }

    /**
     * Resolve the advertised host from an already-read setting value.
     *
     * Split out from {@see self::host()} so a caller that has the `dlna` array
     * in hand does not re-read it, and so the fallback is testable without an
     * `EffectiveConfig` bootstrap.
     *
     * @param mixed $configured The raw `dlna.advertise_host` value.
     *
     * @return string A bare host or IP — never a URL, never empty.
     */
    public static function fromValue(mixed $configured): string
    {
        if (is_string($configured)) {
            $sanitized = self::sanitize($configured);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        return SsdpAdvertiser::detectLocalIp();
    }

    /**
     * Reduce an operator-supplied value to a bare host.
     *
     * Strips a leading `scheme://`, any path/query/fragment tail and trailing
     * slashes. Returns `''` when nothing usable is left, which callers read as
     * "not configured".
     *
     * @param string $value Raw setting value.
     */
    public static function sanitize(string $value): string
    {
        $host = trim($value);
        if ($host === '') {
            return '';
        }

        $stripped = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
        $host = $stripped ?? $host;

        // Drop anything after the authority: a pasted description URL
        // (`192.168.1.10:8096/dlna/description.xml`) must not become the host.
        $host = (string) preg_replace('#[/?\#].*$#', '', $host);

        return trim($host);
    }

    /**
     * The absolute `http://{host}:{port}` origin every DLNA URL hangs off.
     *
     * @param int $port The HTTP port this server listens on.
     */
    public static function baseUrl(int $port): string
    {
        return sprintf('http://%s:%d', self::host(), $port);
    }

    /**
     * Prevent instantiation — this class is a static resolver only.
     */
    private function __construct()
    {
    }
}
