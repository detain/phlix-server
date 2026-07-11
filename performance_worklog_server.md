# Worklog — phlix-server

## Tooling (from Recon)

### Tooling Discovery

- **test:** `./vendor/bin/phpunit`
  - Default (no args) runs all suites (Unit + Integration + E2E, excluding `network` group)
  - Unit suite: `./vendor/bin/phpunit --testsuite Unit`
  - Integration suite: `./vendor/bin/phpunit --testsuite Integration`
  - Specific file: `./vendor/bin/phpunit tests/Unit/Auth/JwtHandlerTest.php --testdox`
  - Coverage output: `coverage.xml` (Clover) + `coverage-report/` (HTML)
  - Bootstrap: `tests/bootstrap.php`; DB credentials via env: `DB_HOST=127.0.0.1`, `DB_DATABASE=phlix_test`, `DB_USER=root`, `DB_PASSWORD=root`
  - Source: `composer.json:L41` (phpunit ^10.0 in require-dev), `phpunit.xml:L3-13` (testsuite definitions), `AGENTS.md`

- **static analysis:** `phpstan analyze -c phpstan.neon.dist`
  - Runs at level 9 (max); analyzes `src/` only
  - Bootstrap file: `vendor/autoload.php` (phpstan.neon.dist:L7-8)
  - Excludes: `src/Server/WebSocket/Events.php` (phpstan.neon.dist:L6)
  - Note: plan references `phpstan -c phpstan.neon.dist` at L9 — same command
  - Source: `phpstan.neon.dist:L2` (level 9), `AGENTS.md` (`./vendor/bin/phpstan analyze src/ --level=9`)

- **lint:** `./vendor/bin/phpcs --standard=PSR12 src/`
  - PSR-12 coding standard; targets `src/` directory
  - Source: `AGENTS.md`, `composer.json:L42` (squizlabs/php_codesniffer ^3.10 in require-dev)

- **build:** N/A for phlix-server
  - Server is PHP; no build step required (pure PHP runtime)

- **migrate:** `php scripts/run-migrations.php`
  - Applies all `.sql` files in `migrations/` directory via `MigrationRunner`
  - Idempotent: catches duplicate-column / duplicate-key errors and downgrades to notes
  - Requires `config/database.php` and `vendor/autoload.php`
  - No migration-tracking table — re-runs every boot per apply-all-every-time contract
  - Source: `scripts/run-migrations.php:L11-26` (MigrationRunner apply loop), `AGENTS.md` (`php scripts/run-migrations.php`)

- **deploy/verify:** `ssh root@153.75.226.242` → `/root/update_server.sh`
  - The script performs: `git pull` + `systemctl reload phlix-server`
  - CLI env requires: `. /etc/phlix/env` (loads environment variables)
  - Source: `performance_plan.md:§0.3:L164` ("Deploy: ssh root@153.75.226.242 → /root/update_server.sh"), `performance_plan.md:§H:L136` (recon requirement)

### Environment

- **PHP version:** 8.3.6 (cli) — minimum required: `php >= 8.3` (composer.json:L13)
  - Extensions: ext-ldap required, ext-swoole implied by workerman
  - OPcache enabled (Zend OPcache v8.3.6)

- **Bootstrap gotchas:**
  - **Dual entrypoints** (§0.3): Any constructor/DI/bootstrap change must be mirrored in BOTH:
    - `public/index.php` — CI/FPM path; used in testing and by the web server
    - `start.php` — Swoole resident path; used in production with `php start.php start`
    - `start.php` is outside CI — changes to it must be verified by hand on the box
  - **Coroutine/Swoole hooks:** `eventLoopClass` set in master only; workers re-assert the curated hook mask in `onWorkerStart`; deliberately excludes `SWOOLE_HOOK_NATIVE_CURL` and `exec` — respect it
  - **Migration runner:** runs every boot (apply-all-every-time); idempotent via `IF NOT EXISTS` and error-substring allowlist

## Progress
- [x] SV-0.1  wire hardware acceleration + tone-mapping config ✅ (commits 5d3a3cdf, 809b34b3)
- [x] SV-0.2  reconcile hwaccel config ✅ (commit 85aec93c)
- [x] SV-0.3  isWorkermanContext fix ✅ (commit e48e4aba)
- [x] SV-0.4  replace usleep spin-wait with Channel ✅ (commit e48e4aba)
- [x] SV-0.5  fix WS reaper + heartbeat timer guards ✅ (commit b95a9c22)
- [x] SV-0.6  fix TMDB collections UUID-as-int bug ✅ (commit ad6d6d86)
- [x] SV-0.7  supervise marker/intro-detection worker ✅ (commit 46c71440)
- [ ] SV-0.8  fix path_hash reads + stop re-probing
- [ ] SV-0.9  fix generateThumbnailBatch timestamp escaping
- [ ] SV-1.1  memoize/precompute HDR tone-map decision
- [ ] SV-1.2  make non-probe ffmpeg calls coroutine-friendly
- [ ] SV-1.3  move chapter-thumbnail + trickplay to background job
- [ ] SV-1.4  correct zscale tone-map graph
- [ ] SV-1.5  implement real libplacebo tone-map mode
- [ ] SV-1.6  fix subtitle burn-in escaping + VAAPI overlay
- [ ] SV-1.7  range parser reuse on direct-play
- [ ] SV-1.8  CSRF Origin exact-match
- [ ] SV-1.9  ENOSPC guard on segment cache
- [ ] SV-1.10 login rate limiter bound
- [ ] SV-2.1  stream file-backed responses over relay tunnel
- [ ] SV-2.2  pool hygiene: rollback dirty connections
- [ ] SV-2.3  relay byte-pipe backpressure
- [ ] SV-2.4  stream large binary via withFile()
- [ ] SV-2.5  image/photo caching validators + security headers
- [ ] SV-2.6  WS routing indexes + broadcast backpressure
- [ ] SV-2.7  per-request auth status cache
- [ ] SV-2.8  list-query projection + materialized filter columns
- [ ] SV-2.9  defer similarity computation to background job
- [ ] SV-3.1  DVR recording data plane
- [ ] SV-3.2  book reader + audiobook player backends
- [ ] SV-3.3  client capability negotiation + loudness normalization
- [ ] SV-3.4  local artwork cache with sized variants
- [ ] SV-3.5  metadata pipeline: concurrency, 429 backoff, bounded cache
- [ ] SV-3.6  build out Trakt history sync
- [ ] SV-4.1  segment-cap reservation before glob()
- [ ] SV-4.2  detached-ffmpeg cancellation + apply transcode_timeout
- [ ] SV-4.3  ComskipRunner non-blocking pipe + reachable timeout
- [ ] SV-4.4  WebhookDispatcher backoff + connect-timeout

## Notes / cross-repo blockers
