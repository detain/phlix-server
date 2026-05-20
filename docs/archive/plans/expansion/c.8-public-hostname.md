# Step C.8 — Public hostname claim (`*.phlex.media`)

**Phase:** C (Server↔Hub Pairing & Remote Access)
**Step:** C.8
**Depends on:** C.7
**Review:** Yes — see `c.8-public-hostname-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Give each server a permanent public hostname under the hub's domain
(e.g., `abc123.phlex.media`) so clients can reach it without knowing
the server's IP address or configuring DNS.

Concretely:

1. Hub issues each server a unique subdomain via DNS TXT/AAAA record
   management
2. Hub provides a TLS certificate for the subdomain (Let's Encrypt
   or hub-managed CA)
3. Server includes its subdomain in `hostname_candidates` in heartbeats
4. Relay tunnel (C.6) uses the subdomain as the relay URL

## 2. Context (what already exists)

- After C.6: relay tunnel established; server has a `relay_url`
- After C.7: server knows its `hostname_candidates` including public IP
- After C.3: hub has `servers` table with `hostname_candidates`
- After B.7: hub portal scaffolded
- `PHLEX_EXPANSION_PLAN.md` §1 — current-state: "Public hostname claim"
  is **Missing**
- `docs/dev/pairing-protocol.md` §6 — relay section mentions
  `*.phlex.media`

## 3. Scope — files to create / modify

### Server-side files (`/home/sites/phlex/`)

#### Hub-Side Subdomain Allocator

All paths inside `/home/sites/phlex-hub/`.

- `src/Hub/DnsAliasManager.php` — manages DNS aliases for servers:

  ```php
  final class DnsAliasManager
  {
      public const DOMAIN = 'phlex.media';

      public function __construct(
          Connection $db,
          LoggerInterface $logger,
          string $dnsProvider,   // 'cloudflare' | 'route53' | 'static'
      ) { }

      public function allocateSubdomain(string $serverId): string { }
          // Generates a unique subdomain (8-char alphanumeric),
          // stores it in DB, and creates the DNS record.

      public function getSubdomain(string $serverId): ?string { }
          // Returns the allocated subdomain or null.

      public function revokeSubdomain(string $serverId): void { }
          // Removes the DNS record and clears the DB field.

      public function refreshCertificate(string $serverId): bool { }
          // Triggers Let's Encrypt certificate renewal.
  }
  ```

  For v1, the hub uses a **static file-based DNS manager** (writes
  zone files to `data/dns/zones/`). The `dnsProvider` interface is
  pluggable so Cloudflare/Route53 can be added later.

- `src/Hub/Dns/StaticZoneManager.php` — static zone file writer:

  ```php
  final class StaticZoneManager
  {
      public function __construct(string $zoneDir) { }

      public function addRecord(string $zone, string $name, string $type, string $value): void { }
      public function removeRecord(string $zone, string $name, string $type): void { }
      public function updateSoa(string $zone): void { }
  }
  ```

- `src/Hub/TlsCertificateManager.php` — manages TLS certificates:

  ```php
  final class TlsCertificateManager
  {
      public function __construct(
          string $certsDir,
          string $acmeEmail,
          LoggerInterface $logger,
      ) { }

      public function provisionCertificate(string $subdomain): bool { }
          // Uses ACME (Let's Encrypt) to provision a certificate.
          // Stores in $certsDir/{subdomain}/.

      public function getCertificatePath(string $subdomain): ?string { }
          // Returns the fullchain.pem path or null if not provisioned.

      public function getPrivateKeyPath(string $subdomain): ?string { }
          // Returns the privkey.pem path or null.
  }
  ```

  Uses ` dehydrated` or raw ACME v2 via `openssl_csr` + ` letsencrypt`
  standalone auth. Certificate is provisioned once and renewed at T+60
  days automatically.

#### Hub API Endpoint

- `src/Server/Http/Controllers/DomainController.php`:

  ```
  POST /api/v1/servers/{id}/subdomain
  Authorization: Bearer <enrollment_jwt>
  Response: { "subdomain": "abc12345", "fqdn": "abc12345.phlex.media", "tls_cert_path": "...", "tls_key_path": "..." }
  ```

  If a subdomain already exists, returns the existing one (no duplicate
  allocation).

  ```
  DELETE /api/v1/servers/{id}/subdomain
  Authorization: Bearer <enrollment_jwt>
  Response: 204 No Content
  ```

#### Server-Side Subdomain Integration

All paths inside `/home/sites/phlex/`.

- `src/Hub/SubdomainClient.php` — server-side client to claim a
  subdomain from the hub:

  ```php
  final class SubdomainClient
  {
      public function __construct(
          HubClient $hubClient,
          string $serverId,
          LoggerInterface $logger,
      ) { }

      public function claimSubdomain(): ?SubdomainResult { }
          // POST /api/v1/servers/{id}/subdomain
          // Returns subdomain info or null on failure.

      public function releaseSubdomain(): bool { }
          // DELETE /api/v1/servers/{id}/subdomain

      public function getCurrentSubdomain(): ?string { }
          // Reads from config/hub-subdomain.json
  }
  ```

- `src/Hub/SubdomainResult.php` — DTO:

  ```php
  final class SubdomainResult
  {
      public function __construct(
          public readonly string $subdomain,
          public readonly string $fqdn,
          public readonly string $tlsCertPath,
          public readonly string $tlsKeyPath,
      ) { }
  }
  ```

#### Hub-Side Relay URL Update

All paths inside `/home/sites/phlex-hub/`.

- When a subdomain is allocated, the hub's relay system (C.6) serves
  requests for `https://{subdomain}.phlex.media/*` and proxies them over
  the server's relay WebSocket tunnel.

