# Step N.4 — Install phlex-server on macOS

**Phase:** N (End-User Documentation)
**Step:** N.4
**Depends on:** N.0 (docs platform)
**Review:** No
**Target repo:** detain/phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the macOS installation guide at `docs/install/macos.md`, covering Homebrew, MacPorts, PHP-FPM, Launchd plist for auto-start, macOS firewall, and Intel/Apple Silicon differences.

## 2. Context (what already exists)

- `docs/install/linux.md` (N.1) is the reference install doc — follow its §7 layout pattern
- `docs/install/` index and platform exist via N.0
- Branch `n.4-install-macos` will be cut from `master` after N.0 merges
- No existing macOS install docs

## 3. Scope

### Create

- `docs/install/macos.md` — macOS installation guide

### Modify

- `docs/install/README.md` (only if N.0 created one and it needs the macOS guide added to an index)

## 4. Doc content outline

### TL;DR (one screen)
- Short paragraph: what phlex-server is, that this guide installs it on macOS in ~15 min
- Minimum requirements: macOS 12+ (Monterey or later), 2 CPU / 4 GB RAM, Homebrew or MacPorts
- Quick-command one-liner for the impatient (brew install → clone → composer install → brew services start → open browser)

### 1. Supported macOS versions and hardware
- Table: macOS version | Chip | Architecture | Notes
- macOS 12 (Monterey), 13 (Ventura), 14 (Sonoma), 15 (Sequoia)
- Intel (Broadwell and later) vs Apple Silicon (M1/M2/M3/M4)
- Rosetta 2 notes where relevant

### 2. Choose a package manager

#### 2a. Homebrew (recommended)
```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

#### 2b. MacPorts (alternative)
```bash
# Download installer from https://www.macports.org/install.php
```

### 3. Install system dependencies

#### 3a. Homebrew
```bash
brew install php@8.3 mysql@8.0 ffmpeg git curl
```

#### 3b. MacPorts
```bash
sudo port install php83 php83-mysqlnd php83-gd mysql83 ffmpeg git curl
```

#### Apple Silicon notes
- Homebrew installs to `/opt/homebrew/` on Apple Silicon vs `/usr/local/` on Intel
- Add to PATH in `~/.zshrc` or `~/.bash_profile`:
  ```bash
  # Intel
  export PATH="/usr/local/bin:$PATH"

  # Apple Silicon
  export PATH="/opt/homebrew/bin:$PATH"
  export PATH="/opt/homebrew/sbin:$PATH"
  ```
- MySQL socket: `/opt/homebrew/var/mysql/` (Apple Silicon Homebrew) vs `/usr/local/var/mysql/` (Intel Homebrew)

#### 3c. Verify PHP-FPM
```bash
# Homebrew starts PHP-FPM automatically; manual control:
brew services start php@8.3
php-fpm -t  # test config
```

### 4. Database setup (MySQL)
```bash
# Start MySQL via Homebrew services
brew services start mysql@8.0

# Secure installation (first run)
mysql_secure_installation

# Create phlex database and user
mysql -u root -p -e "CREATE DATABASE phlex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'phlex'@'localhost' IDENTIFIED BY 'your_strong_password';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON phlex.* TO 'phlex'@'localhost';"
mysql -u root -p -e "FLUSH PRIVILEGES;"
```

### 5. Clone phlex-server
```bash
sudo mkdir -p /opt/phlex
sudo chown $USER /opt/phlex
git clone https://github.com/detain/phlex-server.git /opt/phlex
cd /opt/phlex
```

### 6. PHP dependencies (Composer)
```bash
composer install --no-dev --optimize-autoloader
```

### 7. Configure environment
```bash
cp .env.example .env
# Edit .env with:
#   APP_URL=http://your-mac-ip:32400
#   DB_HOST=localhost
#   DB_SOCKET=/opt/homebrew/var/mysql/mysql.sock   # Apple Silicon Homebrew
#   DB_SOCKET=/usr/local/var/mysql/mysql.sock         # Intel Homebrew
#   DB_DATABASE=phlex
#   DB_USERNAME=phlex
#   DB_PASSWORD=your_strong_password
```

### 8. Database migrations
```bash
php scripts/run-migrations.php
```

### 9. Start the server manually (first test)
```bash
php public/index.php
# or to run in background:
nohup php public/index.php > /opt/phlex/phlex.log 2>&1 &
```

### 10. Launchd plist for auto-start

Create `~/Library/LaunchAgents/com.phlex.media-server.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.phlex.media-server</string>
    <key>ProgramArguments</key>
    <array>
        <string>/opt/homebrew/bin/php</string>
        <string>/opt/phlex/public/index.php</string>
        <string>start</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>WorkingDirectory</key>
    <string>/opt/phlex</string>
    <key>StandardOutPath</key>
    <string>/opt/phlex/phlex.log</string>
    <key>StandardErrorPath</key>
    <string>/opt/phlex/phlex.error.log</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PHLEX_ENV</key>
        <string>production</string>
    </dict>
