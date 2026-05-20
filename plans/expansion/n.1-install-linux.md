# Step N.1 — Install phlex-server on Linux

**Phase:** N (End-User Documentation)
**Step:** N.1
**Depends on:** N.0
**Review:** No
**Target repo:** detain/phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the Linux installation guide at `docs/install/linux.md`, covering apt-based (Ubuntu 22.04+/Debian 12+), dnf-based (Fedora 40+), and source installs, plus systemd setup, firewall configuration, and first-run guidance.

## 2. Context (what already exists)

- No `docs/install/` directory yet — N.0 creates the docs platform and `docs/install/` index
- `docs/libraries/` and `docs/dev/` already have some content
- The §7 docs tree layout specifies `docs/install/linux.md`
- Branch `n.1-install-linux` will be cut from `master` after N.0 merges

## 3. Scope

### Create

- `docs/install/linux.md` — Linux installation guide

### Modify

- `docs/install/README.md` (only if N.0 created one and it needs the Linux guide added to an index)

## 4. Doc content outline

### TL;DR (one screen)
- Short paragraph: what phlex-server is, that this guide installs it on Linux in ~15 min
- Minimum requirements: 2 CPU / 4 GB RAM, a clean Ubuntu 22.04+, Debian 12+, or Fedora 40+ host
- Quick-command one-liner for the impatient (apt install → clone → composer install → systemd enable → open browser)

### 1. Supported operating systems
- Table: Distro | Version | Package manager | Notes
- Ubuntu 22.04+ (LTS), Debian 12+ (Bookworm), Fedora 40+
- Recommendation to use a non-root sudo user

### 2. Install system dependencies

#### 2a. Ubuntu / Debian (apt)
```bash
sudo apt update
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-curl php8.3-gd php8.3-zip php8.3-xml \
  php8.3-mbstring php8.3-bcmath mariadb-server ffmpeg git curl unzip
```

#### 2b. Fedora (dnf)
```bash
sudo dnf install -y php-fpm php-mysqlnd php-curl php-gd php-zip php-xml \
  php-mbstring php-bcmath mariadb-server ffmpeg git curl unzip
```

#### 2c. From source (all distros)
```bash
# Install PHP 8.3 from source, MariaDB from distro packages, FFmpeg from jellyfin-ffmpeg PPA/source
```

### 3. Database setup (MariaDB)
```bash
sudo mysql_secure_installation
sudo mysql -u root -p -e "CREATE DATABASE phlex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -u root -p -e "CREATE USER 'phlex'@'localhost' IDENTIFIED BY 'your_strong_password';"
sudo mysql -u root -p -e "GRANT ALL PRIVILEGES ON phlex.* TO 'phlex'@'localhost';"
sudo mysql -u root -p -e "FLUSH PRIVILEGES;"
```

### 4. Clone phlex-server
```bash
sudo mkdir -p /opt/phlex
sudo chown $USER:$USER /opt/phlex
git clone https://github.com/detain/phlex-server.git /opt/phlex
cd /opt/phlex
```

### 5. PHP dependencies (Composer)
```bash
composer install --no-dev --optimize-autoloader
```

### 6. Configure environment
```bash
cp .env.example .env
# Edit .env with:
#   APP_URL=http://your-server-ip:32400
#   DB_HOST=localhost
#   DB_DATABASE=phlex
#   DB_USERNAME=phlex
#   DB_PASSWORD=your_strong_password
```

### 7. Database migrations
```bash
php scripts/run-migrations.php
```

### 8. systemd service unit
```ini
[Unit]
Description=Phlex Media Server
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/phlex
ExecStart=/usr/bin/php /opt/phlex/public/index.php
Restart=on-failure
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```
```bash
sudo cp phlex.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable phlex
sudo systemctl start phlex
```

### 9. Firewall configuration
```bash
# UFW
sudo ufw allow 32400/tcp comment 'Phlex HTTP'
sudo ufw allow 1900/udp comment 'DLNA discovery (optional)'

# firewalld (Fedora)
sudo firewall-cmd --permanent --add-port=32400/tcp
sudo firewall-cmd --permanent --add-port=1900/udp
sudo firewall-cmd --reload
```

### 10. Verify the install
```bash
sudo systemctl status phlex
curl -I http://localhost:32400
```
Expected: HTTP 200 from the phlex index

### What can go wrong

#### PHP extension missing
- Symptom: `Class 'PDO' not found` or similar during `composer install`
- Fix: `apt install php8.3-mysql php8.3-gd` (or matching extensions for your PHP version)
- Verify: `php -m | grep pdo_mysql`

#### MariaDB not running
- Symptom: `Connection refused` on `localhost:3306` after install
- Fix: `sudo systemctl start mariadb && sudo systemctl enable mariadb`
- Verify: `sudo mysql -u root -p -e "SELECT 1;"`

#### FFmpeg not found / wrong version
- Symptom: Transcoding fails, "FFmpeg not found" in logs
- Fix (Ubuntu/Debian): `sudo apt install ffmpeg` — for better transcoding use jellyfin-ffmpeg
- Fix (Fedora): enable RPM Fusion first `dnf install https://mirrors.rpmfusion.org/free/fedora/rpmfusion-free-release-$(rpm -E %fedora).noarch.rpm && dnf install ffmpeg`
- Verify: `ffmpeg -version`

#### Permission denied on /var/lib/phlex
- Symptom: "Cannot create file /var/lib/phlex/..." in logs
- Fix: `sudo chown -R www-data:www-data /var/lib/phlex && sudo chmod -R 755 /var/lib/phlex`

#### Port 32400 already in use
- Symptom: `bind(): Address already in use`
- Fix: `sudo ss -tlnp | grep 32400` to find the conflicting process, stop it or change phlex port via `APP_URL` env var

### Next steps
- [First-run wizard](docs/first-run.md) — complete the browser-based setup at `http://your-server:32400`
- [Docker install](docs/install/docker.md) — alternative install method using containers
- [Hardware transcoding](docs/hardware-transcoding.md) — configure NVENC/VAAPI for better performance
