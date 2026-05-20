# Step F.4 — Player UI: skip button protocol

**Phase:** F (Skip-Intro, Skip-Outro, Scene Markers)
**Step:** F.4
**Depends on:** F.3
**Review:** Yes — see `f.4-skip-protocol-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Define and document the marker-aware playback protocol that is returned
to clients alongside HLS stream URL and manifest data. The protocol
specifies the exact skip-button behavior clients should render: an
"Intro skip" button that appears between `intro_marker.start` and
`intro_marker.end`, and an "Outro skip" button that appears between
`outro_marker.start` and `outro_marker.end`.

This step only defines the server-side spec and embeds it in the
existing playback response JSON. Client consumption happens in Phase M
(Steps M.1–M.8).

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §2 Phase F table — F.4 depends on F.3.
- `src/Media/Streaming/HlsStreamer.php` — serves HLS streams; returns
  playback info to clients. F.4 extends the response shape.
- `src/Media/Markers/MarkerService.php` — `getMarkers()` returns
  `MarkerSet` with `IntroMarker` / `OutroMarker` (F.3).
- `src/Server/Http/Controllers/MediaItemController.php` — already has
  a playback-info endpoint (`/api/v1/media/{id}/playback-info`).
- `src/Server/Http/Controllers/SessionController.php` — manages
  playback sessions; may also embed marker data.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Intro/outro skip via Chromaprint"
  listed in Phase F feature gap map.
- The 4 client repos (`phlex-mobile-client`, `phlex-roku-client`,
  `phlex-tizen-client`, `phlex-windows-client`) are separate repos;
  F.4 defines the spec they will consume in Phase M.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Markers/SkipButtonSpec.php` — a value object serializable
  to the client-facing JSON:

  ```php
  final class SkipButtonSpec
  {
      public function __construct(
          public readonly ?int $skip_intro_start,   // null if no intro detected
          public readonly ?int $skip_intro_end,
          public readonly ?int $skip_outro_start,
          public readonly ?int $skip_outro_end,
      ) {}

      /** Serialize to array for JSON response. */
      public function toArray(): array {}

      /** Deserialize from MarkerSet (F.3). */
      public static function fromMarkerSet(MarkerSet $set): self {}
  }
  ```

- `src/Media/Markers/PlaybackMarkerService.php` — enriches playback
  info with skip-button spec by combining session position with marker data:

  ```php
  class PlaybackMarkerService
  {
      public function __construct(
          private readonly MarkerService $marker_service,
      ) {}

      /** Return skip-button spec for the current playback position. */
      public function getSkipSpec(string $media_item_id, int $position_ticks): SkipButtonSpec {}

      /** Convenience: full spec regardless of position. */
      public function getFullSpec(string $media_item_id): SkipButtonSpec {}
  }
  ```

#### Modified responses

- `src/Server/Http/Controllers/MediaItemController.php` — extend
  `getPlaybackInfo()` to include `skip_buttons` key in response:

  ```php
  [
      'stream_url' => '...',
      'markers' => [
          'skip_intro_start' => 0,
          'skip_intro_end' => 90,
          'skip_outro_start' => 2340,
          'skip_outro_end' => 2520,
      ],
      // ... existing fields
  ]
  ```

- `src/Server/Http/Controllers/SessionController.php` — extend
  session info to include marker data for active sessions.

#### Documentation

- `docs/reference/skip-button-protocol.md` — new doc specifying the
  exact JSON shape, when buttons should appear/disappear, and the
  integration contract for client teams (Phase M).

- `docs/reference/api.md` — update `getPlaybackInfo` entry to document
  the `markers` key.

#### Phase M briefing doc

- `docs/clients/skip-button-integration-brief.md` — a concise technical
  brief for client repo developers in Phase M. Explains the protocol,
  provides JSON examples, and links to the full spec. This is the
  hand-off document from the server team to the client teams.

### Modify

- `src/Server/Http/Controllers/MediaItemController.php` — inject
  `PlaybackMarkerService`, add `markers` to playback info response.
