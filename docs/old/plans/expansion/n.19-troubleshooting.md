# Step N.19 — Troubleshooting & FAQ

**Phase:** N (End-User Documentation)
**Step:** N.19
**Depends on:** N.18 (backup/restore)
**Review:** No (doc-only step)
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:scribe (fallback: general-purpose)

## 1. Goal

Write the **troubleshooting and FAQ guide** at `docs/troubleshooting.md` (end-user tree, under `docs/advanced/` per the §7 layout). This page is the first port of call when something breaks: it consolidates log locations, diagnostic commands, and the three most common failure scenarios for each operational area, plus a compact FAQ.

## 2. Context (what already exists)

Read first:

- `config/server.php` — `http_port`, log channel configuration.
- `config/logger.php` — rotating log path (`.logs/`), channel names (AUTH, HTTP, WEBSOCKET, MEDIA, SESSION, STREAMING).
- `src/Common/Logger/LoggerFactory.php` — how channels map to files.
- `src/Server/Core/Application.php` — Workerman bootstrapping and worker entry.
- `src/Media/Library/LibraryManager.php` — library path + permission checks.
- `src/Media/Transcoding/TranscodeManager.php` — FFmpeg runner invocation.
- `src/Media/Streaming/StreamManager.php` — direct-play vs transcode path selection.
- `src/Auth/AuthManager.php` — JWT validation on hub connections.
- `src/Session/SessionManager.php` — session/device state.
- `config/ffmpeg.php` — FFmpeg binary path, HW accel profiles.
- `scripts/run-migrations.php` — DB migration state.
- `docs/advanced/backup-restore.md` — N.18, referenced from FAQ.
- `docs/hub/claim-server.md` — N.11, referenced from hub connectivity FAQ.
- `docs/reference/cli.md` — CLI commands referenced throughout.

## 3. Scope — file to create

### `docs/troubleshooting.md`

Write the complete guide with the following structure in this exact order:

#### §7 Layout (required sections in this order)

1. **TL;DR** — One-paragraph plain-English summary: Phlex writes logs to `.logs/`; most failures trace to permissions, misconfiguration, or a missing binary. Start every debugging session with `tail -f .logs/phlex.log`. If playback fails, check FFmpeg. If the hub won't connect, check the JWKS URL and network reachability.

2. **Shell Blocks** — All diagnostic commands the user should run:

   - **Log locations:**
     ```
     .logs/                          # Rotating phlex-server logs per channel
     .logs/auth.log                  # AUTH channel
     .logs/http.log                   # HTTP channel
     .logs/websocket.log             # WEBSOCKET channel
     .logs/media.log                  # MEDIA channel (scanner, metadata)
     .logs/session.log               # SESSION channel
     .logs/streaming.log              # STREAMING channel (HLS, segment writes)
     .logs/transcode/                # Per-job FFmpeg transcode logs
     workerman.log                   # Workerman worker stdout (same dir as start command)
     ```

   - **Log tailing commands:**
     ```bash
     tail -f .logs/phlex.log              # All channels combined
     tail -f .logs/auth.log                # AUTH only
     tail -f .logs/http.log                 # HTTP only
     tail -f .logs/websocket.log            # WEBSOCKET only
     grep -i error .logs/phlex.log | tail -50   # Last 50 errors across all channels
     php bin/phlex log:tail --channel=auth  # Channel-specific tail with ANSI colors
     ```

   - **Server status checks:**
     ```bash
     curl -s http://localhost:32400/api/v1/system/status   # Is server responding?
     systemctl status phlex                                # systemd service status (Linux)
     ps aux | grep -E 'phlex|workerman' | grep -v grep     # Running processes
     lsof -i :32400                                        # Is port 32400 bound?
     ```

   - **Library / filesystem checks:**
     ```bash
     chmod -R 755 /media              # Fix permissions on media directories
     lsof data/phlex.db                # Check SQLite locks (if using SQLite)
     php scripts/run-migrations.php    # Verify DB schema is up to date
     ```

   - **FFmpeg / transcoding checks:**
     ```bash
     which ffmpeg                     # Is FFmpeg in PATH?
     ffmpeg -version                  # Version + available encoders/decoders
     php bin/phlex hwaccel:probe      # Probe HW acceleration (VAAPI, NVENC, QSV, VideoToolbox)
     iostat -x 1                      # Disk I/O bottleneck check (Linux)
     ```

   - **Hub connectivity checks:**
     ```bash
     curl -v https://hub.phlex.example.com  # Network reachability + TLS handshake
     curl -v https://hub.phlex.example.com/.well-known/jwks.json  # JWKS endpoint reachable?
     env | grep -i PHLEX_HUB           # Verify hub env vars are set
     ```

   - **Debug logging:**
     ```bash
     PHLEX_LOG_LEVEL=debug php public/index.php   # Start server with debug-level logging
     # Valid levels: debug, info, notice, warning, error, critical, alert, emergency
     ```

   - **Admin password reset:**
     ```bash
     php bin/phlex user:reset-password admin@example.com
     ```

