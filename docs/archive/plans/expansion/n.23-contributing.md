# Plan N.23 — Contributing Guide

## Step Details
- **Step:** N.23
- **Phase:** N (End-User Documentation)
- **Depends on:** N.0 (docs platform)
- **Review:** No (doc-only step)
- **Target:** `docs/dev/contributing.md`
- **One-liner:** Contributing guide (server, hub, clients, plugins)

## Goal
Author `docs/dev/contributing.md` covering how to contribute across all Phlex repositories — server, hub, clients, and plugins.

## Doc Page Structure (§7 layout)
- TL;DR
- Shell blocks (git clone, dev setup)
- what-can-go-wrong (3 failures)
- next-steps

## Context

### Repo structure
```
phlex-server         — PHP 8.3+ Workerman media server
phlex-hub            — Hub orchestration service
phlex-shared         — Shared types, constants, schemas
phlex-mobile-client  — React Native mobile app
phlex-tizen-client   — Samsung Tizen TV app
phlex-roku-client    — Roku channel
phlex-windows-client  — Windows desktop app
```

### Clone all repos
```bash
git clone git@github.com:detain/phlex-server.git
git clone git@github.com:detain/phlex-hub.git
git clone git@github.com:detain/phlex-shared.git
git clone git@github.com:detain/phlex-mobile-client.git
git clone git@github.com:detain/phlex-tizen-client.git
git clone git@github.com:detain/phlex-roku-client.git
git clone git@github.com:detain/phlex-windows-client.git
```

### Dev environment setup

**Server** (`phlex-server`):
```bash
composer install
php scripts/run-migrations.php
php public/index.php
```

**Hub** (`phlex-hub`):
```bash
composer install
# run migrations (document migration script name once N.22 lands)
php bin/hub.php
```

**Clients**:
- Mobile (`phlex-mobile-client`): `npm install` or `yarn`
- Windows (`phlex-windows-client`): `npm install` or `yarn`
- Tizen (`phlex-tizen-client`): manual build (document build command once known)
- Roku (`phlex-roku-client`): manual build (document build command once known)

### Branch naming
- `feature/{slug}` — new features
- `fix/{slug}` — bug fixes
- `step-{phase}.{step}-{slug}` — phase/step deliverables (e.g., `step-n.23-contributing`)

### Commit format
`{type}: {description}` where type is:
- `step-N.M` (e.g., `step-N.23`) for phase/step work
- `fix`, `feat`, `chore` for conventional contributions

### PR process
branch → PR → review → squash-merge → delete branch

### PHPDoc requirements
Every public method needs `@param`, `@return`, `@throws`.

### Code standards
- PSR-12: `phpcs --standard=PSR12 src/`
- Static analysis: `phpstan analyze src/ --level=9`
- Tests: `./vendor/bin/phpunit` — all must pass

### Plugin development
- See `docs/dev/plugin-sdk.md` (N.24)
- Submit PR to `phlex-plugin-catalog` to list a plugin

## Deliverables

### File: `docs/dev/contributing.md`

Sections to author:
1. **TL;DR** — Quick-reference shell blocks for cloning and running
2. **Cloning all repos** — `git clone` for all 7 repos
3. **Dev environment setup** — per-repo setup commands (server, hub, clients)
4. **Branch and commit conventions** — naming rules + commit format
5. **Code standards** — PHPDoc, PSR-12, phpstan level 9, PHPUnit
6. **PR process** — branch → PR → review → squash-merge → delete
7. **Plugin development** — reference to `docs/dev/plugin-sdk.md` + plugin catalog PR
8. **what-can-go-wrong** — 3 failure scenarios with resolutions
9. **next-steps** — links to `docs/dev/plugin-sdk.md` (N.24) and `docs/dev/workflow.md` (N.21)

## Verification
- File exists at `docs/dev/contributing.md`
- Contains TL;DR, shell blocks, what-can-go-wrong (3 items), next-steps sections
- Clone commands reference all 7 repos
- Dev setup commands cover server, hub, mobile, windows
- Failure scenarios cover: PHP version mismatch, migration failure, missing env vars
- Links to sibling docs via cross-ref once those plan steps are executed

## Dependencies
- N.0 — docs platform (infrastructure, templating)
- N.24 — plugin-sdk.md (plugin development reference)
- N.21 — workflow.md (next-steps link)
