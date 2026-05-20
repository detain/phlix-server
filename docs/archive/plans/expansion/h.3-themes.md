# Step H.3 — Custom CSS / themes

**Phase:** H (Smart Features)
**Step:** H.3
**Depends on:** A.5
**Review:** Yes — see `h.3-themes-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement a `ui-theme` plugin type: a registry that ships built-in
themes (Dark, Light, AMOLED Black, High Contrast) and accepts
third-party `ui-theme` plugin bundles that inject CSS and optionally JS
into every WebPortal page. The plugin admin UI (A.5) gains a Themes
tab showing installed themes with a live-preview iframe and a one-click
Activate button. Active theme is stored per-user in `user_settings`.

## 2. Context (what already exists)

- `src/Plugins/PluginLoader.php` (A.4) — install/enable/disable/uninstall.
- `src/Plugins/Manifest.php` (A.3) — parsed plugin manifest.
- `src/Plugins/InstalledPlugin.php` (A.4) — plugin DTO.
- `src/Server/WebPortal/WebPortalRouter.php` — web portal routing.
- `src/Server/WebPortal/PageRenderer.php` — Smarty page renderer.
- `src/Auth/UserProfileManager.php` — per-user settings.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase H table — H.3 is the themes step.
- `PHLEX_EXPANSION_PLAN.md` §5 Plugin types table — `ui-theme` is
  listed as a plugin type.

Existing patterns to follow:

- `config/server.php`, `config/database.php` — flat PHP config files
  returning arrays.
- `config/music_providers.php` (G.1) — per-provider config.
- `src/Server/WebPortal/WebPortalRouter.php` — route registration for
  portal pages.
- `public/assets/css/` — existing asset directory.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Theming/ThemeRegistry.php` — central registry of available themes:

  ```php
  class ThemeRegistry
  {
      /** @var Theme[] */
      private array $themes = [];

      public function __construct(
          private readonly Connection $db,
          private readonly string $themesDir, // var/themes/
      ) {}

      public function registerBuiltIn(Theme $theme): void {}
      public function registerFromPlugin(InstalledPlugin $plugin): void {}
      public function getTheme(string $id): ?Theme {}
      /** @return Theme[] */
      public function getAllThemes(): array {}
      public function getActiveThemeForUser(string $userId): Theme {}
      public function setActiveThemeForUser(string $userId, string $themeId): void {}
  }
  ```

- `src/Theming/Theme.php` — readonly theme descriptor:

  ```php
  class Theme
  {
      public function __construct(
          public readonly string $id,
          public readonly string $name,
          public readonly string $type,        // 'builtin' | 'ui-theme-plugin'
          public readonly string $cssUrl,      // absolute URL or /assets path
          public readonly ?string $jsUrl,      // optional JS bundle
          public readonly ?string $thumbnailUrl, // preview image
          public readonly string $version,
          public readonly ?string $pluginName,  // null for built-in
          public readonly bool $dark,            // UI hint for client
      ) {}
  }
  ```

- `src/Theming/ThemeMiddleware.php` — Workerman HTTP middleware that
  injects the active theme's `<link rel="stylesheet">` and optionally
  `<script>` into the WebPortal response before it is sent:

  ```php
  class ThemeMiddleware
  {
      public function __construct(
          private readonly ThemeRegistry $registry,
          private readonly UserProfileManager $profiles,
      ) {}

      public function onHttpRequest(Request $request, callable $next): Response {}
      // Reads X-Phlex-User-Id header (set by auth middleware);
      // injects theme tags into the Response body.
  }
  ```

- `src/Server/WebPortal/ThemePreviewController.php` — renders a live
  preview of a theme in an iframe sandbox:

  ```
  GET /portal/theme-preview?id={themeId}
  ```

