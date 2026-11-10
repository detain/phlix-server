<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Admin\SettingsRepository;

/**
 * Single source of truth for the auth-cookie issuance policy (COOKIE-DESIGN.md
 * §2.1/§2.4, owner ruling CD-3a). Answers three independent questions:
 *
 *  1. CLASS — is this request browser-class (may receive HttpOnly cookies) or
 *     device-class (bearer-only, NO Set-Cookie ever)? Device marker wins:
 *     `X-Phlix-Device-Type` present, or an Origin that is NOT a same-site
 *     http(s) origin (capacitor://, app://, foreign web host), classifies the
 *     request device-side (§2.1 discriminator, contracts CookieMode law).
 *     Requests with NO browser signal at all (curl, legacy natives) default to
 *     device-class — exactly today's JSON bearer-only posture, byte-ident.
 *  2. MASTER SWITCH — `auth.cookie.enabled` (shared schema, landed @2683579):
 *     false means the server never sets auth cookies at all, on any path
 *     (form redirect included; w28L1 R2 routed the OAuth callback minters
 *     through this same seam, making the claim literally true as shipped).
 *     Live read, fail-open to the shipped default.
 *  3. SCHEME LAW — cookies are issued only when the request arrived over
 *     https or addressed localhost, or the operator opted into insecure
 *     cookies (`PHLIX_COOKIE_INSECURE=1` env or its stored twin
 *     `auth.cookie.insecure`, which ALSO drops the Secure attribute — there is
 *     never a silent Secure downgrade onto plain HTTP).
 *
 * Reads settings exactly like {@see TokenTtlPolicy}: live per-call
 * getEffective() (an admin toggle takes effect on the next request), clamped
 * in code, null store or a throwing store degrades to the shipped posture
 * (enabled, secure, env-only override) so the degraded no-container boot
 * behaves like every pre-cookie-policy release.
 */
final class CookiePolicy
{
    /** Shared-schema master switch (phlix-shared 2683579 era). */
    public const COOKIE_ENABLED_KEY = 'auth.cookie.enabled';

    /** Shared-schema stored form of the PHLIX_COOKIE_INSECURE=1 opt-in. */
    public const COOKIE_INSECURE_KEY = 'auth.cookie.insecure';

    /** Cookie custody class — mirrors contracts COOKIE_MODE.COOKIE (src/auth.ts). */
    public const MODE_COOKIE = 'cookie';

    /** Bearer custody class — mirrors contracts COOKIE_MODE.BEARER (src/auth.ts). */
    public const MODE_BEARER = 'bearer';

    /** Hostnames that satisfy the §2.4 localhost limb (any port). */
    private const LOCALHOST_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * @param SettingsRepository|null $settings Live settings store; null in
     *        the degraded no-container fallback → shipped defaults reproduce
     *        pre-cookie-program behavior.
     */
    public function __construct(private readonly ?SettingsRepository $settings = null)
    {
    }

