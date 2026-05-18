# Phlex on Kubernetes

This directory contains a Helm chart for deploying Phlex Media Server on Kubernetes.

## Prerequisites

- Kubernetes 1.19+
- Helm 3+
- An ingress controller (nginx-ingress recommended)
- cert-manager for TLS certificates (optional)

## Installing the Chart

```bash
# Add the repository (when published)
helm repo add phlex https://charts.phlex.media
helm repo update

# Install the chart
helm install phlex phlex/phlex \
  --set config.database_password=your_password \
  --set config.secret_key=your_secret \
  --set ingress.enabled=true \
  --set ingress.hosts[0].host=phlex.example.com
```

## Using Values

### Basic Configuration

```yaml
replicaCount: 1

image:
  repository: ghcr.io/detain/phlex-server
  tag: latest
  pullPolicy: IfNotPresent

ingress:
  enabled: true
  className: nginx
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
  hosts:
    - host: phlex.example.com
      paths:
        - path: /
          pathType: Prefix
  tls:
    - secretName: phlex-tls
      hosts:
        - phlex.example.com

config:
  database_host: phlex-mysql
  database_port: 3306
  database_name: phlex
  database_user: phlex
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
    database: phlex
    username: phlex
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
| `image.repository` | Docker image repository | `ghcr.io/detain/phlex-server` |
| `image.tag` | Docker image tag | `latest` |
| `service.type` | Kubernetes service type | `ClusterIP` |
| `service.http.port` | HTTP port | `80` |
| `service.websocket.port` | WebSocket port | `3473` |
| `ingress.enabled` | Enable ingress | `true` |
| `config.database_password` | Database password | Required |
| `config.secret_key` | Application secret | Required |
| `persistence.enabled` | Enable persistence | `true` |

## Uninstalling

```bash
helm uninstall phlex
```

## Database Migration

Database migrations run automatically on first startup via the docker-entrypoint.sh script.
