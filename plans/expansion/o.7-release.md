# Step O.7 — Release Process & Versioning

**Phase:** O (Deployment / DevOps / Release)
**Step:** O.7
**Depends on:** O.6 (CI: test + build + publish)
**Review:** Yes — see `o.7-release-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Define **release process and versioning** strategy including SemVer, hub/server compatibility matrix, and release automation scripts.

## 2. Context (what already exists)

Read first:

- `.github/workflows/release.yml` — release workflow from O.6.
- `composer.json` — existing version and dependencies.
- `CHANGELOG.md` — existing changelog format.

## 3. Scope — files to create

### `RELEASE_PROCESS.md`

```markdown
# Phlex Release Process

## Versioning Strategy

Phlex follows [Semantic Versioning (SemVer)](https://semver.org/):

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
- [ ] Version bumped in `composer.json`
- [ ] Docker images built and pushed
- [ ] Helm chart version bumped
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

Update `composer.json`:
```json
{
  "version": "1.2.0",
  "extra": {
    "phlex": {
      "minHubVersion": "1.2.0"
    }
  }
}
```

Update Helm chart `Chart.yaml`:
```yaml
version: 1.2.0
appVersion: "1.2.0"
```

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
| `latest` | Most recent stable release | `ghcr.io/detain/phlex-server:latest` |
| `v1.2.3` | Specific version | `ghcr.io/detain/phlex-server:v1.2.3` |
| `v1.2` | Minor version alias | `ghcr.io/detain/phlex-server:v1.2` |
| `nightly-YYYYMMDD` | Nightly build | `ghcr.io/detain/phlex-server:nightly-20240518` |

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
docker pull ghcr.io/detain/phlex-server:v1.2.2

# Update deployment
kubectl set image deployment/phlex phlex=ghcr.io/detain/phlex-server:v1.2.2
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
```

### `scripts/release.sh`

```bash
#!/bin/bash
set -e

# Release script for Phlex
# Usage: ./scripts/release.sh [patch|minor|major]

TYPE=${1:-patch}
VERSION=$(grep '"version"' composer.json | sed 's/.*"version": "\([^"]*\)".*/\1/')

echo "Current version: $VERSION"

# Extract version parts
IFS='.' read -ra VERSION_PARTS <<< "$VERSION"
MAJOR="${VERSION_PARTS[0]}"
MINOR="${VERSION_PARTS[1]}"
PATCH="${VERSION_PARTS[2]}"

# Calculate new version
case $TYPE in
    patch)
        PATCH=$((PATCH + 1))
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
    *)
        echo "Invalid type: $TYPE (use: patch, minor, major)"
        exit 1
        ;;
esac

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
echo "New version: $NEW_VERSION"

# Update composer.json
sed -i "s/\"version\": \"$VERSION\"/\"version\": \"$NEW_VERSION\"/" composer.json

# Update Helm chart
sed -i "s/^version:.*/version: $NEW_VERSION/" k8s/helm/phlex/Chart.yaml
sed -i "s/^appVersion:.*/appVersion: \"$NEW_VERSION\"/" k8s/helm/phlex/Chart.yaml

# Commit changes
git add composer.json k8s/helm/phlex/Chart.yaml
git commit -m "Release v$NEW_VERSION"

# Create tag
git tag "v$NEW_VERSION"

echo ""
echo "Release v$NEW_VERSION prepared!"
echo "Push with: git push && git push --tags"
```

### `scripts/compatibility-check.sh`

```bash
#!/bin/bash
# Check server-hub compatibility

SERVER_VERSION=$(grep '"version"' composer.json | sed 's/.*"version": "\([^"]*\)".*/\1/')
SERVER_MAJOR=$(echo $SERVER_VERSION | cut -d. -f1)

# Check if hub is available
if [ -z "$PHLEX_HUB_URL" ]; then
    echo "PHLEX_HUB_URL not set, skipping compatibility check"
    exit 0
fi

# Get hub version
HUB_VERSION=$(curl -s "$PHLEX_HUB_URL/api/v1/info" | jq -r '.version // empty')
HUB_MAJOR=$(echo $HUB_VERSION | cut -d. -f1)