- `src/Hub/RelayRouter.php` — routes inbound requests to the correct
  relay session based on subdomain:

  ```php
  final class RelayRouter
  {
      public function routeBySubdomain(string $host): ?string { }
          // Returns serverId for the relay session, or null.
  }
  ```

  The hub's HTTP server uses the `Host` header to dispatch to the
  correct relay session via `RelayRouter::routeBySubdomain()`.

#### CLI Script (Server Side)

- `scripts/claim-subdomain.php`:

  ```
  php scripts/claim-subdomain.php

  # Output:
  # Allocated subdomain: abc12345.phlex.media
  # Certificate: /home/phlex/config/tls/abc12345.phlex.media.crt
  # Key: /home/phlex/config/tls/abc12345.phlex.media.key
  ```

#### Unit Tests

**Hub-side tests** (`/home/sites/phlex-hub/`):
- `tests/Unit/Hub/DnsAliasManagerTest.php`
- `tests/Unit/Hub/TlsCertificateManagerTest.php`
- `tests/Unit/Hub/RelayRouterTest.php`

**Server-side tests** (`/home/sites/phlex/`):
- `tests/Unit/Hub/SubdomainClientTest.php`

#### Documentation

- `docs/hub-admin/subdomain-allocation.md` — hub admin guide for DNS
  setup and subdomain management
- `docs/dev/pairing-protocol.md` — update §13 with C.8 completion

### Modify

**Hub-side (`/home/sites/phlex-hub/`):**

- `src/Server/Http/Router.php` — add subdomain endpoints
- `src/Common/Container/Providers/HubServicesProvider.php` — register
  new classes
- `config/hub.php` — add `subdomain_provider` (default `static`),
  `acme_email` setting for Let's Encrypt
- `composer.json` — no new deps (static file + openssl ACME)
- `CHANGELOG.md` entry

**Server-side (`/home/sites/phlex/`):**
- `src/Hub/HubClient.php` — call `SubdomainClient::claimSubdomain()` on
  enrollment if not already claimed
- `config/hub.php` — add `subdomain_auto_claim` (default true)
- `CHANGELOG.md` entry

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on both repos.
2. **Branch:** `git checkout -b c.8-public-hostname` on both repos.
3. **Hub side:** Write `StaticZoneManager`, `DnsAliasManager`,
   `TlsCertificateManager`, `SubdomainResult`, `DomainController`,
   `RelayRouter`.
4. **Server side:** Write `SubdomainClient`, `SubdomainResult`,
   update `HubClient`.
