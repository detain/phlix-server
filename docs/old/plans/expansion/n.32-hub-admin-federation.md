# Step N.32 — Hub-Admin: Federation & Inter-Hub Policy

**Phase:** N (End-User Documentation)
**Step:** N.32
**Depends on:** C.9 (hub shared libraries — already merged)
**Review:** No (doc-only step)
**Target repo:** phlex-hub (local: /home/sites/phlex-hub/)
**One-liner:** Hub-admin: federation & inter-hub policy (v1 single-hub expectations)

---

## Goal

Write the hub-admin federation guide at `docs/hub-admin/federation-policy.md`, using the §7 one-screen layout (TL;DR → v1 single-hub decision → future federation roadmap → inter-hub policy → migration path → what-can-go-wrong → next-steps).

This doc acknowledges the v1 single-hub reality, documents the decision, and provides a clear roadmap for when/if federation is added.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| v1 is explicitly single-hub; federation (hub-to-hub communication) is out of scope | A single hub handles ~5000 users, ~200 servers — sufficient for most deployments | N.28 sizing data |
| Each user account lives on one hub; server can only be claimed to one hub at a time | Simplifies trust model; avoids split-brain server ownership | v1 design decision |
| Library sharing only works within the same hub instance | Cross-hub sharing deferred to post-v1 federation work | C.9 (already merged) |
| Multiple independent hub instances do not know about each other | No automatic peer discovery or state sync between hubs | v1 design decision |
| Federation is an industry unsolved problem — Jellyfin/Emby do not do true federation either | Sets realistic expectations; federation is non-trivial | industry comparison |
| Manual federation via OAuth-like account linking is the v1 inter-op path | Users who want cross-hub access can explicitly link accounts | inter-hub policy decision |

---

## Phase 1: Draft `docs/hub-admin/federation-policy.md` [IN PROGRESS]

- [ ] **1.1** Read C.9 plan (`plans/expansion/c.9-shared-libraries.md`) to confirm single-hub scope assumptions
- [ ] **1.2** Check if `docs/hub-admin/federation-policy.md` already exists to avoid duplication
- [ ] **1.3** Draft `docs/hub-admin/federation-policy.md` (see §2 Content Outline below)
- [ ] **1.4** Self-review against §7 layout requirements: TL;DR, shell blocks (sparse here), what-can-go-wrong (3 failures), next-steps

---

## Phase 2: Verification [PENDING]

- [ ] **2.1** Confirm all six §7 required sections are present (TL;DR, v1 single-hub decision, future federation roadmap, inter-hub policy, migration path, what-can-go-wrong, next-steps)
- [ ] **2.2** Confirm the v1 single-hub decision is clearly stated and explained (up to ~5000 users, ~200 servers per N.28)
- [ ] **2.3** Confirm library sharing cross-hub limitation is clearly called out with a forwarding link to the roadmap
- [ ] **2.4** Confirm future federation design is clearly marked as roadmap/not-yet-implemented
- [ ] **2.5** Confirm what-can-go-wrong covers exactly 3 distinct failures with diagnostic guidance
- [ ] **2.6** Confirm next-steps links to hub-admin overview and hub-claim docs
- [ ] **2.7** Proofread for clarity, accuracy, and tone suitable for hub operators (not developers)

---

## Phase 3: Commit [PENDING]

- [ ] **3.1** Branch: `git checkout -b n.32-hub-admin-federation`
- [ ] **3.2** Commit: `git add docs/hub-admin/federation-policy.md && git commit -m "Step N.32: hub-admin federation policy guide (v1 single-hub + roadmap)"`
- [ ] **3.3** PR: `gh pr create --title "Step N.32: Hub-admin federation policy guide" --body "Adds docs/hub-admin/federation-policy.md following the §7 one-screen layout (TL;DR, v1 single-hub decision, future federation roadmap, inter-hub policy, migration path, what-can-go-wrong, next-steps). Part of Phase N (Step N.32 of PHLEX_EXPANSION_PLAN.md)."`
- [ ] **3.4** Merge: `gh pr merge --squash --delete-branch`
- [ ] **3.5** Return to master: `git checkout master && git pull --ff-only origin master`

---

## §2 Content Outline for `docs/hub-admin/federation-policy.md`

### TL;DR

One-paragraph summary: what federation means (hub-to-hub communication and cross-hub library sharing), what v1 delivers (a single hub instance handling up to ~5000 users and ~200 servers), what v1 explicitly does not include (any federation — hubs do not know about each other), and what the roadmap contains (hub-to-hub API, cross-hub JWT auth, federated server metadata). Operators choosing a single-hub deployment get a fully functional system; operators needing multi-hub federation should treat this doc as a forward-looking reference and track the federation roadmap.

### Shell blocks (sparse — federation is not yet implemented)

```bash
# Federation API — future endpoints (NOT YET AVAILABLE in v1)
# GET /api/v1/hubs/{hub-id}/servers         # list servers on a specific hub
# GET /api/v1/hubs/{hub-id}/users          # list users on a specific hub
# POST /api/v1/federation/peers             # announce this hub as a federation peer
```

