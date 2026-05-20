# Step N.6 — First-Run Wizard Guide

**Phase:** N (End-User Documentation)
**Step:** N.6
**Depends on:** N.0 (docs platform)
**Review:** No (doc-only step)
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:scribe (fallback: general-purpose)

## 1. Goal

Write the end-user guide for the **first-run wizard** at `docs/first-run.md`. The wizard runs automatically when Phlex is first booted with no admin account configured, guiding new users from server boot to a ready-to-scan library.

## 2. Context (what already exists)

Read first:

- `src/Server/Core/Application.php` — bootstraps auth check on startup.
- `src/Auth/AuthManager.php` — handles admin account creation.
- `src/Auth/UserProfileManager.php` — handles profile + PIN setup.
- `src/Media/Library/LibraryManager.php` — handles library creation.
- `src/Media/Library/MediaScanner.php` — triggers library scans.
- `src/Dlna/DlnaServer.php` — DLNA toggle logic.
- `src/Server/WebPortal/WebPortalRouter.php` — wizard route registration.
- `public/templates/` — existing Smarty templates for the wizard flow.
- `public/assets/` — existing CSS/JS assets.
- `docs/` — existing end-user documentation structure (`docs/libraries/`, `docs/clients/`, etc.).

## 3. Scope — file to create

### `docs/first-run.md`

Write the complete guide with the following structure:

#### §7 Layout (required sections in this order)

1. **TL;DR** — One-paragraph plain-English summary of what the wizard does and how long it takes (~5 minutes).

2. **Screenshots / Flow Description** — Walk through each step with screenshot placeholders and a brief description:
   - Step 1: Welcome screen + URL access (`http://server:32400` or `http://localhost:32400/web`).
   - Step 2: Admin account creation (email + password with strength meter).
   - Step 3: Library path configuration (add folders: `/media/movies`, `/media/tv`, etc.).
   - Step 4: Library type selection (movies, TV shows, music, photos, books, audiobooks).
   - Step 5: Library scan trigger (immediate scan vs defer).
   - Step 6: Hub connection prompt (optional — connect now or skip).
   - Step 7: Language + timezone defaults.
   - Step 8: DLNA server toggle (enable/disable for local discovery).
   - Step 9: Web dashboard ready.

   Screenshot format:
   ```
   ![Step N — Description](screenshots/first-run/step-N-description.webp)
   ```
   If screenshots are not yet available, use:
   ```
   <!-- screenshots TBD — text-first -->
   ```

3. **Shell Blocks** — Any command-line relevant context, e.g.:
   - Verifying the server is running: `curl http://localhost:32400/api/v1/system/status`
   - Checking library paths on disk.

4. **What Can Go Wrong** — Three common failure scenarios:
   - **Admin account email already in use** — What it means and how to resolve (reset flow or use existing account).
   - **Library paths not accessible** — Permissions issues, non-existent directories; how to fix paths.
   - **Initial scan hangs or times out** — Large libraries, network mounts; deferring scan, adjusting timeout.

5. **Next Steps** — After the wizard completes, link to:
   - `docs/libraries/` for managing library content.
   - `docs/clients/` for connecting playback clients.
   - `docs/hub/remote-access.md` for Hub setup.
   - `docs/advanced/live-tv-comskip.md` for Live TV setup.
   - `docs/reference/cli.md` for CLI commands (`php public/index.php`).

#### Metadata header

```markdown
**Phase:** N (End-User Documentation)
**Step:** N.6
**Since:** 0.18.0
```

#### Style notes

- Plain English, second person ("you", "your").
- No implementation details; user-facing only.
- Screenshots are optional; always include the textual description so the doc is complete without them.
- Cross-references to other docs use relative markdown links (e.g., `[Live TV](../advanced/live-tv-comskip.md)`).
- No code blocks for user-facing wizard UI; shell blocks only for CLI/ops context.

## 4. Approach

1. Branch from master: `git checkout -b n.6-first-run-docs`.
2. Read all context files listed in §2 above.
3. Write `docs/first-run.md` following the §7 layout exactly.
4. Add any missing cross-links from existing docs to the new page.
5. Verify: no PHP/JS implementation, only prose + shell + links.
6. Commit + PR + merge.

## 5. Acceptance Criteria

- [ ] `docs/first-run.md` exists with all 5 required §7 sections.
- [ ] TL;DR paragraph is present and ≤ 3 sentences.
- [ ] All 9 wizard steps are documented with descriptions.
- [ ] Screenshot placeholders follow the specified format (or note "screenshots TBD — text-first").
- [ ] At least 3 failure scenarios documented in "What Can Go Wrong".
- [ ] "Next Steps" section links to ≥ 3 other doc pages.
- [ ] Metadata header with Phase, Step, Since fields present.
- [ ] No implementation code; only user-facing prose.
- [ ] Cross-links are valid relative paths.
- [ ] PHPCS clean (no PSR-12 violations in documentation style).

## 6. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b n.6-first-run-docs
# ... write docs/first-run.md ...
git add docs/first-run.md
git commit -m "Step N.6: First-run wizard guide"
unset GITHUB_TOKEN
gh pr create --title "Step N.6: First-run wizard guide" --body "Doc-only step. Creates docs/first-run.md with §7 layout."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 7. Reviewer hand-off

Review = No. This is a doc-only step. Merge when ready.

(End of file - total 118 lines)