</dict>
</plist>
```

Install and load:
```bash
# Copy plist
cp ~/Library/LaunchAgents/com.phlex.media-server.plist ~/Library/LaunchAgents/

# Load (start immediately and on boot)
launchctl load ~/Library/LaunchAgents/com.phlex.media-server.plist

# Verify
launchctl list | grep phlex
```

Note: The plist above uses Apple Silicon paths. For Intel, change `/opt/homebrew/bin/php` to `/usr/local/bin/php` and adjust socket paths in the `.env`.

### 11. macOS Firewall configuration

#### Application Firewall UI (simplest)
1. System Settings → Privacy & Security → Firewall
2. Turn on Firewall
3. Click "Firewall Options..."
4. Add `/opt/phlex/public/index.php` (or allow PHP to accept incoming connections)

#### pfctl CLI (advanced)
```bash
# Add to /etc/pf.anchors/com.phlex
# pass in proto tcp from any to any port 32400 keep state

# Reload pfctl
sudo pfctl -f /etc/pf.conf -E
```

Note: macOS built-in firewall blocks incoming to port 32400. For LAN-only access add an exception via System Settings first. DLNA/UDP 1900 discovery optional.

### 12. Verify the install
```bash
# Check server is running
curl -I http://localhost:32400

# Expected: HTTP 200 from the phlex index

# Check Launchd service
launchctl list | grep phlex
```

### What can go wrong

#### Homebrew path conflicts
- Symptom: `php: command not found` or wrong PHP version after installation
- Fix: Verify PATH — Intel Macs use `/usr/local/bin` first; Apple Silicon use `/opt/homebrew/bin`. Add to `~/.zshrc` explicitly
- Verify: `which php` and `php -v`

#### MySQL socket in wrong location
- Symptom: `SQLSTATE[HY000] [2002] No such file or directory` connecting to MySQL
- Cause: Homebrew MySQL 8.x on Apple Silicon places socket at `/opt/homebrew/var/mysql/mysql.sock`; PHP may look in `/tmp/mysql.sock` or `/var/mysql/`
- Fix: Set `DB_SOCKET=/opt/homebrew/var/mysql/mysql.sock` in `.env` (Apple Silicon) or `DB_SOCKET=/usr/local/var/mysql/mysql.sock` (Intel)
- Verify: `ls -la /opt/homebrew/var/mysql/mysql.sock` (or the Intel equivalent)

#### FFmpeg missing codecs
- Symptom: Some video files fail to transcode; error mentions "Unknown encoder" or "codec not supported"
- Fix (Homebrew): `brew install ffmpeg` installs a standard build; for full codec support use `brew install homebrew-ffmpeg/ffmpeg/homebrew-ffmpeg`
- Fix (MacPorts): `sudo port install ffmpeg +full`
- Verify: `ffmpeg -codecs | grep -c h264` (should be > 0)

#### Port 32400 already in use
- Symptom: `bind(): Address already in use` or `Port 32400 in use`
- Fix: `sudo lsof -i :32400` to find the conflicting process (e.g., another web server or AirPlay receiver). Stop it or change phlex port via `APP_URL` env var
- Verify after fix: `curl -I http://localhost:32400`

### Next steps
- [First-run wizard](docs/first-run.md) — complete the browser-based setup at `http://your-mac-ip:32400`
- [Docker install](docs/install/docker.md) — alternative install using containers on macOS
- [Hardware transcoding](docs/hardware-transcoding.md) — configure VideoToolbox on Apple Silicon for better performance

## 5. Approach

1. Branch from master: `git checkout -b n.4-install-macos`
2. Create `docs/install/macos.md` following the §7 layout pattern from `n.1-install-linux.md`
3. Follow the exact TL;DR → shell blocks → what-can-go-wrong (3 failures) → next-steps structure
4. Commit + PR + merge

## 6. Acceptance Criteria

- [ ] TL;DR section gives a one-screen overview with quick-command one-liner
- [ ] All shell blocks use `zsh`/`bash` correctly
- [ ] Intel vs Apple Silicon path differences documented in §3 and §10
- [ ] Launchd plist example uses correct ProgramArguments for each architecture
- [ ] MySQL socket path differences documented with DB_SOCKET env var
- [ ] Three failures in what-can-go-wrong: Homebrew path conflicts, MySQL socket wrong location, FFmpeg missing codecs
- [ ] Port 32400 already-in-use included as fourth failure
- [ ] Next steps links to first-run, docker, and hardware-transcoding docs
- [ ] PHPCS clean on docs/
- [ ] PHPStan clean on any PHP snippets in docs/

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b n.4-install-macos
# ... write docs/install/macos.md ...
git add -A
git commit -m "Step N.4: macOS installation guide"
unset GITHUB_TOKEN
gh pr create --title "Step N.4: macOS installation guide" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = No. This is a doc-only step.

(End of file — total 257 lines)