- `src/Server/Http/Controllers/SessionController.php` — same for
  session info.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b f.4-skip-protocol`.
2. **SkipButtonSpec value object.** Immutable; `toArray()` for JSON;
   `fromMarkerSet()` factory.
3. **PlaybackMarkerService.** `getSkipSpec()` returns spec; accepts
   position_ticks so server can optionally filter which buttons are
   currently "active" for the client's position in the stream.
4. **MediaItemController update.** `getPlaybackInfo()` response gains
   `markers` key with 4 fields.
5. **SessionController update.** Session info also carries marker data.
6. **Documentation.** Write `docs/reference/skip-button-protocol.md`
   defining the full spec and `docs/clients/skip-button-integration-brief.md`
   as the Phase M hand-off brief.
7. **Tests.** Write `PlaybackMarkerServiceTest` + `SkipButtonSpecTest`.
8. **Verification bar** (§0.4 minimum bar).
9. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `SkipButtonSpecTest::test_to_array_serializes_all_four_fields`
2. `SkipButtonSpecTest::test_null_fields_when_no_marker`
3. `SkipButtonSpecTest::test_from_marker_set_maps_correctly`
4. `SkipButtonSpecTest::test_from_marker_set_with_no_markers`
5. `PlaybackMarkerServiceTest::test_get_full_spec_returns_all_available`
6. `PlaybackMarkerServiceTest::test_get_full_spec_returns_nulls_when_no_markers`
7. `PlaybackMarkerServiceTest::test_get_skip_spec_respects_position`
8. `PlaybackMarkerServiceTest::test_get_skip_spec_nulls_outside_markers`

**Coverage target:** `SkipButtonSpec` ≥ 90 %, `PlaybackMarkerService` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Public HTTP API"** → `docs/reference/api.md` updated with `markers`
  key in playback-info response.
- **"New public class/method"** → `SkipButtonSpec`, `PlaybackMarkerService`
  get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry. Note: clients
  do not change until Phase M, so no end-user visible change yet.
- **"Client integration"** → `docs/clients/skip-button-integration-brief.md`
  (new) is the Phase M hand-off spec.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `SkipButtonSpec::toArray()` returns `{ skip_intro_start, ... }`
      with `null` for unavailable markers.
- [ ] `SkipButtonSpec::fromMarkerSet()` correctly maps `MarkerSet` fields.
- [ ] `PlaybackMarkerService::getFullSpec()` returns a spec for any item.
- [ ] `PlaybackMarkerService::getSkipSpec()` returns nulls for markers
      outside the current position range.
- [ ] `MediaItemController::getPlaybackInfo()` includes `markers` in response.
- [ ] `docs/reference/skip-button-protocol.md` written with full JSON spec.
- [ ] `docs/clients/skip-button-integration-brief.md` written for Phase M client teams.
- [ ] `./vendor/bin/phpunit` — green; ≥ 8 new tests.
- [ ] Coverage of `SkipButtonSpec` ≥ 90 %, `PlaybackMarkerService` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b f.4-skip-protocol

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'SkipButtonSpec|PlaybackMarkerService'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step F.4: Skip-button protocol — SkipButtonSpec, PlaybackMarkerService, markers in playback-info"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step F.4: Skip-button protocol — markers embedded in playback-info response" \
  --body  "Adds SkipButtonSpec value object, PlaybackMarkerService, embeds markers in MediaItemController and SessionController playback responses. Documents skip-button protocol for Phase M client integration. Part of Phase F (Step F.4 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'f.4-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `f.4-skip-protocol-review.md`.

Non-obvious points:
- The protocol is designed for **client-controlled skip**: the server
  provides start/end timestamps; the client decides when to show the
  button and what to do when clicked (seek to `end` position).
- The `getSkipSpec(id, position_ticks)` method lets the server optionally
  filter which buttons are "currently relevant" at the viewer's exact
  playback position — useful for live streams where the viewer may have
  started mid-episode.
- The `docs/clients/skip-button-integration-brief.md` is intentionally
  brief and client-focused — client teams in Phase M should be able to
  implement the UI without reading the full server architecture docs.
