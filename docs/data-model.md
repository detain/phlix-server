# Data model — table catalog

Every first-party table the server ships, with the migration that **creates**
it. This is a map, not a substitute for the DDL: column-level truth lives in
`migrations/NNN_*.sql` (and any later `ALTER`s), which are replayed in full on
a fresh database. The operator-facing migration workflow lives in
[README — Database & migrations](../README.md#database--migrations); the
`php bin/phlix migrate` command surface (its `scripts/run-migrations.php`
equivalence, exit codes and the closed idempotent-error list) lives in
[README — CLI](../README.md#cli).

## Migration numbering law

- File names are `NNN_snake_case_description.sql` with a **zero-padded
  three-digit prefix**. The prefix is the migration's ordinal.
- `Phlix\Common\Database\MigrationRunner` (and `scripts/run-migrations.php`,
  which delegates to it) discovers files with `glob()` + `sort()` — plain
  **lexicographic** order. At the fixed three-digit width lexicographic order
  equals numeric order; a four-digit ordinal would sort *before* the 2xx–9xx
  files lexicographically (`1000_…` lands ahead of `999_…`) — exactly why the
  three-digit law matters. The runner does not re-pad.
- **Gaps are legal and historical.** Applied files are recorded by name in
  `schema_migrations`; the runner skips what it has seen with a matching
  checksum, and replays are tolerated (`IF NOT EXISTS`, duplicate-error
  downgrade). A retired number (e.g. `016`, `099`) is never reused — new work
  takes the next free number.
- **Ordinals are NOT unique.** Several files share a prefix in the shipped tree —
  `003_marker_columns` + `003_plugins`, `004_admin_user_flag` +
  `004_smart_playlists`, `062_profile_tags` + `062_user_recommendations` — and
  same-prefix files therefore apply in lexicographic order of their full names,
  i.e. by description text. The one deliberately non-numeric prefix is `012a`
  (`012a_livetv_recordings.sql` sorts between `012_…` and `013_…`); README's
  migration section documents that legacy deviation.
- `cleanup_NNN.php` files are one-shot data cleanups, not schema migrations,
  and are not part of the `NNN_*.sql` sequence. The runner's glob (`*.sql`) is
  also blind to `migrations/080_backfill_missing_profiles.php` — despite its
  migration-style ordinal it is a retained manual diagnostic (see its own
  header), never applied by `migrate`. The ordinal law means nothing for files
  the glob cannot see.

## Forward-only law: recovery is restore-from-backup

There are no down-migrations: nothing in `migrations/` carries a paired
rollback and `MigrationRunner` has no reverse concept. The recovery path
for a migration that went wrong is **restore a BackupManager archive
(mysqldump-based, admin UI) and re-migrate** — not writing a rollback file.

That stance is also why hand-editing an already-applied file is almost
never the answer: the ledger treats a file whose checksum has DIVERGED as
not-yet-correctly-applied and **re-runs the whole file** on every installed
machine (with a warning, then refreshes the checksum). To stop a shipped
statement from running on new and old machines alike, use the
checksum-neutral full-line version-gate directive instead (the 050/051
case narrated in `src/Common/Database/MigrationRunner.php`), or add a
forward fix-up file taking the next free ordinal.

## Collections vs. media_collections (the classic confusion)

Two unrelated families share a noun:

- `collections` / `collection_items` (**005**) are the **user-owned**
  collections-and-playlists feature (member media lists, `created_by`
  visibility, smart-playlist linkage — see README
  [Collections and playlists](../README.md#collections-and-playlists-member-owned-lists)).
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
| `transcode_jobs` | 001 | Transcoding job queue and outcomes (see "Dead schema facts" for which status values are actually written). |
| `api_keys` | 001 | **Dead** — table created by 001 has no readers or writers anywhere in `src/` (see "Dead schema facts"). |
| `user_profiles` | 002 | Profile layer over accounts: parental controls, per-profile settings document (incl. `theme`). |
| `profile_settings` | 002 | Per-profile key/value settings. |
| `watch_history` | 002 | Completion/watch events per (user/profile, item) — Continue Watching & stats signal. |
| `plugins` | 003 | Installed-plugin registry rows (type, state, version). |
| `smart_playlists` | 004 | Rule documents powering smart collections (`collections.smart_playlist_id`). |
| `collections` | 005 | User-owned collection headers (`created_by` visibility model). |
| `collection_items` | 005 | Membership join between `collections` and `media_items` (+ ordering). |
| `media_extras` | 007 | Cached trailers/extras per item (`source=local\|tmdb`, local `Trailers/` folders) — read by `ExtrasRepository`/`TrailerResolver`. |
| `theme_media` | 008 | Per-library theme media (audio + backdrop video) scanned from disk. |
| `webauthn_credentials` | 010 | Passkey credentials per user (credential id, public key, counters). |
| `audiobook_progress` | 012 | Audiobook time position per (user, item). |
| `livetv_recordings` | 012a | DVR recording schedule/entries (predecessor of the 028 livetv family). |
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
| `newsletter_queue` | 020 | Per-user weekly newsletter delivery queue (status, attempts, error) — drained by `NewsletterSender`. |
| `backups` | 021 | Backup archive registry (path, size, SHA-256 checksum, S3 flag, expiry) — written by `BackupManager`. |
| `lastfm_sessions` | 023 | Last.fm scrobbler session/link per user. |
| `server_settings` | 026 | Typed dotted-key overrides backing the admin settings subsystem (`SettingsRepository::getEffective()` = config default + row override). |
| `library_scan_jobs` | 027 | Scan/rescan lifecycle + progress counters for the library scan pipeline; the table *is* the queue (no Redis in the stack). |
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
| `rate_limit_buckets` | 085 | Shared, DB-backed per-surface rate-limit buckets. |
| `subtitle_provider_quota` | 088 | External subtitle-provider daily quota accounting (external-tracks era). |
| `user_identities` | 092 | Linked external identities per user (oidc/github/ldap/hub). |
| `plugin_settings` | 093 | DB-backed per-plugin settings documents (provider configs live here). |
| `maintenance_jobs` | 098 | One-off admin maintenance task queue (storage snapshots, path dedup) — sibling of `library_scan_jobs`, drained by `MaintenanceQueueWorker` on a timer in the background-timers worker. |
| `client_heartbeats` | 106 | Consent-gated client telemetry heartbeats, one UPSERTed row per client. |
| `first_admin_election` | 108 | Single-row sentinel serializing first-admin bootstrap. |
| `casting_sessions` | 111 | DB-shared casting sessions re-attached by the DLNA/Roku/Chromecast/AirPlay managers. |
| `login_rate_limit_account` | 126 | Per-account login failure window — sibling of `login_rate_limit` (074), keyed by the normalized submitted identifier instead of the client IP. |
| `auth_token_sessions` | 127 | One row per login ceremony (a session "family"): access and refresh JWTs carry its id as the `sid` claim, so revoking one row signs exactly one device out while `users.tokens_not_valid_after` (107) stays the account-wide watermark. Tokens without a `sid` claim never consult it. |
| `auth_refresh_grants` | 127 | One row per minted refresh token, keyed by its `jti` (FK → `auth_token_sessions`, cascade). Rotation stamps `consumed_at`; replaying a consumed grant outside the 10 s grace window revokes the family. 128 adds `expires_at` (the token's own `exp`; NULL on pre-128 rows) and `idx_arg_expires_at` for the expiry sweep. |

## Dead schema facts (measured at the 126-era tip)

Three pieces of shipped schema carry no live code. They are documented here —
NOT deleted: the forward-only law above forbids editing applied files, and
removing a live table/column requires a deliberate drop-migration decision,
which none of these has. Pinned by
`tests/Unit/Media/Transcoding/TranscodeJobSchemaTruthTest.php`; if a future
feature revives one, rotate the pin in the same commit.

- **`transcode_jobs` status values actually written.** The 001 column is
  `ENUM('queued','running','completed','failed','cancelled')`. Today the only
  DB writers are the `getJobReadiness()` marker sync (`completed`/`failed`),
  the on-demand job INSERT (seeds `completed` directly) and
  `reapStaleRunningJobs()` (`failed`, guarded `AND status = 'running'`).
  Consequences: `cancelled` has **no writer at all** (no cancel endpoint or
  flow exists — the read-side carve-out and the predecessor guards are
  pre-armed for a hypothetical future one); `running` rows can only be legacy
  corpses from before the on-demand cutover (what the reaper exists to retire);
  `queued` is the enum DEFAULT but is never explicitly written. The
  `TranscodeManager::STATUS_PENDING` constant is dead **and unrepresentable**
  ('pending' is not an enum member); it survives only as external API surface.
  There is likewise no `TranscodeManager::completeJob()` method — the docblock
  that once implied one now names the two real writers.
- **`api_keys` (001).** Zero readers, zero writers anywhere in `src/`. The
  former catalog prose called it "Static API keys with scopes" — doubly wrong:
  the feature never landed, and the table has no scopes column (id, user_id,
  key_hash, name, created_at, expires_at). Server auth uses JWTs
  (`AuthController`) and hub pairing tokens, not table-backed keys.
- **`sessions.profile_id` (002:56).** Added "for multi-profile support" but
  `SessionManager` never lists the column in any INSERT/UPDATE, so it is NULL
  on every row. Profile identity travels in the **JWT claim** instead — the
  honest explanations live in `src/Auth/JwtHandler.php` ("Why the JWT and not
  `sessions.profile_id`") and `src/Auth/WatchHistory.php` (next-up resolves
  `profileId → userId` and scopes by `user_id` because the column is always
  NULL). Do not build new reads against the column.

## Reading notes

- **Completeness:** the header claim is kept honest by measurement — at the
  128-era tip the distinct `CREATE TABLE` targets across `migrations/*.sql`
  number 85, `migrations/` contains no `DROP TABLE`, and the catalog above is
  exactly those 85 rows one-for-one. New migration → new row, or the claim
  goes stale the way it once did (76 rows against 82 tables, and again 83
  rows against 85 tables before 127's pair was added). Re-measure, never
  hand-copy: count statement lines only, because migration comments quote
  `CREATE TABLE IF NOT EXISTS` in prose and a naive grep picks up words such
  as `IF`/`so`/`for` as table names —
  `` grep -hiE '^\s*CREATE TABLE' migrations/*.sql | grep -oiE 'CREATE TABLE( IF NOT EXISTS)? `?[a-z_0-9]+' | sed -E 's/.* `?//' | sort -u | wc -l ``.
- "Created by" names the **first** migration whose `CREATE TABLE` reaches the
  table; later migrations may `ALTER` it (and a couple replay-guard their
  creation with `IF NOT EXISTS`). Follow the number in `migrations/` for
  column truth.
- **`PREPARE`/`EXECUTE` guards hide DDL inside strings.** MySQL 8 has no
  `IF NOT EXISTS` on `ADD COLUMN`/`ADD INDEX`, so a family of migrations
  (16 files at the 128-era tip — e.g. `096`, `107`, `128`) probes
  `INFORMATION_SCHEMA.COLUMNS`/`STATISTICS`, builds the `ALTER TABLE …` (or a
  no-op `SELECT 1`) into a user variable with `SELECT IF(…)`, then runs it
  via `PREPARE … FROM @var; EXECUTE …; DEALLOCATE PREPARE …`. The real DDL
  is therefore a **quoted string literal**, not a statement line: a
  line-anchored grep for `ALTER TABLE` misses those columns and indexes, so
  read the quoted statements too when chasing column truth. The
  `CREATE TABLE` census above is unaffected — no prepared string creates a
  table at this tip.
- Tables are InnoDB, `utf8mb4`, and since `120_collation_unicode_ci_conversion`
  consolidate on `utf8mb4_unicode_ci`; key columns are `CHAR(36)` UUIDs.
- Server-side entity removal is mostly a hard `DELETE`; the library/item
  trash-era flows keep their own status fields.
