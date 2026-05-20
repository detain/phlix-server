# Step N.13 — Client install guides (mobile, Tizen, Roku, Windows, web)

**Phase:** N (End-User Documentation)
**Step:** N.13
**Depends on:** M.4 (Windows client hub-mode — plan exists, implementation in progress)
**Review:** No (doc-only step)
**Target repo:** detain/phlex-docs (local: /home/sites/phlex/, but docs span phlex-docs and client repos)

## 1. Goal

Write five client installation guide documents covering all five supported platforms:
- `docs/clients/mobile.md` — React Native (iOS App Store / Android Google Play)
- `docs/clients/tizen.md` — Vanilla JS (Samsung Tizen TV)
- `docs/clients/roku.md` — BrightScript (Roku channel)
- `docs/clients/windows.md` — Electron (Windows desktop)
- `docs/clients/web.md` — Server-side Smarty portal (browser, no install)

Each guide follows the §7 per-page layout: TL;DR → install links → platform install steps → setup → hub connection → what-can-go-wrong (3 failures) → next-steps.

## 2. Context (what already exists)

- `docs/clients/skip-button-integration-brief.md` — the only existing file in `docs/clients/`
- `docs/clients/` is the end-user leaf for the client-platform tree per §7 docs tree layout
- `M.4` (Windows hub-mode plan) exists but implementation is still in progress; N.13 writes docs independent of whether M.4 has merged
- Client source repos exist at:
  - `github.com/detain/phlex-mobile-client` (React Native)
  - `github.com/detain/phlex-tizen-client` (Vanilla JS)
  - `github.com/detain/phlex-roku-client` (BrightScript)
  - `github.com/detain/phlex-windows-client` (Electron)
- The web client is the Smarty portal at `src/Server/WebPortal/` served by phlex-server at `/web`
- No existing install guide covers any of the five platforms

## 3. Decision rationale

| Decision | Rationale | Source |
|----------|-----------|--------|
| Five separate docs files (one per platform) | Each platform has radically different install UX; a single "all platforms" page would be unreadable | §7 docs tree layout |
| Each page uses identical §7 section structure | Consistency lets users jump between platform pages; TL;DR → install → setup → hub → troubleshooting → next-steps is a proven doc pattern | N.0 docs platform decision |
| Web client is "no-install" framing | Web runs in browser; the install guide must communicate "nothing to install" clearly while still covering hub login and troubleshooting | User context |
| Hub connection described last (after platform setup) | Users must first get the app running, then connect to hub; putting hub first implies hub is required | UX flow best practice |
| Three what-can-go-wrong entries per page | Three is the minimum viable troubleshooting set; covers connectivity, auth, and platform-specific failure modes | Technical common failure modes |

## 4. Scope

### Create (5 files)

- `docs/clients/mobile.md` — React Native mobile client install guide
- `docs/clients/tizen.md` — Samsung Tizen TV client install guide
- `docs/clients/roku.md` — Roku channel install guide
- `docs/clients/windows.md` — Windows Electron client install guide
- `docs/clients/web.md` — Web portal access guide (no install)

### Modify

- Nothing outside the five new files

### Delete

- Nothing

## 5. Doc content outline

### 5.1 `docs/clients/mobile.md` (React Native)

