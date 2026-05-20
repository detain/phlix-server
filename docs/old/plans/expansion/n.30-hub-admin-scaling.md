# Step N.30 — Hub-Admin Scaling Guide

**Phase:** N (End-User Documentation)
**Step:** N.30
**Depends on:** L.6 (backup/restore — already merged)
**Review:** No (doc-only step)
**Target repo:** phlex-hub (local: /home/sites/phlex-hub/)

## 1. Goal

Write the hub-admin scaling guide at `docs/hub-admin/scaling.md`, using the §7 one-screen layout (TL;DR → shell blocks → what-can-go-wrong (3 failures) → next-steps). Covers multi-region deployment, database backups with point-in-time recovery, restore drills, failover playbook, and Docker-compose HA template.

## 2. Context

- No hub-admin scaling guide currently exists in `docs/hub-admin/`
- Branch `n.30-hub-admin-scaling` will be cut from `master`
- This is a doc-only step — no feature implementation changes
- The §7 docs tree layout specifies the `docs/hub-admin/scaling.md` page
- Reference format: `n.28-hub-admin-capacity.md` and `n.29-hub-admin-abuse.md` use the same §7 layout with what-can-go-wrong sections
- L.6 (backup/restore) is the implementation foundation; this doc annotates it for hub operators
- C.6 (relay tunnel) established the hub's stateless architecture (JWT validation, session in DB, relay state in Redis)

## 3. Scope

### New file

- `docs/hub-admin/scaling.md` — Hub-admin scaling, backups, and DR guide

## 4. Content outline

### TL;DR

One paragraph: Hub is stateless by design — JWT validation, session state, and relay state are stored in MariaDB and Redis — so horizontal scaling is straightforward: add hub instances behind a load balancer, point them all at the same DB and Redis, and enable sticky sessions. Database backups use `mysqldump` with binlog for point-in-time recovery; offsite copies go to S3/R2 with 30-day retention. Restore drills should be run quarterly to catch corrupt or incomplete backups before a real disaster. RTO target is under 15 minutes with automated failover; RPO is under 4 hours.

End with a table of the key tolerances:

| Metric | Target |
|---|---|
| RTO | < 15 minutes |
| RPO | < 4 hours |
| Hub instances | 2+ recommended |
| DB replication | MariaDB Galera (multi-master) or single primary + read replica |
| Redis relay state | Shared across all hub instances |
| Backup retention | 30 days offsite |

---

### Multi-region / Horizontal Scaling

#### Architecture overview

- Hub is stateless: all state lives in MariaDB (users, server registry, grants, audit logs) and Redis (relay session state)
- Multiple hub instances share the same DB + Redis behind a load balancer
- Each server maintains one persistent WSS connection; clients are routed to the same hub instance via sticky sessions (source IP hash)
- Docker Swarm or Kubernetes for orchestration; `docker-compose up --scale phlex-hub=2` for simple HA

#### Deployment topology

```bash
# Minimal HA stack (docker-compose)
# 2 hub instances + nginx load balancer + MariaDB primary + 1 read replica + Redis
services:
  phlex-hub:
    image: phlex/hub:latest
    deploy:
      replicas: 2
    environment:
      HUB_DB_HOST: hub-db
      HUB_DB_USER: phlex_hub
      HUB_DB_NAME: hub_db
      HUB_REDIS_HOST: hub-redis
    depends_on:
      - hub-db
      - hub-redis

  nginx:
    image: nginx:latest
    ports:
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
    depends_on:
      - phlex-hub

  hub-db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: hub_db
      MYSQL_USER: phlex_hub
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - hub-db-data:/var/lib/mysql

  hub-redis:
    image: redis:7-alpine
    volumes:
      - hub-redis-data:/data

  hub-db-replica:
    image: mariadb:10.11
    command: --read-only
    depends_on:
      - hub-db
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: hub_db
      MYSQL_USER: phlex_hub
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_MASTER_HOST: hub-db
```

#### Sticky sessions (relay affinity)

```bash
# nginx upstream with ip_hash for sticky sessions
# Each server's WSS connection lands on the same hub instance
upstream phlex_hub_backend {
    ip_hash;
    server phlex-hub-1:8443;
    server phlex-hub-2:8443;
}
```

- Without sticky sessions, a server's WSS connection would be routed to different hub instances, breaking relay state
- For Kubernetes: use an Ingress with `sessionAffinity: ClientIP`
- For Docker Swarm: use `docker-compose up --scale phlex-hub=2` with a compatible reverse proxy

---

### Database Backups

#### Full mysqldump

