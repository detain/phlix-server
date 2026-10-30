# Data model — table catalog

Every first-party table the server ships, with the migration that **creates**
it. This is a map, not a substitute for the DDL: column-level truth lives in
`migrations/NNN_*.sql` (and any later `ALTER`s), which are replayed in full on
a fresh database. The operator-facing migration workflow lives in
[README — Database & migrations](../README.md#database--migrations).

## Migration numbering law

- File names are `NNN_snake_case_description.sql` with a **zero-padded
  three-digit prefix**. The prefix is the migration's ordinal.
- `Phlix\Common\Database\MigrationRunner` (and `scripts/run-migrations.php`,
  which delegates to it) discovers files with `glob()` + `sort()` — plain
  **lexicographic** order. At the fixed three-digit width lexicographic order
  equals numeric order; a four-digit prefix would still sort after `999`, but
  the house convention is three digits and the runner does not re-pad.
- **Gaps are legal and historical.** Applied files are recorded by name in
  `schema_migrations`; the runner skips what it has seen with a matching
  checksum, and replays are tolerated (`IF NOT EXISTS`, duplicate-error
  downgrade). A retired number (e.g. `016`, `099`) is never reused — new work
  takes the next free number.
- `cleanup_NNN.php` files are one-shot data cleanups, not schema migrations,
  and are not part of the `NNN_*.sql` sequence.

## Collections vs. media_collections (the classic confusion)

Two unrelated families share a noun:

- `collections` / `collection_items` (**005**) are the **user-owned**
  collections-and-playlists feature (member media lists, `created_by`
  visibility, smart-playlist linkage — see README
  [Collections and Playlists](../README.md#collections-and-playlists-member-owned-lists)).
- `media_collections` / `media_collection_members` (**064**) are **TMDB
  box-sets**: server-side catalog data synced from TMDB so the UI can show
  "Part of a Collection" (e.g. "The Lord of the Rings Films"). No user owns
  them.

## Catalog

| Table | Created by | Purpose |
|-------|-----------|---------|
| `users` | 001 | Accounts: credentials, email, admin flag, status (`pending`/`active`/`disabled`). |
| `user_settings` | 001 | Per-user key/value settings. |
| `libraries` | 001 | Library mounts: name, type (`movie`/`series`/`music`/`audiobook`/`photo`/`book`/`video`), paths, options blob. |
| `media_items` | 001 | One scanned media file/entry: path, container, metadata pointers, library link. |
| `media_streams` | 001 | Per-item audio/subtitle/video stream rows (codec, language, default flags). |
| `playback_state` | 001 | Resume position per (user, media item). |
| `sessions` | 001 | Device sessions (id/name/type, activity timestamps). |
| `transcode_jobs` | 001 | Transcoding job queue and outcomes. |
| `api_keys` | 001 | Static API keys with scopes. |
| `user_profiles` | 002 | Profile layer over accounts: parental controls, per-profile settings document (incl. `theme`). |
| `profile_settings` | 002 | Per-profile key/value settings. |
| `watch_history` | 002 | Completion/watch events per (user/profile, item) — Continue Watching & stats signal. |
| `plugins` | 003 | Installed-plugin registry rows (type, state, version). |
| `smart_playlists` | 004 | Rule documents powering smart collections (`collections.smart_playlist_id`). |
| `collections` | 005 | User-owned collection headers (`created_by` visibility model). |
| `collection_items` | 005 | Membership join between `collections` and `media_items` (+ ordering). |
| `theme_media` | 008 | Per-library theme media (audio + backdrop video) scanned from disk. |
| `webauthn_credentials` | 010 | Passkey credentials per user (credential id, public key, counters). |
| `livetv_recordings` | 012a | DVR recording schedule/entries (predecessor of the 028 livetv family). |
| `audiobook_progress` | 012 | Audiobook time position per (user, item). |
| `livetv_series_rules` | 013 | DVR auto-record rules (series → new-episode recording). |
| `livetv_relay_sessions` | 015 | Hub-relayed tuner sessions. |
| `custom_format_sync` | 017 | Custom playback format sync state (external app integration). |
| `trash_guides_sync_log` | 017 | Sync log for the Trash Guides plugin family. |
| `webhooks` | 018 | Outgoing webhook endpoint definitions (V1 era). |
| `webhook_logs` | 018 | Per-delivery log rows for the 018-era webhooks. |
| `stats_playback_events` | 019 | Append-only playback event stream (reporting aggregate source). |
| `stats_user_activity` | 019 | User activity aggregates. |
| `stats_library_changes` | 019 | Library change events (added/removed items). |
| `stats_storage` | 019 | Storage-size snapshots. |
| `lastfm_sessions` | 023 | Last.fm scrobbler session/link per user. |
| `livetv_channels` | 028 | Channel catalog (guide-derived). |
| `livetv_lineups` | 028 | Tuner lineups (wireguide-style). |
| `livetv_lineup_channels` | 028 | Lineup ↔ channel mapping. |
| `livetv_programs` | 028 | EPG program rows. |
| `livetv_tuners` | 028 | Discovered/configured tuners. |
| `livetv_favorites` | 028 | Per-user channel favorites. |
| `imdb_titles` | 029 | IMDb id ↔ title cache (strict-match path). |
| `user_item_data` | 039 | Per-(user, item) favorite + rating state (E10). |
| `syncplay_snapshots` | 042 | Persisted SyncPlay group state (REST write path; WS worker re-attaches). |
| `metrics_rollup` | 046 | Time-bucketed connection-metrics aggregates. |
| `metrics_connections` | 046 | Connection metric samples. |
| `metrics_route_rollup` | 046 | Per-route latency/count aggregates. |
| `oauth_state_store` | 048 | One-shot OIDC/GitHub `state` records (PKCE + redirect_uri pin). |
| `media_item_genres` | 051 | Normalized genre join table for `media_items`. |
| `metadata_ratings` | 052 | Multi-source ratings per item (provider, value, votes). |
| `media_markers` | 053 | Per-item time ranges typed `intro`/`outro`/`credits`/`ad` (skip-marker surface). |
| `manual_match_overrides` | 055 | Operator corrections pinning an item to a metadata id. |
| `network_health_log` | 057 | Relay/network health probes with latency metrics. |
| `item_similar` | 060 | Pre-computed similarity scores for the "More like this" rails. |
| `access_schedules` | 061 | Server-level time-window access rules (403 during denials). |
| `profile_access_schedule` | 061 | Per-profile time-window access rules. |
| `profile_tags` | 062 | Per-profile tag blocking/allowing (blocked tags exclude browse results). |
| `user_recommendations` | 062 | Pre-computed "because you watched" top-K recommendations per user. |
| `active_streams` | 063 | Live concurrent-stream accounting. |
| `profile_stream_limits` | 063 | Per-profile concurrent-stream caps. |
| `media_collections` | 064 | TMDB box-set headers (catalog data — see contrast above). |
| `media_collection_members` | 064 | Box-set ↔ `media_items` membership. |
| `music_artists` | 065 | Music library artist entities. |
| `music_albums` | 065 | Music library album entities. |
| `music_tracks` | 065 | Music library track entities (metadata layer over `media_items`). |
| `webhook_events` | 066 | Event-type catalog rows (V2 webhooks). |
| `webhook_deliveries` | 066 | V2 delivery queue/results with signature state. |
| `webhook_subscriptions` | 066 | V2 subscriptions: endpoint ↔ event types. |
| `login_rate_limit` | 074 | Central login-attempt throttling store (replaces the per-worker static array). |
| `book_progress` | 075 | Per-user per-book progress incl. page-level resume-reading. |
| `schema_migrations` | 076 | The migration ledger (file name + checksum). |
| `livetv_timeshift_sessions` | 078 | Timeshift DVR sessions. |
| `imdb_title_akas` | 082 | Local copy of the useful subset of IMDb alternate/localized titles (akas). |
| `rate_limit_buckets` | 085 | Shared, DB-backed per-surface rate-limit buckets (SV-4.15). |
| `subtitle_provider_quota` | 088 | External subtitle-provider daily quota accounting (external-tracks era). |
| `user_identities` | 092 | Linked external identities per user (oidc/github/ldap/hub). |
| `plugin_settings` | 093 | DB-backed per-plugin settings documents (provider configs live here). |
| `first_admin_election` | 108 | Single-row sentinel serialising first-admin bootstrap (L-3). |
| `client_heartbeats` | 106 | Consent-gated client telemetry heartbeats (AD-27/S518), one UPSERTed row per client. |
| `casting_sessions` | 111 | DB-shared casting sessions re-attached by the DLNA/Roku/Chromecast/AirPlay managers (Device-M1). |

## Reading notes

- "Created by" names the **first** migration whose `CREATE TABLE` reaches the
  table; later migrations may `ALTER` it (and a couple replay-guard their
  creation with `IF NOT EXISTS`). Follow the number in `migrations/` for
  column truth.
- Tables are InnoDB, `utf8mb4`, and since `120_collation_unicode_ci_conversion`
  consolidate on `utf8mb4_unicode_ci`; key columns are `CHAR(36)` UUIDs.
- Server-side entity removal is mostly a hard `DELETE`; the library/item
  trash-era flows keep their own status fields.
