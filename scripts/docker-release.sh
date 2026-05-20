#!/bin/bash
set -e

# Build and push Docker images for release
# Usage: ./scripts/docker-release.sh [VERSION] [--dry-run]

VERSION="${1:-latest}"
DRY_RUN=false

if [[ "$2" == "--dry-run" ]]; then
    DRY_RUN=true
fi

REGISTRY="${REGISTRY:-ghcr.io}"
IMAGE_NAME="${IMAGE_NAME:-detain/phlix-server}"

echo "Building Docker images for v$VERSION..."

if [[ "$DRY_RUN" == true ]]; then
    echo "[DRY-RUN] Would build: $IMAGE_NAME:$VERSION"
    echo "[DRY-RUN] Would build: $IMAGE_NAME:nvidia-$VERSION"
    echo "[DRY-RUN] Would build: $IMAGE_NAME:intel-$VERSION"
    echo "[DRY-RUN] Would push: $IMAGE_NAME:$VERSION"
    echo "[DRY-RUN] Would push: $IMAGE_NAME:nvidia-$VERSION"
    echo "[DRY-RUN] Would push: $IMAGE_NAME:intel-$VERSION"
    if [ "$VERSION" != "latest" ]; then
        echo "[DRY-RUN] Would tag and push: $IMAGE_NAME:latest"
    fi
    exit 0
fi

# Build base image
docker build -f docker/Dockerfile -t "$IMAGE_NAME:$VERSION" .

# Build hardware-accelerated variants
if [ -f "docker/Dockerfile.nvidia" ]; then
    docker build -f docker/Dockerfile.nvidia -t "$IMAGE_NAME:nvidia-$VERSION" .
fi

if [ -f "docker/Dockerfile.intel" ]; then
    docker build -f docker/Dockerfile.intel -t "$IMAGE_NAME:intel-$VERSION" .
fi

# Push to registry
docker push "$IMAGE_NAME:$VERSION"

if [ -f "docker/Dockerfile.nvidia" ]; then
    docker push "$IMAGE_NAME:nvidia-$VERSION"
fi

if [ -f "docker/Dockerfile.intel" ]; then
    docker push "$IMAGE_NAME:intel-$VERSION"
fi

# Push latest alias
if [ "$VERSION" != "latest" ]; then
    docker tag "$IMAGE_NAME:$VERSION" "$IMAGE_NAME:latest"
    docker push "$IMAGE_NAME:latest"
fi

echo "Docker images pushed successfully!"
