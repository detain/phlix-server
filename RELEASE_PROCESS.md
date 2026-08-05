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
- Automated builds from `master` branch
- No stability guarantees
- Tagged with `nightly-YYYYMMDD` format

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
1. Run all tests
2. Build Docker images
3. Push to GHCR with tags `v1.2.0`, `v1.2`, `latest`
4. Create GitHub release
5. Build and push Helm chart

## Docker Image Tagging

| Tag | Description | Example |
|-----|-------------|---------|
| `latest` | Most recent stable release | `ghcr.io/detain/phlix-server:latest` |
| `v1.2.3` | Specific version | `ghcr.io/detain/phlix-server:v1.2.3` |
| `v1.2` | Minor version alias | `ghcr.io/detain/phlix-server:v1.2` |
| `nightly-YYYYMMDD` | Nightly build | `ghcr.io/detain/phlix-server:nightly-20240518` |

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

```bash
# Rollback to previous version
docker pull ghcr.io/detain/phlix-server:v1.2.2

# Update deployment
kubectl set image deployment/phlix phlix=ghcr.io/detain/phlix-server:v1.2.2
```

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
