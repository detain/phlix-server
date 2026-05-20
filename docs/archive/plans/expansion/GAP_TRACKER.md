# Phlex Expansion Gap Tracker

**Last Updated:** 2026-05-19
**Purpose:** Track remaining gaps after Phase A-P implementation review

---

## LOW PRIORITY / ALREADY COMPLETED

| Gap | Status | Notes |
|-----|--------|-------|
| B.2a/B.4a/B.5a metadata | DONE | All 3 repos have description + 19 topics |
| phlex-shared in composer.json | DONE | `detain/phlex-shared:^0.4.0` |
| Skip protocol docs | DONE | `docs/reference/skip-button-protocol.md` (74 lines) |
| L.4 Dashboard WebSocket | DONE | False alarm - WebSocketEvents has DASHBOARD_NOW_PLAYING |
| L.5 Newsletter migration | DONE | `migrations/020_newsletter.sql` |
| L.6 Backup migration | DONE | `migrations/021_backups.sql` |
| K.1-K.4 *arr clients | DONE | In phlex-shared/src/Arr/ + phlex-hub/src/Requests/ |
| O.6 Release scripts | DONE | `scripts/release.sh`, `scripts/docker-release.sh` |
| Rate-limiting (SERVER-A07-1) | DONE | `AuthManager.php` has checkRateLimit() |
| v1.0.0 tags | DONE | All 3 repos tagged |
| F.4 - MediaItemController::getPlaybackInfo() | DONE | Route registered, end_seconds fixed, 10 tests pass |
| F.4 - SessionController markers | DONE | getSessionController() factory, 4 routes, 8 tests pass |
| F.2 - Database Confidence Columns | DONE | Migration 024 added intro_confidence + outro_confidence columns |
| F.2 - findShowsWithUnfingerprintedEpisodes() | DONE | ItemRepository method + delegation, 4 tests pass |
| B.4b - detain/phlex Archive | DONE (CLOSED) | Repo was deleted (not archived). Original plan B.4b cannot be executed since repo no longer exists. Code already migrated to phlex-server. |
| C.1 - Pairing Design Plan File | DONE (CLOSED) | Design exists at `docs/dev/pairing-protocol.md` (635 lines). Plan file was never created in plans/expansion/ but design doc exists - no action needed. |
| P.3 - release-execution.md | DONE | Updated to reflect tags are pushed (was "pending", now "pushed 2026-05-19") |
| P.4 - Announcement Plan | DONE | Created p.4-announce.md covering blog, HN, social, GitHub, community channels |

---

## WORKFLOW LOG

### F.4 - MediaItemController::getPlaybackInfo()
- [x] Coder: DONE - Added method using MarkerService + SkipButtonSpec
- [x] Reviewer: FAIL (dead code - not routed)
- [x] FixerCoder: DONE - Registered route in Application.php, added end_seconds to chapters
- [x] Reviewer 2: APPROVED
- [x] TestEngineer: DONE - 10 tests pass, coverage generated

### F.4 - SessionController Marker Handling
- [x] Coder: DONE - Added buildMarkerData(), marker fields in getProgress()
- [x] Reviewer: FAIL (not wired - constructor needs 3 args)
- [x] FixerCoder: DONE - getSessionController() factory, 5 routes registered
- [x] Reviewer 2: APPROVED
- [x] TestEngineer: DONE - 8 tests pass

### F.2 - Database Confidence Columns
- [x] Coder: DONE - Migration 024_marker_confidence.sql
- [x] Reviewer: PASS (minor BTREE inconsistency - non-blocking)
- [x] TestEngineer: DONE - No DB available, verified file syntax manually

### F.2 - findShowsWithUnfingerprintedEpisodes()
- [x] Coder: DONE - ItemRepository method + IntroDetectionJob delegation
- [x] Reviewer: APPROVED
- [x] TestEngineer: DONE - 4 tests added and passing

### B.4b - detain/phlex Archive
- [x] Investigated: Repo was DELETED (not archived). Original plan cannot be executed since repo doesn't exist. No further action possible.

### P.3 - release-execution.md
- [x] Updated: Changed "Tags pending" to "Pushed 2026-05-19" and checkboxes to checked

### P.4 - Announcement Plan
- [x] Coder: DONE - Created p.4-announce.md (392 lines)
- [x] Reviewer: PASS

---

*All high and medium priority gaps have been addressed. Remaining items are minor documentation fixes that do not block release.*
