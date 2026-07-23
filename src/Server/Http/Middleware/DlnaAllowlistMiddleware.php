<?php

/**
 * Phlix media server component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Restricts the DLNA ContentDirectory (CDS) browse/stream endpoints to an
 * inbound IP allowlist.
 *
 * DLNA/UPnP has NO concept of credentials, so once `dlna.cds_enabled` is on,
 * {@see \Phlix\Server\Core\Application::loadCdsRoutes()} would otherwise serve
 * the entire library to anything that can reach the port — deliberately
 * bypassing the auth gate every other way into Phlix requires. This middleware
 * is the gate: it is attached to the CDS route GROUP exactly as
 * {@see SignedUrlMiddleware} is attached to the streaming group, so it is
 * re-evaluated PER REQUEST (class (a) LIVE) — the {@see \Phlix\Server\Core\Application}
 * that loads the routes is built once per worker, so a check at load time would
 * only apply after a reload.
 *
 * ## Peer IP
 *
 * The client address is resolved via {@see Request::getTrustedClientIp()}, the
 * codebase's designated spoof-resistant accessor (the one the rate limiters
 * use). For a DLNA renderer connecting directly — the normal case, since the
 * SSDP `LOCATION` points renderers straight at the advertise host/port — the
 * direct TCP peer is not a trusted proxy, so a client-forged `X-Forwarded-For`
 * is ignored and the real peer address is used. When a trusted (loopback)
 * reverse proxy fronts the endpoint, the resolver walks `X-Forwarded-For`
 * right-to-left past trusted hops to the real client. The raw peer IP is
 * plumbed onto the {@see Request} by {@see \Phlix\Server\Http\Request::fromWorkerman()}
 * from the {@see \Workerman\Connection\TcpConnection}, and by
 * {@see \Phlix\Server\Http\Request::fromGlobals()} from `REMOTE_ADDR` — so the
 * middleware always sees a real address, never the `0.0.0.0` placeholder.
 *
 * An IPv4-mapped IPv6 peer (`::ffff:192.168.1.5`, as a dual-stack listener may
 * report) is collapsed to its embedded IPv4 form (reusing
 * {@see SsrfGuard::embeddedIpv4()}) before matching, so a mapped loopback/LAN
 * address is evaluated against the IPv4 rules instead of being wrongly denied.
 *
 * ## Policy — an empty allowlist is NEVER "allow all"
 *
 * This is the security defect the middleware exists to prevent. The decision is:
 *
 *   1. If `dlna.allowed_cidrs` is non-empty AND the client matches one of its
 *      CIDRs → ALLOW (an explicit allowlist entry always wins).
 *   2. Otherwise, if `dlna.restrict_to_lan` is true (the shipped default) →
 *      allow ONLY loopback + RFC1918/ULA/link-local ({@see self::LAN_CIDRS}).
 *   3. Otherwise → DENY (403).
 *
 * So an empty `allowed_cidrs` with the default `restrict_to_lan = true` allows
 * only the local network; an empty `allowed_cidrs` with `restrict_to_lan =
 * false` denies everyone (a deliberate, fully-locked-down state). At no point
 * does "no entries" degrade to "everyone".
 *
 * CIDR parsing and matching REUSE {@see SsrfGuard} — the same binary-prefix
 * matcher that guards outbound SSRF — rather than re-rolling CIDR logic.
 *
 * ## Failure behaviour (fail CLOSED)
 *
 * Unlike the fail-OPEN casting/webhooks switches, DLNA access is a security
 * boundary: an unparseable client address, or a malformed override, resolves
 * toward denial, not exposure. `restrict_to_lan` is only disabled by an explicit
 * `false`; any other value (including a store failure that leaves the config
 * default) keeps the LAN restriction in force.
 *
 * ## Wiring note
 *
 * The `SettingsRepository` is passed EXPLICITLY by the route loader (via
 * `optionalSettingsRepository()`), never autowired — PHP-DI would silently
 * supply `null` for an unnamed optional parameter, and here `null` merely means
 * "read the overlaid config file", which is the intended fallback.
 *
 * @package Phlix\Server\Http\Middleware
 * @since 1.6.0
 */
