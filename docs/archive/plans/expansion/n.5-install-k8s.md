# Step N.5 — Install phlex-server on Kubernetes

**Phase:** N (End-User Documentation)
**Step:** N.5
**Depends on:** O.3 (Helm chart — already merged as part of `o.3-k8s-helm.md` plan)
**Review:** No (doc-only step)
**Target repo:** detain/phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the Kubernetes installation guide at `docs/install/kubernetes.md`, covering Helm chart installation, minimal `values.yaml` configuration, required PVCs, service types, ingress annotations, GPU node scheduling, environment variables, Helm upgrade process, and a "what can go wrong" section with three common failure modes.

## 2. Context (what already exists)

After O.3:

- `k8s/helm/phlex/Chart.yaml` — chart metadata (apiVersion v2, appVersion 1.0.0)
- `k8s/helm/phlex/values.yaml` — full configuration reference (PVCs, ingress, probes, env vars)
- `k8s/helm/phlex/templates/` — Deployment, Service, Ingress, PVC, Secrets, ServiceAccount, HPA templates
- `k8s/helm/phlex/.helmignore`
- `k8s/README.md` — Helm and kustomize deployment overview
- `docs/install/` directory exists (created in N.0; N.1 added linux.md)
- `docs/install/linux.md` — existing Linux install guide (per N.1)
- `PHLEX_EXPANSION_PLAN.md` §7 specifies the `docs/install/kubernetes.md` page

N.5 is the Helm chart end-user companion to the O.3 implementation guide.

## 3. Scope

### Create

- `docs/install/kubernetes.md` — Kubernetes installation guide

### Modify

- `docs/install/README.md` (only if it needs the Kubernetes guide added to the install-methods index)

## 4. Doc content outline

### TL;DR (one screen)

- Short paragraph: what phlex-server is, that this guide deploys it on Kubernetes via Helm in ~10 min
- Minimum requirements: Kubernetes 1.21+, Helm 3.8+, a `default` or named StorageClass, 2 CPU / 4 GB RAM per pod
- Quick-command one-liner for the impatient:
  ```bash
  helm repo add phlex https://charts.phlex.media && helm repo update
  helm install phlex phlex/phlex \
    --set config.database_password=SECRET \
    --set config.secret_key=YOUR_KEY \
    --set ingress.enabled=true \
    --set ingress.hosts[0].host=phlex.example.com
  ```

### 1. Prerequisites

- Table: Component | Minimum version | Notes
- Kubernetes 1.21+, Helm 3.8+
- Ingress controller (nginx-ingress or traefik) — with cert-manager for automated TLS
- Default StorageClass or a named StorageClass
- Optional: NVIDIA GPU nodes with device plugin for hardware transcoding
- Optional: internal MySQL or external database connection details

### 2. Add the Helm repository

```bash
helm repo add phlex https://charts.phlex.media
helm repo update
helm search repo phlex/phlex   # confirm latest chart version
```

### 3. Minimal values.yaml

```yaml
replicaCount: 1

image:
  repository: ghcr.io/detain/phlex-server
  pullPolicy: IfNotPresent
  tag: "latest"   # pin to a specific release tag in production

ingress:
  enabled: true
  className: "nginx"
  annotations:
    cert-manager.io/cluster-issuer: "letsencrypt-prod"
    nginx.ingress.kubernetes.io/proxy-read-timeout: "86400"
    nginx.ingress.kubernetes.io/proxy-send-timeout: "86400"
    nginx.ingress.kubernetes.io/upstream-hdrs: "Upgrade"
    nginx.ingress.kubernetes.io/websocket-services: "phlex-websocket"
  hosts:
    - host: phlex.example.com
      paths:
        - path: /
          pathType: Prefix
  tls:
    - secretName: phlex-tls
      hosts:
        - phlex.example.com

resources:
  requests:
    cpu: 500m
    memory: 512Mi
  limits:
    cpu: 2000m
    memory: 2Gi

persistence:
  media:
    enabled: true
    storageClass: ""        # uses default StorageClass; set to "nfs" or "local-path" if needed
    size: 100Gi
    readOnly: true
  data:
    enabled: true
    storageClass: ""
    size: 10Gi
  config:
    enabled: true
    storageClass: ""
    size: 1Gi

config:
  database_host: "mysql.default.svc.cluster.local"
  database_port: 3306
  database_name: phlex
  database_user: phlex
  database_password: "REPLACE_WITH_STRONG_PASSWORD"
  secret_key: "REPLACE_WITH_32_CHAR_KEY"
  log_level: info

# Optional: GPU node scheduling for hardware transcoding
nodeSelector:
  gpu: "nvidia"

tolerations:
  - key: "nvidia.com/gpu"
    operator: "Exists"
    effect: "NoSchedule"
```

