# Phlex Media Server - Supervisor Plan

**Version:** 2.0  
**Date:** 2026-05-14  
**Purpose:** Supervisor agent guide for orchestrating phased implementation

---

## Overview

This plan guides a supervisor AI to spawn subagents sequentially, where each subagent reads and follows a specific step plan file. After each step completes, a review subagent verifies completeness before proceeding.

---

## Critical Rules

1. **ALWAYS unset GITHUB_TOKEN before gh cli commands** - `unset GITHUB_TOKEN`
2. **After each step**: commit, create PR, accept PR, switch to master and pull
3. **After each step's code completion**: spawn a review subagent to verify completeness
4. **Keep steps small** - one focused task per subagent invocation
5. **Test requirements** - Each step must include tests and verify they pass
6. **All PHP code follows PSR-12 standards**
7. **All database queries use workerman/mysql library (NOT PDO or mysqli)**

---

## Phase Execution Order

### Phase 1: Core Media Server Foundation (6 steps)

| Step | Plan File | Description | Review |
|------|-----------|-------------|--------|
| 1.1 | `plans/phase-1/step-1.1-project-setup.md` | Initialize Workerman project, composer, autoloading | Yes |
| 1.2 | `plans/phase-1/step-1.2-database-layer.md` | Database connection pool, migrations, schema | Yes |
| 1.3 | `plans/phase-1/step-1.3-logging.md` | Structured logging with Monolog | Yes |
| 1.4 | `plans/phase-1/step-1.4-http-server.md` | HTTP request/response, routing, middleware | Yes |
| 1.5 | `plans/phase-1/step-1.5-websocket-server.md` | WebSocket handling, message protocol | Yes |
| 1.R | `plans/phase-1/step-1.R-phase-review.md` | Phase 1 verification and gap analysis | - |

### Phase 2: Media Library & Metadata System (4 steps)

| Step | Plan File | Description | Review |
|------|-----------|-------------|--------|
| 2.1 | `plans/phase-2/step-2.1-media-library.md` | Library manager, scanner, folder watcher | Yes |
| 2.2 | `plans/phase-2/step-2.2-metadata-fetching.md` | Metadata providers (TMDB), metadata manager | Yes |
| 2.3 | `plans/phase-2/step-2.3-item-repository.md` | Item repository CRUD, queries | Yes |
| 2.R | `plans/phase-2/step-2.R-phase-review.md` | Phase 2 verification and gap analysis | - |

### Phase 3: Streaming & Transcoding Engine (4 steps)

| Step | Plan File | Description | Review |
|------|-----------|-------------|--------|
| 3.1 | `plans/phase-3/step-3.1-stream-manager.md` | Stream state, stream manager, quality selector | Yes |
| 3.2 | `plans/phase-3/step-3.2-hls-streaming.md` | HLS playlist generation, segmenter | Yes |
| 3.3 | `plans/phase-3/step-3.3-transcoding-engine.md` | FFmpeg runner, encoding helper, transcode manager | Yes |
| 3.R | `plans/phase-3/step-3.R-phase-review.md` | Phase 3 verification and gap analysis | - |

### Phase 4: Authentication & Session Management (3 steps)

| Step | Plan File | Description | Review |
|------|-----------|-------------|--------|
| 4.1 | `plans/phase-4/step-4.1-authentication.md` | JWT authentication, password hashing | Yes |
| 4.2 | `plans/phase-4/step-4.2-session-management.md` | Session tracking, playback state | Yes |
| 4.R | `plans/phase-4/step-4.R-phase-review.md` | Phase 4 verification and gap analysis | - |

### Phase 5: Centralized Web Portal (3 steps)

| Step | Plan File | Description | Review |
|------|-----------|-------------|--------|
| 5.1 | `plans/phase-5/step-5.1-web-portal-setup.md` | Web portal templates, CSS, JS | Yes |
| 5.2 | `plans/phase-5/step-5.2-web-api-endpoints.md` | Web portal API routes, page rendering | Yes |
| 5.R | `plans/phase-5/step-5.R-phase-review.md` | Phase 5 verification and gap analysis | - |

### Phase 6: Client Applications

Client applications are implemented using existing platform plans:

