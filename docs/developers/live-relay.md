# HLS Live TV Relay — Developer Guide

**Since:** 0.12.0
**Component:** Live TV / Hub Relay
**Step:** Phase I, Step I.7

---

## Overview

The HLS relay enables remote clients to watch Live TV by relaying HLS streams through the hub's `RelayConsumer` WebSocket tunnel. When a remote client connects to a hub URL, the hub fetches the local HLS stream (variant playlist + `.ts` segments) and proxies it over the WebSocket tunnel to the remote client.

## Architecture

```
Remote Client
      │
      │ WSS Tunnel
      ▼
Hub RelayConsumer
      │
      ├─► HlsRelayManager (orchestration)
      │         │
      │         ├─► LiveTvManager.tuneToChannel() → gets local stream URL
      │         │
      │         ├─► HlsSegmentPrefetcher (LRU cache, prefetch ahead)
      │         │
      │         └─► RelayConsumer.registerMount() → mounts /relay/live/{sessionId}/*
      │
      └─► HlsStreamer → serves variant playlists & segments
```

## Core Components

### HlsRelaySession

A value object representing a single remote relay session.

```php
$session = new HlsRelaySession(
    sessionId: '550e8400-...',
    channelId: 'channel-123',
    tuneRequestId: 'tune-456',
    createdAt: time(),
    relayPathPrefix: '/relay/live'
);

// Mount URL for remote clients
$mountUrl = $session->getMountUrl();
// → /relay/live/550e8400-.../playlist.m3u8

// Local variant playlist URL
$variantPlaylistUrl = $session->getVariantPlaylistUrl();
// → /hls/550e8400-.../stream_0.m3u8
```

### HlsRelayManager

Orchestrates relay sessions and the hub WebSocket tunnel.

```php
// Start a relay session
$session = $hlsRelayManager->startRelaySession('channel-123', 'user-456');

// Get user's active session (if any)
$session = $hlsRelayManager->getUserSession('user-456');

// Get all active sessions
$sessions = $hlsRelayManager->getActiveSessions();

// Stop a relay session
$hlsRelayManager->stopRelaySession($session->getSessionId());
```

### HlsSegmentPrefetcher

Prefetches HLS segments ahead of playback using a Workerman timer for smooth relay performance.

```php
$prefetcher = new HlsSegmentPrefetcher(
    logger: null,
    prefetchSegments: 3,      // Number of segments to prefetch ahead
    maxCacheSize: 10485760,  // 10 MB LRU cache
    ttlSeconds: 30          // Segment TTL
);

// Start background prefetching
$prefetcher->startPrefetch($sessionId, $variantPlaylistUrl);

// Check cache for segment
$segmentData = $prefetcher->getSegment($segmentUrl);
if ($segmentData === null) {
    // Cache miss - fetch from source
}

// Stop prefetching
$prefetcher->stopPrefetch($sessionId);

// Get cache statistics
$stats = $prefetcher->getCacheStats();
// → ['size_bytes' => 1024000, 'max_size_bytes' => 10485760, 'entries' => 25]
```

### HlsRelaySessionFactory

Factory for building fully-wired `HlsRelayManager` instances from configuration.

```php
$relayConfig = config('livetv.relay');

$manager = HlsRelaySessionFactory::build(
    liveTvManager: $liveTvManager,
    hlsStreamer: $hlsStreamer,
    relayConsumer: $relayConsumer,
    db: $db,
    relayConfig: $relayConfig,
    logger: $logger
);
```

### RelayConsumer Extensions

The `RelayConsumer` class was extended with mount registration:

```php
// Register a mount handler
$relayConsumer->registerMount('/relay/live/{sessionId}', function (string $path): ?string {
    // Handle the relay request, return content or null for 404
    return $segmentData;
});

// Unregister when session ends
$relayConsumer->unregisterMount('/relay/live/' . $sessionId);
```

## Session Lifecycle

1. **Session Start**
   - `HlsRelayManager::startRelaySession()` calls `LiveTvManager::tuneToChannel()`
   - Creates `HlsRelaySession` with unique UUID
   - Stores session in `livetv_relay_sessions` table
   - Registers mount with `RelayConsumer`
   - Starts segment prefetching

2. **During Session**
   - Remote client requests `/relay/live/{sessionId}/playlist.m3u8`
   - `RelayConsumer` routes to registered mount handler
   - Handler checks `HlsSegmentPrefetcher` cache first
   - On cache miss, fetches from local `HlsStreamer`
   - `last_activity_at` updated on each request

3. **Session End**
   - `HlsRelayManager::stopRelaySession()` called
   - Stops segment prefetching via timer deletion
   - Calls `LiveTvManager::stopTuning()` to release tuner
   - Deletes session from database

## Configuration

```php
// config/livetv.php

[
    'relay' => [
        'enabled' => true,
        'prefetch_segments' => 3,          // Segments to prefetch ahead
        'max_concurrent_sessions' => 10,    // Max simultaneous relay sessions
        'segment_cache_ttl_seconds' => 30,  // LRU cache TTL
        'relay_path_prefix' => '/relay/live', // Mount URL prefix
    ],
]
```

## Database Schema

```sql
CREATE TABLE livetv_relay_sessions (
    session_id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    channel_id CHAR(36) NOT NULL,
    tune_request_id CHAR(36) NOT NULL,
    mount_url VARCHAR(512) NOT NULL,
    started_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    bytes_relayed BIGINT NOT NULL DEFAULT 0,
    INDEX idx_user_id (user_id),
    INDEX idx_started_at (started_at)
);
```

## Client Playback URL

Remote clients access the relayed stream at:

```
https://{hub-url}/relay/live/{sessionId}/playlist.m3u8
```

The hub proxies the HLS stream over its WSS tunnel to the remote client, which plays it as a standard HLS stream.

## Error Handling

- **No tuner available:** Throws `RuntimeException` with message "No available tuner"
- **Max sessions reached:** Throws `RuntimeException` with message "Maximum concurrent relay sessions reached"
- **Channel not found:** Throws `InvalidArgumentException`
- **Segment fetch failed:** Logs warning, returns null, relies on next prefetch cycle
- **Mount handler error:** Returns 500 with JSON error, logs the exception

## Testing

```bash
# Run all relay tests
./vendor/bin/phpunit tests/unit/LiveTv/Relay/

# Run specific test class
./vendor/bin/phpunit tests/unit/LiveTv/Relay/HlsRelaySessionTest.php

# Coverage report for relay classes
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HlsRelay'
```
