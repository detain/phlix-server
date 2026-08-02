<?php

/**
 * Phlix media server component: Server.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Theming\BuiltInThemes;
use Phlix\Theming\ThemeSourceRegistry;
use Phlix\Theming\TokenTheme;

/**
 * Read-only HTTP surface over the theme catalogue (S85).
 *
 * Serves `GET /api/v1/themes` and `GET /api/v1/themes/{id}`, merging the two
 * places a token-map theme can come from:
 *
 *  - **Built-ins** — {@see BuiltInThemes}, the SPA's `nocturne` / `daylight` /
 *    `midnight`, host-owned and always present.
 *  - **Plugin-registered** — {@see ThemeSourceRegistry}, filled by the S84
 *    {@see \Phlix\Theming\ThemeSourceInterface} capability arm in
 *    {@see \Phlix\Plugins\PluginLoader::enable()} and emptied again on disable.
 *
 * ## Everything served has passed the S84 sanitiser
 *
 * A registry entry is a {@see TokenTheme}, and the only way one enters the
 * registry is {@see ThemeSourceRegistry::register()}, which validates every raw
 * payload first. The built-ins take the same road: {@see BuiltInThemes::all()}
 * runs each of its payloads through
 * {@see \Phlix\Theming\ThemeTokenValidator::validateBuiltIn()}. So there is no
 * "host is trusted" shortcut into this output — a token key is on
 * {@see \Phlix\Theming\ThemeTokenAllowlist} and a token value matches the
 * colour/number grammar, whoever supplied it.
 *
 * ## Wire shape
 *
 * `{@see TokenTheme::toArray()}` plus one added field:
 *
 * ```json
 * {
 *   "id": "acme-noir",
 *   "name": "Acme Noir",
 *   "dark": true,
 *   "extends": "nocturne",
 *   "tokens": { "--bg": "#08070a", "--accent": "#78beff" },
 *   "source": "acme-themes",
 *   "builtIn": false
 * }
 * ```
 *
 * `builtIn` is load-bearing for the SPA rather than cosmetic: a built-in is
 * applied by setting `data-theme` on `<html>` (its real values live in the
 * shipped stylesheet), whereas a plugin theme is applied token-by-token with
 * `el.style.setProperty()`. A client must be able to tell the two apart without
 * having to infer it from `source === null`.
 *
 * `tokens` is a theme's OWN declared map, not a flattened one. A theme with a
 * non-null `extends` therefore has to be layered over its base by the client;
 * every base that this server knows about is in the list response, so the list
 * endpoint is self-sufficient for that. (`ThemeSourceRegistry::resolveTokens()`
 * can flatten a chain server-side, but exposing a second, derived token map
 * would give clients two sources of truth for the same theme.)
 *
 * ## Auth posture
 *
 * Both routes are registered inside {@see \Phlix\Server\WebPortal\WebPortalRouter}'s
 * {@see \Phlix\Server\Http\Middleware\AuthMiddleware} group — a signed-in user
 * is required. The catalogue is not per-user data, but the theme LIST names the
 * theme plugins installed on this server, which is a plugin-fingerprinting aid
 * for an unauthenticated attacker looking for a known-vulnerable plugin; and
 * the audience that can act on the list (the appearance picker) is signed in by
 * definition. No FOUC follows from that: the SPA's three built-in themes are in
 * its own bundled CSS and need no fetch, so a signed-out page still themes
 * itself.
 *
 * @package Phlix\Server\Http\Controllers
 * @since   0.44.0
 */
final class ThemesController
{
    /**
     * @param ThemeSourceRegistry $registry Process-scoped registry of
     *        plugin-contributed token-map themes. MUST be the same instance
     *        {@see \Phlix\Plugins\PluginLoader} registers into — a second
     *        instance would answer "no plugin themes" forever.
     */
    public function __construct(
        private readonly ThemeSourceRegistry $registry,
    ) {
    }

    /**
     * `GET /api/v1/themes` — the whole catalogue.
     *
     * @param Request $request The HTTP request (unused; the catalogue is
     *        server-wide, not per-user).
     * @param array<string, string> $params Route parameters (unused).
     *
     * @return Response `200` with `{"themes": [ ... ]}`.
     *
     * @api_endpoint GET /api/v1/themes
     *
     * @since 0.44.0
     */
    public function index(Request $request, array $params): Response
    {
        return (new Response())->json([
            'themes' => array_values($this->catalogue()),
        ]);
    }

    /**
     * `GET /api/v1/themes/{id}` — one theme.
     *
     * @param Request $request The HTTP request (unused).
     * @param array<string, string> $params Route parameters including `id`.
     *
     * @return Response `200` with `{"theme": { ... }}`, or `404` with
     *         `{"error": "Theme not found"}` — the same error shape every other
     *         "unknown id" in this API surface uses.
     *
     * @api_endpoint GET /api/v1/themes/{id}
     *
     * @since 0.44.0
     */
    public function show(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $catalogue = $this->catalogue();

        if (!isset($catalogue[$id])) {
            return (new Response())->status(404)->json(['error' => 'Theme not found']);
        }

        return (new Response())->json(['theme' => $catalogue[$id]]);
    }

    /**
     * Built-ins then plugin themes, keyed by id, already in wire shape.
     *
     * Order is deterministic so a client can render the picker without sorting
     * and a test can assert on it: built-ins first in
     * {@see BuiltInThemes::IDS} (i.e. colors.css declaration) order, then plugin
     * themes sorted by id. Registry insertion order is NOT used — it depends on
     * plugin enable order, which is not stable across workers.
     *
     * The `+` union keeps the left operand on a key clash, so a built-in can
     * never be shadowed. That is defence in depth rather than a live branch:
     * {@see \Phlix\Theming\ThemeTokenValidator::validate()} already refuses a
     * plugin payload whose id is one of {@see BuiltInThemes::IDS}, so the clash
     * is unreachable through the plugin path today.
     *
     * @return array<string, array<string, mixed>> id => wire shape.
     */
    private function catalogue(): array
    {
        $builtIns = [];
        foreach (BuiltInThemes::all() as $id => $theme) {
            $builtIns[$id] = $this->shape($theme, true);
        }

        $plugin = [];
        foreach ($this->registry->all() as $id => $theme) {
            $plugin[$id] = $this->shape($theme, false);
        }
        ksort($plugin);

        return $builtIns + $plugin;
    }

    /**
     * One theme in wire shape.
     *
     * @param TokenTheme $theme   The validated theme.
     * @param bool       $builtIn Whether it is host-shipped.
     *
     * @return array<string, mixed>
     */
    private function shape(TokenTheme $theme, bool $builtIn): array
    {
        return $theme->toArray() + ['builtIn' => $builtIn];
    }
}
