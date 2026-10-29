# phlix-plugin-sample-theme

A complete, working **`ui-theme`** plugin — the reference for shipping a theme
to the Phlix SPA. Copy this directory, rename it, change the token values.

```
examples/plugins/phlix-plugin-sample-theme/
├── composer.json              # PSR-4 autoload for the entry class
├── plugin.json                # manifest: type "ui-theme", entry FQCN
└── src/SampleThemePlugin.php  # LifecycleInterface + ThemeSourceInterface
```

## Why it lives here and not in `tests/`

It is exercised by two tests
(`tests/Unit/Plugins/SampleThemePluginTest.php` and
`tests/Integration/Plugins/SampleThemeLifecycleTest.php`) but it is **not a
fixture**: it is a shippable plugin, installable from this exact directory
through the production `PluginLoader` — `PluginLoader::installFromDirectory()`,
which is what the lifecycle test drives — and it is the thing a plugin author
is pointed at. Note the `plugin:install` CLI takes an archive URL or a catalog
repo URL, never a bare directory path: pack it first. Putting it under `tests/`
would
say the opposite. The tests reach into `examples/` rather than the other way
round, so the sample can never be a test double that drifts from a real plugin:
the integration test installs and enables *this exact directory* through the
production `PluginLoader`.

## How registration works

There is no manifest `theme` key and no `onEnable()` wiring. The entry class
implements `Phlix\Theming\ThemeSourceInterface`, and
`PluginLoader::enable()` registers it off the `instanceof` — the same one-arm
capability pattern the metadata- and subtitle-source plugins use:

```php
final class SampleThemePlugin implements LifecycleInterface, ThemeSourceInterface
{
    public function themeSourceName(): string { return 'sample-theme'; }

    public function providedThemes(): array
    {
        return [[
            'id'      => 'sample-dusk',
            'name'    => 'Sample Dusk',
            'dark'    => true,
            'extends' => 'midnight',
            'tokens'  => ['--bg' => '#05060a', /* … */],
        ]];
    }
}
```

Disabling the plugin deregisters exactly the ids it contributed.

## The two rules a theme must obey

1. **Keys** must be on `Phlix\Theming\ThemeTokenAllowlist` — the 53 semantic
   colour tokens of `@phlix/tokens/src/css/colors.css`. Layout tokens (density,
   spacing, radius, shadow, motion) are **not** settable: a theme recolours the
   UI, it never moves it.
2. **Values** must be a hex colour, `rgb()/rgba()/hsl()/hsla()` over numeric
   arguments, a bare number, `transparent`, or `currentColor` — *in full*.
   `var(--x)`, `url(…)`, `;`, `}` and every other CSS construct are rejected by
   having no production in the grammar, not by a blocklist. Note the built-in
   themes are written with `var()` chains in CSS; a plugin ships **literals**.

A payload that breaks either rule fails the whole plugin enable. Registration is
all-or-nothing and never silent.

## What the two sample themes demonstrate

| Theme | `extends` | Point |
| --- | --- | --- |
| `sample-dusk` | `midnight` (built-in) | The base is applied by mechanism: the SPA sets `data-theme="midnight"` — whose real values then come from its bundled stylesheet — and layers these tokens over it with `el.style.setProperty()`. The server does know `midnight`'s map and publishes it in the list; the wire just never flattens chains — `tokens` is always the theme's own map. Status colours are intentionally not overridden, so they fall through from the base. |
| `sample-dusk-high-contrast` | `sample-dusk` (plugin) | A plugin→plugin chain: no stylesheet knows the base, so the SPA merges it from data. Only the LIST endpoint (`GET /api/v1/themes`) carries every link, which is why the SPA reads the list and never `/themes/{id}`. |

## Seeing it in the SPA

1. Install + enable the plugin.
2. `GET /api/v1/themes` (authenticated) now lists five themes: the three
   built-ins plus these two, each with `builtIn` and its own `tokens` map.
3. Settings → Appearance shows five swatches; picking one applies it live and
   caches the resolved token map so the next page load paints it with no flash.

## Licence

MIT, matching the rest of the Phlix plugin/interop surface.
