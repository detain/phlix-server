# Phlix on Kubernetes

This directory contains a Helm chart for deploying Phlix Media Server on Kubernetes.

## Prerequisites

- Kubernetes 1.19+
- Helm 3+
- An ingress controller (nginx-ingress recommended)
- cert-manager for TLS certificates (optional)

## Installing the Chart

The chart is **not published to a Helm repository** — `https://charts.phlix.media`
does not exist (yet). It ships in two forms only: inside this repository
(`k8s/helm/phlix/`), and as a packaged `.tgz` attached to each GitHub release
("Phlix Server <tag>" under [detain/phlix-server/releases](https://github.com/detain/phlix-server/releases);
see `RELEASE_PROCESS.md` — nothing pushes a chart to any chart repository).

Install from a checkout of this repo, or after downloading the release `.tgz`:

```bash
# From a repo checkout (chart sources in ./k8s/helm/phlix):
helm install phlix ./k8s/helm/phlix \
  --set image.tag=<full-sha>-latest \
  --set config.database_password=your_password \
  --set config.secret_key=your_secret \
  --set ingress.enabled=true \
  --set ingress.hosts[0].host=phlix.example.com

# Or from a release asset:
helm install phlix ./phlix-<version>.tgz --set image.tag=... (flags as above)
```

`image.tag` is REQUIRED and must be an immutable tag; a mutable `latest`
exists on the registry but drifts with every push, while the `<full-sha>-…`
forms are published per run.

*(`helm repo add phlix https://charts.phlix.media` is a disabled future idea,
not an install path — do not script against it.)*

## Using Values

### Basic Configuration

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
  database_host: phlix-mysql
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

### Using MySQL

The chart can include a MySQL subchart:

```yaml
mysql:
  enabled: true
  auth:
    database: phlix
    username: phlix
    password: mysql_password
  primary:
    persistence:
      size: 10Gi
```

Or use an external MySQL:

```yaml
mysql:
  enabled: false

config:
  database_host: external-mysql.example.com
  database_port: 3306
```

## Configuration Options

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
central hub. Same pattern as above, with the port map rows for `phlix-hub`
and its own required `config.database_password` / `config.secret_key` values.

```bash
helm install hub k8s/helm/phlix-hub \
  --set image.tag=<sha12> \
  --set config.database_password=your_password \
  --set config.secret_key=your_secret \
  --set config.database_host=your-mysql \
  -n phlix-hub --create-namespace
```

## Database Migration

Database migrations run automatically on **every** container start: the
`docker-entrypoint.sh` script invokes `scripts/run-migrations.php`, which keeps
the `schema_migrations` ledger and applies only files it has not recorded yet —
so a new release's new migrations land on the first boot of the new image, and
later boots are ledger no-ops. Because the replay happens at boot, take a
database backup BEFORE upgrading an instance
(`php bin/phlix backup:create --label=pre-vX.Y.Z`); the backup/restore and
rollback semantics are documented in [`RELEASE_PROCESS.md`](../RELEASE_PROCESS.md)
("Restore from a backup", including what a restore does and does not re-import).
