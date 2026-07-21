<?php

declare(strict_types=1);

/**
 * Casting ("play to a device on the network") configuration.
 *
 * The dotted setting keys are `casting.chromecast.enabled`,
 * `casting.roku.enabled` and `casting.airplay.enabled` (the `casting` file
 * segment + the nested path), declared in the shared
 * `server-settings.schema.json`.
 *
 * ## What these gate
 *
 * Each flag gates one protocol's entire HTTP surface — the six routes under
 * `/api/v1/{cast,roku,airplay}/devices` — via
 * {@see \Phlix\Server\Http\Middleware\CastingEnabledMiddleware}, appended to the
 * three route groups in {@see \Phlix\Server\Core\Application}. Because the
 * middleware runs per request and reads through
 * {@see \Phlix\Admin\SettingsRepository::getEffective()}, these are read-path
 * class (a) LIVE: flipping one takes effect on the very next request, with no
 * reload. That matters here more than for most settings — see below.
 *
 * Unlike DLNA, these subsystems are genuinely reachable. Verified against
 * production on 2026-07-21: `GET /api/v1/{cast,roku,airplay}/devices` all
 * return 401 (route present, auth-gated) while a made-up sibling path returns
 * 404, and all three managers resolve from the container.
 *
 * ## Why a kill-switch is worth having even where casting works
 *
 * Device discovery is BLOCKING. {@see \Phlix\Discovery\Mdns\MdnsSocket::query()}
 * loops on `socket_recvfrom()` with a 5-second `SO_RCVTIMEO`, which stalls the
 * whole Workerman worker for the duration — and AirPlay issues two service
 * queries, so roughly ten seconds. The endpoints are authenticated, but any
 * signed-in user can call them repeatedly. Being able to switch a protocol off
 * immediately, without a restart, is the point.
 *
 * ## Known limitations, recorded so nobody mistakes a working switch for a
 * ## working feature
 *
 * The switches below are real. The features behind two of them are not yet
 * complete, which is a product matter rather than a settings one:
 *
 *  - **roku** — `MdnsDiscovery::SERVICE_ROKU` is `'_ roku-ecnp._tcp.local.'`:
 *    a literal space inside the label and `ecnp` for `ecp`. Roku does not
 *    advertise ECP over mDNS at all (it uses SSDP), and there is no SSDP path
 *    for Roku here, so the device list can never populate. The existing tests
 *    assert the typo rather than catching it.
 *  - **airplay** — discovery is correct, but `AirPlaySession::startStream()`
 *    sends ANNOUNCE then RECORD with no RTSP SETUP, no key exchange and no RTP
 *    sender anywhere, so no audio is ever transmitted.
 *  - **chromecast** — discovery is correct; the control client speaks plaintext
 *    HTTP JSON to port 8009, whereas real Cast is TLS + protobuf (CASTV2), so
 *    listing works but the control verbs will not drive real hardware.
 *
 * Do not widen the schema `helpText` to promise these work. Each key's text
 * describes what the SWITCH does — stop discovery and the control endpoints —
 * which is true regardless of how complete the protocol implementation is.
 *
 * @since 1.3.0
 */

return [
    /**
     * Google Cast / Chromecast device discovery and control endpoints.
     */
    'chromecast' => [
        'enabled' => true,
    ],

    /**
     * Roku device discovery and control endpoints.
     */
    'roku' => [
        'enabled' => true,
    ],

    /**
     * AirPlay device discovery and streaming endpoints.
     */
    'airplay' => [
        'enabled' => true,
    ],
];
