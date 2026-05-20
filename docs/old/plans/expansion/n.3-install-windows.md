# Step N.3 — Install phlex-server on Windows

**Phase:** N (End-User Documentation)
**Step:** N.3
**Depends on:** N.0
**Review:** No
**Target repo:** phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the Windows installation guide at `docs/install/windows.md`, covering XAMPP, WSL2+Ubuntu (recommended), and IIS reverse-proxy options, plus PHP 8.3 setup, firewall configuration, and first-run guidance.

## 2. Context (what already exists)

- No `docs/install/` directory yet — N.0 creates the docs platform and `docs/install/` index
- `docs/install/linux.md` (N.1) provides the Linux install template to mirror for Windows
- The §7 docs tree layout specifies `docs/install/windows.md`
- Branch `n.3-install-windows` will be cut from `master` after N.0 merges

## 3. Scope

### Create

- `docs/install/windows.md` — Windows installation guide

### Modify

- `docs/install/README.md` (only if N.0 created one and it needs the Windows guide added to an index)

## 4. Doc content outline

### TL;DR (one screen)
- Short paragraph: what phlex-server is, that this guide installs it on Windows in ~20 min
- Minimum requirements: Windows 10 21H2+ or Windows 11, 2 CPU / 4 GB RAM
- Quick-command one-liner for the impatient (XAMPP install → clone → composer install → start Apache → open browser)
- Recommendation: WSL2+Ubuntu for production; XAMPP for quick dev / non-Docker users

### 1. Choose your installation path

#### Option 1: XAMPP (recommended for dev / non-Docker users)
- Full stack: Apache + PHP + MariaDB bundled together
- Easiest for users who don't want to touch WSL or containers
- Download from https://www.apachefriends.org/

#### Option 2: WSL2 + Ubuntu (recommended for production)
- Runs a real Ubuntu VM inside Windows
- Native Linux experience: composer, apt, systemd scripts all work
- Requires: Windows 10 21H2+ or Windows 11 with WSL2 enabled
- See §2 for WSL2 setup steps

#### Option 3: IIS reverse-proxy to Workerman PHP
- For enterprise environments that already run IIS
- Requires URL Rewrite module + ARR (Application Request Routing)
- Advanced; less common

### 2. Option 1 — XAMPP install

#### 2a. Download and install XAMPP
- Download XAMPP PHP 8.3 from https://www.apachefriends.org/download.html
- Run installer → deselect unnecessary components (e.g., Tomcat)
- Install to default `C:\xampp`
- Start XAMPP Control Panel

#### 2b. Enable required PHP extensions
Edit `C:\xampp\php\php.ini` and ensure these lines are uncommented (no `;`):
```ini
extension=curl
extension=gd
extension=mbstring
extension=mysql
extension=openssl
extension=zip
extension=xml
extension=bcmath
```
Save and restart Apache from the XAMPP Control Panel.

#### 2c. Verify PHP version
```cmd
C:\xampp\php\php.exe -v
```
Expected: `PHP 8.3.x`

#### 2d. Clone phlex-server
```cmd
git clone https://github.com/detain/phlex-server.git C:\phlex
cd C:\phlex
```

#### 2e. PHP dependencies (Composer)
```cmd
C:\xampp\composer\composer.bat install --no-dev --optimize-autoloader
```
Or if Composer is installed globally:
```cmd
composer install --no-dev --optimize-autoloader
```

#### 2f. Configure environment
```cmd
copy .env.example .env
```
Edit `.env` with:
```
APP_URL=http://localhost:32400
DB_HOST=localhost
DB_DATABASE=phlex
DB_USERNAME=phlex
DB_PASSWORD=your_strong_password
```

#### 2g. Database setup (MariaDB via XAMPP)
Open phpMyAdmin at http://localhost/phpmyadmin or run:
```cmd
C:\xampp\mysql\bin\mysql.exe -u root -p
```
```sql
CREATE DATABASE phlex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'phlex'@'localhost' IDENTIFIED BY 'your_strong_password';
GRANT ALL PRIVILEGES ON phlex.* TO 'phlex'@'localhost';
FLUSH PRIVILEGES;
```

#### 2h. Run migrations
```cmd
php scripts/run-migrations.php
```

#### 2i. Start the server
```cmd
php public\index.php
```
Or start Apache first via XAMPP Control Panel, then run:
```cmd
php public\index.php
```

#### 2j. Firewall configuration
```powershell
New-NetFirewallRule -DisplayName "Phlex HTTP" -Direction Inbound -Protocol TCP -LocalPort 32400 -Action Allow
```
Or via Windows Defender Firewall UI: Inbound Rule → New Rule → Port → 32400 → Allow.

### 3. Option 2 — WSL2 + Ubuntu (recommended)

#### 3a. Enable WSL2
Open PowerShell as Administrator:
```powershell
wsl --install --no-distribution
```
Restart the computer when prompted.

#### 3b. Install Ubuntu
```powershell
wsl --install -d Ubuntu-24.04
```
Create a user account when prompted.

#### 3c. Update Ubuntu
```bash
sudo apt update && sudo apt upgrade -y
```

#### 3d. Install PHP 8.3
```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-curl php8.3-gd php8.3-zip php8.3-xml php8.3-mbstring php8.3-bcmath
```

#### 3e. Install MariaDB
```bash
sudo apt install -y mariadb-server
sudo mysql_secure_installation
```

