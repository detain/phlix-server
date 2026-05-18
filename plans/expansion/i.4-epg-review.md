# Step I.4 — Schedules Direct EPG: Review Checklist

## Reviewer: run these commands.

```bash
cd /home/sites/phlex

./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'SdApi|SdProgram|SdEpg'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
grep -A5 "'schedules_direct'" config/livetv.php
ls docs/developers/schedules-direct.md
```

## Acceptance Criteria:

- [ ] `SdApiClient` sends `Authorization: Bearer <token>` header on all requests
- [ ] `SdApiClient::fetchToken()` returns token string or `null` on bad credentials
- [ ] `SdApiClient::getSchedules()` POSTs to `/schedules` with `stationIDs` and `dates`
- [ ] `SdApiClient::getPrograms()` POSTs to `/programs` with `programIDs`
- [ ] `SdLineupHandler::importLineup()` calls `channelManager->createChannel()` per station
- [ ] `SdProgramMapper::map()` handles: `title`, `description`, `categories`, `episode`, `rating`, `year`
- [ ] `SdProgramMapper::map()` handles missing fields gracefully (null checks)
- [ ] `SdEpgService::syncEpg()` returns `['imported' => int, 'errors' => array]`
- [ ] SD token is not logged in plain text
- [ ] `config/livetv.php` has `schedules_direct.username` and `schedules_direct.password`
- [ ] ≥ 12 new unit tests pass
- [ ] PHPStan level 9 clean
- [ ] PHPCS clean
- [ ] `docs/developers/schedules-direct.md` exists
- [ ] CHANGELOG updated

## Non-obvious points:

- SD API v2 uses JSON over HTTPS. Token is stored in `sd_token_cache_path` file.
- Schedule diffing: `SdEpgService` calls `getScheduleMd5()` first; if md5 unchanged
  since last sync, skip the full schedule fetch to save API calls/quota.
- Programs with no episode info are mapped as `CATEGORY_OTHER` unless `isMovie` is set.
- `SdApiClient::fetchToken()` uses HTTP Basic Auth: base64(`username:password`).
