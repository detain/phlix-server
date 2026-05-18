# phlex-plugin-trakt

Trakt.tv scrobbler plugin for Phlex Media Server.

## Features

- **OAuth2 PKCE Authentication** — Secure login with Trakt.tv
- **3-State Scrobbling** — Start/pause/stop on playback events
- **Two-Way Watch History Sync**:
  - Trakt → Phlex: Pull watched history on schedule
  - Phlex → Trakt: Push completed watches (≥90%) after playback

## Requirements

- Phlex Media Server 0.14.0+
- Trakt.tv account
- Trakt application (register at https://trakt.tv/apps)

## Installation

1. Create a Trakt application at https://trakt.tv/apps
2. Note your `client_id` and `client_secret`
3. Set your redirect URI to `https://your-server.com/api/v1/oauth/trakt/callback`
4. Install the plugin via the Phlex admin panel or CLI
5. Configure the plugin with your Trakt credentials
6. Authenticate via the OAuth flow

## Configuration

```php
// config/scrobblers/trakt.php
return [
    'client_id' => 'your-client-id',
    'client_secret' => 'your-client-secret',
    'redirect_uri' => 'https://your-server.com/api/v1/oauth/trakt/callback',
    'sync_interval' => 30, // minutes
];
```

## Scrobble Behavior

| Event | Trakt Action | Notes |
|-------|-------------|-------|
| PlaybackStarted | `start` | First scrobble when playback begins |
| PlaybackProgressUpdated (30s) | `pause` | Progress update throttled by player.js |
| PlaybackStopped | `stop` | Final scrobble with completion progress |

## History Sync

- **Trakt → Phlex**: Runs every 30 minutes (configurable). Pulls watched
  episodes/movies from Trakt and writes them to local watch history if
  not already at ≥90% complete.
- **Phlex → Trakt**: Pushes local history entries to Trakt after
  `PlaybackStopped` events where progress reached ≥90%.

## Events

The plugin subscribes to:
- `phlex.playback.started`
- `phlex.playback.stopped`
- `phlex.playback.progress`

## API Endpoints

- `GET /api/v1/oauth/trakt` — Initiate OAuth flow
- `GET /api/v1/oauth/trakt/callback` — OAuth callback handler

## License

Proprietary — Phlex Media Server