3. **What Can Go Wrong** — Three failure scenarios per operational area:

   #### A. Connection refused on port 32400
   - **Symptom:** Browser shows "Connection refused" or "Unable to connect" at `http://server:32400`.
   - **Cause 1 — Server not running:** Phlex worker process is not started.
     - **Fix:** `php public/index.php` (foreground, for dev) or `systemctl start phlex` (production).
   - **Cause 2 — Wrong port:** `config/server.php` `http_port` value differs from the URL being accessed.
     - **Fix:** Check `http_port` in `config/server.php`; update the URL or the config.
   - **Cause 3 — Firewall blocking:** Port 32400 is not open on the host firewall.
     - **Fix:** `sudo ufw allow 32400` (ufw) or `sudo firewall-cmd --add-port=32400/tcp` (firewalld).

   #### B. Library not scanning / files not appearing
   - **Symptom:** Media files exist on disk but do not appear in the library after a rescan.
   - **Cause 1 — Wrong permissions:** Phlex worker (running as `phlex` or `www-data`) cannot read the media directory.
     - **Fix:** `chmod -R 755 /path/to/media && chown -R phlex:phlex /path/to/media`.
   - **Cause 2 — File naming not recognized:** Filename does not match `(Year)` or `S01E02` patterns the scanner expects.
     - **Fix:** Rename files to match conventions in `docs/libraries/movies.md` or `docs/libraries/tv.md`. Check scanner log at `.logs/media.log` for "unrecognized file" entries.
   - **Cause 3 — Database locked:** MySQL/MariaDB lock contention or SQLite `data/phlex.db` locked by another process.
     - **Fix:** `lsof data/phlex.db` (SQLite) or check MariaDB `SHOW PROCESSLIST` for lock-waiting threads. Restart the Phlex service to clear stale locks.

   #### C. Transcoding fails / playback stutters
   - **Symptom:** Playback starts but freezes, buffers continuously, or the player shows a transcode error.
   - **Cause 1 — FFmpeg not found:** `ffmpeg` binary is not in `PATH` or `config/ffmpeg.php` `binary_path` is wrong.
     - **Fix:** `which ffmpeg` — if empty, `sudo apt install ffmpeg` (Debian/Ubuntu) or equivalent. Set `binary_path` in `config/ffmpeg.php` if installed to a non-standard location.
   - **Cause 2 — HW acceleration not working:** GPU encode/decode not available; software transcode is too slow.
     - **Fix:** `php bin/phlex hwaccel:probe` — review output for available adapters (VAAPI, NVENC, QSV, VideoToolbox). Set `hwaccel` in `config/ffmpeg.php` and ensure the correct device node is accessible (`/dev/dri/renderD128` for VAAPI, etc.).
   - **Cause 3 — Disk I/O bottleneck:** Slow storage causes segment writing to block, starving the HLS segmenter.
     - **Fix:** `iostat -x 1` (Linux) — if `%util` on the relevant disk is ≥ 90%, move transcode output to a faster volume (tmpfs, SSD) by setting `transcode_tmp_path` in `config/ffmpeg.php`.

   #### D. Hub not connecting / claim fails
   - **Symptom:** Server appears offline in the Hub admin UI, or claim code fails to connect.
   - **Cause 1 — Server cannot reach Hub URL:** Outbound HTTPS to `hub.phlex.example.com` is blocked (corporate firewall, VPS egress filter).
     - **Fix:** `curl -v https://hub.phlex.example.com` from the server — if it times out or rejects, check egress rules. A reverse tunnel or VPN may be required (see `docs/advanced/remote-access-without-hub.md`).
   - **Cause 2 — Claim code expired:** Claim codes are single-use and valid for 15 minutes after generation.
     - **Fix:** Re-generate a fresh claim code in the server's admin UI → Hub → Generate Claim Code.
   - **Cause 3 — JWT validation fails:** The server's JWKS URL (`config/hub_jwks_url` env var) points to the wrong endpoint or the hub's signing key was rotated.
     - **Fix:** Verify `PHLEX_HUB_JWKS_URL` env var is set to `https://hub.phlex.example.com/.well-known/jwks.json`. If the hub signing key was rotated, re-trigger the hub handshake from the admin UI.