```
TL;DR
  - One paragraph: what the mobile app is, that it is available on iOS and Android
  - Minimum requirements: iOS 15+ / Android 10+
  - Quick-start: download → open → enter server URL or sign in with hub

Install / Store links
  - Apple App Store badge (placeholder URL)
  - Google Play badge (placeholder URL)
  - Direct APK download: github.com/detain/phlex-mobile-client/releases

Platform-specific install steps
  - iOS: App Store install, or TestFlight for beta
  - Android: Google Play install, or sideload APK (enable "Install unknown apps")
  - First launch: enter server URL manually OR sign in with hub credentials

Setup steps
  - Server URL entry: http://your-server:32400 (use LAN IP for local, domain for remote)
  - Allow camera/storage permissions if prompted
  - Library scan on first connect

Hub connection
  - Settings → Account → Sign in with Hub
  - Enter hub URL (e.g., https://hub.phlex.example.com)
  - Authenticate with hub credentials
  - Select server from hub-managed server list

What can go wrong (3 failures)
  1. Self-signed certificate error
     - Symptom: "Unable to connect — certificate invalid" on first launch
     - Fix: Install the server's CA cert on the device, or use a properly-signed SSL cert on the server
  2. Server not reachable from mobile (especially on mobile data vs LAN)
     - Symptom: Connection times out when away from home network
     - Fix: Use the hub relay URL when outside LAN; configure port forwarding or Tailscale VPN for direct LAN access
  3. Hub login fails (wrong credentials)
     - Symptom: "Authentication failed" on hub sign-in
     - Fix: Verify hub username/password; ensure hub URL is correct (no trailing slash)

Next steps
  - [Web client](docs/clients/web.md) — access from any browser
  - [Roku](docs/clients/roku.md) — living room streaming
  - [Tizen](docs/clients/tizen.md) — Samsung Smart TV
```

### 5.2 `docs/clients/tizen.md` (Vanilla JS / Tizen)

```
TL;DR
  - One paragraph: what the Tizen app is, that it runs on Samsung Smart TVs (2018+)
  - Quick-start: enable developer mode → sideload .wgt → open app → enter server URL

Install / Store links
  - Samsung Galaxy Store link (placeholder) — or direct .wgt sideload
  - .wgt package: github.com/detain/phlex-tizen-client/releases

Platform-specific install steps
  1. Enable Developer Mode on the TV
     - Settings → About → Support → Developer Mode → Turn On
     - TV will restart
  2. Sideload the .wgt file
     - Download .wgt from releases page
     - Copy to USB drive (FAT32 formatted)
     - Open TV File Manager → USB → click .wgt file → Install
     - App appears in "My Apps" on the TV home screen
  3. Network requirement
     - TV must be on the same LAN as the phlex server (or reachable via hub relay)

Configuration
  - In-app: Settings → Server URL → enter http://your-server:32400
  - Alternatively: set `window.PHLEX_SERVER_URL` env var at build time (for packaged builds)

Hub connection
  - In-app: Settings → Hub → Sign In
  - Enter hub URL → authenticate with hub credentials
  - Server selection handled by hub

What can go wrong (3 failures)
  1. Developer mode not enabled
     - Symptom: .wgt file does not install, "installation blocked" error
     - Fix: Enable Developer Mode per step 1 above; restart TV after enabling
  2. .wgt not installing (wrong TV model year)
     - Symptom: "This app is not compatible with this TV" during install
     - Fix: Confirm TV model year is 2018 or later; Tizen 4.0+ required; check release notes for minimum firmware version
  3. Network isolation preventing server access
     - Symptom: App opens but shows "server unreachable"
     - Fix: Ensure TV and server are on same subnet; check TV's network isolation / guest network settings; try using the server's LAN IP directly

Next steps
  - [Roku](docs/clients/roku.md) — alternative living room platform
  - [Windows](docs/clients/windows.md) — desktop experience with system tray
  - [Live TV](docs/advanced/live-tv.md) — watch live TV through the app
```

### 5.3 `docs/clients/roku.md` (BrightScript)

