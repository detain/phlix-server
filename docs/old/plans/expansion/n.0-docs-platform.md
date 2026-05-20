# Step N.0 — Choose docs platform + repo layout

**Phase:** N (End-User Documentation)
**Step:** N.0
**Depends on:** C.9
**Review:** No
**Target repo:** detain/phlex-server (local: /home/sites/phlex/)

## 1. Goal

Select and scaffold the docs platform and repository layout for Phase N. The chosen tool must:
- Render the §7 three-tree doc layout (end-user / developer / hub-admin) as a navigable site
- Build from markdown source on every commit
- Publish cleanly to github.com (GitHub Pages) or Netlify with zero additional infrastructure
- Allow non-developer contributors to submit doc PRs without touching PHP code

## 2. Context (what already exists)

- `docs/` directory (already populated with 45+ markdown files across 10 sub-dirs: `advanced/`, `clients/`, `dev/`, `developers/`, `hub/`, `hub-admin/`, `libraries/`, `plugins/`, `reference/`, `security/`)
- Existing content covers developer-oriented reference and plugin docs — NOT yet organized as an end-user-facing site
- §7 in PHLEX_EXPANSION_PLAN.md specifies the required three-audience tree layout
- No VitePress / MkDocs / Docusaurus config currently present
- `detain/phlex-docs` does not exist yet

## 3. Decision rationale

**Three tools considered: VitePress, MkDocs (Material), Docusaurus.**

| | VitePress | MkDocs + Material | Docusaurus |
|---|---|---|---|
| Framework | Vue-based static generator | Python / Markdown | React-based static generator |
| Build speed | Fast (Vite) | Medium | Slow (Webpack) |
| GitHub Pages | Native (`vitepress build`) + GitHub Actions | `mkdocs gh-deploy` | `docusaurus build` + GitHub Actions |
| Netlify | Drag-drop or `netlify deploy` | Supported | Supported |
|github.com rendering | Native `.md` renders poorly; needs build step | Native `.md` renders poorly; needs build step | Native `.md` renders poorly; needs build step |
| Customizability | CSS vars, theme override | Extensive plugin ecosystem | Highly extensible |
| Maintenance burden | Low (official Vue team) | Low-Medium | Medium |
| Existing PHP ecosystem precedent | Used by Vue, Vitest, Rollup, Vite core docs | Used by many Python projects | Used by React, Babel, Jest, Bundler |

**Decision: VitePress (Option A — keep in `phlex-docs` repo, NOT inside `phlex-server`)**