4. **FAQ** — Six compact question/answer pairs:

   **Q: Can I run Phlex in a subfolder instead of at the root domain?**
   A: Not natively. Phlex serves all routes relative to the root of the configured port. Running in a subfolder (e.g., `https://example.com/phlex/`) requires a reverse proxy (nginx, Caddy, or Apache) to rewrite the path before forwarding to Phlex. See `docs/advanced/reverse-proxy.md`.

   **Q: How many concurrent streams can Phlex handle?**
   A: It depends on the hardware and the playback mode. Direct play (no transcoding) uses minimal CPU — a single-core server can serve 10+ concurrent direct-play sessions. Transcoding is CPU-bound; a modern 8-core server can typically handle 2–4 simultaneous 1080p transcode streams. 4K HEVC transcoding requires significantly more CPU. Enable hardware acceleration to improve transcoding throughput.

   **Q: How do I reset the admin password?**
   A: Use the CLI:
   ```bash
   php bin/phlex user:reset-password admin@example.com
   ```
   You will be prompted to enter a new password interactively. If the server is running, restart it after resetting the password to clear existing sessions.

   **Q: How do I enable debug logging?**
   A: Set the `PHLEX_LOG_LEVEL` environment variable before starting the server:
   ```bash
   PHLEX_LOG_LEVEL=debug php public/index.php
   ```
   Valid levels (in order of verbosity): `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. Restart the server for the change to take effect.

   **Q: My media is not being found after adding new files. What do I do?**
   A: (1) Check that the media directory has correct permissions (`chmod -R 755 /path/to/media`). (2) Trigger a manual rescan from the web UI (Library → Scan) or check `.logs/media.log` for scanner activity. (3) Verify your file naming matches the conventions in the library guide (`docs/libraries/movies.md` or `docs/libraries/tv.md`). If the scanner logs show "unrecognized file", rename the file to match the expected pattern.

   **Q: Where are the Workerman/FFmpeg logs?**
   A: Workerman stdout is written to `workerman.log` in the directory where you ran `php public/index.php`. FFmpeg transcode logs are in `.logs/transcode/`, one file per job. Phlex application logs are in `.logs/` split by channel (AUTH, HTTP, WEBSOCKET, MEDIA, SESSION, STREAMING).

#### Metadata header

```markdown
**Phase:** N (End-User Documentation)
**Step:** N.19
**Since:** 0.18.0
```

#### Style notes

- Plain English, second person ("you", "your").
- No implementation details; user-facing only.
- Shell blocks use `bash` fenced code blocks.
- Cross-references to other docs use relative markdown links (e.g., `[backup guide](../advanced/backup-restore.md)`).
- FAQ answers are ≤ 4 sentences; each is self-contained (no "as mentioned above").
- "What Can Go Wrong" failure scenarios follow the pattern: symptom → cause → fix.

## 4. Approach

1. Branch from master: `git checkout -b n.19-troubleshooting-docs`.
2. Read all context files listed in §2 above.
3. Write `docs/troubleshooting.md` following the §7 layout exactly (TL;DR → Shell Blocks → What Can Go Wrong → FAQ).
4. Add cross-links from existing docs that reference troubleshooting content.
5. Verify: no PHP/JS implementation, only prose + shell blocks + links.
6. Commit + PR + merge.

## 5. Acceptance Criteria

- [ ] `docs/troubleshooting.md` exists with all 4 required §7 sections.
- [ ] TL;DR paragraph is present and ≤ 3 sentences.
- [ ] Shell Blocks section covers all 8 diagnostic groups (log locations, log tailing, server status, library/filesystem, FFmpeg, hub connectivity, debug logging, admin password reset).
- [ ] "What Can Go Wrong" documents ≥ 3 failure scenarios (Connection refused, Library not scanning, Transcoding fails, Hub not connecting — covering all 4 is ideal).
- [ ] Each failure scenario has a symptom + cause + fix structure.
- [ ] FAQ section contains ≥ 5 question/answer pairs covering subfolder, concurrent streams, admin password reset, debug logging, and media not found.
- [ ] Metadata header with Phase, Step, Since fields present.
- [ ] No implementation code; only shell blocks, prose, and cross-links.
- [ ] All cross-links are valid relative paths.
- [ ] PHPCS clean (no PSR-12 violations in documentation style).

## 6. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b n.19-troubleshooting-docs
# ... write docs/troubleshooting.md ...
git add docs/troubleshooting.md
git commit -m "Step N.19: Troubleshooting & FAQ guide"
unset GITHUB_TOKEN
gh pr create --title "Step N.19: Troubleshooting & FAQ guide" --body "Doc-only step. Creates docs/troubleshooting.md with §7 layout."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 7. Reviewer hand-off

Review = No. This is a doc-only step. Merge when ready.

(End of file - total 208 lines)
