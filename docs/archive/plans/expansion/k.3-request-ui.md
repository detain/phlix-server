# Step K.3 — Jellyseerr-Class Request UI on Hub

**Phase:** K (*arr Integration)
**Step:** K.3
**Depends on:** K.1 (Sonarr/Radarr clients needed for search + request flow)
**Review:** Yes — see `k.3-request-ui-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add a **request workflow** to the Phlex Hub web portal that lets users request:
- **Movies** — search TMDB, request via Radarr, notify when downloaded.
- **TV series** — search TMDB, request via Sonarr, specify season/episode.
- **Music** — not in scope (future).

This is modeled after **Jellyseerr** (the open-source request UI for Jellyfin/Emby). Users browse a catalog, request items, and are notified when the item is available in their library.

## 2. Context (what already exists)

Read first:

- `src/Server/WebPortal/WebPortalRouter.php` — existing portal routing.
- `src/Server/WebPortal/PageRenderer.php` — existing Smarty renderer.
- `src/Arr/SonarrClient.php`, `src/Arr/RadarrClient.php` — from K.1.
- `src/Media/Library/LibraryManager.php` — existing library.
- `public/templates/` — existing Smarty templates.
- `public/assets/js/api-client.js` — existing API client JS.

## 3. Scope — files to create / modify

### Create

#### Backend — request management

- `src/Requests/RequestManager.php`:
  - Manages user requests (movie/series), status (pending/approved/available/rejected).
  - `createRequest(string $userId, string $type, int $tmdbId, ?int $season = null, ?int $episode = null): array`
  - `approveRequest(string $requestId): bool` — triggers Sonarr/Radarr add
  - `rejectRequest(string $requestId, string $reason = ''): bool`
  - `getRequestStatus(string $requestId): string` — pending/approved/available/rejected
  - `listPendingRequests(?string $userId = null): array`
  - `listAvailableRequests(): array`

- `src/Requests/RequestNotification.php`:
  - Sends notifications when request status changes.
  - `notifyAvailable(string $userId, string $title): void` — email/websocket.
  - `notifyRejected(string $userId, string $title, string $reason): void`.

#### API endpoints

- `src/Server/Http/Controllers/Requests/RequestController.php`:
  - `GET /api/v1/requests` — list user's requests (with status).
  - `POST /api/v1/requests` — create new request (search TMDB first).
  - `PUT /api/v1/requests/{id}/approve` — admin approve.
  - `PUT /api/v1/requests/{id}/reject` — admin reject.
  - `DELETE /api/v1/requests/{id}` — delete request.

#### Database schema

- `migrations/00X_requests.sql`:
  ```sql
  CREATE TABLE requests (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    type ENUM('movie','series') NOT NULL,
    tmdb_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    poster_url VARCHAR(500),
    season INT,
    episode INT,
    status ENUM('pending','approved','available','rejected') DEFAULT 'pending',
    rejection_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status),
    INDEX idx_status (status)
  );
  ```

#### Smarty pages

- `public/templates/requests/index.tpl` — browse/search requests page:
  - TMDB-powered search bar (typeahead).
  - Movie/Series tabs.
  - "Request" button → POST /api/v1/requests.
  - My requests list with status badges.

- `public/templates/requests/detail.tpl` — request detail with approve/reject (admin only).

#### PageRenderer methods

- `renderRequestsPage(Request $request): Response` — list + search.
- `renderRequestDetail(Request $request, string $id): Response` — detail + admin actions.

### Modify

- `src/Server/WebPortal/WebPortalRouter.php` — add request routes.
- `src/Server/Core/Application.php` — register request routes.
- `CHANGELOG.md` — add entry.

## 4. Approach

1. Branch from master (after K.1 merged): `git checkout -b k.3-request-ui`.
2. Create migration first, then models, then controller, then Smarty templates.
3. Use TMDB for search (existing metadata provider pattern).
4. Request → stored in DB → Admin approves → SonarrClient/RadarrClient add → status updated → notification.
5. Write backend unit tests; JS tests are out of scope.
6. Verify: PHPUnit green, PHPStan level 9, PHPCS clean.
7. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `RequestManagerTest::test_create_request_stores_pending`
2. `RequestManagerTest::test_approve_request_triggers_radarr`
3. `RequestManagerTest::test_reject_request_sets_status`
4. `RequestManagerTest::test_list_pending_for_user`
5. `RequestManagerTest::test_list_available_requests`
6. `RequestNotificationTest::test_notify_available_sends`
7. `RequestControllerTest::test_list_requests_returns_user_requests`
8. `RequestControllerTest::test_create_request_returns_201`

## 6. Acceptance Criteria

- [ ] Migration creates `requests` table.
- [ ] `RequestManager` handles full request lifecycle.
- [ ] API: `GET/POST /api/v1/requests`, `PUT /approve`, `PUT /reject`.
- [ ] Smarty templates for request browsing and detail page.
- [ ] TMDB-powered search on request page.
- [ ] Admin can approve/reject; user gets notification.
- [ ] ≥ 8 new backend tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG entry added.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b k.3-request-ui
# ... implement ...
./vendor/bin/phpunit tests/Unit/Requests/
./vendor/bin/phpstan analyze src/Requests src/Server/Http/Controllers/Requests --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/Requests/ src/Server/Http/Controllers/Requests/
git add -A
git commit -m "Step K.3: Jellyseerr-class request UI on hub"
unset GITHUB_TOKEN
gh pr create --title "Step K.3: Jellyseerr-class request UI on hub" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `k.3-request-ui-review.md`.

(End of file - total 149 lines)