End with a note: federation endpoints above are design notes for future planning, not implemented features. Do not attempt to call them against a v1 hub.

---

### v1 Single-Hub Decision

#### What v1 delivers

A single Phlex hub instance handles:
- Up to ~5000 user accounts
- Up to ~200 claimed servers
- Library sharing within the same hub instance
- Server relay and streaming through the hub

#### What v1 explicitly does not include

- Hub-to-hub communication (federation)
- Cross-hub library sharing
- Automatic peer discovery between hubs
- Shared JWT/SSO across multiple hub instances

#### Why single-hub was chosen for v1

1. **Simplicity**: No trust model, NAT traversal, or cross-hub identity complexity
2. **Sufficiency**: ~5000 users and ~200 servers cover the vast majority of deployments
3. **Industry precedent**: Jellyfin and Emby — the closest analogs — do not implement true federation either; this is an unsolved problem in personal media serving
4. **Clear migration path**: When scaling beyond a single hub is needed, the migration path (see §Migration Path) is well-defined

#### What this means for users

- Each user account lives on one hub — there is no cross-hub login
- A server can only be claimed to one hub at a time — first claim wins
- Library sharing only works within the same hub instance — shared libraries are not visible on other hubs
- If you have accounts on two separate hubs, they are independent — no automatic synchronization

#### What this means for operators

- Multiple independent hub instances do not know about each other
- Each hub operator manages their own users, servers, and content policies
- There is no automatic peering with unknown hubs
- Cross-hub collaboration requires manual account linking (see §Manual Federation)

---

### Future Federation Design (Roadmap — Not Yet Implemented)

> ⚠️ This section describes a future design for when federation is needed. None of this is implemented in v1. Treat as planning reference only.

#### Hub-to-Hub API

If/when federation is added, the hub-to-hub API would expose:

```
GET /api/v1/hubs/{hub-id}/servers    # list servers registered on a specific hub
GET /api/v1/hubs/{hub-id}/users      # list user accounts on a specific hub
GET /api/v1/hubs/{hub-id}/libraries  # list shared libraries on a specific hub
```

These endpoints would be authenticated via mutual TLS or a pre-shared federation key.

#### Cross-Hub User Auth

Instead of each hub issuing its own JWTs with no cross-hub validity, a federated auth model would:

- Accept JWTs issued by other trusted hubs
- Maintain a shared JWKS list of all federation peers' public keys
- Allow a user on Hub A to access resources on Hub B without creating a new Hub B account

#### Federation Protocol

A hub announces itself as a federation peer by:
1. Publishing its public key to a shared JWKS endpoint
2. Exchanging server metadata with known peers
3. Periodically updating its presence in the peer registry

Federation peers exchange:
- Server metadata (hostname, library descriptions, server capabilities)
- User identity assertions (verifying a user exists on Hub A without revealing their personal data)
- Content policy declarations (what type of content a hub allows/rejects)

#### Federation Challenges

The following challenges make federation non-trivial and are the reason it was deferred past v1:

| Challenge | Description |
|-----------|-------------|
| **NAT traversal** | Servers behind NAT cannot be directly addressed by peers — requires relay or hole-punching |
| **Trust model** | Each hub must decide which other hubs are trusted; trust is not transitive |
| **User identity across hubs** | A user on Hub A is not the same entity as a user on Hub B — no shared identity layer |
| **Data privacy between operators** | Hub operators may have different privacy policies; federation must not leak user data across trust boundaries |
| **Content policy heterogeneity** | One hub may allow adult content; another may not — cross-hub sharing raises content policy conflicts |
| **No industry standard** | Jellyfin, Emby, Plex, and other media servers have not solved true federation — there is no spec to follow |

---

### Inter-Hub Policy (Even for v1 Single-Hub)

Even though v1 does not implement federation, hub operators should be aware of the following inter-hub policy expectations:

#### Publishing your hub URL and terms of service

Hub operators should publish:
- The public URL of their hub (e.g., `https://hub.example.com`)
- A terms of service document covering content policy, acceptable use, and DMCA procedures
- A privacy policy covering what user data the hub collects and how it is handled

This is a prerequisite for any future federation participation.

#### No automatic peering with unknown hubs

v1 hubs do not automatically discover or peer with other hubs. A hub operator must explicitly configure federation peers. Unknown hubs cannot:
- Query your hub's server list
- Authenticate users against your hub
- Access your hub's library metadata

#### Manual federation: OAuth-like account linking

The only cross-hub interaction available in v1 is manual account linking, similar to OAuth trust:

1. User on Hub A wants to access resources on Hub B
2. Hub A issues a cross-hub identity token for that specific user
3. User presents the token to Hub B
4. Hub B validates the token against Hub A's public key
5. Hub B creates a shadow account for the user (mapped to their Hub A identity) with scoped access

This is not true federation — it is a bilateral manual trust agreement between two specific hub operators.

#### Content policy

Each hub operator sets their own rules about what content can be served from their hub. There is no cross-hub content policy enforcement. If Hub A serves content that Hub B's operator considers objectionable, Hub B can:
- Block access from Hub A's users to Hub B's servers
- Decline to federate with Hub A at all
- File a DMCA or abuse complaint with Hub A's operator

