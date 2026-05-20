# Last.fm Scrobble Plugin — Developer Guide

**Since:** 0.15.0
**Plugin type:** `scrobbler`
**Reference implementation:** `Phlix\Plugins\Lastfm\Plugin`

This document covers the Last.fm API protocol, scrobble semantics,
session key management, and configuration options for the built-in
Last.fm scrobble plugin.

---

## 1. Overview

Phlix ships an in-core Last.fm scrobbler as a **reference implementation**
of the `scrobbler` plugin type. It is enabled by creating a plugin entry
in `config/plugins.php` (or installing via the admin UI from the plugin
catalog). The plugin:

1. Subscribes to `phlix.playback.started` and `phlix.playback.stopped`.
2. On **start**: sends a `track.updateNowPlaying` notification (if
   `submit_now_playing` is `true`).
3. On **stop**: sends a `track.scrobble` submission (if the
   `scrobble_threshold` is met and the session key is valid).

The plugin is **off by default** (`enabled: false` in
`config/lastfm.php`). The operator must provide API credentials and
authenticate a session key before it will function.

---

## 2. Last.fm API Protocol

The Last.fm API uses HMAC-MD5 signing for all authenticated calls.
Every request is a POST to `https://ws.audioscrobbler.com/2.0/`.

### 2.1 Signing

Parameters are sorted alphabetically, concatenated as `key1value1key2value2...`,
and the API secret is appended before computing the MD5 digest:

```php
function signParams(array $params, string $apiSecret): string
{
    ksort($params);
    $str = '';
    foreach ($params as $key => $value) {
        $str .= $key . $value;
    }
    $str .= $apiSecret;
    return md5($str);
}
```

### 2.2 Mobile Authentication (`auth.getMobileSession`)

Used to obtain a session key without a web OAuth redirect.

```
POST https://ws.audioscrobbler.com/2.0/
Parameters:
  method=auth.getMobileSession
  api_key=<API_KEY>
  username=<username>
  password_hash=<md5(password)>
  api_sig=<computed>
  format=json
```

Response:
```json
{
  "session": {
    "name": "username",
    "key": "abc123sessionkey",
    "subscriber": 1
  }
}
```

Store the `key` as the `session_key` in config. Session keys do not
expire unless revoked.

### 2.3 Scrobble (`track.scrobble`)

```
POST https://ws.audioscrobbler.com/2.0/
Parameters:
  method=track.scrobble
  api_key=<API_KEY>
  sk=<session_key>
  artist=<artist_name>
  track=<track_title>
  timestamp=<unix_timestamp>
  [album=<album_name>]
  [duration=<duration_seconds>]
  [trackNumber=<track_number>]
  [mbid=<musicbrainz_recording_id>]
  api_sig=<computed>
  format=json
```

Response:
```json
{
  "scrobbles": {
    "@attr": { "artist": "...", "track": "...", "status": "ok" }
  }
}
```

`status` will be `ok` on success, or the response will contain an
`error` code on failure.

### 2.4 Now Playing (`track.updateNowPlaying`)

```
POST https://ws.audioscrobbler.com/2.0/
Parameters:
  method=track.updateNowPlaying
  api_key=<API_KEY>
  sk=<session_key>
  artist=<artist_name>
  track=<track_title>
  [album=<album_name>]
  [duration=<duration_seconds>]
  [mbid=<musicbrainz_recording_id>]
  api_sig=<computed>
  format=json
```

Response:
```json
{
  "nowplaying": {
    "@attr": { "artist": "...", "track": "...", "status": "ok" }
  }
}
```

Now Playing does **not** scrobble. It only updates what displays on
the user's Last.fm profile as "now playing".

---

## 3. Scrobble Threshold

The `scrobble_threshold` setting (0.0 – 1.0) controls when a
scrobble is actually submitted on `playback.stopped`.

```
scrobble_submitted = (finalPositionTicks / durationTicks) >= scrobbleThreshold
```

| Value | Behaviour |
| ----- | --------- |
| `0.0` | Scrobble immediately on every stop |
| `0.5` | Default — scrobble after 50% of track played |
| `1.0` | Only scrobble when track reaches end |

