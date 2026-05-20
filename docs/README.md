# Documentation

This directory contains documentation that is **server-internal** — material that lives next to the code because it's mainly for contributors hacking on `phlix-server` itself.

End-user, hub-admin, client, plugin, and reference documentation lives in the dedicated [`phlix-docs`](https://github.com/detain/phlix-docs) repository and is published at <https://detain.github.io/phlix-docs>.

## What's here

### Developer guides (server-internal)
- [Developer Documentation](dev/DEVELOPER.md) — server architecture, setup, conventions
- [Technical Specification](dev/PHLIX_MEDIA_SERVER_TECHNICAL_SPEC.md) — detailed spec

### Brand
- [`brand/`](brand/) — Phlix brand identity kits, logo concepts, SVG/UI prompts. Sourced by [`phlix-website`](https://github.com/detain/phlix-website).

### Runtime assets
- [`plugins/manifest.schema.json`](plugins/manifest.schema.json) — JSON-Schema loaded at runtime by `src/Plugins/Manifest/ManifestSchema.php` via `SCHEMA_RELATIVE_PATH`. Kept here, not in phlix-docs, because the server reads it from disk. The phlix-docs copy is published documentation only.

### Plugins, API, environment, install flows
Now live in [`phlix-docs`](https://github.com/detain/phlix-docs):

- [Install from catalog](https://detain.github.io/phlix-docs/plugins/install-from-catalog)
- [Install from URL](https://detain.github.io/phlix-docs/plugins/install-from-url)
- [Plugin developer guide](https://detain.github.io/phlix-docs/plugins/developer-guide), [manifest](https://detain.github.io/phlix-docs/plugins/manifest), [plugin catalog](https://detain.github.io/phlix-docs/plugins/plugin-catalog), [trusted-plugin list](https://detain.github.io/phlix-docs/plugins/trusted-plugin-list), [auth-provider plugins](https://detain.github.io/phlix-docs/plugins/auth-providers)
- [API reference](https://detain.github.io/phlix-docs/reference/api)
- [Environment variables](https://detain.github.io/phlix-docs/reference/env-vars)
- [CLI](https://detain.github.io/phlix-docs/reference/cli), [skip-button protocol](https://detain.github.io/phlix-docs/reference/skip-button-protocol), [webauthn API](https://detain.github.io/phlix-docs/reference/api/auth-webauthn), [admin-plugins API](https://detain.github.io/phlix-docs/reference/api/admin-plugins), [hub JWKS schema](https://detain.github.io/phlix-docs/reference/api/hub-jwks)

### Archived material
- [`archive/`](archive/) — old planning documents and superseded material

## Moved to phlix-docs

The following sections previously lived under `phlix-server/docs/` and are now exclusively in [`phlix-docs`](https://github.com/detain/phlix-docs):

- [`advanced/`](https://detain.github.io/phlix-docs/advanced/) — Live TV comskip, hardware transcoding, backup/restore, remote access without hub
- [`clients/`](https://detain.github.io/phlix-docs/clients/) — Roku, Tizen, Windows, mobile, web
- [`dev/architecture-server`](https://detain.github.io/phlix-docs/dev/architecture-server), [`dev/event-reference`](https://detain.github.io/phlix-docs/dev/event-reference), [`dev/pairing-protocol`](https://detain.github.io/phlix-docs/dev/pairing-protocol), [`dev/plugin-sdk`](https://detain.github.io/phlix-docs/dev/plugin-sdk), [`dev/relay-protocol`](https://detain.github.io/phlix-docs/dev/relay-protocol), [`dev/tls-certificates`](https://detain.github.io/phlix-docs/dev/tls-certificates)
- [`developers/`](https://detain.github.io/phlix-docs/developers/) — chromaprint, collections, discovery, DVB-T, DVR, hardware acceleration, HDHomeRun, intro/outro detection, IPTV, Last.fm plugin, live relay, music providers, schedules-direct, scrobbler plugins, smart playlists, streaming protocols, subtitle processing, theme media, trailers, UI themes
- [`hub-admin/network`](https://detain.github.io/phlix-docs/hub-admin/network)
- [`hub/remote-access`](https://detain.github.io/phlix-docs/hub/remote-access)
- [`libraries/`](https://detain.github.io/phlix-docs/libraries/) — audiobooks, books, music, photos (and tv-shows / movies)
- [`plugins/`](https://detain.github.io/phlix-docs/plugins/) — developer-guide, manifest, manifest schema, auth-providers, plugin-catalog, trusted-plugin-list
- [`reference/`](https://detain.github.io/phlix-docs/reference/) — CLI, skip-button-protocol, API/admin-plugins, API/auth-webauthn, API/hub-jwks
- [`security/passkeys`](https://detain.github.io/phlix-docs/security/passkeys)

If you find a topic missing from `phlix-docs` that used to live here, please open an issue at <https://github.com/detain/phlix-docs/issues>.
