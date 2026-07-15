# phlix-ui Deployment — phlix-server

This document describes how to update `@phlix/ui` and rebuild the Vite assets on **phlix-server**.

## Two-Level Architecture

`@phlix/ui` is published as an npm package. `phlix-server`'s `web-ui/` imports it and runs `vite build` to produce **Vite code-split chunks** in `public/assets/app/assets/`. The player page loads those chunks at runtime — not `node_modules/@phlix/ui/dist/player.js` directly.

```
@phlix/ui npm package (dist/player.js, dist/phlix-ui.js, ...)
        ↓  npm install / npm pack
phlix-server/web-ui/node_modules/@phlix/ui/
        ↓  vite build
phlix-server/public/assets/app/assets/    ←  what the browser actually loads
```

## Prerequisites

- SSH access to `root@153.75.226.242`
- `npm` installed on the build machine (local or server)
- `rsync` for syncing built assets to the live server

## Deploy Steps

### Option A — Build locally, sync to server

**1. Build `@phlix/ui` (if you made source changes):**

```bash
cd /home/sites/phlix/phlix-ui
npm run build
git add dist/
git commit -m "chore(phlix-ui): bump to vX.Y.Z"
git tag vX.Y.Z
git push && git push --tags
```

**2. Wait for npm package to be published** (GitHub tarball via `package.json` reference), or if using a local path, update `web-ui/package.json` to point to the new version.

**3. Build server web-ui locally:**

```bash
cd /home/sites/phlix/phlix-server/web-ui
# Verify @phlix/ui is the right version
cat node_modules/@phlix/ui/package.json | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("version"))'
npm run build
```

**4. Sync built assets to the live server:**

```bash
rsync -av --delete \
  /home/sites/phlix/phlix-server/public/assets/app/assets/ \
  root@153.75.226.242:/var/www/phlix/public/assets/app/assets/
```

**5. Verify on live server:**

```bash
# Check the index chunk has changed
ssh root@153.75.226.242 "ls -la /var/www/phlix/public/assets/app/assets/index-*.js"

# Verify toggleQuality is in a chunk
ssh root@153.75.226.242 "grep -rl 'toggleQuality' /var/www/phlix/public/assets/app/assets/"

# Test live site (hard refresh required — CDN/browser may cache old chunks)
curl -s https://intertainer.phlix.interserver.net/assets/app/assets/ | head -5
```

---

### Option B — Build directly on the server

**1. SSH to the server:**

```bash
ssh root@153.75.226.242
```

**2. Pull latest code:**

```bash
cd /var/www/phlix
git fetch origin
git checkout master
git pull origin master
```

**3. Build the web-ui:**

```bash
cd /var/www/phlix/web-ui
npm run build
```

**4. Commit the new assets (recommended):**

```bash
cd /var/www/phlix
git add public/assets/app/
git commit -m "chore(server): rebuild web-ui assets"
git push
```

## What Gets Deployed

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

## Verifying the Update

**1. Check chunk hashes changed:**

```bash
ssh root@153.75.226.242 "ls -la /var/www/phlix/public/assets/app/assets/index-*.js"
```

**2. Check the new chunk contains the fix:**

```bash
ssh root@153.75.226.242 "grep -c 'toggleQuality' /var/www/phlix/public/assets/app/assets/*.js"
```

**3. Verify Q key shortcut works in browser:**
- Open DevTools (F12) → **Network** tab → check "Disable cache" → reload the player page
- Press **Q** during playback
- You should see the Quality menu open (for transcoded streams) or a "Direct Stream" toast (for direct streams)

## Troubleshooting

### "toggleQuality not found" after deploy
The build may have produced chunks with **different names** (Rolldown uses content-based hashes). The old chunks may still be cached by the CDN. Ensure the HTML references the new chunk names and do a hard refresh (`Ctrl+Shift+R`).

### TDZ / "can't access lexical declaration before initialization" error
The error typically appears in `Select.vue` when a `const` variable is used in a function that runs during setup (e.g., a `{ immediate: true }` watch) before the variable's declaration line is evaluated. Fix: move the `const` declaration **before** the code that triggers it. Example — if `selectedIndex` is used in `openList()` called by an immediate watch, `selectedIndex` must be declared **before** the watch.

### Hub going down during restart
The hub requires `HUB_JWT_SECRET` env var from `/etc/phlix-hub.env`. When restarting via `php start.php`, source the env first:

```bash
source /etc/phlix-hub.env
cd /opt/phlix-hub && php start.php restart
```

Or kill and restart manually:
```bash
kill <hub_pid>
source /etc/phlix-hub.env
cd /opt/phlix-hub && nohup php start.php start > /opt/phlix-hub/.logs/startup.log 2>&1 &
```

## Package-lock Integrity Hash

When updating `@phlix/ui` to a new version in `package.json`, you must also update the `integrity` SHA512 hash in `package-lock.json`. Compute it from the live GitHub tarball:

```bash
# For @phlix/ui v0.Y.Z
curl -sL "https://github.com/detain/phlix-ui/archive/refs/tags/v0.Y.Z.tar.gz" | openssl dgst -sha512 | awk '{print $NF}'
```

Replace the `integrity` value in `web-ui/package-lock.json` before building.