Rationale:
- Fastest path to a working published site: `npm init vitepress` + one config file + push = done
- GitHub Pages deployment via GitHub Actions is one YAML file; official VitePress docs ship a [proven workflow](https://vitepress.dev/guide/deploy#github-pages)
- VitePress supports sidebar, search (Algolia/MinSearch local), dark/light toggle, and code syntax highlighting out of the box
- Option B (MkDocs) would require a Python toolchain the project doesn't otherwise have; Docusaurus adds a heavy React dependency
- Keeping docs in a separate `phlex-docs` repo avoids coupling doc site releases to server release cadence
- `detain/phlex-docs` does not exist yet; creating it from scratch is cheaper than migrating off a bad choice later

**Rejection of Option B (VitePress inside `phlex-server/docs/`):** coupling doc-site releases to server repo is acceptable for plugin docs but creates two problems for end-user docs: (1) a server `git push --force` or branch protection policy could interfere with doc publishing workflow, and (2) contributors must clone the full server repo to fix a typo in the docs. A separate docs repo lowers the barrier for community contributions.

**Rejection of MkDocs:** introduces a Python runtime the project otherwise has no stake in; Python toolchain maintenance is a hidden ongoing cost. VitePress is Node.js, which is already implicitly available via the frontend client repos.

## 4. Scope

### Create
- `detain/phlex-docs` GitHub repo (empty public repo already exists per PHLEX_EXPANSION_PLAN.md §2)
- `docs/` tree content (all 45+ existing `.md` files moved into VitePress structure)
- `.vitepress/config.ts` — sidebar, nav, social links, theme
- `.vitepress/theme/` — custom CSS vars (dark/light brand tokens)
- `package.json` — VitePress as dev dependency
- `.github/workflows/docs.yml` — build and deploy to GitHub Pages
- `index.md` — landing page
- Per-section `README.md` stubs where sections need a landing page but none exists yet

### Modify
- Nothing in `detain/phlex-server` (docs content moves to phlex-docs; `docs/` in server becomes a redirect notice)

### Delete
- Nothing permanently deleted; all markdown source is preserved in phlex-docs

## 5. Approach

### New repo: `detain/phlex-docs`

1. **Create `phlex-docs` repo** — clone the already-existing empty `detain/phlex-docs` (created 2026-05-17 per PHLEX_EXPANSION_PLAN.md §2), or use `git init` if the repo was not pre-created; push initial commit with a placeholder README
2. **Copy existing `docs/` content** into the new repo under `docs/` (preserving all 45+ files)
3. **Initialize VitePress:**
   ```bash
   cd phlex-docs
   npm init -y
   npm install --save-dev vitepress vue
   mkdir -p .vitepress/theme
   ```
4. **Write `.vitepress/config.ts`** — nav bar, sidebar for each of the three trees (end-user / dev / hub-admin), algolia/minsearch config placeholder
5. **Write `.vitepress/theme/style.css`** — brand colors (pull from the server's existing CSS if any), dark/light toggle
6. **Write landing `index.md`** — three-path landing (end user / developer / hub admin) with icons and direct links to each tree
7. **Add `package.json` scripts:**
   ```json
   "scripts": {
     "docs:dev": "vitepress dev docs",
     "docs:build": "vitepress build docs",
     "docs:preview": "vitepress preview docs"
   }
   ```
8. **Add `.github/workflows/docs.yml`** — on push to `master`, run `npm run docs:build` and deploy to GitHub Pages using the [official VitePress action](https://github.com/nicepkg/vitepress-build-gh-pages-action) or the manual `peaceiris/actions-gh-pages` pattern
9. **Write `README.md`** in repo root pointing at the live site
10. **Map existing content** into VitePress sections per §6 below

### In `detain/phlex-server`

- Replace `docs/README.md` with a single line:
  ```markdown
  # Documentation has moved to [phlex-docs](https://github.com/detain/phlex-docs)
  ```
- Commit, push, done — `docs/` in server repo is now a stub

### Content mapping (existing → VitePress section)

| Existing `docs/` dir | VitePress section |
|---|---|
| `docs/developers/` | `docs/dev/` (developer tree) |
| `docs/dev/` | `docs/dev/` (developer tree) |
| `docs/plugins/` | `docs/dev/plugin-sdk.md` (developer tree) |
| `docs/reference/` | `docs/reference/` (developer tree) |
| `docs/security/` + `docs/hub/` + `docs/hub-admin/` | New end-user and hub-admin trees |
| `docs/libraries/` | `docs/libraries/` (end-user tree) |
| `docs/clients/` | `docs/clients/` (end-user tree) |
| `docs/advanced/` | `docs/advanced/` (end-user tree) |

Where files don't yet exist for a §7 leaf node (e.g., `docs/install/linux.md`), N.1–N.5 write those files; N.0 scaffolds only the platform, not the content.

## 6. §7 docs tree layout (reference)

```
docs/
├── index.md                        # Landing: end-user / dev / hub-admin
├── install/
│   ├── linux.md                   # N.1
│   ├── docker.md                  # N.2
│   ├── windows.md                 # N.3
│   ├── macos.md                   # N.4
│   └── kubernetes.md             # N.5
├── first-run.md                   # N.6
├── libraries/
│   ├── overview.md
│   ├── movies.md                 # N.7
│   ├── tv-shows.md               # N.8
│   ├── music.md                  # N.9
│   ├── photos.md
│   ├── books.md
│   └── audiobooks.md             # N.10
├── hub/
│   ├── what-is-the-hub.md
│   ├── claim-server.md           # N.11
│   ├── share-with-friends.md     # N.12
│   └── self-host-the-hub.md
├── clients/
│   ├── mobile.md                 # N.13
│   ├── tizen.md
│   ├── roku.md
│   ├── windows.md
│   └── web.md
├── plugins/
│   ├── install-from-catalog.md   # N.14
│   ├── install-from-url.md
│   └── trusted-plugin-list.md
├── advanced/
│   ├── hardware-transcoding.md   # N.15
│   ├── live-tv.md               # N.16
│   ├── remote-access-without-hub.md  # N.17
│   ├── reverse-proxy.md
│   ├── backup-restore.md        # N.18
│   └── arr-integration.md
├── privacy-security.md          # N.22
├── troubleshooting.md            # N.19
├── faq.md
├── reference/
│   ├── env-vars.md
│   ├── config-files.md
│   ├── cli.md
│   └── api/                      # N.21
├── dev/
│   ├── architecture-server.md
│   ├── architecture-hub.md
│   ├── pairing-protocol.md
│   ├── event-reference.md
│   ├── plugin-sdk.md            # N.26
│   ├── test-harness.md
│   ├── debug-recipes.md
│   ├── release-process.md
│   └── contributing.md          # N.23
└── hub-admin/
    ├── install.md               # N.27
    ├── first-boot.md
    ├── capacity-planning.md      # N.28
    ├── relay-tuning.md
    ├── abuse-handling.md         # N.29
    ├── gdpr-data-rights.md
    ├── monitoring-alerting.md    # N.31
    ├── scaling.md               # N.30
    ├── backup-restore.md
    ├── federation-policy.md      # N.32
    └── audit-log.md
```

## 7. Acceptance criteria

- [ ] `detain/phlex-docs` exists as a public GitHub repo
- [ ] `docs/` in `phlex-docs` contains all existing markdown files from `detain/phlex-server/docs/`
- [ ] `.vitepress/config.ts` exists and defines nav + sidebar for all three doc trees
- [ ] `package.json` contains `docs:dev`, `docs:build`, `docs:preview` scripts
- [ ] `.github/workflows/docs.yml` exists and deploys to GitHub Pages on push to `master`
- [ ] `npm run docs:dev` starts the VitePress dev server locally without errors
- [ ] `npm run docs:build` produces a `docs/.vitepress/dist/` directory
- [ ] `docs/` in `detain/phlex-server` contains only a redirect README pointing to `detain/phlex-docs`
- [ ] Repo description + topic tags applied to `detain/phlex-docs`

## 8. Git ritual

```bash
cd /home/sites/phlex
git status --short
git checkout -b n.0-docs-platform

# Write the plan

git add plans/expansion/n.0-docs-platform.md
git status --short
git commit -m "n.0: choose docs platform + repo layout"

unset GITHUB_TOKEN
git push -u origin n.0-docs-platform
gh pr create --title "n.0: choose docs platform + repo layout" --body "Step n.0 of PHLEX_EXPANSION_PLAN.md — choose docs platform."
gh pr merge --squash --delete-branch

git checkout master && git pull
git branch -d n.0-docs-platform
git log --oneline -1
```