if [ "$SERVER_MAJOR" != "$HUB_MAJOR" ]; then
    echo "ERROR: Server v$SERVER_VERSION is not compatible with Hub v$HUB_VERSION"
    echo "Server and Hub must have matching major versions"
    exit 1
fi

echo "Server v$SERVER_VERSION is compatible with Hub v$HUB_VERSION"
```

### `scripts/docker-release.sh`

```bash
#!/bin/bash
set -e

# Build and push Docker images for release

VERSION=${1:-latest}
REGISTRY=${REGISTRY:-ghcr.io}
IMAGE_NAME=${IMAGE_NAME:-detain/phlex-server}

echo "Building Docker images for v$VERSION..."

# Build base image
docker build -f docker/Dockerfile -t "$IMAGE_NAME:$VERSION" .

# Build hardware-accelerated variants
docker build -f docker/Dockerfile.nvidia -t "$IMAGE_NAME:nvidia-$VERSION" .
docker build -f docker/Dockerfile.intel -t "$IMAGE_NAME:intel-$VERSION" .

# Push to registry
docker push "$IMAGE_NAME:$VERSION"
docker push "$IMAGE_NAME:nvidia-$VERSION"
docker push "$IMAGE_NAME:intel-$VERSION"

# Push latest alias
if [ "$VERSION" != "latest" ]; then
    docker tag "$IMAGE_NAME:$VERSION" "$IMAGE_NAME:latest"
    docker push "$IMAGE_NAME:latest"
fi

echo "Docker images pushed successfully!"
```

### Update `composer.json`

Add version constraints for hub compatibility:

```json
{
  "name": "detain/phlex-server",
  "version": "1.2.0",
  "extra": {
    "phlex": {
      "minHubVersion": "1.2.0",
      "maxHubVersion": "1.9.9",
      "minPhpVersion": "8.3"
    }
  }
}
```

## 4. Approach

1. Branch from master: `git checkout -b o.7-release`.
2. Create `RELEASE_PROCESS.md` with full release documentation.
3. Create `scripts/release.sh` for version bumping.
4. Create `scripts/compatibility-check.sh` for server-hub compatibility.
5. Create `scripts/docker-release.sh` for Docker image building.
6. Update `composer.json` with `extra.phlex` metadata.
7. Update `CHANGELOG.md` format to follow Keep a Changelog.
8. Create release workflow documentation.
9. Validate all scripts.
10. Write tests for release scripts.
11. Verify: PHPStan level 9, PHPCS clean.
12. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `ReleaseTest::test_release_script_bumps_version_correctly`
2. `ReleaseTest::test_compatibility_check_passes_matching_major`
3. `ReleaseTest::test_compatibility_check_fails_mismatched_major`
4. `ReleaseTest::test_changelog_format_valid`

## 6. Acceptance Criteria

- [ ] `RELEASE_PROCESS.md` documents SemVer strategy.
- [ ] `RELEASE_PROCESS.md` includes hub/server compatibility matrix.
- [ ] `RELEASE_PROCESS.md` documents release channels (stable/beta/nightly).
- [ ] `scripts/release.sh` bumps version correctly for patch/minor/major.
- [ ] `scripts/release.sh` updates both composer.json and Helm chart.
- [ ] `scripts/compatibility-check.sh` checks major version match.
- [ ] `scripts/docker-release.sh` builds and pushes all variants.
- [ ] `composer.json` includes `extra.phlex` with version constraints.
- [ ] `CHANGELOG.md` follows Keep a Changelog format.
- [ ] All scripts are executable and have proper error handling.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b o.7-release
# ... implement ...
./scripts/release.sh --dry-run patch
./scripts/compatibility-check.sh
./vendor/bin/phpstan analyze scripts/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 scripts/
git add -A
git commit -m "Step O.7: Release process & versioning"
unset GITHUB_TOKEN
gh pr create --title "Step O.7: Release process & versioning" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `o.7-release-review.md`.

(End of file - total 296 lines)