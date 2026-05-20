# Phlix Plugin Catalog

> **Status:** This document lists officially maintained and community-
> contributed plugins for the Phlix Media Server. Plugin installation
> is documented in [install-from-catalog.md](./install-from-catalog.md)
> and [install-from-url.md](./install-from-url.md).

## Official Plugins (Maintained by Phlix)

### phlix-plugin-oidc

**Type:** `auth-provider` | **Version:** 1.0.0

OpenID Connect / OAuth2 authentication provider plugin. Adds SSO login
via any OIDC-compliant identity provider (Keycloak, Authelia, Authentik,
Google Workspace, GitHub OAuth).

**Repository:** `detain/phlix-plugin-oidc` (bundled in `src/Plugins/Oidc/`)

**Features:**
- Authorization Code flow with PKCE support
- RS256/RS384/RS512 signature validation
- Discovery document caching (24h)
- Automatic user provisioning on first login
- Account linking for existing users
- Admin UI for provider configuration

**Manifest fields:**
```json
{
  "name": "phlix-plugin-oidc",
  "version": "1.0.0",
  "phlix_min_server_version": "0.11.0",
  "type": "auth-provider",
  "entry": "Phlix\\Plugins\\Oidc\\Plugin",
  "settings": {
    "provider_url": { "type": "string", "required": true, "secret": false },
    "client_id": { "type": "string", "required": true, "secret": false },
    "client_secret": { "type": "string", "required": true, "secret": true },
    "scopes": { "type": "string", "required": false, "default": "openid profile email" }
  }
}
```

**Configuration:**
1. Install the plugin from the admin UI
2. Navigate to **Admin → Auth Providers → OIDC**
3. Enter your OIDC provider's base URL, client ID, and client secret
4. Register `https://your-phlix-server/auth/oidc/callback` as a redirect URI in your OIDC provider
5. Save settings and enable the provider

**Supported providers:**
- Keycloak (any version with OIDC support)
- Authelia
- Authentik
- Google Workspace / Gmail OAuth
- GitHub OAuth (limited — not a true OIDC provider)
- Any OIDC-compliant IdP

## Community Plugins

Community plugins are not officially supported by Phlix. Use at
your own risk.

| Plugin | Type | Description |
|--------|------|-------------|
| _(none yet)_ | | |

## Plugin Types

| Type | Description |
|------|-------------|
| `metadata-provider` | Provides movie/TV show metadata (TMDB, TVDB, etc.) |
| `auth-provider` | External authentication (OIDC, LDAP, SAML, passkeys) |
| `scrobbler` | Scrobbles watched content to third-party services |
| `transcoder` | Alternative transcoding pipelines |
| `storage` | Cloud storage backends |
| `ui-theme` | Web portal visual themes |
| `dlna` | DLNA/Digital Media Server features |
| `syncplay` | SyncPlay replacement for synchronized viewing |
| `livetv` | Live TV / DVR functionality |
| `analytics` | Usage analytics and reporting |
| `admin-plugin` | Admin UI enhancements |

## Plugin Manifest Reference

Full `plugin.json` schema is documented in [manifest.md](./manifest.md).

## Security

All plugins run with the same privileges as the Phlix server process.
Only install plugins from trusted sources. Review the plugin's code
before installing, especially if it requires network access or handles
sensitive data.

Plugins must be signed before they can be installed from the catalog.
See [trusted-plugin-list.md](./trusted-plugin-list.md) for the trust
model and how to add trusted keys.
