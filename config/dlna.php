<?php

declare(strict_types=1);

/**
 * DLNA / UPnP configuration.
 *
 * The dotted setting key is `dlna.enabled` (the `dlna` file segment + the
 * `enabled` array key), declared in the shared `server-settings.schema.json`.
 *
 * ## Scope — read this before adding keys here
 *
 * `enabled` governs the **SSDP advertiser only**. That is deliberate, and it is
 * narrower than "DLNA" sounds, because the DLNA *browse* service is not
 * currently wired:
 *
 *  - {@see \Phlix\Server\Core\Application::loadCdsRoutes()} resolves
 *    {@see \Phlix\Dlna\CdsServer} inside a bare `catch (\Throwable)`.
 *  - That resolution ALWAYS throws. {@see \Phlix\Dlna\DlnaServer} is registered
 *    in no DI provider, and its constructor takes three un-autowirable `string`
 *    parameters (`$serverId`, `$friendlyName`, `$baseUrl`), so PHP-DI reports
 *    "Parameter $serverId of __construct() has no value defined or guessable".
 *  - The exception is swallowed, so **no CDS route has ever been registered**.
 *    Verified against production on 2026-07-21: `POST /dlna/content_directory`,
 *    `POST /cds/control` and `GET /scpd/ContentDirectory.xml` all return 404.
 *    `GET /dlna/description.xml` returns 200 only because a STATIC file sits at
 *    `public/dlna/description.xml` — note its `Last-Modified`/`Accept-Ranges`
 *    headers, which a controller response would not carry.
 *
 * The practical effect is that Phlix advertises itself to every UPnP device on
 * the LAN as a MediaServer that then 404s every browse request. Switching
 * `enabled` off is therefore a genuine improvement today, not merely a
 * preference.
 *
 * If the CDS is ever wired up, extend this gate to `loadCdsRoutes()` as well
 * and widen the schema's `helpText` at the same time — but note that DLNA has
 * no authentication of any kind, so wiring it exposes the library to anything
 * on the local network. That is a product decision, not a settings change.
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
];