#### 3f. Install FFmpeg
```bash
sudo apt install -y ffmpeg
```

#### 3g. Install Composer
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

#### 3h. Clone phlex-server
```bash
sudo mkdir -p /opt/phlex
sudo chown $USER:$USER /opt/phlex
git clone https://github.com/detain/phlex-server.git /opt/phlex
cd /opt/phlex
```

#### 3i. PHP dependencies
```bash
composer install --no-dev --optimize-autoloader
```

#### 3j. Configure environment
```bash
cp .env.example .env
nano .env
```
Set:
```
APP_URL=http://localhost:32400
DB_HOST=localhost
DB_DATABASE=phlex
DB_USERNAME=phlex
DB_PASSWORD=your_strong_password
```

#### 3k. Database setup (MariaDB)
```bash
sudo mysql -u root -p
```
```sql
CREATE DATABASE phlex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'phlex'@'localhost' IDENTIFIED BY 'your_strong_password';
GRANT ALL PRIVILEGES ON phlex.* TO 'phlex'@'localhost';
FLUSH PRIVILEGES;
```

#### 3l. Run migrations
```bash
php scripts/run-migrations.php
```

#### 3m. Start the server
```bash
php /opt/phlex/public/index.php
```

#### 3n. Firewall configuration (from PowerShell on Windows host)
```powershell
New-NetFirewallRule -DisplayName "Phlex HTTP" -Direction Inbound -Protocol TCP -LocalPort 32400 -Action Allow
```

#### 3o. Access from Windows browser
Open `http://localhost:32400` in your Windows browser.

### 4. Option 3 — IIS reverse-proxy (advanced)

#### 4a. Install URL Rewrite and ARR
- Download and install URL Rewrite 2.1 from https://www.iis.net/downloads/microsoft/url-rewrite
- Enable ARR (Application Request Routing) via IIS Manager → Server Farm

#### 4b. Create a site binding
- IIS Manager → Sites → Add Website
- Site name: `phlex`
- Physical path: `C:\phlex\public`
- Binding: Host: `phlex.local`, Port: `80`

#### 4c. Configure reverse-proxy
In `C:\phlex\public\web.config`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="ProxyToWorkerman" enabled="true" stopProcessing="true">
          <match url="(.*)" />
          <conditions>
            <add input="{CACHE_URL}" pattern="^(https?)://" />
          </conditions>
          <action type="Rewrite" url="http://127.0.0.1:32400/{R:1}" />
        </rule>
      </rules>
    </rewrite>
  </system.webServer>
</configuration>
```

#### 4d. Start Workerman
```cmd
php C:\phlex\public\index.php
```

### 5. Verify the install
Open your browser:
```
http://localhost:32400
```
Expected: phlex-server index page loads (HTTP 200).

### What can go wrong

#### WSL2 not enabled or wrong version
- Symptom: `wsl --install` fails, or `wsl -l -v` shows Ubuntu with version 1
- Fix: Enable Hyper-V and WSL2 via Windows Features, or run `wsl --set-version Ubuntu-24.04 2` to migrate
- Verify: `wsl -l -v` should show Ubuntu-24.04 with version 2

#### Hyper-V conflicts preventing WSL2
- Symptom: WSL2 fails to start, or Ubuntu stuck at "Installing"
- Fix: Ensure Windows is fully updated (21H2 or later); disable conflicting virtualization software (e.g., older VirtualBox)
- Verify: `systeminfo | findstr /C:"Hyper-V"` should show Hyper-V present

#### PHP version mismatch
- Symptom: `composer install` fails with version errors, or runtime errors about missing PHP 8.3 features
- Fix (XAMPP): Download PHP 8.3 version from apachefriends.org; uninstall old version first
- Fix (WSL2): `php -v` should show 8.3; if not, `sudo update-alternatives --config php` or reinstall from ondrej/php PPA
- Verify: `php -v` shows `PHP 8.3.x`

#### Missing Visual C++ Runtime
- Symptom: `php.exe` fails to start with "VCRUNTIME140.dll not found"
- Fix: Download and install Visual C++ Redistributable 2015-2022 from https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist
- Verify: `php -v` runs without error

#### Path separator issues (Windows vs Unix)
- Symptom: "File not found" errors, broken requires/includes
- Fix: Ensure all paths in `.env` use Windows-style separators or that the app uses `DIRECTORY_SEPARATOR` correctly; avoid hardcoded `/` in file paths
- Verify: Cloning on Windows handles line endings; set `git config --global core.autocrlf true`

#### Port 32400 already in use
- Symptom: `bind(): Address already in use`
- Fix: `netstat -ano | findstr :32400` to find conflicting process; stop it or change phlex port via `APP_URL` env var

#### XAMPP Apache won't start (port 80/443 conflict)
- Symptom: Apache shows "Error: Apache shutdown unexpectedly"
- Fix: Check Skype, IIS, or another web server on ports 80/443; change XAMPP Apache ports in `C:\xampp\apache\conf\httpd.conf` (listen 8080, 4433) and update virtual host config

### Next steps
- [First-run wizard](docs/first-run.md) — complete the browser-based setup at `http://your-server:32400`
- [Docker install](docs/install/docker.md) — alternative install method using containers
- [Hardware transcoding](docs/hardware-transcoding.md) — configure NVENC/VAAPI for better performance (WSL2+Ubuntu only)
- [Linux install](docs/install/linux.md) — for mixed Windows/Linux environments