5. **Wire routes** in hub's Router.
6. **Write tests** on both sides.
7. **Verification bar.**
8. **Doc updates.**
9. **Commit + PR + merge** (both repos in sequence; hub first, then server).

## 5. Tests (REQUIRED — §0.4 minimum bar)

**Hub-side tests:**
1. `DnsAliasManagerTest::test_allocateSubdomain_generates_unique_subdomain`
2. `DnsAliasManagerTest::test_allocateSubdomain_stores_in_db`
3. `DnsAliasManagerTest::test_getSubdomain_returns_allocated`
4. `DnsAliasManagerTest::test_getSubdomain_returns_null_when_not_allocated`
5. `DnsAliasManagerTest::test_revokeSubdomain_removes_dns_record`
6. `TlsCertificateManagerTest::test_provisionCertificate_stores_files`
7. `TlsCertificateManagerTest::test_getCertificatePath_returns_path`
8. `RelayRouterTest::test_routeBySubdomain_returns_server_id`

**Server-side tests:**
9. `SubdomainClientTest::test_claimSubdomain_success`
10. `SubdomainClientTest::test_claimSubdomain_failure_returns_null`
11. `SubdomainClientTest::test_releaseSubdomain`

**Coverage target:** `src/Hub/` on hub ≥ 85 %, new server classes ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Hub admin functionality** → `docs/hub-admin/subdomain-allocation.md` (new)
- **User-visible behavior change** → CHANGELOG entry

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `DnsAliasManager::allocateSubdomain()` creates a DNS record and
      stores the subdomain in the DB
- [ ] `TlsCertificateManager::provisionCertificate()` obtains a TLS
      certificate for the subdomain
- [ ] `POST /api/v1/servers/{id}/subdomain` returns the allocated
      subdomain, FQDN, and TLS paths
- [ ] `DELETE /api/v1/servers/{id}/subdomain` revokes the subdomain
- [ ] `SubdomainClient::claimSubdomain()` on the server side claims a
      subdomain via the hub API
- [ ] Server stores subdomain and TLS paths in `config/hub-subdomain.json`
- [ ] Hub's `RelayRouter` routes requests by `Host` header to the
      correct relay session
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests
- [ ] Coverage of new classes ≥ 85 %
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean
- [ ] `docs/hub-admin/subdomain-allocation.md` created
- [ ] CHANGELOG entry added on both repos
- [ ] Git ritual §8 executed on both repos; postcondition checks PASS

## 8. Git ritual

This step touches **both repos**. Execute hub side first, then server side:

### Hub-side ritual

```bash
cd /home/sites/phlex-hub
git status --short && git branch --show-current && git pull --ff-only origin master
git checkout -b c.8-public-hostname
# ... write hub-side files ...
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
git add -A
git commit -m "Step C.8 (hub): subdomain allocation, TLS certs, RelayRouter"
unset GITHUB_TOKEN
gh pr create --title "Step C.8 (hub): subdomain allocation and TLS certs" \
  --body "Implements DnsAliasManager, TlsCertificateManager, DomainController, RelayRouter for *.phlex.media subdomain allocation. Part of Phase C (Step C.8 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
git status --short && git branch --show-current && git branch --list 'c.8-*'
```

### Server-side ritual

```bash
cd /home/sites/phlex
git status --short && git branch --show-current && git pull --ff-only origin master
git checkout -b c.8-public-hostname
# ... write server-side files ...
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
git add -A
git commit -m "Step C.8 (server): SubdomainClient for *.phlex.media"
unset GITHUB_TOKEN
gh pr create --title "Step C.8 (server): SubdomainClient for subdomain claim" \
  --body "Implements SubdomainClient for claiming a *.phlex.media subdomain from the hub. Part of Phase C (Step C.8 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
git status --short && git branch --show-current && git branch --list 'c.8-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `c.8-public-hostname-review.md`.

Non-obvious point: The hub's `TlsCertificateManager` uses **Let's
Encrypt ACME v2** in standalone mode (challenges are answered by the
hub's HTTP server on port 80). This requires port 80 to be accessible
from the internet for certificate issuance. Document this requirement in
`docs/hub-admin/subdomain-allocation.md`.
