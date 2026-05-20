# Step N.16 — Live TV Setup Guide

**Phase:** N (End-User Documentation)
**Step:** N.16
**Depends on:** I.5 (scheduled DVR — already merged)
**Review:** No (doc-only step)
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**One-liner:** Live TV setup (tuner selection, EPG account)

---

## Goal

Write the end-user guide for **Live TV setup** at `docs/advanced/live-tv.md`. Covers tuner selection and configuration (HDHomeRun, USB DVB-T, M3U/IPTV), EPG account setup (Schedules Direct, XMLTV), and a concise DVR scheduling overview — with the required §7 layout.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Three tuner types covered: HDHomeRun (network), USB DVB-T (Linux), M3U/IPTV | These are the three tuner types the Live TV stack supports | `src/LiveTv/LiveTvManager.php` + Phase I implementation |
| HDHomeRun discovery via `hdhomerun_config discover` and `/lineup/status` | Standard HDHomeRun discovery commands used across the project | `config/livetv.php` HDHomeRun config section |
| IPTV M3U playlist + XMLTV guide data upload | Two-file model for IPTV: playlist for channels, XMLTV for guide | I.2 IPTV implementation |
| EPG via Schedules Direct ($25/yr) or XMLTV import | Both are real options; SD is more reliable, XMLTV is self-hosted/free | I.4 EPG implementation |
| DVR scheduling: series rules, conflict resolution, storage | These three DVR concepts are what end users most commonly ask about | I.5 DVR implementation |
| §7 layout: TL;DR, shell blocks, what-can-go-wrong (3 failures), next-steps | Required structure for all Phase N end-user guides | PHLEX_EXPANSION_PLAN.md §7 |
| Three what-can-go-wrong failures: HDHomeRun UDP blocked, EPG channel mismatch, storage full + conflict resolution | These are the three most common Live TV setup failures | Live TV ops experience from Phase I |
| Fourth failure as bonus: tuner busy / recording missed | Common follow-on when two shows overlap and both tuners are busy | I.5 conflict resolution |

---

## Phase 1: Draft `docs/advanced/live-tv.md` [IN PROGRESS]

- [ ] **1.1** Read `plans/expansion/i.3-dvbt.md` for DVB-T tuner context
- [ ] **1.2** Read `plans/expansion/i.2-iptv.md` for IPTV/M3U context
- [ ] **1.3** Read `plans/expansion/i.4-epg.md` for EPG/Schedules Direct context
- [ ] **1.4** Read `plans/expansion/i.5-dvr.md` for DVR scheduling context
- [ ] **1.5** Read `src/LiveTv/LiveTvManager.php` and `src/LiveTv/ChannelManager.php` for current tuner API surface
- [ ] **1.6** Read `config/livetv.php` for all tuner config options
- [ ] **1.7** Draft `docs/advanced/live-tv.md` (see §2 Content Outline below)
- [ ] **1.8** Self-review against §7 layout requirements: TL;DR, shell blocks, what-can-go-wrong (3 failures), next-steps

---

## Phase 2: Verification [PENDING]

- [ ] **2.1** Confirm all four §7 required sections are present (TL;DR, shell blocks, what-can-go-wrong, next-steps)
- [ ] **2.2** Confirm all three tuner types are covered (HDHomeRun, DVB-T, IPTV/M3U) with distinct setup steps
- [ ] **2.3** Confirm EPG setup covers both Schedules Direct (account + channel selection) and XMLTV import
- [ ] **2.4** Confirm DVR scheduling section covers series rules, conflict resolution, and storage location
- [ ] **2.5** Confirm "what can go wrong" covers exactly 3 distinct failures with diagnostic shell commands
- [ ] **2.6** Confirm tuner status and concurrent stream limits are documented
- [ ] **2.7** Proofread for clarity, accuracy, and tone suitable for end users (not developers)
- [ ] **2.8** Confirm all cross-links use valid relative paths to other docs

---

## Phase 3: Commit [PENDING]

