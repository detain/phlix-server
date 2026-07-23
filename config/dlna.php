<?php

declare(strict_types=1);

/**
 * DLNA / UPnP configuration.
 *
 * The dotted setting key is `dlna.enabled` (the `dlna` file segment + the
 * `enabled` array key), declared in the shared `server-settings.schema.json`.
 *
 * ## Two switches, and why they are separate
 *
 *  - `enabled` governs the **SSDP advertiser** — the broadcast that makes this
 *    server appear in a TV's source list. Default TRUE (pre-existing
 *    behaviour).
 *  - `cds_enabled` governs the **ContentDirectory browse service** — the SOAP
 *    endpoints a control point uses to list and play your library. Default
 *    **FALSE**, and it must stay that way. See the warning below.
 *
 * They are separate because they carry very different risk, and because
 * flipping `enabled` to false by default would have removed the advertisement
 * from every existing install.
 *
 * ## ⚠️ `cds_enabled` exposes the library with NO AUTHENTICATION
 *
 * DLNA/UPnP has no concept of credentials. Turning `cds_enabled` on lets ANY
 * device on the local network browse and stream the entire library without
 * logging in — deliberately bypassing the auth gate that makes
 * listing/search/detail require a token on both HTTP dispatch paths. That is
 * why it ships off and why the schema marks it `advanced` with an explicit
 * warning in its helpText. It is the right choice for a trusted home LAN and
 * the wrong one for a shared or untrusted network.
 *
 * ## History — this used to be broken rather than disabled
 *
 * Before 1.3.0 the browse service was not wired at ALL:
 * {@see \Phlix\Server\Core\Application::loadCdsRoutes()} resolved
 * {@see \Phlix\Dlna\CdsServer} inside a bare `catch (\Throwable)`, and that
 * resolution always threw because {@see \Phlix\Dlna\DlnaServer} had no DI
 * registration and takes three un-autowirable `string` parameters. The
 * exception was swallowed, so no CDS route was ever registered. Verified on
 * production 2026-07-21: `POST /dlna/content_directory`, `POST /cds/control`
 * and `GET /scpd/ContentDirectory.xml` all 404'd, while
 * `GET /dlna/description.xml` returned 200 only because a STATIC file sat at
 * `public/dlna/description.xml` (its `Last-Modified`/`Accept-Ranges` headers
 * were the tell). The server advertised itself as a MediaServer that then 404'd
 * every browse request.
 *
 * `DlnaServer`, `CdsServer` and `ContentDirectory` are now registered in
 * {@see \Phlix\Common\Container\Providers\DlnaServicesProvider}, and the route
 * loader logs a resolution failure instead of swallowing it.
 *
 * @since 1.3.0
 */

