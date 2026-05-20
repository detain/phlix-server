---
status: not-started
phase: N
updated: 2026-05-19
---

# Step N.12 — Hub: Share Libraries with Friends and Family

**Phase:** N (End-User Documentation)
**Step:** N.12
**Depends on:** C.9 (hub shared libraries — already merged)
**Review:** No (doc-only step)
**Target repo:** detain/phlex-server (local: `/home/sites/phlex/`)
**One-liner:** Hub: share libraries with friends and family

---

## Goal

Write the user-facing library sharing guide at `docs/hub/share-with-friends.md` covering the hub's sharing permissions model, CLI grant workflow, invite/link flows, per-profile sharing, and three common failure scenarios.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| C.9 shipped hub sharing: owner grants access via dashboard → server → sharing tab, with view-only / view+playback / view+playback+download tiers, email or link invites, and per-profile content filtering | C.9 is the stable implementation this guide documents | `ref:c.9-shared-libraries` (merged Wave 3) |
| §7 layout: TL;DR, shell blocks (for CLI grant command), what-can-go-wrong (3 failures), next-steps | Required structure for all Phase N end-user guides per PHLEX_EXPANSION_PLAN.md §7 | N.0 docs platform decision |
| Three what-can-go-wrong failures: invite email blocked by spam, account email mismatch, library scan incomplete | These are the three most common end-user failures for hub library sharing | C.9 implementation + hub ops experience |
| Fourth common failure documented as bonus: view-only user cannot cast to DLNA | DLNA casting requires download permission; surfacing this avoids confusion | C.9 permission model |
| Invite via email supports optional expiry and optional library-scope restriction | These are the two most commonly requested invite options; both shipped in C.9 | C.9 feature set |

---

## Phase 1: Draft `docs/hub/share-with-friends.md` [IN PROGRESS]

- [ ] **1.1** Read C.9 plan (`plans/expansion/c.9-shared-libraries.md`) to confirm all sharing features, permission tiers, and CLI commands
- [ ] **1.2** Read existing `docs/hub/share-with-friends.md` if present to avoid duplicating content already covered
- [ ] **1.3** Draft `docs/hub/share-with-friends.md` (see §2 Content Outline below)
- [ ] **1.4** Self-review against §7 layout requirements: TL;DR, shell blocks, what-can-go-wrong (3 failures), next-steps

---

## Phase 2: Verification [PENDING]

- [ ] **2.1** Confirm all four §7 required sections are present (TL;DR, shell blocks, what-can-go-wrong, next-steps)
- [ ] **2.2** Confirm CLI grant command syntax is accurate: `php bin/phlex share:grant --user friend@example.com --library "Movies" --permission view`
- [ ] **2.3** Confirm all three permission tiers (view-only, view+playback, view+playback+download) are documented with clear differences
- [ ] **2.4** Confirm "what can go wrong" covers exactly 3 distinct failures with shell-friendly diagnostic commands
- [ ] **2.5** Confirm per-profile sharing (G-rated content filters) is covered as a distinct sharing dimension
- [ ] **2.6** Proofread for clarity, accuracy, and tone suitable for end users (not developers)

---

## Phase 3: Commit [PENDING]

- [ ] **3.1** Branch: `git checkout -b n.12-hub-share`
- [ ] **3.2** Commit: `git add docs/hub/share-with-friends.md && git commit -m "Step N.12: hub library sharing guide (end-user docs)"`
- [ ] **3.3** PR: `gh pr create --title "Step N.12: hub library sharing guide" --body "Writes docs/hub/share-with-friends.md as an end-user guide covering hub sharing permissions model, CLI grant workflow, email/link invite flows, per-profile content filtering, and 3 common failure scenarios. Part of Phase N (Step N.12 of PHLEX_EXPANSION_PLAN.md)."`
- [ ] **3.4** Merge: `gh pr merge --squash --delete-branch`
- [ ] **3.5** Return to master: `git checkout master && git pull --ff-only origin master`

---

## §2 Content Outline for `docs/hub/share-with-friends.md`

### TL;DR

One-paragraph summary: what hub sharing enables (sharing your media library with friends and family through the hub), what this guide covers (granting access, inviting friends, permission levels), and the 30-second version: go to your server's sharing tab → choose what to share → invite by email or link → friend logs in and sees it under "Shared with me".

### 1. How Hub Sharing Works

Explain the owner-to-friend flow:
- Server owner grants access from hub dashboard → server → sharing tab
- Three sharing scopes: entire library, specific folders, specific media items
- Three permission levels: view-only, view+playback, view+playback+download
- Invite via email (with optional expiry, optional library-scope restriction) or shareable link
- Friend accepts: creates a new hub account or logs into an existing one
- Friend sees shared server listed under "Shared with me" in their hub dashboard
- Per-profile sharing: owner can restrict shared content to G-rated content for certain profiles

