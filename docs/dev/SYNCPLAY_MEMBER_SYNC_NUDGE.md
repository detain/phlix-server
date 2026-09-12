# SyncPlay member sync: NUDGE policy (S446)

**Model, in one sentence:** every member's periodic `playback_sync` report now
lands on the live `GroupState` as *that member's* position in milliseconds; the
server judges each report against the group's authoritative position with a
revived, legitimately-live predicate, and the one decided reaction to a report
that drifts is a **NUDGE** — a single rate-limited, corrective `playback_sync`
frame carrying soft drift/rate guidance aimed at the drifting member only.
Per the owner ruling (2026-09-12) the server **never** answers a member's own
report with a seek: a force-seek is hostile UX, and seek frames stay reserved
for host commands.

## Why this exists

S291 removed `GroupState::isInSync()` as *runtime-proved unreachable* — the
server stored no per-member positions, so nothing could ever be judged, and a
guard test pinned the predicate dead. The removal note was explicit about what
re-adding it would require: real storage plus a **decided** out-of-sync policy
written down. This change supplies both in one commit:

1. **Storage** — `GroupState::$memberPositions`, keyed by member id, each entry
   `{position: int (ms), at_ms: int}`. Frames have carried milliseconds since
   S417; the store keeps that unit.
2. **Policy** — NUDGE, not force-seek (owner ruling 2026-09-12), stated
   verbatim in the `GroupState` class docblock and enforced in code: the nudge
   path rides `Messages::TYPE_PLAYBACK_SYNC` and never touches
   `TYPE_PLAYBACK_SEEK`.
3. **Liveness** — the ingest consumer makes the predicate reachable by
   construction; the S291 guard test is retired in the same commit by its
   named replacement pair (see Verification map).

## Components

| Piece | File | Role |
|---|---|---|
| Position storage + sync predicate | `src/Session/SyncPlay/GroupState.php` (`recordMemberPosition`, `getMemberPosition`, `isInSync`, `isMemberInSync`) | live state + judgment |
| Nudge slot (rate limit) | `src/Session/SyncPlay/GroupState.php` (`claimNudgeSlot`) | atomic check-and-set per member |
| Live ingest + emission | `src/Session/SyncPlay/SyncPlayManager.php` (`handlePlaybackSync` → `sendOutOfSyncNudge`) | WS worker |
| Frame envelope + wire clock | `src/Session/SyncPlay/Messages.php` (`frame`, `nowMs`) | S417 outbound rule |
| Policy sentinel | `GroupState::SYNC_NUDGE_POLICY_ID` (+ test-home twin) | marks the decided policy as code-resident |

## Data flow

```
member ──playback_sync {position:ms,...}──▶ handlePlaybackSync
                                             │ reportedPositionMs()  (parse; null ⇒ fully inert)
                                             │ recordMemberPosition() (live-only store)
                                             │ broadcastToGroup()     (unchanged state echo → all)
                                             ▼
                                   isMemberInSync(memberId, nowMs)?
                                     │ yes ──────────────────────── done (echo only)
                                     │ no
                                     ▼
                              claimNudgeSlot(memberId, nowMs)?
                                │ false (cooldown) ────────────── done (silent)
                                │ true
                                ▼
                    ONE nudge frame → drifting member's connection ONLY
```

The group echo broadcast is byte-for-byte the pre-S446 behaviour; the nudge is
an *additional* frame aimed only at the reporter, sent after the echo. A client
that understands nothing new still receives the authoritative state it always
did.

## Thresholds — derived, never invented

| Constant | Value | Derived from |
|---|---|---|
| Sync window | `POSITION_TOLERANCE` | 2000 ms — the pre-existing constant whose own docblock already said "out of sync" (S446 gives it its consumer) |
| Nudge cooldown | `NUDGE_COOLDOWN_MS` | 1000 ms — `TimeSync::MAX_ACCEPTABLE_RTT`: one full round-trip envelope; re-issuing before the member could plausibly have acted on the first directive is spam |
| Report staleness | `MEMBER_POSITION_STALENESS_MS` | 300 000 ms — the estate's existing stale-connection budget (`config/server.php` `websocket.stale_connection_timeout = 300` s, mirrored by the WS worker's 300 s SyncPlay cleanup tick) |
| Guidance rate step | `NUDGE_RATE_STEP` | 0.1 — `TimeSync::DRIFT_CORRECTION_FACTOR`, the factor the time authority already applies to clock drift |

Judgment rules (early-exit order inside `isMemberInSync`): never reported ⇒
**in sync** (absence of evidence is not evidence of drift); older than the
staleness budget ⇒ **in sync** (the server would already treat that member as
disconnected); otherwise the revived `isInSync`: a group that is not
`STATE_PLAYING` is trivially in sync (drift does not accumulate against a
stopped clock), and any position within `POSITION_TOLERANCE` ms of the group's
authoritative position is in sync.

## The nudge frame (example, full)

Additive keys only, on the existing `playback_sync` family, through the S417
`Messages::frame` envelope (`{type, protocol_version: 1, timestamp: ms}`):

