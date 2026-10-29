# Phlix Release Process

## Versioning Strategy

Phlix follows [Semantic Versioning (SemVer)](https://semver.org/):

- **MAJOR** version: Incompatible API changes between server and hub
- **MINOR** version: New backward-compatible functionality
- **PATCH** version: Backward-compatible bug fixes

Example: `v1.2.3` where:
- `1` = major version
- `2` = minor version
- `3` = patch version

## Server-Hub Compatibility

> ⚠ **The matrix previously printed here ("server and hub must have
> matching major versions") was never real and is falsified by the live
> estate:** the shipping pair is server `1.2.3`
> (`src/Common/Version.php::STRING`) × hub `0.5.0` (hub `VERSION` marker),
> and they interoperate. The two products do not share a version space and
> are not released in lockstep, so matching majors is neither required nor
> meaningful until a coordinated 1.0 (pending the owner's S274
> release-authority decision, same caveat class as the tag table below).

What actually governs interop today:

1. **The shared wire contract.** Both repos vendor the generated contract
   artifacts of the pinned `@phlix/contracts` release — the error-code
   registry (`tests/Fixtures/Contracts/error-codes.json`, asserted by
   `tests/Unit/Contracts/ErrorCodesContractTest.php` on the server; the
   hub carries its own mirror) — and each repo machine-checks its
   `openapi.yaml` against its live router. A contract-breaking change
   requires coordinated releases on both sides, each proven green against
   the pinned contract version in its own CI.
2. **Per-release-pair acceptance.** Until both products reach a coordinated
   1.0, compatibility is asserted by the pair that was CI-tested together at
   release time — not by a version formula.
3. **Version advertisement, not version enforcement.** The server reports
   `Version::STRING` on `/health` and `/system/info` (and via the published
   `VERSION` marker to the update check). There is **no** compatibility
   endpoint — the formerly advertised `/api/v1/compatibility` never existed
   in this repo's router or `openapi.yaml`, and the hub enforces no
   version gate on relay registration. The only runtime version floor that
   *is* enforced is plugin → server: `PluginLoader` validates each
   manifest's `phlix_min_server_version` against `Version::STRING`.
   `composer.json`'s `extra.phlix.minHubVersion` is a declared floor with
   no read sites — a statement of intent, not an enforcement point.

## Release Channels

### Stable
- Production-ready releases
- Minimum 2 weeks of beta testing
- Semantic version tags (e.g., `v1.2.3`)

### Beta
- Pre-release testing
- Tagged with `-beta.N` suffix (e.g., `v1.3.0-beta.1`)
- May contain breaking changes

### Nightly (dev)
- Continuous builds from `master` by the `Docker Build & Push` workflow
  (runs on every push to `master`): three legs publish mutable `latest`,
  `intel` and `nvidia` tags plus deterministic immutable
  `<full-sha>-<variant>` tags.
- No stability guarantees.
- ⚠ `nightly-YYYYMMDD` image tags are **NOT-YET-PUBLISHED** — no nightly
  workflow exists and the registry serves none (S483DOCTRUTHX9P2,
  measured against `ghcr.io/v2/detain/phlix-server/tags/list` 2026-09-11).
  The `master`-push regime above is the real continuous-build channel;
  date-stamped nightly tagging awaits the owner's S274 release-authority
  decision and should not be relied on until it ships.

## Release Schedule

| Release Type | Frequency | Example |
|--------------|-----------|---------|
| Patch | As needed | v1.2.1 released 2 weeks after v1.2.0 |
| Minor | Monthly | v1.3.0 on 3rd Thursday |
| Major | 6-12 months | v2.0.0 with breaking changes |

## Pre-Release Checklist

- [ ] All tests pass on `master`
- [ ] Coverage >= 80%
- [ ] PHPStan level 9 clean
- [ ] PHPCS PSR-12 clean
- [ ] Changelog updated
- [ ] Version bumped with `./scripts/release.sh` (see below — it bumps every source at once)
- [ ] Docker images built and pushed
- [ ] Draft release created and reviewed
- [ ] Release notes reviewed

## Release Steps

### 1. Create Release Branch

```bash
git checkout master
git pull
git checkout -b release/v1.2.0
```

### 2. Update Version

**Never edit the version by hand.** Run:

```bash
./scripts/release.sh [patch|minor|major] [--dry-run]
```

`src/Common/Version.php::STRING` is the **authoritative** version source — it is
what the API reports, what `PluginLoader` enforces `phlix_min_server_version`
against, and what the S74 core-update check compares the published marker to.
The script reads it and writes the bumped value to every mirror in one commit:

| Source | Role |
|---|---|
| `src/Common/Version.php` `public const STRING` | authoritative |
| `VERSION` (repo root) | the update marker every deployed server polls |
| `k8s/helm/phlix/Chart.yaml` `version:` + `appVersion:` | Helm chart |

`composer.json` deliberately carries **no** `version` field, and the script will
never add one: the `composer-validate` CI job runs
`composer validate --strict --no-check-publish`, which exits non-zero when the
field is present (that was the decision in `f5375f7a`).

The script refuses to release from a tree where those sources disagree, and
`tests/Unit/Server/Updates/VersionSourcesAgreeTest.php` +
`tests/Unit/Scripts/ReleaseScriptTest.php` keep any drift red in CI.

`extra.phlix.minHubVersion` in `composer.json` is a compatibility floor, not a
version of this package — bump it only when the server actually starts
requiring a newer hub.

### 3. Update Changelog

Generate changelog:
```bash
git log --pretty=format:"- %s" v1.1.0..HEAD
```

### 4. Create Pull Request

```bash
git add .
git commit -m "Release v1.2.0"
git push -u origin release/v1.2.0
gh pr create --title "Release v1.2.0" --body "Release notes..."
```

### 5. Tag and Release

After PR merge:
```bash
git tag v1.2.0
git push origin v1.2.0
```

GitHub Actions will:
1. `Release` workflow (trigger `v*.*.*`): assert both Helm charts'
   `appVersion` and `composer.json`'s `version` match the tag, lint and
   package the charts, and create the GitHub release with the chart
   `.tgz` files attached as **release assets**
2. `Docker Build & Push` workflow (trigger `v*`): rebuild and publish the
   three runtime image legs with mutable `latest`/`intel`/`nvidia` tags
   plus the immutable `<full-sha>-<variant>` tags for that commit

**Not yet true (S483, pending the owner's S274 release-authority decision):**
no workflow pushes GHCR tags named `v1.2.0` or `v1.2` (the registry serves
zero semver image tags — see the tag table below), and nothing "pushes" a
Helm chart to any repository — charts ship only as release assets. Steps 3–4
as previously worded here described an intended flow that has never run.

## Docker Image Tagging

Published today (S474 regime — measured against the live registry, see the
recipe below):

| Tag | Description | Example |
|-----|-------------|---------|
| `<full-sha>-latest` / `-intel` / `-nvidia` | **Deterministic immutable tag per commit and leg** — the form to pin | `ghcr.io/detain/phlix-server:<full-sha>-latest` |
| `latest` / `intel` / `nvidia` | Mutable convenience tags, repushed by every `master` (and `v*`) build — may be stale or move under you | `ghcr.io/detain/phlix-server:latest` |
| `buildcache-latest` / `-intel` / `-nvidia` | BuildKit registry cache, not runnable app images | — |

**Not-yet-published** (no workflow produces them; the registry serves zero of
these; re-check with the recipe below before trusting any change here):

| Tag | Status |
|-----|--------|
| `v1.2.3` (semver image tags) | NOT-YET-PUBLISHED — pending the owner's S274 release-authority decision |
| `v1.2` (minor alias) | NOT-YET-PUBLISHED — same |
| `nightly-YYYYMMDD` | NOT-YET-PUBLISHED — no nightly workflow exists |

To find the tags that actually exist right now (anonymous, no login):

```bash
curl -s "https://ghcr.io/token?scope=repository:detain/phlix-server:pull" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])' >/tmp/ghcr.token
curl -s -H "Authorization: Bearer $(cat /tmp/ghcr.token)" \
  "https://ghcr.io/v2/detain/phlix-server/tags/list" | python3 -m json.tool
```

Never bake a `<full-sha>` into docs or manifests by hand — query the list and
pick the commit you want.

## Hub/Server Compatibility

### Feature Compatibility

> ⚠ **The per-feature "1.x.0+ / 1.x.0+" floor table that used to sit here
> never matched shipped versions:** pairing, relay tunnels, delegated auth
> and shared libraries all went live while the hub was still on its 0.x
> line (hub `0.5.0` today) against a 1.x server. There is no per-feature
> version floor recorded in either repo. What these features actually
> negotiate is the pinned `@phlix/contracts` wire contract and the
> documented relay envelope — see "Server-Hub Compatibility" above.

### API Versioning

- Server API: `/api/v1/*`
- Hub API: `/api/v1/*`
- Breaking changes increment major version

## Rollback Procedures

### Docker Image Rollback

Rollback targets the immutable `<full-sha>-<variant>` tag of the previous
known-good commit — precise because the S474 regime publishes one per build
(never `latest`: it is mutable and may already be the broken build). Find the
current tag list with the anonymous recipe in "Docker Image Tagging" above,
pick the previous run's full SHA, then:

```bash
# Rollback to the previous good build's immutable tag
PREV_SHA=<full-sha-of-last-good-master-build>   # from tags/list, see above
docker pull ghcr.io/detain/phlix-server:${PREV_SHA}-latest

# Update deployment
kubectl set image deployment/phlix phlix=ghcr.io/detain/phlix-server:${PREV_SHA}-latest
```

(`-intel` / `-nvidia` for the hardware-accelerated legs. The Helm chart has
**no default `image.tag`** — an unset value fails rendering via `required`
with this exact immutable form named in the error; the chart does not
mechanically reject a hand-set mutable `latest`, but pinning one defeats
rollback, so don't.)

### Database Migration Rollback

**There is no automated migration rollback.** The commands this section used
to list — `php scripts/run-migrations.php rollback` and
`php scripts/run-migrations.php migrate:down <version>` — exist nowhere in the
codebase: `scripts/run-migrations.php` parses no arguments at all, so typing
them during an incident ran the **forward** migration anyway — the opposite of
what was asked. `bin/phlix migrate` has no `down`/rollback command either, and
`MigrationRunner` has no reverse path. Stray words passed to either entry
point are silently ignored, not guarded against.

Real recovery, in the order an incident should reach for it:

1. **Roll back the image and leave the schema alone.** The
   `schema_migrations` ledger and all applied DDL live in the database, not in
   the image, so a rolled-back image re-runs only its own (older) migration
   files — every one of them is already in the ledger and is skipped. Recent
   migrations are additive or drop *dead* indexes (e.g.
   `migrations/119_drop_redundant_dead_indexes.sql`), which old code tolerates;
   read the release's migration SQL to confirm before relying on this.
2. **Restore from a backup.** Take one before the release with
   `php bin/phlix backup:create --label=pre-v1.2.3` and find its id with
   `php bin/phlix backup:list`. Restore is admin HTTP API only —
   `POST /api/v1/admin/backup/{id}/restore`, behind `AdminMiddleware` (valid
   admin JWT); there is no CLI restore command. Each archive carries a
   `mysqldump --single-transaction` of all tables, every `*.php` under
   `config/` (recursive), the `data/` tree, and SSL certs when present; the
   stored SHA-256 checksum is verified before the import runs. Honest
   semantics: the dump replaces the tables that existed at backup time
   (mysqldump's per-table `DROP TABLE IF EXISTS` + `CREATE TABLE`) but does not
   drop tables created afterwards, the `schema_migrations` ledger travels
   inside the dump — so once newer code boots, post-backup migrations replay
   FORWARD against the restored schema — and the `backups` history table
   itself is rolled back too (later archives survive on disk/S3 but vanish
   from the list). Pair the restore with the image rollback so the replay
   matches the code.
3. **Remediate forward.** The supported fix for a migration that left the
   schema in a bad shape is a NEW numbered migration that repairs it — the
   ledger records filenames and applies each once, so history is never
   rewritten, only followed up.
4. **Manual reversal, where a migration documents one.** Some migrations ship
   a `## Reversibility` header with hand-written `ALTER` recipes and a
   data-shape safety analysis — `migrations/100_user_item_data_profile_id.sql`
   states in those words that its reverse is *"deliberately NOT automated"*.
   These are best-effort, human-audited escape hatches to run only against a
   verified backup, not a command surface.

## Security Releases

For critical security issues:
1. Release patch version within 48 hours
2. No advance notice
3. No beta period
4. Immediate Docker image push
5. Security advisory published

## Hotfix Process

For critical bugs in production:

1. Create hotfix branch from tag:
   ```bash
   git checkout -b hotfix/v1.2.1 v1.2.0
   ```

2. Fix and test

3. Merge to master and tag:
   ```bash
   git checkout master
   git merge --no-ff hotfix/v1.2.1
   git tag v1.2.1
   git push origin v1.2.1
   ```

4. Delete hotfix branch:
   ```bash
   git branch -d hotfix/v1.2.1
   git push origin --delete hotfix/v1.2.1
   ```

## GPG Signing

All release tags and Docker images are GPG signed for security.

### Tag Signing

```bash
# Sign a tag
git tag -s v1.2.0 -m "Release v1.2.0"
git push origin v1.2.0
```

### Docker Image Signing

Docker images are signed using Cosign:

```bash
# Sign the image
cosign sign --yes ghcr.io/detain/phlix-server:v1.2.0

# Verify the image
cosign verify ghcr.io/detain/phlix-server:v1.2.0
```
