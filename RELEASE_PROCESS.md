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

## Server-Hub Compatibility Matrix

| Server Version | Hub Version | Compatible |
|----------------|-------------|-------------|
| 1.x.x | 1.x.x | Yes |
| 1.x.x | 2.x.x | No |
| 2.x.x | 1.x.x | No |
| 2.x.x | 2.x.x | Yes |

### Compatibility Rules

1. **Server and Hub must have matching major versions** to be compatible.
2. **Minor and patch versions can differ** — server 1.2.0 works with hub 1.3.0.
3. **Check compatibility** using the `/api/v1/compatibility` endpoint.

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

| Feature | Server Version | Hub Version |
|----------|---------------|-------------|
| Basic pairing | 1.0.0+ | 1.0.0+ |
| Relay tunnel | 1.1.0+ | 1.1.0+ |
| Delegated auth | 1.2.0+ | 1.2.0+ |
| Shared libraries | 1.3.0+ | 1.3.0+ |

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

```bash
# Rollback last migration
php scripts/run-migrations.php rollback

# Rollback to specific version
php scripts/run-migrations.php migrate:down <version>
```

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
