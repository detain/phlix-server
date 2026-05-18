#!/bin/bash

# Check server-hub compatibility
# Usage: ./scripts/compatibility-check.sh

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

if [ -z "$HUB_VERSION" ]; then
    echo "WARNING: Could not fetch hub version from $PHLEX_HUB_URL"
    exit 1
fi

if [ "$SERVER_MAJOR" != "$HUB_MAJOR" ]; then
    echo "ERROR: Server v$SERVER_VERSION is not compatible with Hub v$HUB_VERSION"
    echo "Server and Hub must have matching major versions"
    exit 1
fi

echo "Server v$SERVER_VERSION is compatible with Hub v$HUB_VERSION"
exit 0
