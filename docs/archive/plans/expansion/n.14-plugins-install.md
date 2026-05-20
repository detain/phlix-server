# Step N.14 — Plugin installation guides (catalog + URL)

**Phase:** N (End-User Documentation)
**Step:** N.14
**Depends on:** A.7 (plugin developer docs — already merged)
**Review:** No (doc-only step)
**Target repo:** phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write and polish the two end-user plugin installation guides:

- `docs/plugins/install-from-catalog.md` — browse and install from the
  in-product plugin catalog (web UI flow: Settings → Plugins → Browse
  Catalog → click Install).
- `docs/plugins/install-from-url.md` — already exists with content
  from A.5; N.14 polishes it to §7 layout standard (TL;DR, shell blocks,
  what-can-go-wrong with 3 failures, next-steps).

Both pages follow the `§7 layout`: TL;DR → shell install blocks →
what-can-go-wrong (3 failures) → next-steps.

## 2. Context (what already exists)

After A.7:

- `src/Plugins/PluginLoader.php` (A.4) — `installFromUrl()`,
  `enable()`, `disable()`, `uninstall()`, `listInstalled()`.
- `src/Plugins/Installer/HttpInstaller.php` (A.4) — downloads
  `plugin.json` (or stub with a `source` tarball URL), extracts,
  validates manifest, runs `composer install`.
- `src/Plugins/Manifest.php` (A.3) — `phlex_min_server_version` field;
  `Manifest::validate()` returns `ManifestValidationError[]`.
- Signature field (`sha256:<hex>`) on every signed plugin; trust
  allowlist at `docs/plugins/trusted-plugin-list.md`.
- `docs/plugins/plugin-catalog.md` — lists official and community plugins
  with type table.
- `docs/plugins/install-from-url.md` — **already exists** with A.5 content
  (full API table, security notes, reference-plugin walkthrough). Needs
  §7 layout polish.
- `docs/plugins/install-from-catalog.md` — **already exists** as a
  stub/roadmap page claiming "catalog not yet shipped". Needs to be
  rewritten as the live catalog install guide.

Plugin types covered in docs: `metadata-provider`, `auth-provider`,
`notifier`, `scrobbler`, `tuner`, `transcoder-hook`, `ui-theme`,
`library-type`, `subtitle-provider`, `arr-integration`, `analytics-sink`.

`docs/plugins/developer-guide.md` (A.7) already links both install pages.

## 3. Scope

### Modify

- `docs/plugins/install-from-url.md` — restructure to §7 layout:
  add TL;DR, expand what-can-go-wrong to 3 canonical failures, add
  next-steps links. Keep the existing API table and security notes.
- `docs/plugins/install-from-catalog.md` — replace the stub with a full
  end-user guide: TL;DR, browse-enable-install flow, plugin settings
  form, enable/disable toggle, signature verification note, 3 failure
  modes, next-steps.

### No source changes

N.14 is doc-only. No `src/` changes, no migrations, no tests.

## 4. Doc content outline

### `install-from-url.md` (polish existing → §7 layout)

#### TL;DR (≤ 5 lines)

One paragraph: what this page does (install a plugin from any public
HTTPS URL), the two prerequisites (admin account, plugin's `plugin.json`
URL), and the outcome (plugin lands disabled → flip toggle to enable).

#### 1. Prerequisites

- Admin account on the Phlex server (`users.is_admin = 1`).
- The plugin's public `plugin.json` URL (HTTPS; `http://` refused unless
  `PHLEX_PLUGINS_ALLOW_HTTP=1`).
- Optional: a signed plugin needs its author key in the trusted-key
  allowlist (`docs/plugins/trusted-plugin-list.md`).

#### 2. Install from the web UI

Step-by-step numbered list:

1. Browse to **Settings → Plugins** (or `/admin/plugins`).
2. Locate **Install from URL** panel.
3. Paste the plugin's `plugin.json` URL.
4. Click **Install**.
5. Wait for the server to download, validate, and stage the plugin.
6. Find the plugin in the table — it lands **disabled** by default.
7. Flip the toggle to enable it.

Screenshot placeholder: `[screenshot: admin/plugins table with
phlex-plugin-example row, toggle off]`

#### 3. Install from the command line

```bash
TOKEN="…your admin bearer token…"

# 1. Install from URL
curl -sS -X POST https://phlex.example.com/api/v1/admin/plugins/install \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"url": "https://example.com/my-plugin/plugin.json"}'

# 2. Enable
curl -sS -X POST https://phlex.example.com/api/v1/admin/plugins/my-plugin/enable \
     -H "Authorization: Bearer $TOKEN"

# 3. List installed plugins
curl -sS https://phlex.example.com/api/v1/admin/plugins \
     -H "Authorization: Bearer $TOKEN"

# 4. Disable
curl -sS -X POST https://phlex.example.com/api/v1/admin/plugins/my-plugin/disable \
     -H "Authorization: Bearer $TOKEN"

# 5. Uninstall
curl -sS -X DELETE https://phlex.example.com/api/v1/admin/plugins/my-plugin \
     -H "Authorization: Bearer $TOKEN"
```