---

### Migration Path: From Single to Multi-Hub

If a hub grows beyond the v1 single-hub capacity (~5000 users, ~200 servers), the migration path to multi-hub is:

#### Step 1: Identify the split point

Determine whether the split is:
- **Geographic**: users in different regions (e.g., US East vs. EU West)
- **Organizational**: different tenant groups that need isolation
- **Scale-based**: pure capacity overflow

#### Step 2: Export user accounts

```bash
# Export all user accounts from the source hub
php bin/hub.php user:export --all --format json > users-export.json

# Export all server claims
php bin/hub.php server:export --all --format json > servers-export.json

# Export all library sharing grants
php bin/hub.php share:export --all --format json > shares-export.json
```

#### Step 3: Import into new hub instances

```bash
# Create Hub A (e.g., US East) and import users
php bin/hub.php user:import --hub us-east --file users-export.json

# Create Hub B (e.g., EU West) for the second region
php bin/hub.php user:import --hub eu-west --file users-export.json

# Filter imports by region during import (e.g., only EU users to EU hub)
php bin/hub.php user:import --hub eu-west --file users-export.json --filter-region eu
```

#### Step 4: Server reassignment

Servers must be re-claimed to the new hub:
1. Server owner logs into the new hub
2. Server owner runs the claim flow against the new hub
3. Old hub releases the server claim (automatic after re-claim to new hub)

```bash
# On the server, claim to the new hub
php bin/phlex hub:claim --hub https://eu-west.hub.example.com --token <new-hub-token>
```

#### Step 5: Verify library access

After migration:
- Users on Hub A cannot see Hub B's libraries (and vice versa)
- Shared library grants do not cross hub boundaries — re-establish shares if needed
- Cross-hub access requires federation (future work) or manual account linking

---

### What Can Go Wrong

#### Failure 1: User expects cross-hub library sharing (not available in v1)

**Symptom:** User on Hub A expects to see libraries shared by their friend on Hub B, but no cross-hub sharing is visible.

**Cause:** Library sharing in v1 only works within the same hub instance. Cross-hub sharing requires federation, which is not yet implemented.

**Fix:** Check whether both users are on the same hub (compare hub URLs in the dashboard). If on different hubs, explain that cross-hub sharing is not yet available. Direct users to the federation roadmap at `docs/hub-admin/federation-policy.md` (this doc) and the manual account linking option.

---

#### Failure 2: Hub operator assumes federation exists (it does not)

**Symptom:** Hub operator sets up two independent hub instances expecting them to share users and servers automatically.

**Cause:** v1 does not implement any federation. Each hub is fully independent with no shared state.

**Fix:** Review this doc to understand v1's single-hub scope. If cross-hub collaboration is needed, consider: (a) consolidating to a single hub if under the ~5000 user / ~200 server limit, or (b) using the manual account linking approach for specific cross-hub use cases. Track the federation roadmap for future multi-hub support.

---

#### Failure 3: Server owner tries to claim to two hubs simultaneously (not allowed — first claim wins)

**Symptom:** Server owner attempts to claim the same server to a second hub and gets an error, or discovers the server is registered to a different hub than expected.

**Cause:** A server can only be claimed to one hub at a time. The first hub to receive and persist the claim owns the server relationship. Subsequent claims to other hubs are rejected.

**Fix:** If the server was claimed to the wrong hub unintentionally:
1. Contact the hub operator of the hub that currently holds the claim
2. Request that the hub operator release the server claim: `php bin/hub.php server:release <server-id>`
3. Once released, claim the server to the correct hub: `php bin/phlex hub:claim --hub https://correct-hub.example.com`

Note: Releasing a server claim does not delete any media or data on the server — it only removes the relay association with the hub.

---

### Next Steps

- [Hub-admin overview](docs/hub-admin/overview.md) — hub dashboard and admin CLI reference
- [Hub claim and setup](docs/hub-claim.md) — understanding server claiming and hub identity
- [Hub shared libraries](docs/hub-shared-libraries.md) — how shared libraries work within a single hub
- [Hub-admin abuse handling](docs/hub-admin/abuse-handling.md) — DMCA workflow, GDPR data handling, audit log review

---

## Git Ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-hub
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b n.32-hub-admin-federation

# ─── 2. Do the work ───
# Create docs/hub-admin/federation-policy.md following the §7 one-screen layout
# TL;DR → v1 single-hub decision → future federation roadmap
# → inter-hub policy → migration path
# → what-can-go-wrong (3 failures) → next-steps

# ─── 3. Verify ───
# Verify file has TL;DR, all six sections present,
# what-can-go-wrong (3 failures), and next-steps links

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step N.32: Hub-admin federation policy guide"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step N.32: Hub-admin federation policy guide" \
  --body  "Adds docs/hub-admin/federation-policy.md following the §7 one-screen layout (TL;DR, v1 single-hub decision, future federation roadmap, inter-hub policy, migration path, what-can-go-wrong, next-steps). Part of Phase N (Step N.32 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch -d n.32-hub-admin-federation
```

(End of file — total 327 lines)
