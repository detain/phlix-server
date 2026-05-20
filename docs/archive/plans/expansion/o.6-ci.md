# Step O.6 — CI: Test + Build + Publish

**Phase:** O (Deployment / DevOps / Release)
**Step:** O.6
**Depends on:** O.1 (Docker images)
**Review:** Yes — see `o.6-ci-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Enhance **CI/CD pipelines** to test, build Docker images, publish to container registries, and re-enable coverage checking that was removed in commit `01fa91b`.

## 2. Context (what already exists)

Read first:

- `.github/workflows/phpunit.yml` — existing test workflow.
- `.github/workflows/coding-standards.yml` — existing coding standards workflow.
- `docker/Dockerfile` — Docker image from O.1.
- Look up commit `01fa91b` to understand what coverage check was removed.

## 3. Scope — files to create/modify

### Create `docker-registry.env` (GitHub Actions secret template)

```
# Container registry credentials for CI
CR_PAT=<your-github-pat-with-registry-write>
REGISTRY=ghcr.io
IMAGE_NAME=ghcr.io/detain/phlex-server
HUB_IMAGE_NAME=ghcr.io/detain/phlex-hub
```

### Modify `.github/workflows/phpunit.yml`

Add a job to check minimum coverage threshold:

```yaml
      - name: Check coverage threshold
        run: |
          # Re-enabled coverage check removed in 01fa91b
          COVERAGE=$(grep -oP '<coverage.*line\s+coverage="\K[0-9.]+' coverage.xml | head -1)
          MIN_COVERAGE=80
          if (( $(echo "$COVERAGE < $MIN_COVERAGE" | bc -l) )); then
            echo "Coverage $COVERAGE% is below minimum $MIN_COVERAGE%"
            exit 1
          fi
          echo "Coverage check passed: $COVERAGE% >= $MIN_COVERAGE%"
```

### Create `.github/workflows/docker.yml`

```yaml
name: Docker Build & Push

on:
  push:
    branches: [ master, main ]
    tags: [ 'v*' ]
  pull_request:
    branches: [ master, main ]

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ${{ github.repository }}

jobs:
  docker:
    name: Build and Push Docker Images
    runs-on: ubuntu-latest

    permissions:
      contents: read
      packages: write

    strategy:
      matrix:
        include:
          - dockerfile: docker/Dockerfile
            tag: latest
            platform: linux/amd64,linux/arm64
          - dockerfile: docker/Dockerfile.nvidia
            tag: nvidia
            platform: linux/amd64
          - dockerfile: docker/Dockerfile.intel
            tag: intel
            platform: linux/amd64

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to Container Registry
        if: github.event_name != 'pull_request'
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Extract metadata
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}
          tags: |
            type=ref,event=branch
            type=semver,pattern={{version}}
            type=semver,pattern={{major}}.{{minor}}
            type=sha,prefix=

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          file: ${{ matrix.dockerfile }}
          platforms: ${{ matrix.platform }}
          push: ${{ github.event_name != 'pull_request' }}
          tags: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}:${{ matrix.tag }}
          labels: ${{ steps.meta.outputs.labels }}
          cache-from: type=gha
          cache-to: type=gha,mode=max
          build-args: |
            PHP_VERSION=8.3
            PHLEX_VERSION=${{ github.ref_name }}

  docker-hub:
    name: Build phlex-hub Docker Image
    runs-on: ubuntu-latest
    if: github.event_name == 'push' && github.ref == 'refs/heads/master'

    permissions:
      contents: read
      packages: write

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to Container Registry
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Extract metadata
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.REGISTRY }}/detain/phlex-hub
          tags: |
            type=ref,event=branch
            type=semver,pattern={{version}}

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          file: docker/Dockerfile.hub
          platforms: linux/amd64,linux/arm64
          push: true
          tags: ${{ env.REGISTRY }}/detain/phlex-hub:latest
          labels: ${{ steps.meta.outputs.labels }}
```

### Create `.github/workflows/release.yml`

```yaml
name: Release

on:
  push:
    tags:
      - 'v*.*.*'

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ghcr.io/detain/phlex-server

