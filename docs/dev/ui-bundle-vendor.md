# Updating the vendored `@phlix/ui` bundle — phlix-server

This document describes how to update `@phlix/ui` and rebuild the Vite assets on **phlix-server**.

> This is a runbook template: hostnames and users below are placeholders —
> substitute your deployment's real values (and prefer a non-root deploy user
> with sudo for the few service actions). The paths shown are the installer's
> defaults (`/var/www/phlix`, matching the README's documented layout).

## Two-level architecture

`@phlix/ui` is published as an npm package. `phlix-server`'s `web-ui/` imports it and runs `vite build` to produce **Vite code-split chunks** in `public/assets/app/assets/`. The player page loads those chunks at runtime — not `node_modules/@phlix/ui/dist/player.js` directly.

```
@phlix/ui npm package (dist/player.js, dist/phlix-ui.js, ...)
        ↓  npm install / npm pack
phlix-server/web-ui/node_modules/@phlix/ui/
        ↓  vite build
phlix-server/public/assets/app/assets/    ←  what the browser actually loads
```

## Prerequisites

- SSH access as the deploy user on the live host (`deploy@example.net` below; `sudo` is needed only for the service restart)
- `npm` installed on the build machine (local or server)
- `rsync` for syncing built assets to the live server

## Deploy steps

### Option A — Build locally, sync to server

**1. Build `@phlix/ui` (if you made source changes):**

```bash
cd /path/to/phlix-ui
npm run build
git add dist/
git commit -m "chore(phlix-ui): bump to vX.Y.Z"
git tag vX.Y.Z
git push && git push --tags
```

**2. Wait for npm package to be published** (GitHub tarball via `package.json` reference), or if using a local path, update `web-ui/package.json` to point to the new version.

**3. Fetch the new dependency and build server web-ui locally:**

```bash
cd /path/to/phlix-server/web-ui
# Point web-ui/package.json at the new version FIRST, then INSTALL it —
# `npm run build` never fetches anything on its own; without this step the
# build silently reuses the stale node_modules copy and publishes old chunks.
npm ci            # npm install if package.json/package-lock.json changed
# Verify @phlix/ui is the right version (fix the version above if this prints the old one)
cat node_modules/@phlix/ui/package.json | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("version"))'
npm run build
```

**4. Sync built assets to the live server:**

```bash
rsync -av --delete \
  /path/to/phlix-server/public/assets/app/assets/ \
  deploy@example.net:/var/www/phlix/public/assets/app/assets/
```

**5. Verify on live server:**

```bash
# Check the index chunk has changed
ssh deploy@example.net "ls -la /var/www/phlix/public/assets/app/assets/index-*.js"

# Verify toggleQuality is in a chunk
ssh deploy@example.net "grep -rl 'toggleQuality' /var/www/phlix/public/assets/app/assets/"

# Test live site (hard refresh required — CDN/browser may cache old chunks).
# The assets directory is never LISTED by the server (no index route — a bare
# curl of it 404s by design); instead read the SPA shell's entry reference:
curl -s https://phlix.example.net/app/ | grep -o 'index-[A-Za-z0-9_-]*\.js' | head -2
# The hash printed must equal the NEW index-<hash>.js from the ls above.
```

---

### Option B — Build directly on the server

**1. SSH to the server:**

```bash
ssh deploy@example.net
```

**2. Pull latest code:**

```bash
cd /var/www/phlix
git fetch origin
git checkout master
git pull origin master
```

**3. Install dependencies and build the web-ui:**

```bash
cd /var/www/phlix/web-ui
npm ci    # fetches the @phlix/ui version pinned in package.json — required
          # after `git pull`; a bare `npm run build` reuses stale node_modules
npm run build
```

**4. Commit the new assets (recommended):**

```bash
cd /var/www/phlix
git add public/assets/app/
git commit -m "chore(server): rebuild web-ui assets"
git push
```

## What gets deployed

Only the `public/assets/app/assets/` Vite chunks are served to the browser. After a build you should see:

```
public/assets/app/assets/
  index-XXXXXXXX.js       ← main entry chunk (changes most often)
  PlayerPage-XXXXXXXX.js   ← player page (contains Player + QualityMenu)
  Select-XXXXXXXX.js      ← shared Select component (used by menus)
  runtime-core.esm-*.js
  runtime-dom.esm-*.js
  hls-*.js
  ...other chunks...
```

## Verifying the update

**1. Check chunk hashes changed:**

```bash
ssh deploy@example.net "ls -la /var/www/phlix/public/assets/app/assets/index-*.js"
```

**2. Check the new chunk contains the fix:**

```bash
ssh deploy@example.net "grep -c 'toggleQuality' /var/www/phlix/public/assets/app/assets/*.js"
```

**3. Verify Q key shortcut works in browser:**
- Open DevTools (F12) → **Network** tab → check "Disable cache" → reload the player page
- Press **Q** during playback
- You should see the Quality menu open (for transcoded streams) or a "Direct Stream" toast (for direct streams)

## Troubleshooting

### "toggleQuality not found" after deploy
The build may have produced chunks with **different names** (Vite's bundler uses content-based hashes). The old chunks may still be cached by the CDN. Ensure the HTML references the new chunk names and do a hard refresh (`Ctrl+Shift+R`).

### TDZ / "can't access lexical declaration before initialization" error
The error typically appears in `Select.vue` when a `const` variable is used in a function that runs during setup (e.g. a `{ immediate: true }` watch) before the variable's declaration line is evaluated. Fix: move the `const` declaration **before** the code that triggers it. Example — if `selectedIndex` is used in `openList()` called by an immediate watch, `selectedIndex` must be declared **before** the watch.

### Hub restarts

This is the **server** runbook. Restarting the hub is the hub's own operation —
see the hub's `@phlix/ui` rebuild runbook
([phlix-hub `docs/ui-asset-rebuild.md`](https://github.com/detain/phlix-hub/blob/master/docs/ui-asset-rebuild.md)),
which covers the service-manager restart, `HUB_JWT_SECRET`, and the hub's
restart troubleshooting. A vendored-`@phlix/ui` deploy on the server never
restarts the hub; if a deploy script appears to need one, it is the hub's
cascade lane, not this runbook.

## Package-lock integrity hash

When you update `@phlix/ui` to a new version in `package.json`, the `integrity`
field of that package's node in `web-ui/package-lock.json` must describe the new
tarball. npm's format is `sha512-<BASE64 digest>` — **not** hex — so the lock
value must be the base64 of the raw SHA-512 digest, prefixed with `sha512-`.
Compute it from the live GitHub tarball:

```bash
# For @phlix/ui v0.Y.Z
curl -sL "https://github.com/detain/phlix-ui/archive/refs/tags/v0.Y.Z.tar.gz" \
  | openssl dgst -sha512 -binary | openssl base64 -A
```

Store the output as `sha512-<output>` in the `integrity` field before building.
(The previous recipe printed a bare hex digest — a value npm rejects outright,
so hand-editing with it never installed.)

Prefer the ordinary route when you can run npm: bump the version in
`web-ui/package.json` and let `npm ci`/`npm install` rewrite
`package-lock.json` (both the `resolved` URL and the `integrity`) itself —
that is the estate's vendoring idiom and cannot produce a wrong hash. On this
estate run it with npm 11 and `NPM_CONFIG_USERCONFIG=/dev/null`; hand-edit the
lock only when a real install is unavailable (the pin-waves do this, and verify
their result against the recipe above).
