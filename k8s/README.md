# Phlix on Kubernetes

This directory contains a Helm chart for deploying Phlix Media Server on Kubernetes.

## Prerequisites

- Kubernetes 1.19+
- Helm 3+
- An ingress controller (nginx-ingress recommended)
- cert-manager for TLS certificates (optional)

## Installing the chart

The chart is **not published to a Helm repository** — `https://charts.phlix.media`
does not exist (yet). It ships in two forms only: inside this repository
(`k8s/helm/phlix/`), and as a packaged `.tgz` attached to each GitHub release
("Phlix Server <tag>" under [detain/phlix-server/releases](https://github.com/detain/phlix-server/releases);
see `RELEASE_PROCESS.md` — nothing pushes a chart to any chart repository).

Install from a checkout of this repo, or after downloading the release `.tgz`:

```bash
# From a repo checkout (chart sources in ./k8s/helm/phlix). Credentials go in
# a PRIVATE values file, never in `--set`: argv is shell history and `ps`
# fodder, and both charts refuse known placeholder values at render time
# (templates/_helpers.tpl `rejectKnownPlaceholder` — see "Secret hygiene").
helm install phlix ./k8s/helm/phlix \
  --values ~/phlix-secrets.yaml \
  --set image.tag=<full-sha>-latest \
  --set ingress.enabled=true \
  --set ingress.hosts[0].host=phlix.example.com

# ~/phlix-secrets.yaml — chmod 0600, keep it out of git:
#   config:
#     database_host: your-mysql.example.internal
#     database_password: <real credential>
#     secret_key: <output of: openssl rand -hex 64>

# Or from a release asset:
helm install phlix ./phlix-<version>.tgz --values ~/phlix-secrets.yaml --set image.tag=... (flags as above)
```

`image.tag` is REQUIRED and must be an immutable tag; a mutable `latest`
exists on the registry but drifts with every push, while the `<full-sha>-…`
forms are published per run.

*(`helm repo add phlix https://charts.phlix.media` is a disabled future idea,
not an install path — do not script against it.)*

## Using values

### Basic configuration

```yaml
replicaCount: 1

image:
  repository: ghcr.io/detain/phlix-server
  tag: ""  # REQUIRED — immutable tag, form: ghcr.io/detain/phlix-server:<full-sha>-<latest|intel|nvidia>
  pullPolicy: IfNotPresent

ingress:
  enabled: true
  className: nginx
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
  hosts:
    - host: phlix.example.com
      paths:
        - path: /
          pathType: Prefix
  tls:
    - secretName: phlix-tls
      hosts:
        - phlix.example.com

config:
  database_host: phlix-mysql   # a MySQL YOU operate; the chart deploys none
  database_port: 3306
  database_name: phlix
  database_user: phlix
  database_password: your_secure_password
  secret_key: your_secret_key
  log_level: info

persistence:
  enabled: true
  media:
    enabled: true
    size: 100Gi
    mountPath: /media
    readOnly: true

resources:
  limits:
    cpu: 2000m
    memory: 2Gi
  requests:
    cpu: 500m
    memory: 512Mi
```

### Database

**This chart deploys no database.** `Chart.yaml` declares no `dependencies:`
and `charts/` is empty — the `mysql:` block is an inert hook that only gains
meaning if you vendor a real MySQL subchart into `Chart.yaml` yourself. The
operative knob is `config.database_host`, which is REQUIRED (no default):
`helm install` fails with a clear message rather than booting a pod that
CrashLoops behind the image's `PHLIX_MIGRATIONS_STRICT=1` entrypoint gate.

```yaml
config:
  database_host: external-mysql.example.com   # any MySQL that serves database_name
  database_port: 3306
```

(The historical `mysql.enabled: true` default pointed `DB_HOST` at a
`<release>-mysql` Service that no template could ever create — every
documented default install was a guaranteed CrashLoopBackOff. Fixed
2026-11-03; see the entry in CHANGELOG.md.)

## Configuration options

| Parameter | Description | Default |
|-----------|-------------|---------|
| `replicaCount` | Number of replicas | `1` |
| `image.repository` | Docker image repository | `ghcr.io/detain/phlix-server` |
| `image.tag` | Docker image tag — REQUIRED, set to an immutable tag (`<full-sha>-<latest|intel|nvidia>`); no default | `""` |
| `service.type` | Kubernetes service type | `ClusterIP` |
| `service.http.port` | Service port → container HTTP worker `:8096` | `80` |
| `service.websocket.port` | Service port → container WS worker `:8097` (separate listener; needs an L4 route, not an HTTP ingress path) | `8097` |
| `ingress.enabled` | Enable ingress | `true` |
| `config.database_password` | Database password | Required |
| `config.secret_key` | Application secret | Required |
| `persistence.enabled` | Enable persistence | `true` |

## Uninstalling

```bash
helm uninstall phlix
```

## Port map (what actually listens)

| Chart | Container listens | Service default | Notes |
|-------|-------------------|-----------------|-------|
| `phlix` | `:8096` HTTP API (serves `/health`) | `service.http.port: 80` | Ingress + probes route here |
| `phlix` | `:8097` WebSocket (SyncPlay) | `service.websocket.port: 8097` | Separate WS listener — needs an L4 route (LoadBalancer/NodePort/TCP mesh), HTTP ingress path rules cannot reach it |
| `phlix-hub` | `:8800` hub HTTP (serves `/health`, `/api`, admin UI) | `service.http.port: 80` | Ingress + probes route here |
| `phlix-hub` | `:8802` relay WebSocket worker | `service.relay.port: 8802` | Separate WS listener for server relay connections (`hub_wss_url`) — needs an L4 route, not the HTTP ingress |

## Verifying a render

```bash
helm template phlix k8s/helm/phlix --set image.tag=test | grep -B1 -A2 containerPort
# expect: 8096 (http), 8097 (websocket)

helm template hub k8s/helm/phlix-hub --set image.tag=test | grep -B1 -A2 containerPort
# expect: 8800 (http), 8802 (relay)
```

## Hub chart

The `phlix-hub` chart (in this repo under `k8s/helm/phlix-hub`) deploys the
central hub. Same pattern as above, with the port map rows for `phlix-hub`,
and the same credential discipline: its `config.database_password` /
`config.secret_key` ride the chart's `rejectKnownPlaceholder` guard, so copy-
pasted example values fail at render (SH-027).

```bash
helm install hub k8s/helm/phlix-hub \
  --values ~/phlix-hub-secrets.yaml \
  --set image.tag=<sha12> \
  -n phlix-hub --create-namespace

# ~/phlix-hub-secrets.yaml — chmod 0600, keep it out of git:
#   config:
#     database_host: your-mysql
#     database_password: <real credential>
#     secret_key: <output of: openssl rand -hex 64>
```

## Secret hygiene

Both charts refuse to render when a secret-position value matches a known
placeholder shape (`change_me`, `CHANGE_ME`, `replace_me`, `secret`,
`password`, `test`, `demo`, `dev`, the literal command string
`openssl rand -hex 64`, …) — the guard is
`templates/_helpers.tpl::rejectKnownPlaceholder`, applied in each chart's
`secret.yaml`. `values.example.yaml` deliberately keeps those bait strings so
a copy-paste install hits a loud render failure instead of booting with a
publicly-known JWT signing key. `required()` alone is NOT the safeguard: it
only proves non-emptiness. Pass real credentials through a `--values` file
with mode 0600, never through `--set` on the command line.

## Database migration

Database migrations run automatically on **every** container start: the
`docker-entrypoint.sh` script invokes `scripts/run-migrations.php`, which keeps
the `schema_migrations` ledger and applies only files it has not recorded yet —
so a new release's new migrations land on the first boot of the new image, and
later boots are ledger no-ops. Because the replay happens at boot, take a
database backup BEFORE upgrading an instance
(`php bin/phlix backup:create --label=pre-vX.Y.Z`); the backup/restore and
rollback semantics are documented in [`RELEASE_PROCESS.md`](../RELEASE_PROCESS.md)
("Restore from a backup", including what a restore does and does not re-import).
