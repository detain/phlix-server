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
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Gates one casting protocol's HTTP surface behind its `casting.*.enabled`
 * setting.
 *
 * Backs `casting.chromecast.enabled`, `casting.roku.enabled` and
 * `casting.airplay.enabled`. One instance per protocol is appended to that
 * protocol's existing route group in {@see \Phlix\Server\Core\Application}, so
 * a single object covers all six of its routes — rather than a check each
 * controller method must remember, which is the "half-effective setting"
 * failure this settings program keeps running into.
 *
 * ## Why the guard lives in middleware
 *
 * Middleware runs PER REQUEST, so the setting is read-path class (a) LIVE: an
 * admin flipping it off takes effect on the very next request. Putting the
 * check in the route loader instead would have made it class (b) RESTART,
 * because {@see \Phlix\Server\Core\Application} is constructed once per worker
 * inside `onWorkerStart`. Live matters here specifically: the thing being
 * gated, {@see \Phlix\Discovery\Mdns\MdnsSocket::query()}, blocks the entire
 * Workerman worker for ~5 s per call (twice that for AirPlay), so the switch is
 * an operational lever that needs to work immediately, not after a reload.
 *
 * The per-request `SettingsRepository` read is cheap relative to what it
 * protects, and these are low-traffic, user-initiated endpoints.
 *
 * ## Failure behaviour
 *
 * Falls back to {@see EffectiveConfig::file()} when no `SettingsRepository` is
 * available, and to "enabled" when neither source says otherwise. Casting is
 * on today, so a settings-store failure must not silently remove endpoints
 * that currently work — the same fail-open reasoning as `webhooks.enabled` and
 * `stats.enabled`.
 *
 * ## Wiring note
 *
 * The `SettingsRepository` is passed EXPLICITLY by the route loader, never
 * autowired. PHP-DI silently supplies `null` for an unnamed optional
 * constructor parameter, which would make this guard inert by construction —
 * the class (g) trap that has bitten this codebase before. If you ever register
 * this class in a DI provider, name the parameter.
 *
 * @package Phlix\Server\Http\Middleware
 * @since 1.3.0
 */
final class CastingEnabledMiddleware
{
    /**
     * @param string                  $protocol Config/schema segment naming the
     *        protocol: `chromecast`, `roku` or `airplay`.
     * @param SettingsRepository|null $settings Settings store, or null to read
     *        the overlaid config file directly.
     */
    public function __construct(
        private readonly string $protocol,
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * Is this casting protocol enabled?
     *
     * Compares against `false` explicitly rather than casting, so a malformed
     * hand-edited `server_settings` row leaves the shipped default alone
     * instead of being coerced into "off".
     */
    public function isEnabled(): bool
    {
        $key = 'casting.' . $this->protocol . '.enabled';

        if ($this->settings !== null) {
            try {
                return $this->settings->getEffective($key) !== false;
            } catch (\Throwable) {
                // Settings store unreachable — fall through to the file.
            }
        }

        $file = EffectiveConfig::file('casting');
        $section = $file[$this->protocol] ?? null;

        return !is_array($section) || ($section['enabled'] ?? true) !== false;
    }

    /**
     * Run the middleware. Returning `null` continues routing; returning a
     * {@see Response} short-circuits the dispatch chain (per
     * {@see \Phlix\Server\Http\Router::runMiddleware()} semantics).
     *
     * A disabled protocol answers 404 rather than 403: the endpoints are meant
     * to look absent, which is what a client probing for casting support should
     * see, and it avoids advertising a capability the admin has switched off.
     */
    public function __invoke(Request $request): ?Response
    {
        if ($this->isEnabled()) {
            return null;
        }

        return (new Response())->status(404)->json([
            'error' => ucfirst($this->protocol) . ' casting is disabled on this server.',
            'code'  => 'casting.disabled',
        ]);
    }
}