return [
    /**
     * Announce this server to DLNA/UPnP devices via SSDP.
     *
     * Enforced in {@see \Phlix\Dlna\SsdpAdvertiser::isEnabled()}, consulted by
     * that worker's `onWorkerStart`. The worker re-reads the EFFECTIVE value on
     * every graceful reload and idles without opening its multicast socket when
     * this is false, so an admin override applies on the next reload.
     *
     * The value HERE (the file default) additionally drives the master-process
     * spawn decision in `start.php`, which cannot consult the settings store:
     * a blocking DB read in the master would leave its connection inherited by
     * every fork. Setting this to false on disk therefore means no advertiser
     * process exists at all, and an admin override can no longer switch it back
     * on without a full service restart — the same asymmetry the five
     * `process.*` keys carry, and the schema's helpText says so.
     */
    'enabled' => true,

    /**
     * Serve the DLNA ContentDirectory browse/stream endpoints.
     *
     * OFF BY DEFAULT AND INTENTIONALLY SO — see the "NO AUTHENTICATION"
     * warning in this file's header. Enforced in
     * {@see \Phlix\Server\Core\Application::loadCdsRoutes()}, which registers
     * `/dlna/description.xml`, `/dlna/content_directory`, `/cds/control` and
     * `/scpd/{service}.xml` only when this is true.
     *
     * Read per worker start, so it applies on a graceful reload.
     */
    'cds_enabled' => false,

    /**
     * Inbound IP allowlist (CIDR notation) for the DLNA browse/stream endpoints.
     *
     * DLNA/UPnP carries no credentials (see the "NO AUTHENTICATION" warning in
     * this file's header), so once `cds_enabled` is on,
     * {@see \Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware} is the ONLY
     * thing standing between a caller and the whole library. Every request to a
     * CDS route is matched against this list.
     *
     * Each entry is a CIDR block — `192.168.1.0/24`, `10.0.0.0/8`, a `/32`
     * single host, or an IPv6 range such as `fd00::/8`. Parsing and matching
     * REUSE {@see \Phlix\Common\Net\SsrfGuard} so the same well-tested CIDR
     * logic guards inbound DLNA and outbound SSRF.
     *
     * An EMPTY list does NOT mean "allow everyone" — that would reinstate the
     * exact exposure this key exists to prevent. When the list is empty (or a
     * caller matches none of its entries) the effective policy is decided by
     * `restrict_to_lan` below.
     *
     * @var list<string>
     */
    'allowed_cidrs' => [],

    /**
     * Default posture when `allowed_cidrs` is empty (or a caller matched none of
     * its entries): restrict DLNA to the local network.
     *
     * When TRUE (the shipped default) only loopback and the private/local ranges
     * — RFC1918 (10/8, 172.16/12, 192.168/16), IPv4 link-local (169.254/16),
     * IPv6 loopback (::1), unique-local (fc00::/7) and link-local (fe80::/10) —
     * may reach the CDS routes; anything else gets a 403. That is the right
     * default for DLNA, which is a LAN protocol.
     *
     * When FALSE, `allowed_cidrs` becomes the ONLY gate: a caller must match an
     * explicit entry. An empty `allowed_cidrs` together with FALSE therefore
     * denies EVERYONE — a deliberate, fully-locked-down state, and still never
     * "allow all."
     */
    'restrict_to_lan' => true,

    /**
     * Name shown in a TV's source list.
     *
     * Only meaningful when `cds_enabled` is on: it is served in the device
     * description that a control point fetches. Note that the legacy STATIC
     * `public/dlna/description.xml` is now shadowed by the real route, so this
     * value is what devices actually see.
     */
    'friendly_name' => 'Phlix Media Server',

    /**
     * Stable UPnP device identifier (the UDN suffix).
     *
     * MUST NOT change between restarts — control points cache devices by UDN,
     * and a server whose identity changes on every boot shows up as an endless
     * list of duplicates. Leave empty to derive one deterministically from the
     * host name, which satisfies that requirement without the operator having
     * to invent a UUID.
     */
    'server_id' => '',

    /**
     * HOST (or IP) devices should reach this server on — e.g. `192.168.1.10`.
     *
     * A BARE HOST, not a URL. {@see \Phlix\Dlna\DlnaServer::getBaseUrl()}
     * composes `http://{host}:{port}` itself, so passing `http://…` here yields
     * a malformed `http://http://…` — a real trap, since that class names the
     * constructor parameter `$baseUrl` while using it as a host.
     *
     * Leave empty to auto-detect the LAN-facing address via
     * {@see \Phlix\Dlna\SsdpAdvertiser::detectLocalIp()} — the SAME detection
     * the SSDP advertiser uses for its LOCATION header. That sharing is
     * load-bearing: a control point fetches the description from LOCATION and
     * then follows the URLs inside it, so if the two disagreed every browse
     * request would go to the wrong host. Only set this explicitly if
     * auto-detection picks the wrong interface (multi-homed hosts, Docker
     * bridges), and make sure it is an address devices can actually reach.
     */
    'advertise_host' => '',
];