- [ ] **3.1** Branch: `git checkout -b n.16-livetv-setup`
- [ ] **3.2** Commit: `git add docs/advanced/live-tv.md && git commit -m "Step N.16: Live TV setup guide (end-user docs)"`
- [ ] **3.3** PR: `gh pr create --title "Step N.16: Live TV setup guide" --body "Writes docs/advanced/live-tv.md as an end-user guide covering tuner selection and setup (HDHomeRun, DVB-T, IPTV/M3U), EPG account setup (Schedules Direct, XMLTV), DVR scheduling basics, and 3 common failure scenarios. Part of Phase N (Step N.16 of PHLEX_EXPANSION_PLAN.md)."`
- [ ] **3.4** Merge: `gh pr merge --squash --delete-branch`
- [ ] **3.5** Return to master: `git checkout master && git pull --ff-only origin master`

---

## §2 Content Outline for `docs/advanced/live-tv.md`

### Metadata header

```markdown
**Phase:** N (End-User Documentation)
**Step:** N.16
**Since:** 0.18.0
```

---

### TL;DR

One-paragraph plain-English summary: Live TV lets you watch and record broadcast/cable/IPTV channels through Phlex. You connect a tuner (HDHomeRun over the network, a USB DVB-T stick on Linux, or an IPTV M3U playlist), configure guide data (Schedules Direct or XMLTV), and you're ready to watch live TV or schedule recordings. Setup takes 10–30 minutes depending on tuner type and guide data source. Once configured, Live TV appears alongside your regular media library.

---

### 1. Tuner Types — Which One to Use

Brief comparison table:

| Tuner | Connection | Platforms | Channels | Notes |
|-------|-----------|-----------|----------|-------|
| HDHomeRun | Network (Ethernet/WiFi) | Any | ATSC/DVB-C/T | Zero-config discovery on LAN |
| USB DVB-T/T2 | USB on server | Linux only | DVB-T/T2 | Kernel drivers required |
| IPTV / M3U | Internet/INTV | Any | Varies | Playlist from ISP or IPTV provider |

Recommendation: HDHomeRun for most users (simplest), IPTV for cord-cutters without an antenna, DVB-T for Linux-only server with antenna access.

---

### 2. Setting Up an HDHomeRun Tuner

**Step-by-step:**

1. Connect the HDHomeRun device to your network (wired recommended for reliability).
2. Discover the device IP address:

   ```bash
   # Auto-discover HDHomeRun devices on the LAN
   hdhomerun_config discover

   # Example output:
   # hdhr: 192.168.1.100 / tuners: 2
   ```

3. Verify channel lineup is detected:

   ```bash
   # Replace DEVICE_ID with your HDHomeRun device ID
   hdhomerun_config DEVICE_ID get /lineup/status
   ```

   You should see a list of channels with numbers and names. If the list is empty, the HDHomeRun may not have found antenna/cable channels — check your antenna placement or cable signal.

4. In the Phlex web UI: go to **Settings → Live TV → Add Tuner → HDHomeRun**.
5. The device IP is auto-detected if it is on the same LAN. Select it and confirm.
6. Phlex will scan and import all detected channels.

**Tuner status and concurrent streams:** Each HDHomeRun model specifies a maximum number of simultaneous streams (commonly 2 or 4). The Live TV section of the UI shows each tuner and its current status (idle / streaming / recording). You cannot exceed the per-tuner stream limit — a second stream request when all tuners are busy returns an error.

---

### 3. Setting Up a USB DVB-T Tuner (Linux)

**Requirements:**
- Linux server with a kernel supporting DVB-T/T2 (most modern kernels include these drivers)
- A supported USB DVB-T/T2 stick (e.g., RTL-SDR, Astro DMW, Hauppauge WinTV)
- Antenna connected to the tuner

**Step-by-step:**

1. Plug the USB tuner into the server. Check dmesg for recognition:

   ```bash
   dmesg | grep -i dvb
   # Expected: "DVB: registering adapter 0/0" etc.
   ```

2. Install the required firmware (varies by tuner — check the device docs):

   ```bash
   # Example for RTL-SDR:
   apt install librtlsdr0
   ```

3. In Phlex: go to **Settings → Live TV → Add Tuner → DVB-T**.
4. Phlex scans the available frequency range and imports discovered channels.
5. If no channels are found: check antenna placement, try outdoors, or use an amplified antenna.