- `config/themes.php` — default config for built-in themes:

  ```php
  return [
      'builtin' => [
          'dark' => [
              'id'    => 'phlex-dark',
              'name'  => 'Phlex Dark',
              'css'   => '/assets/css/themes/phlex-dark.css',
              'js'    => null,
              'thumb' => '/assets/images/themes/phlex-dark.png',
              'dark'  => true,
          ],
          'light' => [...],
          'amoled' => [...],
          'contrast' => [...],
      ],
      'user_override' => [], // plugin themes merged in at runtime
  ];
  ```

- `public/assets/css/themes/phlex-dark.css` — Dark theme base CSS.
- `public/assets/css/themes/phlex-light.css` — Light theme base CSS.
- `public/assets/css/themes/phlex-amoled.css` — AMOLED Black theme.
- `public/assets/css/themes/phlex-contrast.css` — High Contrast theme.
- `public/assets/images/themes/phlex-dark.png` — thumbnail (1:2 ratio).
- `public/assets/images/themes/phlex-light.png`, etc.

- `var/themes/` — runtime directory for extracted plugin themes
  (gitignored; same pattern as `var/plugins/`).

- `migrations/006_user_theme_settings.sql` — add `active_theme_id` to
  `user_profiles`:

  ```sql
  ALTER TABLE user_profiles
    ADD COLUMN active_theme_id VARCHAR(64) NULL AFTER max_profiles;
  ```

- `tests/Unit/Theming/ThemeRegistryTest.php`
- `tests/Unit/Theming/ThemeMiddlewareTest.php`

#### Documentation

- `docs/developers/ui-themes.md` — how to build a `ui-theme` plugin:
  required manifest fields, CSS structure, JS bundle, screenshot
  thumbnail requirements.

### Modify

- `src/Server/WebPortal/WebPortalRouter.php` — register
  `ThemePreviewController` route.
- `src/Server/Core/Application.php` — register `ThemeMiddleware` in
  the HTTP pipeline after auth.
- `src/Auth/UserProfileManager.php` — add
  `getActiveThemeId()` / `setActiveThemeId()`.
- `public/templates/layouts/base.tpl` — add a `{$theme_css|raw}`
  placeholder in `<head>`; add `{$theme_js|raw}` before
  `</body>`.