```
TL;DR
  - One paragraph: what the Roku channel is, that it runs on any Roku device
  - Quick-start: add channel → open → enter server URL → sign in with hub

Install / Store links
  - Roku Channel Store (placeholder URL) — "Phlex" channel
  - Sideload .ipk for developer testing: github.com/detain/phlex-roku-client/releases

Platform-specific install steps
  1. Add Phlex from the Roku Channel Store (recommended)
     - Roku home screen → Search → "Phlex" → Add Channel
  2. Developer sideload (for beta/testing)
     - Download .ipk from releases
     - Use Roku Developer Application Loader (RDA) to sideload
     - Or use `curl` with rokudev: developer.roku.com tools
  3. First launch
     - App opens to server URL entry screen
     - Enter your server URL (e.g., http://192.168.1.100:32400)
     - Storage key `server_url` persists across launches

Hub connection
  - In-app: Settings → Hub Login
  - Enter hub URL → authenticate with hub credentials
  - Server URL can be auto-populated from hub

What can go wrong (3 failures)
  1. Server URL is wrong or mistyped
     - Symptom: "Unable to reach server" immediately after entering URL
     - Fix: Verify URL protocol (http vs https), port number, and IP/domain; use LAN IP for local testing
  2. Roku not on same network as server
     - Symptom: Server unreachable only on Roku (works on phone/computer)
     - Fix: Verify both devices are on same LAN; check Roku's network (some Roku models default to a guest network); try direct LAN IP instead of hostname
  3. Channel store version vs dev channel version mismatch
     - Symptom: Published channel behaves differently from locally-sideloaded version
     - Fix: Report discrepancy at github.com/detain/phlex-roku-client; use channel store version for production

Next steps
  - [Tizen](docs/clients/tizen.md) — Samsung Smart TV app
  - [Windows](docs/clients/windows.md) — desktop client with more features
  - [First-run wizard](docs/first-run.md) — configure libraries after connecting
```

### 5.4 `docs/clients/windows.md` (Electron)

```
TL;DR
  - One paragraph: what the Windows client is, full-featured Electron app with system tray and media key support
  - Quick-start: download .exe installer → run → hub login or direct server URL

Install / Store links
  - .exe installer: github.com/detain/phlex-windows-client/releases
  - Installer handles VC++ runtime and auto-update

Platform-specific install steps
  1. Download the latest .exe installer from releases
  2. Run the installer (no admin required unless installing to Program Files)
  3. Launch Phlex from the Start Menu or Desktop shortcut
  4. System tray: app minimizes to tray; right-click tray icon for quick controls

Setup steps
  - First launch: server URL entry or hub login prompt
  - Settings → Server: configure direct server URL (e.g., http://localhost:32400)
  - Settings → Startup: optionally start with Windows

Hub connection
  - Settings → Hub → Enable Hub Connection
  - Enter hub URL (e.g., https://hub.phlex.example.com)
  - Authenticate with hub credentials
  - Server is auto-discovered from hub account

What can go wrong (3 failures)
  1. Electron not launching (missing VC++ runtime)
     - Symptom: App crashes immediately or shows "VCRUNTIME140.dll not found"
     - Fix: Install Visual C++ 2015-2022 Redistributable from Microsoft; the installer link is in the release notes
  2. Port 32400 blocked by firewall
     - Symptom: Server connection fails with "connection refused"
     - Fix: Add rule to Windows Defender Firewall: Allow inbound TCP on port 32400, or run phlex-server as admin once to prompt the firewall dialog
  3. Hub relay not working (network issue)
     - Symptom: Direct LAN connection works but hub remote access fails
     - Fix: Check internet connectivity on both server and client; verify hub relay URL is accessible from external networks; check if corporate network blocks WebSocket connections

Next steps
  - [Mobile app](docs/clients/mobile.md) — Android and iOS
  - [Web client](docs/clients/web.md) — browser access without installing software
  - [Hardware transcoding](docs/advanced/hardware-transcoding.md) — GPU acceleration on Windows
```

### 5.5 `docs/clients/web.md` (Smarty portal, no install)

