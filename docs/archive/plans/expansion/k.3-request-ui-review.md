# Step K.3 — Jellyseerr-Class Request UI: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit tests/Unit/Requests/
# MUST be green; ≥ 8 new tests

# ── 2. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/Requests src/Server/Http/Controllers/Requests --level=9 --no-progress
# Zero errors

# ── 3. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/Requests/ src/Server/Http/Controllers/Requests/
# Clean

# ── 4. Migration exists ────────────────────────────────────
ls migrations/00X_requests.sql
# File must exist
```

## Acceptance Criteria

- [ ] Migration `migrations/00X_requests.sql` creates `requests` table with all columns.
- [ ] `RequestManager::createRequest()` stores pending request and returns array with id.
- [ ] `RequestManager::approveRequest()` calls SonarrClient/RadarrClient add + updates status.
- [ ] `RequestManager::rejectRequest()` sets `status='rejected'` with reason.
- [ ] `RequestController` exposes: `GET/POST /api/v1/requests`, `PUT /api/v1/requests/{id}/approve`, `PUT /api/v1/requests/{id}/reject`.
- [ ] Smarty templates: `public/templates/requests/index.tpl` and `detail.tpl`.
- [ ] TMDB-powered search on request page.
- [ ] `RequestNotification::notifyAvailable()` and `notifyRejected()` handle status changes.
- [ ] ≥ 8 new backend tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

(End of file - total 40 lines)