Save as `values.yaml` and install with:

```bash
helm install phlex phlex/phlex -f values.yaml
```

### 4. Required PersistentVolumeClaims

The Helm chart creates four PVCs automatically. Describe them:

```bash
kubectl get pvc | grep phlex
```

| PVC name | Purpose | Default size | Access mode |
|----------|---------|--------------|-------------|
| `phlex-media` | Media files (read-only mount) | 100 Gi | ReadWriteOnce |
| `phlex-data` | Application data (DB, watch history) | 10 Gi | ReadWriteOnce |
| `phlex-config` | Config directory | 1 Gi | ReadWriteOnce |

**StorageClass:** If your cluster has no default StorageClass, you must set `persistence.media.storageClass` explicitly (e.g., `local-path`, `nfs`, `cephfs`). Using a StorageClass that supports `ReadWriteMany` (e.g., NFS) is required for the media PVC to be mounted read-only by multiple pods.

### 5. Service type

Three options:

#### 5a. ClusterIP (default — requires Ingress)

```yaml
service:
  type: ClusterIP
  http:
    port: 80
```

Access via Ingress at `https://phlex.example.com`.

#### 5b. LoadBalancer

```yaml
service:
  type: LoadBalancer
  http:
    port: 80
```

Exposes phlex directly on a cloud LB. For on-premises, MetalLB can provide this.

#### 5c. NodePort

```yaml
service:
  type: NodePort
  http:
    port: 80
    nodePort: 32400
```

Access at `http://<any-node-ip>:32400`. Not recommended for production.

### 6. Ingress annotations

#### nginx-ingress (recommended)

```yaml
ingress:
  annotations:
    cert-manager.io/cluster-issuer: "letsencrypt-prod"
    nginx.ingress.kubernetes.io/proxy-read-timeout: "86400"
    nginx.ingress.kubernetes.io/proxy-send-timeout: "86400"
    # WebSocket proxying
    nginx.ingress.kubernetes.io/proxy-http-version: "1.1"
    nginx.ingress.kubernetes.io/upstream-hdrs: "Upgrade"
    nginx.ingress.kubernetes.io/websocket-services: "phlex-websocket"
    nginx.ingress.kubernetes.io/use-regex: "true"
```

#### traefik

```yaml
ingress:
  annotations:
    cert-manager.io/cluster-issuer: "letsencrypt-prod"
    traefik.ingress.kubernetes.io/router.entrypoints: "websecure"
    traefik.ingress.kubernetes.io/router.http-services: "phlex-http"
    traefik.ingress.kubernetes.io/router.headers.customrequestheaders: "Upgrade: websocket"
```

If using traefik's `IngressRoute` CRD instead of plain Ingress, see the [traefik docs](https://doc.traefik.io/traefik/routing/providers/kubernetes-ingress/).

### 7. Environment variables

The chart passes these to the pod automatically via `PHLEX_*` env vars:

| Env var | Description | Example |
|---------|-------------|---------|
| `PHLEX_DATABASE_HOST` | MySQL host | `mysql.default.svc.cluster.local` |
| `PHLEX_DATABASE_PORT` | MySQL port | `3306` |
| `PHLEX_DATABASE_NAME` | Database name | `phlex` |
| `PHLEX_DATABASE_USER` | Database user | `phlex` |
| `PHLEX_DATABASE_PASSWORD` | Database password | from Kubernetes Secret |
| `PHLEX_SECRET_KEY` | JWT/signing key | from Kubernetes Secret |
| `PHLEX_LOG_LEVEL` | Log verbosity | `info`, `debug` |
| `PHLEX_HTTP_PORT` | Internal HTTP port | `80` |