```bash
# Daily full backup
mysqldump -h hub-db -u phlex_hub -p hub_db > hub-backup-$(date +%Y%m%d).sql

# Verify the dump is valid before archiving
grep -c "INSERT INTO" hub-backup-$(date +%Y%m%d).sql
# Expected: table count > 0
```

#### Point-in-time recovery (binlog)

```bash
# Ensure binlog is enabled on the primary
# Check in my.cnf or via:
SHOW VARIABLES LIKE 'log_bin';

# If binlog is enabled, replay to a specific time:
mysqlbinlog --stop-datetime="2025-06-01 12:00:00" /var/lib/mysql/mysql-bin.* | \
  mysql -h hub-db -u phlex_hub -p hub_db
```

#### Incremental backup (every 4 hours)

```bash
# Copy binlog files since last full backup
cp /var/lib/mysql/mysql-bin.{n} /backup/binlog-incremental-$(date +%Y%m%d%H%M)/
# Or use mysqlbinlog to dump the active binlog since last backup:
mysqlbinlog --read-from-remote-server \
  --host=hub-db \
  --user=phlex_hub \
  --password \
  --stop-never \
  mysql-bin.000123 > hub-incremental-$(date +%Y%m%d%H%M).sql
```

#### Off-site copy

```bash
# Copy daily full backup to S3/R2 with 30-day retention
aws s3 cp hub-backup-$(date +%Y%m%d).sql s3://your-hub-backups/hub/
# Or with rclone:
rclone copy hub-backup-$(date +%Y%m%d).sql remote:hub-backups/hub/ \
  --excludes "*.tmp"

# Retention: 30 days
rclone delete remote:hub-backups/hub/ --min-age 30d
```

---

### Restore Drill

Run this quarterly. Do NOT wait for a real disaster to discover your backups are corrupt.

#### Step 1 — Stop hub instances

```bash
# Stop all hub instances (avoid writes during restore)
docker-compose stop phlex-hub
# Or for Kubernetes:
kubectl scale deployment phlex-hub --replicas=0
```

#### Step 2 — Restore the database

```bash
# Restore from latest full backup
mysql -h hub-db -u phlex_hub -p hub_db < hub-backup-20250601.sql

# If point-in-time recovery is needed, replay binlog:
mysqlbinlog --stop-datetime="2025-06-01 12:00:00" \
  /var/lib/mysql/mysql-bin.* | \
  mysql -h hub-db -u phlex_hub -p hub_db
```

#### Step 3 — Restart hub instances

```bash
# Verify DB is accessible and schema is current
docker-compose up -d phlex-hub
# Or for Kubernetes:
kubectl scale deployment phlex-hub --replicas=2
```

#### Step 4 — Verify

```bash
# Verify users can log in
curl -X POST https://your-hub.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@yourhub.com","password":"testpassword"}'

# Verify enrolled servers show correct claim status
php bin/hub.php server:list

# Verify relay sessions resume (servers reconnect automatically after hub restart)
php bin/hub.php relay:status
```

---

### Failover Playbook

#### Hub instance down

- **Detection:** Load balancer health check fails (`/health` endpoint returns non-200)
- **Response:** Load balancer automatically routes traffic to healthy instance(s)
- **Recovery:** Restart crashed container/pod; no data loss (stateless)
- **RTO:** < 1 minute

#### Database down

- **Detection:** Hub instances report DB connection errors in logs
- **Response:** If using Galera multi-master, remaining nodes continue serving
- **If single primary + read replica:** promote read replica to primary
  ```bash
  # On the read replica, stop writes and promote:
  STOP SLAVE;
  RESET SLAVE ALL;
  # Update hub connection strings to point at new primary
  ```
- **RTO:** < 15 minutes (automated failover preferred)
- **RPO:** < 4 hours (last full backup + binlog replay)

#### Redis down

- **Symptom:** Relay sessions drop; clients must reconnect after timeout (~30s)
- **Recovery:** Redis restarts; server WSS connections re-establish automatically
- **Important:** Relay session state is lost on Redis crash — clients reconnect and resume stream position from their own last reported position (server-side), not from hub state
- **RTO:** < 5 minutes if Redis is restarted; longer if data directory recovery is needed
- **RPO:** N/A for Redis (no persistent relay state needed for playback resumption)

#### Summary RTO/RPO

| Failure | RTO | RPO |
|---|---|---|
| Hub instance crash | < 1 minute (LB routes to other instance) | N/A (stateless) |
| DB primary crash | < 15 minutes (automated failover or manual promote) | < 4 hours |
| Redis crash | < 5 minutes (restart; clients reconnect) | N/A |
| Full site failure | < 15 minutes (restore from backup in new region) | < 4 hours |