### 2. Granting Access via the Hub Dashboard

Step-by-step for the GUI flow:
1. Log into the hub at `https://hub.phlex.app` (or your self-hosted hub)
2. Navigate to Servers → your server → Sharing tab
3. Click "Share Library" and choose the library (or specific folders/media items)
4. Select permission level: view-only / view+playback / view+playback+download
5. Choose invite method: email address (with optional expiry) or generate shareable link
6. Click "Send Invite"

Note on per-profile sharing: within the same server, you can choose to share only G-rated content with certain profiles, restricting what appears in the shared library based on the recipient's profile rating filter.

### 3. Granting Access via CLI

```bash
# Grant view-only access to a friend's account
php bin/phlex share:grant --user friend@example.com --library "Movies" --permission view

# Grant view+playback access
php bin/phlex share:grant --user friend@example.com --library "Movies" --permission playback

# Grant view+playback+download access (full access)
php bin/phlex share:grant --user friend@example.com --library "Movies" --permission download

# Share specific folder
php bin/phlex share:grant --user friend@example.com --folder "Movies/Classics" --permission playback

# Share specific media item
php bin/phlex share:grant --user friend@example.com --item "abc123-def456" --permission view

# Revoke access
php bin/phlex share:revoke --user friend@example.com --library "Movies"
```

### 4. Accepting a Share Invite

#### Via email invite:
1. Open the invite email (check spam if not seen within a few minutes)
2. Click "Accept Invite" — if no account exists, create one first
3. Log into the hub — the shared server appears under "Shared with me" in the dashboard
4. Select the shared server to browse the library

#### Via shareable link:
1. Click the link — if not logged in, sign in or create an account
2. The shared library is immediately accessible under "Shared with me"

### 5. Managing Shared Access

As a library owner, you can:
- View all active shares from the Sharing tab
- Change permission level for an existing share
- Revoke access at any time
- Set an expiry on an email invite (after which the link becomes invalid)

### 6. What Can Go Wrong

#### Failure 1: Friend doesn't receive the invite email

**Symptom:** Sender sees "Invite sent" but recipient cannot find the email.

**Diagnosis:**
```bash
# Check if the invite was sent (server-side logging):
# Look for "share_invite_sent" events in the hub audit log
grep "share_invite_sent" .logs/hub-audit.log | tail -20

# Verify the email address was correct on the invite form
# Most common cause: typo in email address during invite flow
```

**Fix:** Ask the recipient to check their spam/junk folder. If not found, re-send the invite with a double-checked email address. For enterprise users, ask their mail admin to allow-list `noreply@phlex.app`.

---

#### Failure 2: Friend creates an account with a different email than invited

**Symptom:** Invite link is clicked but the library doesn't appear under "Shared with me" after login.

**Diagnosis:**
```bash
# Check the hub audit log for invite acceptance:
grep "share_invite_accepted" .logs/hub-audit.log | tail -10
# The log will show the invited email vs the accepting account email
```

**Fix:** The invite is tied to a specific email address. The friend must use the exact email address that received the invite. Alternatively, the library owner can grant access to the friend's actual email address via the dashboard or CLI.

---

#### Failure 3: Shared library doesn't appear (scan not complete on server)

**Symptom:** Friend accepts invite and logs in, but the shared server shows no libraries or an empty library.

**Diagnosis:**
```bash
# On the server, check library scan status:
php bin/phlex library:status

# Check if the library scan is still in progress:
ps aux | grep -i "media_scanner\|phlex" | grep -v grep

# Manually trigger a rescan:
php bin/phlex library:scan --all
```

**Fix:** Library sharing requires the library scan to be complete. If the scan is still running, wait for it to finish. If no scan is in progress, trigger one manually. The friend should refresh the hub page after the scan completes.

---

#### Failure 4 (bonus): View-only user cannot cast to DLNA

**Symptom:** Friend logs in, browses the shared library, but pressing "Cast" or "Play To" on a DLNA device does nothing or shows an error.

**Diagnosis:**
```bash
# Check the permission level on the share:
# Via CLI:
php bin/phlex share:list --user friend@example.com
```

**Fix:** DLNA casting requires `view+playback+download` permission (the download component is what enables the stream to be redirected to a DLNA renderer). Ask the library owner to upgrade your permission level to `download` via the hub dashboard or CLI: `php bin/phlex share:grant --user friend@example.com --library "Movies" --permission download`.

### 7. Next Steps

- [Claim your server to the hub](../hub/claim-server.md) — if you own a server and haven't connected it to the hub yet
- [Hub: self-host the hub](../hub/self-host-the-hub.md) — run your own hub instance for full control
- [Hub: what is the hub?](../hub/what-is-the-hub.md) — overview of hub features and account management
- [DLNA / Play To](../clients/dlna.md) — stream media to DLNA-enabled devices (requires download permission on shared library)