#### 4. Reference plugin walkthrough

How to verify the install flow on a fresh server using the reference
plugin:

```
https://raw.githubusercontent.com/detain/phlex-plugin-example/main/plugin.json
```

Steps: paste → Install → toggle Enable → confirm in the plugins table.

#### 5. Plugin settings

After install, click **Settings** (wrench icon) next to any enabled
plugin to open its per-plugin settings form. Settings are persisted in
the `plugins.settings_json` column. Each plugin exposes its own fields
(api keys, endpoint URLs, etc.) as declared in its `plugin.json` `settings`
block.

#### What can go wrong

| Failure | Symptom | Cause | Fix |
|---------|---------|-------|-----|
| **Version incompatibility** | `422` / `plugin.install.failed` with `phlex_min_server_version` in `fields[]` | Running server is older than what the plugin requires | Upgrade phlex-server first, or choose a different plugin version |
| **Signature verification failure** | `422` / `plugin.signature.mismatch` | Downloaded tarball was corrupted in transit, or the plugin was tampered with | Re-download the plugin, or check with the plugin author that the signature is current |
| **Plugin requires restart** | Plugin listener never fires even after enable | Some plugins (e.g., `transcoder-hook`, `ui-theme`) register their hooks only at server boot | Restart phlex-server; the plugin auto-re-attaches on boot for enabled plugins |
| **ui-theme breaks web portal** | Web portal blank or unstyled after enabling a `ui-theme` | Theme's CSS conflicts with current portal version | Disable the plugin via CLI: `curl -X POST …/disable`, then contact the plugin author |
| **`401` on API call** | `{"error":"auth.required"}` | Missing or expired JWT bearer token | Re-authenticate and obtain a fresh token |
| **`403` — not admin** | `{"error":"auth.not_admin"}` | Your user lacks `users.is_admin = 1` | Ask an existing admin to promote your account |

Keep the first three as the primary "what-can-go-wrong" (version,
signature, restart) plus the ui-theme CSS failure as the fourth.
The auth failures are already in the existing doc's table — retain them.

#### Next steps

- [Browse the plugin catalog](docs/plugins/install-from-catalog.md) — for
  curated, signature-verified plugins.
- [Trusted plugin list](docs/plugins/trusted-plugin-list.md) — add an
  author's signing key to the allowlist.
- [Plugin developer guide](docs/plugins/developer-guide.md) — for plugin
  authors; understand what types exist and how to implement them.