jobs:
  release:
    name: Create Release
    runs-on: ubuntu-latest

    permissions:
      contents: write

    steps:
      - name: Checkout code
        uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Get version from tag
        id: version
        run: echo "VERSION=${GITHUB_REF#refs/tags/v}" >> $GITHUB_OUTPUT

      - name: Generate changelog
        id: changelog
        run: |
          CHANGELOG=$(git log --pretty=format:'%s' ${{ github.ref_name }}..HEAD | head -20)
          echo "changelog<<EOF" >> $GITHUB_OUTPUT
          echo "## What's Changed" >> $GITHUB_OUTPUT
          echo "" >> $GITHUB_OUTPUT
          echo "$CHANGELOG" >> $GITHUB_OUTPUT
          echo "" >> $GITHUB_OUTPUT
          echo "**Full Changelog**: https://github.com/${{ github.repository }}/compare/${{ github.ref_name }}...HEAD" >> $GITHUB_OUTPUT
          echo "EOF" >> $GITHUB_OUTPUT

      - name: Create release
        uses: softprops/action-gh-release@v1
        with:
          tag_name: ${{ github.ref_name }}
          name: Phlex Server ${{ github.ref_name }}
          body: ${{ steps.changelog.outputs.changelog }}
          draft: false
          prerelease: ${{ contains(github.ref_name, 'alpha') || contains(github.ref_name, 'beta') }}
          files: |
            coverage-report.tar.gz
```

### Create `.github/workflows/coverage.yml` (re-enable from 01fa91b)

```yaml
name: Coverage Check

on:
  push:
    branches: [ master, main ]
  pull_request:
    branches: [ master, main ]

jobs:
  coverage:
    name: Minimum Coverage Threshold
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: xdebug

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
          restore-keys: ${{ runner.os }}-composer-

      - name: Install dependencies
        run: composer install --no-interaction

      - name: Run tests with coverage
        run: ./vendor/bin/phpunit --coverage-clover coverage.xml --coverage-html coverage-report --testsuite Unit

      - name: Check minimum coverage
        run: |
          # Re-enabled coverage check from 01fa91b
          LINE_COVERAGE=$(xmllint --xpath "string(/coverage/project[@name='Phlex']/metrics[@elements and @covered-elements]/@line-rate)" coverage.xml 2>/dev/null || echo "0")
          LINE_COVERAGE=$(echo "$LINE_COVERAGE * 100" | bc)

          MIN_COVERAGE=80

          echo "Line coverage: $LINE_COVERAGE%"
          echo "Minimum required: $MIN_COVERAGE%"

          if (( $(echo "$LINE_COVERAGE < $MIN_COVERAGE" | bc -l) )); then
            echo "ERROR: Coverage $LINE_COVERAGE% is below minimum $MIN_COVERAGE%"
            exit 1
          fi

          echo "Coverage check passed!"

      - name: Upload coverage report
        uses: actions/upload-artifact@v4
        with:
          name: coverage-report
          path: coverage-report/
          retention-days: 14

      - name: Upload coverage XML
        uses: actions/upload-artifact@v4
        with:
          name: coverage-xml
          path: coverage.xml
          retention-days: 14
```

## 4. Approach

1. Branch from master: `git checkout -b o.6-ci`.
2. Read the current CI workflows and understand what was removed in commit `01fa91b`.
3. Create `docker-registry.env` as a template for registry secrets.
4. Modify `.github/workflows/phpunit.yml` to re-add coverage threshold check.
5. Create `.github/workflows/docker.yml` for building and pushing Docker images.
6. Create `.github/workflows/release.yml` for GitHub Releases.
7. Create `.github/workflows/coverage.yml` (standalone coverage check).
8. Test workflow syntax with `act` or review manually.
9. Write tests for CI configuration validation.
10. Verify: PHPStan level 9, PHPCS clean.
11. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `CITest::test_workflow_yaml_syntax_valid`
2. `CITest::test_docker_workflow_has_all_jobs`
3. `CITest::test_coverage_workflow_threshold_correct`
4. `CITest::test_release_workflow_has_all_steps`

## 6. Acceptance Criteria

- [ ] `.github/workflows/phpunit.yml` re-enables coverage threshold check.
- [ ] `.github/workflows/docker.yml` builds and pushes Docker images to GHCR.
- [ ] Docker workflow builds base, nvidia, and intel variants.
- [ ] Docker workflow supports multi-platform builds (amd64, arm64).
- [ ] `.github/workflows/release.yml` creates GitHub releases with changelog.
- [ ] `.github/workflows/coverage.yml` checks minimum 80% line coverage.
- [ ] All workflows use latest GitHub Actions versions.
- [ ] Workflows follow security best practices (least privilege, secrets handling).
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b o.6-ci
# ... implement ...
# Check workflow syntax
yamllint .github/workflows/
./vendor/bin/phpstan analyze .github/workflows/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 .github/workflows/
git add -A
git commit -m "Step O.6: CI test + build + publish pipelines"
unset GITHUB_TOKEN
gh pr create --title "Step O.6: CI test + build + publish pipelines" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `o.6-ci-review.md`.

(End of file - total 266 lines)