---

### What Can Go Wrong

**Galera cluster split-brain (network partition)**

- Symptom: Two sets of Galera nodes accept writes independently; data diverges; corruption on reconnect
- Cause: Network partition without proper quorum configuration; `pc.recovery` not enabled
- Fix: Configure proper quorum: set `pc.wait_prim=true` and `pc.ignore_splits=true`; ensure at least 3 nodes in cluster; enable `pc.recovery=true` so cluster recovers state on restart; always validate with a network-partition test drill
- Prevention: Minimum 3 nodes, odd node count, proper network isolation testing in staging

**Redis relay state lost on crash (sessions drop)**

- Symptom: All active relay sessions disappear; users see "connection lost" and must reconnect
- Cause: Redis was running without persistence (`appendonly no`) or Redis crashed before last `AOF` write
- Fix: Enable AOF persistence (`appendonly yes` and `appendfsync everysec`); after Redis restarts, servers reconnect automatically and resume from last reported stream position (server-side); relay state is not critical for playback resumption
- Prevention: Verify AOF is on; test Redis crash recovery in staging quarterly

**Backup not tested (restore fails when needed)**

- Symptom: Full disaster strikes; backup file is corrupt, incomplete, or from wrong point in time
- Cause: Backups run on schedule but are never validated; disk Full at backup destination causes truncated files
- Fix: Run a full restore drill quarterly on an isolated environment; verify checksum (`sha256sum`) of every backup immediately after creation; store checksum alongside backup
- Prevention: Automate restore drill notification; alert if backup size drops below expected minimum

**binlog not enabled (point-in-time recovery impossible)**

- Symptom: DB crashes; full backup is 3 days old; 3 days of new users, server enrollments, and grants are lost
- Cause: `log_bin` was not enabled in `my.cnf`; PITR is not possible without it
- Fix: Enable binlog before disaster: `log_bin=mysql-bin` in my.cnf and restart; for existing data, take a fresh full backup immediately after enabling; document this in the operations runbook
- Prevention: Verify binlog is enabled in all DB nodes during setup; check with `SHOW VARIABLES LIKE 'log_bin';` in initial deployment checklist

**Load balancer health check misconfigured (routes to unhealthy instance)**

- Symptom: Users see 502s or connection timeouts; hub logs show requests from unhealthy instance
- Cause: Health check interval too long (e.g., 60s), or endpoint returns 200 when instance is actually degraded (relay sessions exhausted, DB connection pool depleted)
- Fix: Use a meaningful `/health` endpoint that checks DB, Redis, and relay session capacity; set `intervall_ms: 5000` and `timeout: 3s`; mark instance unhealthy if any check fails; test health check manually before deploying
- Prevention: Canary deploy new hub versions with brief health check window; monitor both healthy and unhealthy state transitions in alerting

---

### Next Steps

- [Hub-admin capacity planning](docs/hub-admin/capacity-planning.md) — sizing hub hardware for your user base
- [Hub claim and setup](docs/hub-claim.md) — understanding server claiming and hub identity
- [Hub relay tunnel](docs/hub-admin/relay-tunnel.md) — how the WSS relay actually works under the hood
- [Hub-admin overview](docs/hub-admin/overview.md) — hub dashboard and admin CLI reference

## 5. Git Ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-hub
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b n.30-hub-admin-scaling

# ─── 2. Do the work ───
# Create docs/hub-admin/scaling.md following the §7 one-screen layout
# TL;DR → multi-region/horizontal scaling → database backups (mysqldump + binlog)
# → restore drill (4 steps) → failover playbook (RTO/RPO table)
# → what-can-go-wrong (5 failures) → next-steps

# ─── 3. Verify ───
# Verify file has TL;DR with table, multi-region section with docker-compose
# YAML block, backup section with mysqldump + binlog shell blocks, restore drill
# with 4 numbered steps, failover playbook with RTO/RPO table,
# what-can-go-wrong (5 failures), and next-steps links

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step N.30: Hub-admin scaling guide"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step N.30: Hub-admin scaling guide" \
  --body  "Adds docs/hub-admin/scaling.md following the §7 one-screen layout (TL;DR, multi-region/horizontal scaling, database backups with PITR, restore drill, failover playbook with RTO/RPO, what-can-go-wrong, next-steps). Covers stateless hub architecture, Docker-compose HA template with sticky sessions, mysqldump + binlog backup strategy, quarterly restore drill, Galera split-brain, Redis AOF persistence, and load balancer health check configuration. Part of Phase N (Step N.30 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch -d n.30-hub-admin-scaling
```
