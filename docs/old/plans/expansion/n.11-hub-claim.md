---
status: not-started
phase: N
updated: 2026-05-19
---

# Implementation Plan

## Goal
Write the end-user hub claim guide at `docs/hub/claim-server.md` covering the server-to-hub claiming flow with screenshots, shell blocks, and troubleshooting.

## Context & Decisions
| Decision | Rationale | Source |
|----------|-----------|--------|
| §7 layout (TL;DR, screenshots, shell, failures, next-steps) | Required doc page structure per Phase N instructions | user task description |
| 4 failure modes documented | Claim code expiry, already-claimed, network isolation, wrong code | user task description |
| CLI alternative via `php bin/phlex hub:claim` | Covers headless/server-admin use cases | user task description |
| 3 screenshots: server claim dialog, hub Claim Server page, My Servers dashboard | Visual flow coverage for GUI users | user task description |
| 10-minute claim code expiry | Security posture: short window reduces hijack risk | C.9 hub shared libraries |

## Phase 1: Write claim-server.md [PENDING]
- [ ] 1.1 Draft TL;DR section (2-3 sentences, what and why)
- [ ] 1.2 Write screenshot caption section for 3 UI states
- [ ] 1.3 Write CLI alternative block (`php bin/phlex hub:claim --code ABCD-1234 --hub https://hub.phlex.example.com`)
- [ ] 1.4 Write what-can-go-wrong section (4 failure modes with resolutions)
- [ ] 1.5 Write next-steps section pointing to hub relay/access docs

## Notes
- 2026-05-19: Plan created for step N.11 — doc-only, no review step
- Depends on C.9 (hub shared libraries) which is already merged
- Target file: `docs/hub/claim-server.md`
