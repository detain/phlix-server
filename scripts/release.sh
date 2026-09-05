#!/bin/bash
set -euo pipefail

# Release script for Phlix (phlix-server).
#
# Usage: ./scripts/release.sh [patch|minor|major] [--dry-run]
#        (the two arguments may be given in either order; the bump type
#         defaults to `patch`)
#
# AUTHORITATIVE VERSION SOURCE: src/Common/Version.php::STRING.
#
# That constant is what the running server reports over the API, what
# PluginLoader compares `phlix_min_server_version` against, and what the S74
# core-update check compares the published VERSION marker to. Everything else
# is a MIRROR of it and is rewritten here:
#
#   src/Common/Version.php     public const STRING = 'X.Y.Z';  (source of truth)
#   VERSION                    X.Y.Z                           (published update marker)
#   k8s/helm/phlix/Chart.yaml  version: / appVersion:          (Helm chart)
#
# composer.json deliberately carries NO `version` field and this script must
# never add one: the `composer-validate` CI job runs
# `composer validate --strict --no-check-publish`, which exits non-zero when
# the field is present (measured: exit 1 with the field, 0 without). That was
# the explicit decision in f5375f7a.
#
# History note: before this rewrite the script read the version out of a
# composer.json key that had not existed since f5375f7a, so it computed the
# empty string, produced "..1", rewrote Chart.yaml with it, committed, and then
# died on `fatal: 'v..1' is not a valid tag name` — leaving a bogus commit
# behind. It also never touched Version.php or VERSION at all.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

VERSION_PHP="src/Common/Version.php"
VERSION_MARKER="VERSION"
CHART="k8s/helm/phlix/Chart.yaml"
COMPOSER_JSON="composer.json"

SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+$'

die() {
    echo "ERROR: $*" >&2
    exit 1
}

# --- argument parsing -------------------------------------------------------
# The old script only looked at $2 for --dry-run, so the documented invocation
# `./scripts/release.sh --dry-run` was parsed as a bump type and rejected.
TYPE=""
DRY_RUN=false
for arg in "$@"; do
    case "$arg" in
        patch|minor|major)
            [[ -n "$TYPE" ]] && die "bump type given twice: $TYPE and $arg"
            TYPE="$arg"
            ;;
        --dry-run)
            DRY_RUN=true
            ;;
        -h|--help)
            echo "Usage: ./scripts/release.sh [patch|minor|major] [--dry-run]"
            exit 0
            ;;
        *)
            die "unknown argument: $arg (use: patch, minor, major, --dry-run)"
            ;;
    esac
done
TYPE="${TYPE:-patch}"

# --- read the authoritative version ----------------------------------------
[[ -f "$VERSION_PHP" ]] || die "$VERSION_PHP not found under $REPO_ROOT"

VERSION="$(sed -n "s/^[[:space:]]*public const STRING = '\([^']*\)';.*/\1/p" "$VERSION_PHP")"

[[ -n "$VERSION" ]] || die "could not read 'public const STRING' from $VERSION_PHP"
[[ "$VERSION" =~ $SEMVER_RE ]] || die "$VERSION_PHP::STRING is '$VERSION', which is not MAJOR.MINOR.PATCH"

echo "Current version: $VERSION  (from $VERSION_PHP::STRING)"

# --- every mirror must already agree ---------------------------------------
# Releasing from a drifted tree silently publishes disagreeing numbers. This is
# exactly how Chart.yaml reached 1.2.3 while Version.php stayed at 1.2.2.
read_marker() { tr -d '[:space:]' < "$VERSION_MARKER"; }
read_chart_version() { sed -n 's/^version:[[:space:]]*"\?\([^"[:space:]]*\)"\?[[:space:]]*$/\1/p' "$CHART"; }
read_chart_app_version() { sed -n 's/^appVersion:[[:space:]]*"\?\([^"[:space:]]*\)"\?[[:space:]]*$/\1/p' "$CHART"; }

[[ -f "$VERSION_MARKER" ]] || die "$VERSION_MARKER not found"
[[ -f "$CHART" ]] || die "$CHART not found"

DRIFT=0
check_source() {
    local label="$1" actual="$2"
    if [[ "$actual" != "$VERSION" ]]; then
        echo "  DRIFT: $label is '$actual', expected '$VERSION'" >&2
        DRIFT=1
    fi
}

check_source "$VERSION_MARKER" "$(read_marker)"
check_source "$CHART (version:)" "$(read_chart_version)"
check_source "$CHART (appVersion:)" "$(read_chart_app_version)"