Set passwords/keys via the chart's secrets mechanism (required):

```bash
helm install phlex phlex/phlex \
  --set config.database_password=STRONG_PASSWORD \
  --set config.secret_key=YOUR_32_CHAR_SECRET
```

Or pre-create a Kubernetes Secret and reference it in values.yaml.

### 8. GPU node scheduling (NVIDIA)

For hardware-accelerated transcoding on NVIDIA GPUs:

```bash
# Install the NVIDIA device plugin (one-time per cluster)
kubectl apply -f https://raw.githubusercontent.com/NVIDIA/k8s-device-plugin/v0.14.5/nvidia-device-plugin.yml
```

Then in values.yaml:

```yaml
nodeSelector:
  nvidia.com/gpu: "true"

tolerations:
  - key: "nvidia.com/gpu"
    operator: "Exists"
    effect: "NoSchedule"
```

The container automatically detects and uses NVENC/NVDEC when available.

### 9. Helm upgrade process

When a new chart or image version is released:

```bash
# Update chart repo
helm repo update

# Check what would change
helm diff upgrade phlex phlex/phlex -f values.yaml

# Apply the upgrade
helm upgrade phlex phlex/phlex -f values.yaml

# Roll back if needed
helm rollback phlex
```

For zero-downtime upgrades, the chart uses `RollingUpdate` strategy with `maxSurge: 1` and `maxUnavailable: 0`. Ensure readinessProbe is properly configured (it is by default).

To update only the Docker image tag:

```bash
helm upgrade phlex phlex/phlex --set image.tag=v1.2.3
```

### What can go wrong

#### Failure 1: PVC pending — storage class not found

- **Symptom:** `kubectl get pvc` shows all PVCs `Pending`
- **Cause:** Cluster has no default StorageClass, or the named StorageClass (`nfs`, `cephfs`, etc.) does not exist
- **Fix:** Check available StorageClasses: `kubectl get storageclass`. Then set it explicitly in values.yaml:
  ```yaml
  persistence:
    media:
      storageClass: "local-path"
  ```
- **Verify:** `kubectl describe pvc <name>` shows `Waiting for a volume to be created either by the external provisioner`

#### Failure 2: OOMKilled — memory limit too low

- **Symptom:** Pod is `OOMKilled` shortly after starting, especially during first-run metadata fetch or FFmpeg probe
- **Cause:** Default memory `limit: 2Gi` may be insufficient for libraries with large watch histories or concurrent transcoding
- **Fix:** Increase memory limits in values.yaml:
  ```yaml
  resources:
    limits:
      memory: 4Gi
    requests:
      memory: 1Gi
  ```
- **Verify:** `kubectl top pod phlex-xxxxxxxxx` (requires metrics-server) or check `kubectl describe pod` for `Last State: Terminated, Reason: OOMKilled`

#### Failure 3: Ingress 502 — ingress controller not found or WebSocket misconfiguration

- **Symptom:** HTTP requests return 502, or WebSocket connections fail immediately (`ws://...` fails to connect)
- **Cause 1:** No ingress controller is installed in the cluster
  - **Fix:** Install nginx-ingress: `helm install ingress-nginx ingress-nginx/ingress-nginx --namespace ingress-nginx --create-namespace`
- **Cause 2:** WebSocket annotations missing from Ingress (required for the WebSocket port 3473)
  - **Fix:** Ensure the ingress annotations include the WebSocket proxy directives listed in §6
- **Verify:** `kubectl describe ingress phlex-xxxx` shows backend services correctly; check nginx-ingress logs: `kubectl logs -n ingress-nginx -l app.kubernetes.io/name=ingress-nginx`

### Next steps

- [First-run wizard](docs/first-run.md) — complete the browser-based setup at `https://phlex.example.com`
- [Linux install](docs/install/linux.md) — alternative install method on bare metal
- [Docker install](docs/install/docker.md) — alternative install method using containers
- [Hardware transcoding](docs/hardware-transcoding.md) — configure NVENC/VAAPI for GPU-accelerated transcoding on Kubernetes nodes
- [Helm chart source (O.3)](https://github.com/detain/phlex-helm) — report chart issues or contributing improvements