**Linux-only note:** DVB-T tuners require the server to be Linux. If your Phlex server runs in Docker on a NAS, the USB passthrough must be correctly configured for the container to access the device.

---

### 4. Setting Up IPTV / M3U

**When to use IPTV:** When you have an IPTV subscription from an ISP or third-party provider (e.g., a local cable company that offers an internet stream), or when you use a public IPTV service that provides an M3U playlist.

**Step-by-step:**

1. Get the M3U playlist file from your IPTV provider (usually a `.m3u` or `.m3u8` URL or file).

2. In Phlex: go to **Settings → Live TV → Add IPTV Tuner**.
3. Upload the `.m3u` file or paste the M3U URL.
4. Phlex imports the channel list from the playlist.
5. Optional: upload XMLTV guide data (see §5 below).

**M3U format reminder:** Phlex reads `#EXTINF` lines for channel names and channel numbers. The order in the M3U determines the channel numbering unless overridden.

---

### 5. Setting Up the Electronic Program Guide (EPG)

Live TV is significantly more useful with guide data (EPG) showing program listings and schedules. Phlex supports two sources: **Schedules Direct** (recommended) and **XMLTV** (self-hosted).

#### 5a. Schedules Direct (Recommended)

**Account setup:**

1. Go to [schedulesdirect.org](https://www.schedulesdirect.org) and create an account (~$25/year).
2. Log in and select your lineup (antenna channels by ZIP/postal code or your IPTV provider's channel lineup).
3. Note your username and password for the next step.

**In Phlex:**

1. Go to **Settings → Live TV → EPG Source → Schedules Direct**.
2. Enter your Schedules Direct username and password.
3. Phlex connects and syncs your channel lineup.
4. Initial sync downloads ~14 days of guide data and may take a few minutes.
5. Guide data refreshes automatically every night. To force an immediate refresh, click **Refresh Guide** in the Live TV settings.

**What you get:** Program titles, descriptions, start/end times, categories, and original air dates for each channel. This data powers: program guide in the UI, upcoming program listings, and DVR series rule matching.

#### 5b. XMLTV Import (Self-Hosted / Free)

If you prefer not to pay for Schedules Direct, you can use free XMLTV data from [xmltv.org](https://www.xmltv.org) or a similar source. Note that free XMLTV data is often less complete than Schedules Direct and may have stale or missing entries for some channels.

**Step-by-step:**

1. Download an XMLTV schedule for your region (e.g., from xmltv.org).
2. In Phlex: go to **Settings → Live TV → EPG Source → XMLTV Import**.
3. Upload the `.xml` or `.xml.gz` file.
4. Phlex parses and imports the guide data.
5. Re-upload periodically (or script it) to keep the guide current.

---

### 6. DVR Scheduling Basics

Once your tuners and guide data are configured, you can schedule recordings.

#### Series Rules

When you record a show from the guide, Phlex asks whether to create a **series rule**:

| Option | What it does |
|--------|-------------|
| Record all episodes | Records every future episode of this show |
| New episodes only | Skips reruns; only records episodes flagged as new |
| Specific timeslot | Records only episodes that air in the chosen time slot |

Series rules appear in **Settings → Live TV → Recording Rules** where you can edit or delete them.

#### Conflict Resolution

When two shows are scheduled to record at the same time:

1. **Both tuners free** — both recordings start normally.
2. **One tuner busy, one free** — the free tuner records the higher-priority show; the other is marked as conflict.
3. **Both tuners busy** — one show is recorded; the other is marked as conflict and you are notified.

**Conflict resolution preference:** In **Settings → Live TV → DVR**, you can set whether Phlex prefers to keep existing recordings or prioritize new episodes when resolving conflicts.

#### Storage

Recordings are stored in the path configured in **Settings → Live TV → Storage**. This can be:
- A dedicated folder (e.g., `/var/recordings`) outside your media library
- A subfolder of an existing media library (e.g., `/media/recordings` inside your Movies library)

Storage usage is shown in **Settings → Live TV → Storage** with total / used / free bytes. See "What Can Go Wrong" below if the drive fills up.

**Post-recording:** After a recording completes, Comskip runs automatically if enabled (see [Live TV Comskip](../advanced/live-tv-comskip.md)) to detect and flag commercials.

---

### 7. What Can Go Wrong

#### Failure 1: HDHomeRun not discovered (UDP port 65001 blocked)

**Symptom:** HDHomeRun tuner is connected to the network but does not appear in Phlex during setup.

**Diagnosis:**

```bash
# Check if the HDHomeRun device is reachable on the network
# hdhomerun_config uses UDP port 65001 for discovery
nc -zvu 192.168.1.100 65001

# Or use the discovery command (broadcasts on UDP 65001)
hdhomerun_config discover
```

**Fix:** The `hdhomerun_config discover` command and Phlex's auto-discovery both use UDP port 65001. If a firewall (server firewall, router firewall, or VPN) blocks this port, discovery fails. Open UDP 65001 on the relevant firewall(s), or manually enter the HDHomeRun IP address during setup instead of using auto-discovery. You can find the IP address via your router's device list or by checking the HDHomeRun's built-in web interface at `http://<hdhomerun-ip>`.

---

#### Failure 2: EPG guide data shows wrong channels or mismatched channel numbers (M3U + XMLTV mismatch)

**Symptom:** The program guide appears but channel numbers or names don't match the actual channels in the M3U playlist.

**Diagnosis:**

```bash
# Check the first few entries of your M3U playlist — note the #EXTINF channel numbers
head -20 /path/to/playlist.m3u

# Check the corresponding channel IDs in your XMLTV file
grep -m 5 "<channel" /path/to/guide.xml

# The M3U #EXTINF index order should match the XMLTV channel IDs
# If they don't, the guide data is mapped to the wrong channels
```

**Fix:** The M3U and XMLTV files must have matching channel references. The M3U defines channel order by its `#EXTINF` line sequence; the XMLTV file uses `<channel id="...">` elements. When using both together, Phlex maps them by channel number or name. If the mapping is wrong, either:
- Re-export the M3U or XMLTV with matching channel identifiers, or
- Switch to Schedules Direct which maintains its own channel map and avoids this mismatch entirely.

---

#### Failure 3: DVR storage drive fills up

**Symptom:** Recordings stop mid-recording or fail to start. The Live TV UI shows a storage error.

**Diagnosis:**

```bash
# Check recording storage path usage
df -h /var/recordings

# Or check the configured storage path from config/livetv.php:
# 'storage_path' setting

# List recording file sizes
du -sh /var/recordings/* | sort -rh | head -20
```

**Fix:** Free up space by:
- Deleting completed recordings you no longer need (select them in the UI → delete).
- Changing the storage path to a larger drive: **Settings → Live TV → Storage**.
- Setting a maximum storage limit (Phlex stops recording when the limit is reached).

To prevent this, set a **maximum storage** value in **Settings → Live TV → DVR** and enable **auto-delete** to remove old recordings when space is low.

---

#### Failure 4 (bonus): Recording missed due to tuner conflict

**Symptom:** A scheduled recording did not happen. The recording shows as "Missed" or "Conflict" in the UI. The tuner was busy with another show at the same time.

**Diagnosis:**

```bash
# Check the Live TV tuner status in the UI (Settings → Live TV → Tuners)
# Or via the API:
curl http://localhost:32400/api/v1/livetv/tuners

# Look for a tuner that was busy (status: recording) during the missed show's time slot
```

**Fix:** Conflict resolution follows the priority set in **Settings → Live TV → DVR**. If both tuners were busy, one show was recorded and the other was flagged as a conflict. To avoid this:
- Add a second tuner (HDHomeRun dual-tuner, a second DVB-T stick, or an extra IPTV connection).
- Adjust series rules to avoid overlapping timeslots for shows you want to keep.
- Set conflict preference to "prioritize new episodes" to prefer first-run content over reruns.
- Check the **Upcoming Recordings** list regularly for conflicts and resolve manually.

---

### 8. Next Steps

- [Live TV Comskip](../advanced/live-tv-comskip.md) — configure automatic commercial detection and skipping in recordings
- [DLNA / Play To](../clients/dlna.md) — stream live TV or recordings to DLNA-enabled devices
- [Remote Access / Hub](../hub/remote-access.md) — access Live TV from outside your home network via the hub
- [Recording Rules](../advanced/live-tv-recordings.md) — managing and editing scheduled recordings
