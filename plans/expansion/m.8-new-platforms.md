# Step M.8 — Android TV + Apple TV Apps (Earmark for v2)

**Phase:** M (Client Hub-Mode)
**Step:** M.8
**Depends on:** M.1
**Review:** No
**Target repos:** New repos (not yet created)

## 1. Goal

Document the Android TV and Apple TV app plan with a recommendation
to defer to v2.0 due to toolchain complexity, certification requirements,
and platform-specific distribution challenges.

## 2. Context

- `PHLEX_EXPANSION_PLAN.md` §3 row M.8 — Android TV + Apple TV (earmark)
- `HANDOFF_WAVE5_PLUS.md` §Wave 6 — step list with M.8 earmark note
- Android TV: requires Google Play certification, ADT-2/Chromecast TV
  hardware, Leanback UI, separate APK signing
- Apple TV: requires Apple Developer Program ($99/yr), tvOS SDK,
  App Store certification, physical Apple TV for testing
- Both platforms have strict content protection (DRM) requirements
  that complicate subtitle burn-in and transcoding flows

## 3. Recommendation

**Defer to v2.0.** The complexity of these two platforms warrants
separating them from the v1.0 launch scope.

## 4. Reasons to Defer

### Android TV

1. **Google Play certification** — apps must pass review for
   content rating, ads, and subscription disclosure
2. **ADT-2 / Chromecast TV hardware** — requires real device testing
   (emulator insufficient for all features)
3. **Leanback UI** — requires separate UI design and component library
4. **DRM (Widevine)** — Netflix-level content protection adds
   significant complexity to the streaming pipeline
5. **Separate APK signing** — different keystore, Play App Signing
6. **Distribution** — Google Play requires $25 one-time fee + compliance

### Apple TV

1. **Apple Developer Program** — $99/year membership required
2. **tvOS SDK** — different from iOS, requires Apple TV hardware
3. **App Store review** — strict guidelines for content, UI, accessibility
4. **Physical device testing** — simulator does not support all TV
   features (Game Center, TV Providers, etc.)
5. **DRM (FairPlay)** — AVContentKeySession for secure key handling
6. **Siri Remote** — different input model, requires D-pad navigation

### Shared Complexity

1. **Both platforms** need hub-mode (inherit from M.1–M.4)
2. **Both platforms** need SyncPlay (M.6)
3. **Both platforms** need Skip button (M.7)
4. **Both platforms** need offline downloads (M.5)
5. **Distribution** for both is app-store gated (vs. direct APK/URL)

## 5. What to Do Now

### Document the Requirements

Create the plan files for Android TV and Apple TV so the work is
defined when the team is ready:

- `plans/expansion/m.8-android-tv.md` — Android TV requirements and approach
- `plans/expansion/m.8-apple-tv.md` — Apple TV requirements and approach

### Pre-Work (Optional for v2 team)

- Research Android TV Leanback UI libraries
- Research Apple TV tvOS component libraries
- Investigate DRM integration options (Widevine/FairPlay)
- Set up Apple Developer account and tvOS provisioning
- Set up Google Play developer account and TV app listing

### For the v1.0 Launch

Inform users that:
- Android TV and Apple TV apps are planned for v2.0
- The existing mobile (iOS/Android), Tizen, Roku, and Windows
  clients cover the primary platforms at launch
- Users can access via web portal on Android TV browsers if needed

## 6. Acceptance criteria

- [ ] This plan file (`m.8-new-platforms.md`) documents the deferral
- [ ] Plan files for Android TV and Apple TV created as placeholders
- [ ] README in phlex-server updated to reflect v2 timeline for these platforms
- [ ] No implementation work done (by definition of deferral)

## 7. Git ritual

```bash
cd /home/sites/phlex
git status --short
git branch --show-current    # MUST be 'master'
git checkout -b m.8-new-platforms

# Write placeholder plan files
git add plans/expansion/m.8-new-platforms.md \
        plans/expansion/m.8-android-tv.md \
        plans/expansion/m.8-apple-tv.md
git commit -m "M.8: document Android TV + Apple TV deferral to v2"

unset GITHUB_TOKEN
git push -u origin m.8-new-platforms
# No PR/merge needed for planning artifact

git checkout master && git pull
git branch -d m.8-new-platforms
```