| Platform | Plan File | Description |
|----------|-----------|-------------|
| Samsung Tizen TV | `/home/sites/phlex/PLATFORM_SAMSUNG_TIZEN.md` | BrightScript/TypeScript web app |
| Roku | `/home/sites/phlex/PLATFORM_ROKU.md` | BrightScript SceneGraph app |
| Windows | `/home/sites/phlex/PLATFORM_WINDOWS.md` | Electron/React desktop app |
| iOS/Android | `/home/sites/phlex/PLATFORM_MOBILE.md` | React Native mobile app |

### Phase 7: Advanced Features

Advanced features are documented in `plans/phase-7/phase-7-overview.md`:
- SyncPlay (group watching)
- DLNA support
- Live TV
- Additional metadata providers
- User management & parental controls

---

## Supervisor Workflow

**IMPORTANT: The supervisor should NOT read the individual plan files. This is intentional to keep the supervisor's token usage low. Only spawn subagents and let them read the plan files.**

For each step in Phases 1-5:

```
1. Identify the next step from the Phase Execution tables above
2. Spawn a CoderAgent subagent with:
   - description: "Execute Step X.Y"
   - prompt: "Read and follow the plan at {plan_path}. Work in /home/sites/phlex/"
3. Wait for completion
4. If step has Review column = Yes:
   a. Spawn a CoderAgent subagent with:
      - description: "Review Step X.Y"
      - prompt: "Read and follow the review plan at {review_plan_path}. Work in /home/sites/phlex/"
   b. Wait for completion
5. Proceed to next step
```

For Phase 6 (client applications):
```
1. For each platform, spawn a subagent with:
   - description: "Implement {platform} app"
   - prompt: "Read and follow the platform plan at {plan_path}. Work in /home/sites/phlex/"
2. Platforms can be implemented in parallel
3. Each platform follows its own verification steps
```

---

## Git Workflow (to be included in each step plan)

After completing code work:
```bash
cd /home/sites/phlex
git checkout -b step-X.Y-description
# ... make changes ...
git add .
git commit -m "Step X.Y: Description of what was done"
unset GITHUB_TOKEN
gh pr create --title "Step X.Y: Description" --body "Implements step X.Y of the Phlex implementation plan"
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Verification Commands

After each step, run:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit --testdox
```

To check PHP syntax:
```bash
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

---

## Project Structure

```
/home/sites/phlex/
├── SUPERVISOR_PLAN.md          # This file
├── IMPLEMENTATION_PLAN.md       # Original 7-phase plan
├── PHLEX_MEDIA_SERVER_TECHNICAL_SPEC.md  # Technical specification
├── PLATFORM_SAMSUNG_TIZEN.md   # Samsung TV app plan
├── PLATFORM_ROKU.md            # Roku app plan
├── PLATFORM_WINDOWS.md         # Windows app plan
├── PLATFORM_MOBILE.md          # Mobile app plan
├── plans/
│   ├── phase-1/                # Phase 1 step plans (6 files)
│   ├── phase-2/                # Phase 2 step plans (4 files)
│   ├── phase-3/                # Phase 3 step plans (4 files)
│   ├── phase-4/                # Phase 4 step plans (3 files)
│   ├── phase-5/                # Phase 5 step plans (3 files)
│   ├── phase-6/                # Phase 6 overview
│   └── phase-7/                # Phase 7 overview
└── phlex/                      # Main project code (to be created)
    ├── src/
    ├── config/
    ├── public/
    ├── tests/
    └── migrations/
```

---

## Important Notes

- **The supervisor should NOT read the individual plan files themselves** - only spawn subagents and instruct them to read their respective plan files
- Each step plan file contains detailed instructions that subagents will read and follow
- The supervisor only needs to know which plan file to invoke for each step - the tables above provide that mapping
- Review subagents check if the previous step was completed correctly
- All platform plans should be read and followed by dedicated subagents for each platform
- The project can be considered production-ready after Phases 1-5 and at least one client application

---

## Quick Reference: Step Count

**Note: Supervisor does NOT read these plans. Subagents read them when spawned.**

| Phase | Steps | Review Steps |
|-------|-------|--------------|
| Phase 1 | 5 | 1 |
| Phase 2 | 3 | 1 |
| Phase 3 | 3 | 1 |
| Phase 4 | 2 | 1 |
| Phase 5 | 2 | 1 |
| **Total** | **15** | **5** |

## Token Usage Guidelines

To keep supervisor token usage low:
1. **Never read plan file contents** - only reference filenames
2. **Keep prompts to subagents simple** - just point to the plan file path
3. **Trust subagents to follow plans** - don't re-verify their work unless review step
4. **Move quickly between steps** - don't linger on a step once complete