- `.gitignore` — add `var/themes/`.
- `CHANGELOG.md` — `Added: ui-theme plugin type + 4 built-in themes
  (dark, light, AMOLED, high-contrast). ThemeMiddleware injects CSS/JS
  into WebPortal responses. Per-user theme preference stored in
  user_profiles. (H.3)`

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b h.3-themes`.
2. **Config.** Write `config/themes.php` with four built-in themes.
3. **Theme entity + registry.** `Theme` + `ThemeRegistry`. Built-in
   themes registered at construction; plugin themes called via
   `registerFromPlugin()` during A.4/A.5 bootstrap.
4. **Middleware.** `ThemeMiddleware` reads `X-Phlex-User-Id` (set by auth
   middleware earlier in the pipeline), looks up the user's active theme
   from `user_profiles`, and injects `<link>` / `<script>` tags into the
   response HTML body using a simple string replacement on the
   `base.tpl` placeholders.
5. **Preview controller.** `ThemePreviewController` renders a standalone
   preview page (iframe sandbox) showing the theme applied to a standard
   page layout. Used by the Themes tab in the admin UI.
6. **User settings.** Extend `user_profiles` via migration; add
   getter/setter to `UserProfileManager`.
7. **Built-in CSS.** Write four practical themes (not just color swaps —
   also adjust border-radius, spacing, shadows for a coherent look).
8. **Tests.** Unit tests per §5.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

**`ui-theme` plugin manifest additions:**

```json
{
  "type": "ui-theme",
  "entry": "Phlex\\Themes\\MyTheme\\Theme",
  "theme": {
    "id": "my-custom-theme",
    "name": "My Custom Theme",
    "css": "dist/theme.css",
    "js": "dist/theme.js",
    "thumbnail": "screenshots/preview.png",
    "dark": true
  }
}
```

The entry class implements a marker interface
`Phlex\Theming\ThemePluginInterface` (empty) just to be discoverable;
the `ThemeRegistry::registerFromPlugin()` path reads `manifest.theme`
directly without instantiating the entry class.

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

`ThemeRegistryTest`:
1. `test_register_built_in_adds_to_list`
2. `test_get_theme_returns_correct_theme`
3. `test_get_theme_returns_null_for_unknown`
4. `test_get_all_themes_returns_all_registered`
5. `test_get_active_theme_for_user_returns_default_when_not_set`
6. `test_set_active_theme_for_user_persists_to_db`
7. `test_register_from_plugin_extracts_theme_from_manifest`

`ThemeMiddlewareTest`:
8. `test_injects_css_link_into_html_response`
9. `test_injects_both_css_and_js_when_js_present`
10. `test_does_not_modify_non_html_response`
11. `test_uses_default_theme_when_user_not_authenticated`

**Coverage target:** `ThemeRegistry` ≥ 85 %,
`ThemeMiddleware` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"A configurable env var or config/*.php key"** →
  `docs/reference/config-files.md` adds `config/themes.php` keys.
- **"The plugin API"** → flesh out `docs/plugins/developer-guide.md`
  with `ui-theme` type section.
- **"Anything"** → `docs/developers/ui-themes.md` (new) is the main
  doc for this step.
- **"User-visible behavior change"** → CHANGELOG entry.
- **"New public class/method"** → PHPDoc with `@since 0.14.0`.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `config/themes.php` defines 4 built-in themes (dark, light,
      amoled, contrast).
- [ ] `Theme` entity with all fields.
- [ ] `ThemeRegistry::registerBuiltIn()` adds theme to registry.
- [ ] `ThemeRegistry::registerFromPlugin()` extracts theme from plugin
      manifest without instantiating entry class.
- [ ] `ThemeRegistry::getActiveThemeForUser()` falls back to default
      `phlex-dark` when no preference set.
- [ ] `ThemeMiddleware` injects `<link>` into HTML responses; does not
      modify non-HTML responses.
- [ ] `migrations/006_user_theme_settings.sql` runs cleanly.
- [ ] `UserProfileManager` has `getActiveThemeId()` /
      `setActiveThemeId()` methods.
- [ ] `base.tpl` has `{$theme_css|raw}` and `{$theme_js|raw}` placeholders.
- [ ] `ThemePreviewController` serves live preview at
      `/portal/theme-preview`.
- [ ] `var/themes/` is gitignored.
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests.
- [ ] Coverage of `ThemeRegistry` ≥ 85 %, `ThemeMiddleware` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/ui-themes.md` written with plugin author guide.
- [ ] `docs/plugins/developer-guide.md` updated with `ui-theme` section.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b h.3-themes

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ThemeRegistry|ThemeMiddleware'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step H.3: custom CSS / themes with ui-theme plugin type"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step H.3: custom CSS / themes" \
  --body  "Adds ThemeRegistry, Theme, ThemeMiddleware, ThemePreviewController, 4 built-in themes (dark/light/amoled/contrast), migration 006_user_theme_settings.sql. Themes shipped as ui-theme plugin type. ThemeMiddleware injects CSS/JS into WebPortal responses. Per-user theme stored in user_profiles. Part of Phase H (Step H.3 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'h.3-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `h.3-themes-review.md`.

Non-obvious points:
- The middleware operates on the HTML string after Smarty rendering but
  before the Response is sent to the client — it does a simple
  `str_replace()` on the `{$theme_css|raw}` and `{$theme_js|raw}`
  placeholders that MUST be present in `base.tpl`.
- `ui-theme` plugins do NOT implement `LifecycleInterface`; they are
  purely declarative (CSS/JS bundle + manifest) and require no runtime
  event subscription.
- The `$themesDir` in `ThemeRegistry` (`var/themes/`) follows the same
  sandboxed-directory pattern as `var/plugins/`.