# The field this rejects is the ROOT package's `"version"` key (the one
# composer validate --strict fails on). A textual match on any indentation
# false-positives on nested occurrences — S228 added a dev-only composer
# `repositories` block whose inline package definition legitimately carries
# its own `"version"`. Parse the JSON and ask the top level instead. The
# regression in both directions is pinned by tests/Unit/Scripts/
# ReleaseScriptTest (driftProvider's root-injection case, and every happy-path
# case running against the real composer.json that now contains the nested one).
if php -r 'exit(array_key_exists("version", json_decode(file_get_contents($argv[1]), true) ?? []) ? 0 : 1);' "$COMPOSER_JSON"; then
    echo "  DRIFT: $COMPOSER_JSON declares a \"version\" field; composer validate --strict fails on it" >&2
    DRIFT=1
fi

if [[ "$DRIFT" -ne 0 ]]; then
    die "version sources disagree — reconcile them (./vendor/bin/phpunit --filter VersionSourcesAgree) before releasing"
fi

# --- compute the new version ------------------------------------------------
IFS='.' read -r MAJOR MINOR PATCH <<< "$VERSION"

case $TYPE in
    patch) PATCH=$((PATCH + 1)) ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
esac

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
[[ "$NEW_VERSION" =~ $SEMVER_RE ]] || die "computed version '$NEW_VERSION' is not MAJOR.MINOR.PATCH"
echo "New version:     $NEW_VERSION"

# --- pre-flight git checks (BEFORE any file is written) ---------------------
# The old script committed first and only then discovered the tag was bad.
git rev-parse --git-dir >/dev/null 2>&1 || die "not inside a git repository"

git check-ref-format "refs/tags/v$NEW_VERSION" \
    || die "'v$NEW_VERSION' is not a valid git tag name"

if git rev-parse -q --verify "refs/tags/v$NEW_VERSION" >/dev/null; then
    die "tag v$NEW_VERSION already exists"
fi

if [[ "$DRY_RUN" != true ]] && ! git diff --cached --quiet; then
    die "the index already has staged changes; they would be swept into the release commit"
fi

if [[ "$DRY_RUN" == true ]]; then
    echo "[DRY-RUN] Would update $VERSION_PHP::STRING:   $VERSION -> $NEW_VERSION"
    echo "[DRY-RUN] Would update $VERSION_MARKER:                     $VERSION -> $NEW_VERSION"
    echo "[DRY-RUN] Would update $CHART version:     $VERSION -> $NEW_VERSION"
    echo "[DRY-RUN] Would update $CHART appVersion:  $VERSION -> $NEW_VERSION"
    echo "[DRY-RUN] Would leave $COMPOSER_JSON untouched (no version field by design)"
    echo "[DRY-RUN] Would commit those 3 files and create tag v$NEW_VERSION"
    exit 0
fi

# --- write every source ------------------------------------------------------
sed -i "s/public const STRING = '$VERSION';/public const STRING = '$NEW_VERSION';/" "$VERSION_PHP"
printf '%s\n' "$NEW_VERSION" > "$VERSION_MARKER"
sed -i "s/^version:.*/version: $NEW_VERSION/" "$CHART"
sed -i "s/^appVersion:.*/appVersion: \"$NEW_VERSION\"/" "$CHART"

# --- verify what was actually written ---------------------------------------
WRITTEN_PHP="$(sed -n "s/^[[:space:]]*public const STRING = '\([^']*\)';.*/\1/p" "$VERSION_PHP")"
[[ "$WRITTEN_PHP" == "$NEW_VERSION" ]] || die "$VERSION_PHP still reads '$WRITTEN_PHP'"
[[ "$(read_marker)" == "$NEW_VERSION" ]] || die "$VERSION_MARKER still reads '$(read_marker)'"
[[ "$(read_chart_version)" == "$NEW_VERSION" ]] || die "$CHART version: still reads '$(read_chart_version)'"
[[ "$(read_chart_app_version)" == "$NEW_VERSION" ]] || die "$CHART appVersion: still reads '$(read_chart_app_version)'"

# --- commit and tag ----------------------------------------------------------
git add "$VERSION_PHP" "$VERSION_MARKER" "$CHART"
git commit -m "Release v$NEW_VERSION"
git tag "v$NEW_VERSION"

echo ""
echo "Release v$NEW_VERSION prepared!"
echo "Remember to move the CHANGELOG's [Unreleased] entries under a [$NEW_VERSION] heading."
echo "Push with: git push && git push origin v$NEW_VERSION"
