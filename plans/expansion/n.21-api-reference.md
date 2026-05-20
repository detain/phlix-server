---
status: not-started
phase: N
updated: 2026-05-19
---

# Step N.21 — API Reference (OpenAPI/Swagger)

**Phase:** N (End-User Documentation)
**Step:** N.21
**Depends on:** C.9 (hub shared libraries — already merged)
**Review:** No (doc-only step)
**Target repo:** phlex-server (local: `/home/sites/phlex/`)
**One-liner:** API reference (OpenAPI/Swagger auto-generated from PHP attrs)

---

## Goal

Write a unified API reference covering all public REST endpoints, auto-generated from PHP OpenAPI attributes via `swagger-php`, with a Swagger UI explorer at `/api/v1/docs` and the raw OpenAPI 3.x spec at `/api/v1/openapi.json`. Fall back to manual markdown documentation in `docs/reference/api.md` for any endpoints not covered by attributes.

Deliverables:
- `composer.json` updated with `swaggerphp/swagger-php` (latest stable)
- `@OA\*` attributes on all controller action methods (`src/Server/Http/Controllers/`)
- OpenAPI spec served at `GET /api/v1/openapi.json`
- Swagger UI mounted at `GET /api/v1/docs`
- `docs/reference/api.md` expanded with all endpoint groups in the existing manual format
- `docs/reference/api/` individual files for Admin and Hub sections

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Install `swaggerphp/swagger-php` instead of documenting manually | swagger-php is the industry standard for PHP OpenAPI generation; auto-generation keeps docs in sync with code | N.21 goal |
| OpenAPI 3.0.x (not 3.1) for maximum tool compatibility | Most OpenAPI tools (Stoplight, Insomnia, redocly) support 3.0 best; swagger-php 4.x defaults to 3.0 | swagger-php docs |
| Serve spec at `/api/v1/openapi.json` — no separate YAML file in the repo | The router can serialize the spec on demand; avoids stale file syndrome | convention decision |
| Swagger UI at `/api/v1/docs` using `swagger-api/swagger-ui` dist (vendor'd or CDN) | Keeps the UI under the same origin; avoids CORS issues with third-party CDNs | standard practice |
| Manual markdown fallback for endpoints not yet annotated | Step must ship complete docs; annotate incrementally | N.21 scope |
| Per-endpoint file in `docs/reference/api/` for Admin and Hub (admin-plugins.md, hub-jwks.md already exist) | Easier to maintain; already established pattern in docs/reference/api/ | existing structure |

---

## Phase 1: Investigate & Scaffold [IN PROGRESS]

- [ ] **1.1** Verify `swaggerphp/swagger-php` is absent from `composer.json`
- [ ] **1.2** Read existing controller files to understand current structure:
  - `src/Server/Http/Controllers/AuthController.php`
  - `src/Server/Http/Controllers/LibraryController.php`
  - `src/Server/Http/Controllers/MediaItemController.php`
  - `src/Server/Http/Controllers/SessionController.php`
  - `src/Server/Http/Controllers/HlsController.php`
- [ ] **1.3** Read `src/Server/Core/Application.php` to see where router setup happens and how `/api/v1/` routes are registered
- [ ] **1.4** Check if any controller already has `@OA\*` annotations (grep for `/** @OA` in `src/`)
- [ ] **1.5** Read existing `docs/reference/api.md` in full to understand the current manual format to preserve

---

## Phase 2: Install swagger-php [PENDING]

- [ ] **2.1** Add to `composer.json` require: `"swaggerapi/swagger-php": "^4.0"`
- [ ] **2.2** `composer update swaggerapi/swagger-php --no-interaction`
- [ ] **2.3** Verify CLI works: `./vendor/bin/openapi --version`
- [ ] **2.4** Confirm the OpenAPI annotation schema is available by running `./vendor/bin/openapi src/Server/Http/Controllers/ --output /dev/null` (dry run)

---

## Phase 3: Annotate Controllers [PENDING]

Add `@OA\*` attributes to every controller action method. Target schema: OpenAPI 3.0.x, JWT Bearer auth on all `/api/v1/*` endpoints except `/api/v1/auth/*` (no auth required for register/login).

### 3.1 AuthController (`src/Server/Http/Controllers/AuthController.php`)

| Method | Endpoint | Auth | Annotations to add |
|--------|----------|------|--------------------|
| `POST` | `/api/v1/auth/register` | None | `@OA\Post`, `@OA\RequestBody` (email/username/password), `@OA\Response(201)`, `@OA\Response(422)` |
| `POST` | `/api/v1/auth/login` | None | `@OA\Post`, `@OA\RequestBody` (username/password), `@OA\Response(200)` with `@OA\JsonContent` (access_token, refresh_token, user), `@OA\Response(401)` |
| `POST` | `/api/v1/auth/refresh` | Refresh token | `@OA\Post`, `@OA\RequestBody` (refresh_token), `@OA\Response(200)`, `@OA\Response(401)` |

### 3.2 LibraryController (`src/Server/Http/Controllers/LibraryController.php`)

| Method | Endpoint | Annotations to add |
|--------|----------|--------------------|
| `GET` | `/api/v1/libraries` | `@OA\Get`, `@OA\Response(200)` with `@OA\JsonContent` listing libraries |
| `POST` | `/api/v1/libraries` | `@OA\Post`, `@OA\RequestBody` (name, type, path), `@OA\Response(201)`, `@OA\Response(400)` |

### 3.3 MediaItemController (`src/Server/Http/Controllers/MediaItemController.php`)

| Method | Endpoint | Annotations to add |
|--------|----------|--------------------|
| `GET` | `/api/v1/media/{id}` | `@OA\Get`, `@OA\Parameter` (path, id), `@OA\Response(200)`, `@OA\Response(404)` |
| `GET` | `/api/v1/media/{id}/markers` | `@OA\Get`, `@OA\Parameter` (path, id), `@OA\Response(200)` (intro/outro/chapters schema), `@OA\Response(404)` |

### 3.4 PlaybackController / HlsController

| Method | Endpoint | Annotations to add |
|--------|----------|--------------------|
| `GET` | `/api/v1/playback/{id}/stream` | `@OA\Get`, `@OA\Parameter` (path, id), `@OA\Response(200)` (m3u8 URL), `@OA\Response(404)` |
| `POST` | `/api/v1/playback/{id}/progress` | `@OA\Post`, `@OA\RequestBody` (positionTicks), `@OA\Response(200)`, `@OA\Response(401)` |

### 3.5 SessionController (`src/Server/Http/Controllers/SessionController.php`)

| Method | Endpoint | Annotations to add |
|--------|----------|--------------------|
| `GET` | `/api/v1/sessions` | `@OA\Get`, `@OA\Response(200)`, `@OA\Response(401)` |
| (others) | `/api/v1/sessions/{id}` | `@OA\Delete`, `@OA\Response(204)`, `@OA\Response(404)` |

### 3.6 Hub endpoints (ServerClaimsController, MyServersController)

| Method | Endpoint | Annotations to add |
|--------|----------|--------------------|
| `POST` | `/api/v1/server-claims/new` | `@OA\Post`, `@OA\RequestBody` (hub_token), `@OA\Response(201)`, `@OA\Response(401)` |
| `GET` | `/api/v1/me/servers` | `@OA\Get`, `@OA\Response(200)`, `@OA\Response(401)` |

### 3.7 Admin endpoints (`src/Server/Http/Controllers/Admin/`)

Admin endpoints use a different auth scheme (API key or admin JWT). Document per endpoint group.

| Method | Endpoint | Annotations to add |
|--------|----------|--------------------|
| `GET` | `/api/v1/admin/users` | `@OA\Get`, `@OA\SecurityScheme` (adminKey), `@OA\Response(200)`, `@OA\Response(403)` |
| `POST` | `/api/v1/admin/plugins` | `@OA\Post`, `@OA\RequestBody` (plugin manifest URL), `@OA\Response(201)`, `@OA\Response(400)` |
| `DELETE` | `/api/v1/admin/plugins/{id}` | `@OA\Delete`, `@OA\Response(204)`, `@OA\Response(404)` |

### 3.8 Shared schema components

Add to a new or existing file `src/Server/Http/Controllers/OpenAPI/Schemas.php`:

- `@OA\Schema` for `User`, `Library`, `MediaItem`, `PlaybackProgress`, `Error`
- `@OA\Response` for `401Unauthorized`, `404NotFound`, `422ValidationError`
- `@OA\SecurityScheme` for `bearerAuth` (JWT, HTTP 401)

Reference these via `$ref` in individual endpoint annotations.

---

## Phase 4: Wire OpenAPI Serving and Swagger UI [PENDING]

- [ ] **4.1** In `Application.php` or `Router.php`, add a route: `GET /api/v1/openapi.json` → handler that calls `OpenApi::generate(['src/Server/Http/Controllers/'])` and returns `new Response()->json($spec)`
- [ ] **4.2** Add route: `GET /api/v1/docs` → handler that serves the Swagger UI `index.html` from `vendor/swagger-api/swagger-ui/dist/` (or the project's own copy)
- [ ] **4.3** Configure Swagger UI to point at `/api/v1/openapi.json`
- [ ] **4.4** Verify both endpoints work locally: `curl http://localhost:8080/api/v1/openapi.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(list(d.get('paths',{}).keys())[:5])"`

---

## Phase 5: Document manual fallback in api.md [PENDING]

Expand `docs/reference/api.md` to cover all endpoint groups not fully annotated, using the existing format (endpoint heading, Parameters table, Response 200 JSON block, Response codes). Sections to add or expand:

### 5.1 Auth Endpoints (new section)

```
### POST /api/v1/auth/register

**Request Body:**
{ "email": "...", "username": "...", "password": "..." }

**Response 201:**
{ "user": { "id": "...", "email": "...", "username": "..." }, "access_token": "...", "refresh_token": "..." }

### POST /api/v1/auth/login

... (similar format)

### POST /api/v1/auth/refresh

... (similar format)
```

### 5.2 Library Endpoints (new section)

Add `GET /api/v1/libraries` and `POST /api/v1/libraries` following the same format.

### 5.3 Playback / Progress Endpoints (new section)

Document `GET /api/v1/playback/{id}/stream` and `POST /api/v1/playback/{id}/progress`.

### 5.4 Session Endpoints (new section)

Document `GET /api/v1/sessions` and `DELETE /api/v1/sessions/{id}`.

### 5.5 Hub Endpoints (new section)

Document `POST /api/v1/server-claims/new` and `GET /api/v1/me/servers`.

### 5.6 Admin Endpoints (new section)

Document the three admin endpoint groups: user management, plugin management.

---

## Phase 6: Verification [PENDING]

- [ ] **6.1** Run `./vendor/bin/openapi src/Server/Http/Controllers/ -o /tmp/openapi.json` and confirm it succeeds with no errors
- [ ] **6.2** Validate the generated spec: `python3 -c "import json; json.load(open('/tmp/openapi.json')); print('valid')"`
- [ ] **6.3** Confirm all required endpoint groups appear in the spec (`paths` key)
- [ ] **6.4** Confirm `components/securitySchemes/bearerAuth` is defined
- [ ] **6.5** Confirm `/api/v1/docs` serves the Swagger UI
- [ ] **6.6** Check Swagger UI renders without console errors (load in browser or check dist assets)
- [ ] **6.7** Confirm `docs/reference/api.md` has sections for Auth, Library, Playback, Sessions, Hub, Admin
- [ ] **6.8** Run `php -l` on all modified controller files
- [ ] **6.9** Run `./vendor/bin/phpcs --standard=PSR12` on modified files — zero errors
- [ ] **6.10** Run `./vendor/bin/phpstan analyze src/Server/Http/Controllers/ --level=9` — zero errors

---

## Phase 7: Commit [PENDING]

- [ ] **7.1** Branch: `git checkout -b n.21-api-reference`
- [ ] **7.2** Commit: `git add composer.json composer.lock src/Server/Http/Controllers/ docs/reference/api.md docs/reference/api/ && git commit -m "Step N.21: add OpenAPI/Swagger API reference with swagger-php""`
- [ ] **7.3** PR: `gh pr create --title "Step N.21: OpenAPI/Swagger API reference" --body "Adds swagger-php for OpenAPI 3.0 annotation and auto-generation, serves spec at /api/v1/openapi.json and Swagger UI at /api/v1/docs. Expands docs/reference/api.md with all endpoint groups. Part of Phase N (Step N.21 of PHLEX_EXPANSION_PLAN.md)."`
- [ ] **7.4** Merge: `gh pr merge --squash --delete-branch`
- [ ] **7.5** Return to master: `git checkout master && git pull --ff-only origin master`

---

## §2 Content Outline for `docs/reference/api.md`

### Overview

One-paragraph intro: Phlex exposes a REST API at `/api/v1/` returning JSON. Authentication is JWT Bearer token (except `/auth/*`). The spec is auto-generated from PHP attributes via swagger-php and available as OpenAPI 3.0 JSON at `/api/v1/openapi.json`. An interactive explorer is at `/api/v1/docs`.

### Sections (same as Phase 5.1–5.6 above)

- **Auth Endpoints** — register, login, refresh, logout
- **Library Endpoints** — list libraries, create library
- **Media Endpoints** — get media item, get markers
- **Playback Endpoints** — get stream URL, report progress
- **Session Endpoints** — list sessions, delete session
- **Hub Endpoints** — claim server, list my servers
- **Admin Endpoints** — user management, plugin management

Each section: brief description of what the endpoints do as a group, then each endpoint with its heading, HTTP method + path, parameter table (path/query/body), request body JSON example, response 200 JSON example, other response codes.

### Error Codes Table

Document standard error codes used across all endpoints:
- `401` — Unauthorized (missing or invalid token)
- `403` — Forbidden (insufficient permissions)
- `404` — Not found
- `422` — Validation error (invalid request body)
- `500` — Internal server error

---

## What Can Go Wrong

### Failure 1: `@OA\Schema` missing on DTO classes causes "component not found" in spec

**Symptom:** `./vendor/bin/openapi` succeeds but references like `$ref: '#/components/schemas/User'` resolve to nothing in the output spec.

**Fix:** Ensure every class used as a request body or response model has `@OA\Schema(title="User")` at the class-level docblock. Add `description` and `required` fields to properties.

---

### Failure 2: Circular references in OpenAPI spec cause parsers to fail

**Symptom:** OpenAPI spec is valid JSON but Stoplight/Insomnia refuse to load it with a "circular reference" error.

**Fix:** Use `@OA\Property(ref="#/components/schemas/OtherSchema")` only for non-recursive references. For self-referential structures (e.g., `MediaItem` with `extras: MediaItem[]`), use `additionalProperties` or a flat list instead of nested arrays of the same type.

---

### Failure 3: Missing auth headers on annotated endpoints means Swagger UI tries requests without tokens

**Symptom:** Interactive docs load but "Try it out" fails with 401 because the lock icon is not shown.

**Fix:** Add `@OA\SecurityScheme` at top of file and `@OA\Security("bearerAuth")` on each endpoint that requires auth. Ensure the security scheme matches the JWT format the server expects (Bearer token in Authorization header).

---

### Failure 4: `openapi.json` route conflicts with existing router pattern

**Symptom:** `GET /api/v1/openapi.json` returns 404 because the router already consumed the request.

**Fix:** Register the OpenAPI spec route before the general `group('/api/v1', ...)` group in `Application.php` so it has higher priority. Alternatively, serve it at a different path like `GET /openapi.json` outside the versioned group.

---

### Failure 5: Controller classes without PSR-4 namespace cause openapi CLI to skip them

**Symptom:** Some controllers are silently omitted from the generated spec.

**Fix:** Ensure all controllers follow PSR-4 autoloading under `Phlex\Server\Http\Controllers\`. Run `./vendor/bin/openapi src/ --bootstrap vendor/autoload.php` to ensure autoloading is active during scan.