```
TL;DR
  - One paragraph: web portal runs in any modern browser, no software to install
  - Access URL: https://your-server-domain.com/web (or http://LAN-IP:32400/web)
  - Hub login supported; direct server login supported

Install / Store links
  - No install required — runs in browser
  - Supported browsers: Chrome 110+, Firefox 115+, Safari 16+, Edge 110+
  - Bookmark the URL for easy access

Platform-specific notes
  - URL must be accessible (local network or via hub relay / reverse proxy)
  - Mobile browsers work but optimized for desktop (responsive design)

Setup steps
  - Open the web URL in a browser
  - If using hub: click "Sign in with Hub" and enter hub credentials
  - If using direct server: enter server URL on the login screen
  - No downloads, no permissions, no plugins needed

Hub connection
  - "Sign in with Hub" button on the login screen
  - Enter hub URL → authenticate with hub credentials
  - Select which server to connect to (if multiple)
  - Hub relay enables remote access without configuring router/ firewall

What can go wrong (3 failures)
  1. Browser not supported
     - Symptom: Page looks broken or shows "browser not supported"
     - Fix: Use Chrome, Firefox, Safari, or Edge (current version minus 2); Internet Explorer is not supported
  2. WebSocket blocked
     - Symptom: Page loads but playback does not start, "connection error" in console
     - Fix: Check corporate/network proxy settings; ensure WebSocket (ws:// or wss://) is not blocked; try a different network
  3. SSL certificate invalid or self-signed
     - Symptom: Browser shows "Your connection is not private" warning
     - Fix: Replace self-signed cert with a valid Let's Encrypt certificate; or add the self-signed cert as trusted in the browser/OS for local IP access

Next steps
  - [Mobile app](docs/clients/mobile.md) — iOS and Android
  - [Windows client](docs/clients/windows.md) — desktop app with system tray
  - [First-run wizard](docs/first-run.md) — complete server setup after first login
```

## 6. Approach

1. **Write five markdown files** following the §7 per-page layout and the outline in §5 above
2. **Consistency rules across all five files:**
   - Each file starts with a second-level heading (`##`) matching the filename (e.g., `## Mobile` in `mobile.md`)
   - TL;DR section uses `> [!TIP]` callout style or bold labels for quick scanning
   - All code snippets use language tags (e.g., ```bash, ```ini)
   - Hub connection always comes after platform install/setup in the document order
   - What-can-go-wrong always lists three failures with Symptom / Fix structure
   - Next-steps links to other client docs and one server config doc
3. **Placeholder URLs** to be replaced with real links before publish:
   - App Store / Play Store / Galaxy Store links (pending platform account setup)
   - Hub relay URLs (e.g., `hub.phlex.example.com`) — use placeholder domain
4. **Commit** to branch `n.13-clients-install`, push, open PR, merge per git ritual

## 7. Acceptance criteria

- [ ] `docs/clients/mobile.md` exists and contains all 7 §7 sections (TL;DR, install links, platform steps, setup, hub connection, 3 failures, next steps)
- [ ] `docs/clients/tizen.md` exists and contains all 7 §7 sections
- [ ] `docs/clients/roku.md` exists and contains all 7 §7 sections
- [ ] `docs/clients/windows.md` exists and contains all 7 §7 sections
- [ ] `docs/clients/web.md` exists and contains all 7 §7 sections
- [ ] Each file uses the same section order and naming conventions
- [ ] All "what can go wrong" sections list exactly 3 failures with Symptom / Fix pairs
- [ ] No platform-install-specific content appears in the wrong file (e.g., no Tizen content in the Roku doc)
- [ ] All code blocks have language tags
- [ ] Placeholder URLs are clearly marked as placeholder

## 8. Git ritual

```bash
cd /home/sites/phlex
git status --short
git checkout -b n.13-clients-install

# Write the five doc files

git add docs/clients/mobile.md
git add docs/clients/tizen.md
git add docs/clients/roku.md
git add docs/clients/windows.md
git add docs/clients/web.md
git add plans/expansion/n.13-clients-install.md
git status --short
git commit -m "n.13: add client install guides for all 5 platforms"

unset GITHUB_TOKEN
git push -u origin n.13-clients-install
gh pr create --title "n.13: client install guides (mobile, Tizen, Roku, Windows, web)" --body "Step n.13 of PHLEX_EXPANSION_PLAN.md — install guides for all 5 client platforms."
gh pr merge --squash --delete-branch

git checkout master && git pull
git branch -d n.13-clients-install
git log --oneline -1
```