```json
{
  "type": "syncplay_playback_sync",
  "protocol_version": 1,
  "member_id": "sp_m_9f2c",
  "group_id": "sp_7ab13c",
  "current_media_id": "media_42",
  "position": 9000,
  "is_playing": true,
  "server_time": 1789240131,
  "nudge": {
    "drift_ms": 4000,
    "direction": "behind",
    "suggested_rate": 1.1,
    "tolerance_ms": 2000,
    "cooldown_ms": 1000
  },
  "timestamp": 1789240131734
}
```

- `drift_ms = group.position − reported` (>0 ⇒ the member trails the group).
- `direction` is the human-readable sign of the drift; `suggested_rate` is
  `1 ± NUDGE_RATE_STEP` (1.1 speed-up when behind, 0.9 slow-down when ahead).
- It is **guidance**: a client may play it as a gentle rate adjust, an
  animated catch-up, or ignore it. There is deliberately no target-position
  key; nothing in this shape can be mistaken for a seek command.
- Tolerant decode: the client framing layer (`phlix-syncplay` `framing.ts`)
  requires only `type` on the wire and passes unknown keys through untouched,
  so old clients keep working with zero coordinated deploy.

## Rate limit & per-tick idempotency

**Bound: at most one nudge per member per `NUDGE_COOLDOWN_MS` (1 s), and at
most one per ingest tick.** Enforcement is `GroupState::claimNudgeSlot()` — a
check-and-set that records the claim *inside* the same call, so two paths in
the same tick cannot both see the slot as free. Boundary semantics: exactly at
the cooldown edge the slot is available again (`>=`). A rewound wall clock
cannot manufacture extra slots (`$nowMs − $last` goes negative ⇒ suppressed).
A claim burns the slot even if the send afterwards fails — deliberate: the
slot rate-limits the *reaction channel*, not a confirmed delivery.

## Interaction with the S445 bridge — the non-clobber rule

`$memberPositions` / `$lastNudgeAtMs` are **WS-worker-local live state**. The
write-through bridge mirror (`applyBridgeFrame`) carries `GroupState::serialize()`
output, which contains neither map. Rules:

- A bridge **upsert** (merge path) replaces membership and host facets only —
  it cannot fabricate a position for a member who never reported, and cannot
  clobber the live positions of members who did.
- A bridge **delete** (or wholesale adopt of a group the worker has never
  served) removes the `GroupState` object itself, so the positions die with it
  — legitimately, because the live reports died with the object; the next
  periodic report repopulates.
- `removeMember()` drops both the departed member's position and its nudge
  stamp — a re-joining identity must never inherit an old judgment;
  `setCurrentMedia()` clears both maps — positions measured on a previous
  track's timeline say nothing about the new one.

## Failure modes

| Situation | Behaviour |
|---|---|
| `position` missing / negative / bool / non-numeric | `reportedPositionMs()` ⇒ null: nothing stored, nothing judged, pure pre-S446 echo — legacy clients unaffected |
| Reporter left between resolve and store | `recordMemberPosition()` returns false (race), report dropped; no fabricated live state |
| Member keeps drifting forever | at most one directive per cooldown window; echo broadcast is unchanged, so the group never sees the churn |
| Wall clock jumps backwards | `claimNudgeSlot()` suppresses until real time passes the last stamp |
| Group not playing | `isInSync()` trivially true — no nudges against a stopped clock |

## Verification map

| Guarantee | Test |
|---|---|
| Position stored per member, in ms | `GroupStateTest::testMemberPositionIsStoredPerMemberInMilliseconds` |
| Revived predicate uses the existing tolerance | `GroupStateTest::testRevivedIsInSyncPredicateUsesTheExistingPositionTolerance` |
| Staleness/absence ⇒ in-sync | `GroupStateTest::testIsMemberInSyncJudgesTheStoredPosition` |
| Slot claim bounded per member | `GroupStateTest::testNudgeSlotClaimIsBoundedPerMember` |
| Live-only ⇒ never serialized | `GroupStateTest::testMemberPositionsAreLiveOnlyAndNeverSerialized` |
| **Out-of-sync report ⇒ exactly one bounded nudge** | `SyncPlayMemberSyncNudgeTest::testOutOfSyncReportYieldsExactlyOneBoundedNudge` |
| **In-sync report ⇒ none** | `SyncPlayMemberSyncNudgeTest::testInSyncReportYieldsNoNudge` |
| One per cooldown window under repeat drift | `SyncPlayMemberSyncNudgeTest::testNudgeIsRateLimitedToOneDirectivePerCooldownWindow` |
| Position-less report fully inert | `SyncPlayMemberSyncNudgeTest::testPositionlessReportIsInertAndStoresNothing` |
| S417 envelope conformance | `SyncPlayMemberSyncNudgeTest::testNudgeFrameConformsToTheMessagesFactoryEnvelope` + `OutboundFrameShapeGuardTest` |
| Bridge cannot fabricate/clobber positions | `SyncPlayMemberSyncNudgeTest::testBridgeUpsertMergesFacetsWithoutTouchingLivePositions` |

The retired guard `GroupStateTest::testIsInSyncRemainsRemovedAsUnreachableDeadCode`
(S291) is replaced in the same commit by the pair in bold above — the written
replacement note sits at its former location in `GroupStateTest`.
