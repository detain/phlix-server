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
 * ## ⚠️ PARENTAL CONTROLS DO NOT APPLY TO DLNA
 *
 * A consequence of the above that deserves saying on its own, because it is the
 * one an operator is most likely to be surprised by: **parental controls and
 * per-profile stream limits do not apply to DLNA.** There is no signed-in user on
 * a DLNA request, so there is no profile, so there is no rating filter and no
 * stream cap to enforce — {@see \Phlix\Media\Library\RatingGate} is applied on the
 * authenticated `/media/{id}/stream` path and structurally cannot be applied
 * here. Any device the allowlist permits can BROWSE and PLAY content that a
 * profile's rating filter would hide inside the app.
 *
 * This is not new in 1.7.0 — DLNA browse has never filtered by rating — but 1.7.0
 * added `/dlna/stream/{id}`, which makes it playable rather than merely listed.
 * There is no per-profile fix available (there is no profile); a SERVER-WIDE
 * `dlna.max_rating` that excludes over-cap items from DLNA browse entirely is the
 * enforceable form, and it is not implemented yet. Until it is, treat
 * `cds_enabled` as "publish this library, unfiltered, to every device on the
 * allowlist".
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
     * Announce this server to DLNA/UPnP devices via SSDP, and answer their
     * searches.
     *
     * Covers BOTH halves of SSDP discovery since 1.7.0: the 30-second alive
     * `NOTIFY` this server multicasts, and the unicast reply it sends to an
     * inbound `M-SEARCH`. Most control points use the second — waiting up to 30
     * seconds for the next announcement is not an acceptable UI — so turning
     * this off makes the server invisible to them too, not merely quiet.
     *
     * Enforced in {@see \Phlix\Dlna\SsdpAdvertiser::isEnabled()}, consulted by
     * that worker's `onWorkerStart`. The worker re-reads the EFFECTIVE value on
     * every graceful reload and idles without opening its multicast socket when
     * this is false, so an admin override applies on the next reload.
     *
     * ⚠️ One operational consequence worth knowing: the worker's UDP LISTEN
     * socket on `0.0.0.0:1900` is bound by Workerman before `onWorkerStart`
     * runs, so a disabled advertiser still HOLDS port 1900 — it simply never
     * answers. It binds with `SO_REUSEPORT`, so this coexists with other SSDP
     * software on the host rather than fighting it; and if the bind fails
     * anyway, the worker logs a warning and carries on announcing.
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
     * OFF BY DEFAULT AND INTENTIONALLY SO — see the "NO AUTHENTICATION" and
     * "PARENTAL CONTROLS DO NOT APPLY" warnings in this file's header. Enforced in
     * {@see \Phlix\Server\Core\Application::loadCdsRoutes()}, which registers
     * `/dlna/description.xml`, `/dlna/content_directory`, `/cds/control`,
     * `/scpd/{service}.xml` and — since 1.7.0 — the media byte route
     * `/dlna/stream/{mediaItemId}` only when this is true.
     *
     * That byte route is what makes this switch a MEDIA exposure and not just a
     * metadata one: before 1.7.0 browse listed items whose `<res>` URL 404'd, so
     * nothing could actually be played. It is gated by the same
     * `allowed_cidrs`/`restrict_to_lan` allowlist as every other path here, and by
     * nothing else — there is no token on it, by design, because a DLNA renderer
     * cannot present one.
     *
     * Read per worker start, so it applies on a graceful reload.
     *
     * Since S297 the FILE value additionally participates in the master-process
     * spawn decision in `start.php`, exactly like `enabled`: an advertiser is
     * forked only when BOTH this and `enabled` are on, and the master cannot
     * consult the settings store — so setting this to false on disk (the
     * shipped default) means no advertiser process exists at all, and an admin
     * override can no longer switch it back on without a full service restart.
     * It also means a CDS-disabled install no longer holds `udp://0.0.0.0:1900`.
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
     * {@see \Phlix\Dlna\SsdpAdvertiser::detectLocalIp()}.
     *
     * This key is read in exactly ONE place — {@see \Phlix\Dlna\DlnaAdvertisedHost} —
     * and THREE things read that: the SSDP `LOCATION` header, the device
     * description's service URLs, and the `<res>` stream URL inside every Browse
     * response. That sharing is load-bearing: a control point fetches the
     * description from `LOCATION`, follows the URLs inside it, and then fetches
     * the bytes from `<res>`, so any disagreement between the three sends part
     * of the conversation to the wrong host. (Before S53 the SSDP advertiser
     * ignored this key entirely and always auto-detected, so setting it broke
     * exactly that chain.)
     *
     * Only set this explicitly if auto-detection picks the wrong interface
     * (multi-homed hosts, Docker bridges), and make sure it is an address
     * devices can actually reach.
     */
    'advertise_host' => '',
];
