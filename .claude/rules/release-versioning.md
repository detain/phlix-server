---
paths:
  - "src/Common/Version.php"
  - "VERSION"
  - "scripts/release.sh"
  - "k8s/helm/phlix/Chart.yaml"
  - "composer.json"
  - "RELEASE_PROCESS.md"
  - "start.php"
  - "docker/**"
  - "scripts/docker-boot-smoke.sh"
  - "scripts/required-php-extensions.php"
---

# Release, Versioning & the Daemon Entry Point

- **`src/Common/Version.php::STRING` is the ONLY version source.** `VERSION` (the published update marker) and `k8s/helm/phlix/Chart.yaml` (`version` + `appVersion`) are MIRRORS — never hand-edit them. `./scripts/release.sh [patch|minor|major] [--dry-run]` rewrites all three in one commit and tags; `tests/Unit/Server/Updates/VersionSourcesAgreeTest.php` reddens on drift. `Version::STRING` is also what `PluginLoader` compares `phlix_min_server_version` against.
- **`composer.json` carries no `version` field, deliberately.** The `composer-validate` CI job runs `composer validate --strict --no-check-publish`, which exits non-zero when the field is present. Do not add one to "fix" a release script.
- **The daemon is `start.php`, not `public/index.php`.** `php start.php start` is what `systemd/phlix-server.service` and `docker/supervisord.conf` run: HTTP worker `:8096` (`count = 14`), WebSocket `:8097`, plus hub-heartbeat / background-timer / relay-tunnel workers at `count = 1` (a stall there is a 100% outage of that subsystem). `public/index.php` is the one-shot CGI front controller — it ignores argv and returns immediately.
- **`docker build` proves nothing about the runtime path.** It never executes `CMD`/`ENTRYPOINT`/`HEALTHCHECK`, which is how the images shipped for their whole life starting the wrong binary behind green builds. The gate is `./scripts/docker-boot-smoke.sh <dockerfile> <tag>`, run per variant (`docker/Dockerfile`, `.intel`, `.nvidia`) by `.github/workflows/docker.yml`. `tests/Unit/Docker/DockerEntrypointTest.php` asserts Dockerfile TEXT only and cannot see a wrong binary name.
- **PHP extensions are one derived list.** `scripts/required-php-extensions.php` is the source; `composer.json`'s `require` (`ext-*`) and `config.platform` blocks are pinned to it and `tests/Unit/Platform/RequiredPhpExtensionsContractTest.php` fails on drift. Derive from CALL SITES, never from `get_loaded_extensions()`.