When `durationTicks` is unknown (live streams, some video), the
threshold check is bypassed and the scrobble is always submitted.

---

## 4. Configuration Reference

| Key                  | Type    | Default | Description                                    |
| -------------------- | ------- | ------- | ---------------------------------------------- |
| `enabled`            | bool    | `false` | Must be `true` to activate                     |
| `api_key`           | string  | `''`    | From last.fm/api/account/create               |
| `api_secret`        | string  | `''`    | From last.fm/api/account/create               |
| `session_key`       | string  | `''`    | From `getMobileSession()` call                 |
| `username`          | string  | `''`    | Last.fm username for scrobble attribution     |
| `submit_now_playing` | bool   | `true`  | Send `updateNowPlaying` on `playback.started` |
| `scrobble_threshold` | float  | `0.5`   | Fraction of track required before scrobble    |

### Obtaining credentials

1. Go to https://www.last.fm/api/account/create
2. Create an API account (name can be "Phlix Media Server")
3. Copy the **API Key** and **API Secret**
4. Use `LastfmApiClient::getMobileSession('username', md5('password'))`
   once to get a session key (use PHP CLI or a script)
5. Fill in `config/lastfm.php` with all values and set `enabled: true`

---

## 5. Class Reference

### `Phlix\Plugins\Lastfm\LastfmApiClient`

```php
class LastfmApiClient
{
    public function __construct(string $api_key, string $api_secret, ?LoggerInterface $logger = null);

    /** Authenticate with username + password hash. Returns session key. */
    public function getMobileSession(string $username, string $passwordHash): string;

    /** Validate that a session key is currently valid. */
    public function validateSession(string $sessionKey): bool;

    /** Submit a scrobble. */
    public function scrobble(ScrobbleData $data): bool;

    /** Update Now Playing status. */
    public function nowPlaying(NowPlayingData $data): bool;
}
```

### `Phlix\Plugins\Lastfm\ScrobbleData`

```php
final readonly class ScrobbleData
{
    public function __construct(
        public string $artist_name,
        public string $track_title,
        public int $timestamp_unix,
        public ?string $album_name = null,
        public ?int $track_number = null,
        public ?int $duration_secs = null,
        public ?string $mbid = null,
    ) {}
}
```

### `Phlix\Plugins\Lastfm\NowPlayingData`

```php
final readonly class NowPlayingData
{
    public function __construct(
        public string $artist_name,
        public string $track_title,
        public ?string $album_name = null,
        public ?int $duration_secs = null,
        public ?string $mbid = null,
    ) {}
}
```

---

## 6. Error Handling

| Exception                                    | When thrown                                          |
| -------------------------------------------- | ---------------------------------------------------- |
| `LastfmPluginNotConfiguredException`         | `api_key` or `api_secret` is empty                   |
| `LastfmScrobbleFailedException`             | API returns a non-OK status with an error code        |

Both exceptions extend `RuntimeException`. The plugin catches them
internally and logs warnings rather than propagating — one failed
scrobble should not crash the playback pipeline.

---

## 7. Plugin Architecture

```
Phlix\Plugins\Lastfm\Plugin
  ├── implements LifecycleInterface        (onEnable/onDisable/subscribedEvents)
  ├── implements EventSubscriberInterface (getSubscribedEvents)
  │
  ├── LastfmApiClient        (HTTP calls to Last.fm)
  ├── SessionManager         (session lookup)
  └── ItemRepository         (media item lookup for artist/track metadata)
```

The plugin resolves `ItemRepository` from the PSR-11 container in
`onEnable()`. The `LastfmApiClient` is constructed fresh in
`buildApiClient()` using the stored config — this makes the client
easily mockable in tests.

---

## 8. Testing

Unit tests are in `tests/unit/Plugins/Lastfm/`:

- `LastfmApiClientTest` — tests value object immutability, exception
  classes, and API client construction.
- `PluginTest` — tests event subscriptions, configuration parsing,
  threshold handling, and lifecycle.

Run with:

```bash
./vendor/bin/phpunit tests/unit/Plugins/Lastfm/
```