final class DlnaAllowlistMiddleware
{
    /**
     * The ranges permitted by the default `restrict_to_lan` posture: loopback
     * plus every private/local range — RFC1918, IPv4 link-local, IPv6 loopback,
     * unique-local (ULA) and IPv6 link-local. Matched with the same reused
     * {@see SsrfGuard} CIDR matcher as the operator allowlist.
     *
     * @var list<string>
     */
    private const array LAN_CIDRS = [
        '127.0.0.0/8',    // IPv4 loopback
        '10.0.0.0/8',     // RFC1918
        '172.16.0.0/12',  // RFC1918
        '192.168.0.0/16', // RFC1918
        '169.254.0.0/16', // IPv4 link-local
        '::1/128',        // IPv6 loopback
        'fc00::/7',       // IPv6 unique-local (ULA)
        'fe80::/10',      // IPv6 link-local
    ];

    /**
     * @param SettingsRepository|null $settings Settings store for LIVE per-request
     *        reads of `dlna.allowed_cidrs` / `dlna.restrict_to_lan`, or null to
     *        read the overlaid {@see EffectiveConfig::file()} `dlna` config.
     */
    public function __construct(
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * Run the middleware. Returning `null` continues routing; returning a
     * {@see Response} short-circuits the dispatch chain (per
     * {@see \Phlix\Server\Http\Router::runMiddleware()} semantics).
     *
     * A blocked caller answers 403 Forbidden: unlike the casting switches (which
     * 404 to look absent), the DLNA endpoints legitimately exist and the caller
     * is simply not permitted from its network location.
     */
    public function __invoke(Request $request): ?Response
    {
        if ($this->isAllowed($request->getTrustedClientIp())) {
            return null;
        }

        return (new Response())->status(403)->json([
            'error' => 'DLNA access is not permitted from this network address.',
            'code'  => 'dlna.forbidden',
        ]);
    }

    /**
     * Whether a client IP may reach the DLNA CDS endpoints.
     *
     * Public so the decision can be asserted directly in tests without building
     * a {@see Request}/{@see Response} round-trip.
     *
     * @param string $clientIp The resolved client address (as returned by
     *        {@see Request::getTrustedClientIp()}).
     */
    public function isAllowed(string $clientIp): bool
    {
        // Collapse an IPv4-mapped IPv6 peer to its embedded IPv4 form so it is
        // evaluated against the IPv4 rules, reusing the guard's helper.
        $ip = SsrfGuard::embeddedIpv4($clientIp) ?? $clientIp;

        // Fail closed on anything that is not a well-formed address.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // 1) An explicit allowlist entry always wins.
        $allowed = SsrfGuard::filterCidrs($this->allowedCidrs());
        if ($allowed !== [] && SsrfGuard::ipMatchesAnyCidr($ip, $allowed)) {
            return true;
        }

        // 2) Default posture: LAN-only. An empty allowlist is NEVER "allow all".
        if ($this->restrictToLan()) {
            return SsrfGuard::ipMatchesAnyCidr($ip, self::LAN_CIDRS);
        }

        // 3) restrict_to_lan disabled and the explicit allowlist did not match
        //    (or is empty) → deny.
        return false;
    }

    /**
     * The configured `dlna.allowed_cidrs` list, coerced to a list of strings.
     *
     * @return list<string>
     */
    private function allowedCidrs(): array
    {
        /** @var mixed $raw */
        $raw = $this->settingValue('dlna.allowed_cidrs', 'allowed_cidrs');
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        /** @var mixed $entry */
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Whether the LAN restriction is in force.
     *
     * Compared against `false` explicitly (not cast), so a malformed hand-edited
     * `server_settings` row — or an absent key — leaves the safe default (LAN
     * restriction ON) in place rather than being coerced to "off".
     */
    private function restrictToLan(): bool
    {
        return $this->settingValue('dlna.restrict_to_lan', 'restrict_to_lan') !== false;
    }

    /**
     * Read one `dlna.*` setting: LIVE via the settings store when available,
     * else from the overlaid config file. A settings-store failure falls back to
     * the file rather than throwing.
     *
     * @param string $dottedKey Full dotted key (e.g. `dlna.allowed_cidrs`).
     * @param string $fileKey   The corresponding `config/dlna.php` array key.
     */
    private function settingValue(string $dottedKey, string $fileKey): mixed
    {
        if ($this->settings !== null) {
            try {
                return $this->settings->getEffective($dottedKey);
            } catch (\Throwable) {
                // Settings store unreachable — fall through to the config file.
            }
        }

        return EffectiveConfig::file('dlna')[$fileKey] ?? null;
    }
}
