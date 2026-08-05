---
paths:
  - "src/Access/**"
  - "src/Server/Http/Middleware/AccessScheduleMiddleware.php"
  - "src/Server/Http/Middleware/StreamLimitMiddleware.php"
  - "src/Server/Http/Controllers/AccessScheduleController.php"
  - "src/Server/Http/Controllers/ProfileTagController.php"
  - "src/Server/Http/Controllers/StreamLimitController.php"
  - "migrations/061_access_schedules.sql"
  - "migrations/062_profile_tags.sql"
  - "migrations/063_device_stream_limits.sql"
---

# Access Module (P5 Parental Controls)

- **Profile IDs are `string` UUIDs**, never `int`. `profile_id` columns are `CHAR(36)` matching `user_profiles.id` (migrations `061_access_schedules.sql`, `062_profile_tags.sql`, `063_device_stream_limits.sql`). All `src/Access/` service methods take `string $profileId`; `ProfileStreamLimit::fromRow()` narrows with `is_string()`, not `is_numeric()`.
- `RequestContext::setProfileId(?string)` / `getProfileId(): ?string` — the request-scoped profile id is a UUID string (`src/Server/Http/RequestContext.php`).
- **Services** (`src/Access/`): `AccessScheduleService` — time-window access control, `isAccessAllowed()` returns false when an active schedule matches (middleware responds 403). `ProfileTagService` — `blocked`/`allowed` tag lists (`ProfileTag::TYPE_BLOCKED`/`TYPE_ALLOWED`); tag filtering is applied in `ItemRepository::query()` from the `RequestContext` profile id. `StreamSessionService` + `ProfileStreamLimit` — per-profile concurrent-stream caps backed by `profile_stream_limits` and `active_streams`.
- **Routes**: `/api/v1/profiles/{profileId}/schedules`, `/tags`, `/stream-limits`, `/active-streams` are registered in `src/Server/Core/Application.php` via `$router->group('', $cb, [new AuthMiddleware()])` — always gate these behind `AuthMiddleware`; `AccessScheduleMiddleware` and `StreamLimitMiddleware` run globally after auth.
- DB access follows the repo-wide rule: `Workerman\MySQL\Connection` with parameterized `?` placeholders only.
