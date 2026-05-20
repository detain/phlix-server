#!/bin/bash
set -e

# Release script for Phlix
# Usage: ./scripts/release.sh [patch|minor|major] [--dry-run]

TYPE="${1:-patch}"
DRY_RUN=false

if [[ "$2" == "--dry-run" ]]; then
    DRY_RUN=true
fi

# Get current version from composer.json
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

if [[ "$DRY_RUN" == true ]]; then
    echo "[DRY-RUN] Would update composer.json version: $VERSION -> $NEW_VERSION"
    echo "[DRY-RUN] Would update Helm chart version: -> $NEW_VERSION"
    echo "[DRY-RUN] Would create git commit and tag"
    exit 0
fi

# Update composer.json
sed -i "s/\"version\": \"$VERSION\"/\"version\": \"$NEW_VERSION\"/" composer.json

# Update Helm chart
if [ -f "k8s/helm/phlix/Chart.yaml" ]; then
    sed -i "s/^version:.*/version: $NEW_VERSION/" k8s/helm/phlix/Chart.yaml
    sed -i "s/^appVersion:.*/appVersion: \"$NEW_VERSION\"/" k8s/helm/phlix/Chart.yaml
fi

# Commit changes
git add composer.json k8s/helm/phlix/Chart.yaml
git commit -m "Release v$NEW_VERSION"

# Create tag
git tag "v$NEW_VERSION"

echo ""
echo "Release v$NEW_VERSION prepared!"
echo "Push with: git push && git push --tags"
