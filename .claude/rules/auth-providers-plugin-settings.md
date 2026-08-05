---
paths:
  - "src/Auth/AuthProviderBootstrapper.php"
  - "src/Auth/AuthProviderRegistry.php"
  - "src/Plugins/OAuth2/**"
  - "src/Plugins/Github/**"
  - "src/Plugins/Oidc/**"
  - "src/Plugins/Ldap/**"
  - "src/Plugins/PluginDbSettings.php"
  - "src/Plugins/Repository/PluginSettingsRepository.php"
  - "src/Plugins/Repository/PluginSettingsStore.php"
  - "src/Server/Http/AuthProviderRouteRegistrar.php"
  - "migrations/093_plugin_settings.sql"
---

# Bundled Auth Providers (OIDC / LDAP / GitHub)

- **Enable-state lives in server settings, not the plugin catalog.** `AuthProviderBootstrapper` reads/writes `auth.oidc.enabled`, `auth.ldap.enabled`, and `auth.github.enabled` through `SettingsRepository`, then `registerEnabledProviders()` attaches each enabled + configured provider to the per-worker `AuthProviderRegistry`. That registry is plain in-memory state — re-register on every worker start; a live admin enable/disable also mutates the current worker's registry. Never seed bundled providers into the catalog `plugins` table (it would double-register them — see the header comment in `migrations/093_plugin_settings.sql`).
- **Provider config is DB-backed.** One row per plugin in `plugin_settings` (`plugin_name` PK, `settings_json`). Depend on the `PluginSettingsStore` interface, not the concrete `PluginSettingsRepository`. Plugins consume it via the `PluginDbSettings` trait, which does a one-time lazy import of a legacy `settings.json` when no row exists yet and falls back to the file store when no store is injected (unit tests / no DB).
- **OAuth2 state is DB-backed and browser-bound.** `DbOAuth2StateStore` (plain OAuth2; the `provider` column tags the family) and `DbOidcStateStore` both use the `oauth_state_store` table with a 600 s TTL — never `$_SESSION`. `StateCorrelation` additionally binds `state` to the browser that started the flow: a 32-byte secret in an HttpOnly + Secure + SameSite=Lax cookie (`phlix_oauth_github` / `phlix_oauth_oidc`), only its SHA-256 persisted in the state context, checked with `hash_equals()` before any token exchange, account link, or session-cookie mint.
- **`redirect_uri` must be absolute.** Build it with `Phlix\Plugins\OAuth2\CallbackUrl`: the configured `redirect_uri` setting first, else `<scheme>://<Host><callback path>` only when the request `Host` — **`host:port`, the port is part of the origin** — matches the configured public authority (`PHLIX_DOMAIN`; a default `:443`/`:80` for the scheme is normalised away on both sides), else `null` → `503 callback_url_not_configured`. Never send a path-only callback and never derive from an unallowlisted `Host`. With no `PHLIX_DOMAIN` configured NOTHING is derived (fail closed); the 503 body stays generic (the route is unauthenticated) and the operator remedy goes to the AUTH log.
- New provider families extend `AbstractOAuth2Provider` (see `GithubOAuthProvider`) and implement `Phlix\Shared\Auth\ProviderInterface`; their routes are wired in `AuthProviderRouteRegistrar` (`/auth/{provider}/authorize`, `/auth/{provider}/callback`).