    /**
     * Master switch: when false the server never sets auth cookies anywhere,
     * including the form-era browser redirect arms (§7 rollback law).
     * Anything other than an explicit `false` means enabled (fail-open: the
     * stored default is true, and an unreachable store must not strand the
     * working browser login).
     */
    public function cookieModeEnabled(): bool
    {
        if ($this->settings === null) {
            return true;
        }

        try {
            return $this->settings->getEffective(self::COOKIE_ENABLED_KEY) !== false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Explicit operator opt-in to LAN-readable cookies: the env switch
     * (already shipped) or its stored twin. True = the scheme gate is bypassed
     * AND the Secure attribute is dropped. Never enabled by default, never
     * inferred from anything else (CD-3a: no silent Secure downgrade).
     */
    public function insecureOverride(): bool
    {
        if (getenv('PHLIX_COOKIE_INSECURE') === '1') {
            return true;
        }

        if ($this->settings === null) {
            return false;
        }

        try {
            return $this->settings->getEffective(self::COOKIE_INSECURE_KEY) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether issued cookies carry the `Secure` attribute. The inverse of the
     * override — a cookie forced onto plain HTTP without `Secure` is the
     * documented LAN-sniffable posture the operator asked for; a `Secure`
     * cookie over plain HTTP would simply never be stored (silent breakage).
     */
    public function cookiesSecure(): bool
    {
        return !$this->insecureOverride();
    }

    /**
     * §2.4 scheme law for a browser-class request: https (per the first hop of
     * X-Forwarded-Proto, the estate's TLS-termination idiom — see
     * CallbackUrl::scheme for the identical first-hop-wins reading), or a
     * localhost/127.0.0.1/::1 Host (dev boxes, any port), or the explicit
     * insecure override. A spoofed `X-Forwarded-Proto: https` on a plain-HTTP
     * box only yields a `Secure` cookie a plain-HTTP browser refuses to store:
     * fail-safe, and the direct-listener estate already reads this header for
     * callbacks and Quick-Connect.
     */
    public function schemeEligible(?string $forwardedProto, ?string $hostHeader): bool
    {
        if ($this->insecureOverride()) {
            return true;
        }

        if (self::isHttpsForwarded($forwardedProto)) {
            return true;
        }

        return self::isLocalhostHost($hostHeader);
    }

    /**
     * The §2.1 device-class discriminator over raw header values. Device
     * marker present → bearer. An Origin that is present but not a same-site
     * http(s) origin (non-browser scheme, foreign host) → bearer. Otherwise:
     * a form-era signal (browser path alias / form Content-Type — the values
     * isBrowserRequest() reads) or `Sec-Fetch-Site: same-origin` marks the
     * request browser-class; with no signal at all the request stays bearer,
     * which is today's JSON posture for every legacy native client.
     *
     * @param bool $formEraBrowser Signal from the controller's existing
     *        isBrowserRequest() (form path/content-type detection).
     */
    public function custodyMode(
        bool $formEraBrowser,
        ?string $deviceTypeHeader,
        ?string $originHeader,
        ?string $hostHeader,
        ?string $secFetchSite,
    ): string {
        if (is_string($deviceTypeHeader) && $deviceTypeHeader !== '') {
            return self::MODE_BEARER;
        }

        if (is_string($originHeader) && $originHeader !== '' && !self::originIsSameSite($originHeader, $hostHeader)) {
            return self::MODE_BEARER;
        }

        $browserClass = $formEraBrowser
            || (is_string($originHeader) && $originHeader !== '')
            || (is_string($secFetchSite) && strtolower($secFetchSite) === 'same-origin');

        return $browserClass ? self::MODE_COOKIE : self::MODE_BEARER;
    }

    /**
     * First-hop https test on an X-Forwarded-Proto value (chains append:
     * "https, http" — the client-facing hop wins; only https|http are read).
     */
    public static function isHttpsForwarded(?string $forwardedProto): bool
    {
        if (!is_string($forwardedProto) || $forwardedProto === '') {
            return false;
        }

        $first = strtolower(trim(explode(',', $forwardedProto)[0]));

        return $first === 'https';
    }

    /**
     * Hostname limb of the §2.4 localhost rule: exact-name match after
     * lowercasing and :port strip (bracketed IPv6 literals kept whole).
     */
    public static function isLocalhostHost(?string $hostHeader): bool
    {
        if (!is_string($hostHeader) || $hostHeader === '') {
            return false;
        }

        return in_array(self::hostOf($hostHeader), self::LOCALHOST_HOSTS, true);
    }

    /**
     * Whether an Origin header is a same-site http(s) origin against the
     * request Host: http/https scheme only (app://, capacitor://, tizen://,
     * file:// are device webview vaults — bearer per §2.1/§4.4/§4.5), and the
     * host:port pair matches after default-port normalization.
     */
    public static function originIsSameSite(string $origin, ?string $hostHeader): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $originHost = strtolower($parts['host'] ?? '');
        $originHost = str_starts_with($originHost, '[') && str_ends_with($originHost, ']')
            ? substr($originHost, 1, -1)
            : $originHost;
        if ($originHost === '') {
            return false;
        }

        $hostParts = is_string($hostHeader) ? parse_url('//' . $hostHeader) : false;
        if (!is_array($hostParts)) {
            return false;
        }

        $requestHost = self::hostOf($hostHeader);
        if ($originHost !== $requestHost) {
            return false;
        }

        $originPort = $parts['port'] ?? self::defaultPort($scheme);
        $requestPort = $hostParts['port'] ?? self::defaultPort($scheme);

        return $originPort === $requestPort;
    }

    /** Default port used when a side omits the :port (URL normalization). */
    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * Lowercased hostname of a Host header value, :port stripped and bracketed
     * IPv6 literals unwrapped (parse_url keeps the brackets on both forms).
     */
    private static function hostOf(string $hostHeader): string
    {
        $parts = parse_url('//' . $hostHeader);
        $host = strtolower(is_array($parts) ? ($parts['host'] ?? '') : '');

        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }
}