- [Troubleshooting](docs/plugins/developer-guide.md#faq--troubleshooting)
  — common plugin errors and `.logs/` exploration.

---

### `install-from-catalog.md` (replace stub → §7 layout)

#### TL;DR (≤ 5 lines)

One paragraph: what the catalog is (hub-hosted curated list of trusted
plugins), what the operator does (Settings → Plugins → Browse Catalog
→ click Install), and what they get (signature-verified plugin in one
click, no manual URL pasting). Note that catalog plugins are SHA256
signed and verified against the hub's published keys automatically.

#### 1. What is the plugin catalog

Short paragraph: the catalog is a JSON endpoint served by the Phlex
hub (`https://catalog.phlex.media/v1/plugins` or similar), listing every
community plugin that has passed hub moderation and carries a valid
signature from a registered author. Operators can browse by plugin type,
search by name, and install in one click. The catalog replaces the
manual URL paste for operators who prefer curated plugins.

#### 2. Browse and install from the web UI

Step-by-step:

1. Browse to **Settings → Plugins**.
2. Click **Browse Catalog** (or navigate directly to `/admin/plugins/catalog`).
3. Use the type filter dropdown to narrow by category
   (`metadata-provider`, `auth-provider`, `notifier`, `scrobbler`,
   `tuner`, `transcoder-hook`, `ui-theme`, etc.).
4. Click a plugin card to expand its detail panel: description, author,
   version, `phlex_min_server_version`, signature status.
5. Click **Install** on the chosen plugin.
6. The plugin is downloaded, signature-verified, and staged
   automatically.
7. It lands **disabled** in the plugins table — flip the toggle to enable.

Screenshot placeholder: `[screenshot: plugin catalog browse view with type
filter and install button]`

#### 3. Enabling and configuring

After install:

- **Enable:** flip the toggle in **Settings → Plugins** table.
- **Configure:** click the wrench icon to open the plugin's settings
  form. Settings vary by plugin type:
  - `metadata-provider`: API key, endpoint URL.
  - `auth-provider`: provider URL, client ID/secret, scopes.
  - `notifier`: webhook URL, channel/room, auth token.
  - `scrobbler`: service credentials.
  - `tuner`: device ID, lineup URL.
  - `transcoder-hook`: priority, encoding profile.
  - `ui-theme`: no required config; applies immediately on enable.

#### 4. Updating catalog plugins

When a catalog plugin ships a new version:

- A badge appears on the plugin card in the catalog view.
- The **Update** button triggers `install` again; the loader replaces
  the on-disk files and retains `enabled` state and `settings_json`.
- After update, the plugin auto-re-attaches on the next server restart
  (or immediately if it does not require restart — see Failure 3 below).

#### 5. Removing a catalog plugin

Same as URL-installed plugins: **Settings → Plugins** → toggle
Disable → click **Uninstall**. This removes the on-disk files and the
database row. Catalog browsing state is preserved (the plugin remains
browsable in the catalog; you can reinstall).

#### What can go wrong

| Failure | Symptom | Cause | Fix |
|---------|---------|-------|-----|
| **Version incompatibility** | "This plugin requires phlex-server ≥ 1.2.0; you are running 1.1.4" shown in the catalog detail panel | Running server is older than the plugin's `phlex_min_server_version` | Upgrade phlex-server before installing; catalog shows compatibility info before install |
| **Signature verification failure** | Install fails with "Signature verification failed" | Plugin tarball was corrupted during download, or hub moderation was bypassed | Report to hub moderation; do not bypass the signature check manually |
| **Plugin requires restart** | Plugin is enabled but its hooks do not fire (e.g., `transcoder-hook` never intercepts a transcode) | Plugin registers event listeners only at container boot, not on enable | Restart phlex-server: `systemctl restart phlex` or the appropriate restart command for your install method |
| **ui-theme CSS breaks portal** | Web portal blank or partially styled after enabling a `ui-theme` | Theme uses selectors that conflict with the current portal DOM structure | Disable immediately: `curl -X POST https://…/api/v1/admin/plugins/<name>/disable -H "Authorization: Bearer $TOKEN"`; report the conflict to the theme author |

#### Next steps

- [Install from URL](docs/plugins/install-from-url.md) — for plugins
  not yet in the catalog, or for testing unreleased versions.
- [Trusted plugin list](docs/plugins/trusted-plugin-list.md) — how the
  hub's signature allowlist works and how to request plugin listing.
- [Plugin developer guide](docs/plugins/developer-guide.md) — for plugin
  authors; learn how to publish a plugin to the catalog.
- [Plugin catalog source](https://github.com/detain/phlex-plugin-catalog)
  — file an issue or PR to add or update a community plugin listing.

## 5. Cross-links

After writing both pages, confirm:

- Every `docs/plugins/*.md` page links to at least one other page.
- `docs/plugins/developer-guide.md` §11 (distribution) links to both
  install pages.
- `docs/plugins/manifest.md` links outward to both install pages.
- `docs/plugins/install-from-catalog.md` and
  `docs/plugins/install-from-url.md` each link to each other.

## 6. Verification

N.14 makes no source changes. The §0.4 minimum bar is trivially
satisfied:

```bash
# no-op for src/ — these should show no changes
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

Doc-side checks (optional, not a hard gate — `markdownlint` config not yet
established):

```bash
# Confirm both files exist and have the required §7 sections
grep -E "^##? (TL;DR|What can go wrong|Next steps)" docs/plugins/install-from-catalog.md
grep -E "^##? (TL;DR|What can go wrong|Next steps)" docs/plugins/install-from-url.md

# Confirm neither file is orphaned (each links to at least one sibling)
grep -c "install-from" docs/plugins/install-from-catalog.md   # should be > 0
grep -c "install-from" docs/plugins/install-from-url.md        # should be > 0
```

## 7. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short                          # MUST be empty
git branch --show-current                     # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b n.14-plugins-install

# ─── 2. Do the doc work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step N.14: plugin install guides (catalog + URL)"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, merge, cleanup ───
gh pr create \
  --title "Step N.14: plugin installation guides" \
  --body  "Writes docs/plugins/install-from-catalog.md (replaces stub with full §7-layout guide) and polishes docs/plugins/install-from-url.md to §7 layout (TL;DR, shell blocks, 3 failure modes, next-steps). No src/ changes. Implements step N.14 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                         # MUST show the N.14 commit
git branch --list 'n.14-*'                  # MUST be empty
```
