# Step O.3 — Kubernetes Helm Chart

**Phase:** O (Deployment / DevOps / Release)
**Step:** O.3
**Depends on:** O.1 (Docker images)
**Review:** Yes — see `o.3-k8s-helm-review.md`
**Target repo:** `detain/phlex-helm` (chart repo) + `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Create a **Helm chart** for Kubernetes deployment of phlex-server and phlex-hub.

## 2. Context (what already exists)

Read first:

- `docker/Dockerfile` — base Docker image from O.1.
- `docker/examples/full-stack/docker-compose.yml` — full stack configuration.

## 3. Scope — files to create

### `k8s/helm/phlex/Chart.yaml`

```yaml
apiVersion: v2
name: phlex
description: A Helm chart for Phlex Media Server
type: application
version: 0.1.0
appVersion: "1.0.0"
keywords:
  - media-server
  - plex
  - jellyfin
  - emby
maintainers:
  - name: Phlex Team
    url: https://github.com/detain
sources:
  - https://github.com/detain/phlex-server
```

### `k8s/helm/phlex/values.yaml`

```yaml
replicaCount: 1

image:
  repository: ghcr.io/detain/phlex-server
  pullPolicy: IfNotPresent
  tag: "latest"

imagePullSecrets: []
nameOverride: ""
fullnameOverride: ""

service:
  type: ClusterIP
  http:
    port: 80
    httpsPort: 443
  websocket:
    port: 3473

ingress:
  enabled: true
  className: "nginx"
  annotations:
    cert-manager.io/cluster-issuer: "letsencrypt-prod"
    nginx.ingress.kubernetes.io/proxy-read-timeout: "86400"
    nginx.ingress.kubernetes.io/proxy-send-timeout: "86400"
    nginx.ingress.kubernetes.io/upstream-hash-by: "$remote_addr"
  hosts:
    - host: phlex.local
      paths:
        - path: /
          pathType: Prefix
  tls:
    - secretName: phlex-tls
      hosts:
        - phlex.local

resources:
  limits:
    cpu: 2000m
    memory: 2Gi
  requests:
    cpu: 500m
    memory: 512Mi

persistence:
  enabled: true
  config:
    storageClass: ""
    size: 1Gi
  data:
    storageClass: ""
    size: 10Gi
  backups:
    storageClass: ""
    size: 5Gi
  media:
    enabled: true
    storageClass: ""
    size: 100Gi
    mountPath: /media
    readOnly: true

mysql:
  enabled: true
  auth:
    database: phlex
    username: phlex
  primary:
    persistence:
      size: 10Gi
  secondary:
    enabled: false

config:
  database_host: "{{ .Release.Name }}-mysql"
  database_port: 3306
  database_name: phlex
  database_user: phlex
  database_password: ""
  secret_key: ""
  log_level: info
  hub_url: ""
  hub_pairing_code: ""

nodeSelector: {}

tolerations: []

affinity: {}

livenessProbe:
  httpGet:
    path: /health
    port: http
  initialDelaySeconds: 30
  periodSeconds: 10

readinessProbe:
  httpGet:
    path: /health
    port: http
  initialDelaySeconds: 5
  periodSeconds: 5

startupProbe:
  httpGet:
    path: /health
    port: http
  initialDelaySeconds: 10
  periodSeconds: 5
  failureThreshold: 30
```

### `k8s/helm/phlex/templates/`

#### `templates/NOTES.txt`

```
Thank you for installing Phlex Media Server.

Your release is named {{ .Release.Name }}.

To get the application URL:

{{- if .Values.ingress.enabled }}
{{- range $host := .Values.ingress.hosts }}
  http{{ if $.Values.ingress.tls }}s{{ end }}://{{ $host.host }}
{{- end }}
{{- end }}

To learn more about the release, try:
  $ helm get all {{ .Release.Name }}
```

#### `templates/_helpers.tpl`

```yaml
{{/*
Expand the name of the chart.
*/}}
{{- define "phlex.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
*/}}
{{- define "phlex.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Create chart name and version as used by the chart label.
*/}}
{{- define "phlex.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "phlex.labels" -}}
helm.sh/chart: {{ include "phlex.chart" . }}
{{ include "phlex.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "phlex.selectorLabels" -}}
app.kubernetes.io/name: {{ include "phlex.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}
```

#### `templates/deployment.yaml`

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "phlex.fullname" . }}
  labels:
    {{- include "phlex.labels" . | nindent 4 }}
spec:
  replicas: {{ .Values.replicaCount }}
  selector:
    matchLabels:
      {{- include "phlex.selectorLabels" . | nindent 6 }}
  template:
    metadata:
      labels:
        {{- include "phlex.selectorLabels" . | nindent 8 }}
    spec:
      {{- with .Values.imagePullSecrets }}
      imagePullSecrets:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      serviceAccountName: {{ include "phlex.fullname" . }}
      securityContext:
        runAsNonRoot: true
        runAsUser: 1000
        fsGroup: 1000
      containers:
        - name: {{ .Chart.Name }}
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: false
            capabilities:
              drop:
                - ALL
          image: "{{ .Values.image.repository }}:{{ .Values.image.tag | default .Chart.AppVersion }}"
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          ports:
            - name: http
              containerPort: 80
              protocol: TCP
            - name: https
              containerPort: 443
              protocol: TCP
            - name: websocket
              containerPort: 3473
              protocol: TCP
          livenessProbe:
            {{- toYaml .Values.livenessProbe | nindent 12 }}
          readinessProbe:
            {{- toYaml .Values.readinessProbe | nindent 12 }}
          startupProbe:
            {{- toYaml .Values.startupProbe | nindent 12 }}
          env:
            - name: PHLEX_DATABASE_HOST
              value: {{ .Values.config.database_host | quote }}
            - name: PHLEX_DATABASE_PORT
              value: {{ .Values.config.database_port | quote }}
            - name: PHLEX_DATABASE_NAME
              value: {{ .Values.config.database_name | quote }}
            - name: PHLEX_DATABASE_USER
              value: {{ .Values.config.database_user | quote }}
            - name: PHLEX_DATABASE_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ include "phlex.fullname" . }}
                  key: database-password
            - name: PHLEX_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ include "phlex.fullname" . }}
                  key: secret-key
            - name: PHLEX_LOG_LEVEL
              value: {{ .Values.config.log_level | quote }}
            - name: PHLEX_HUB_URL
              value: {{ .Values.config.hub_url | quote }}
            - name: PHLEX_HUB_PAIRING_CODE
              value: {{ .Values.config.hub_pairing_code | quote }}
          resources:
            {{- toYaml .Values.resources | nindent 12 }}
          volumeMounts:
            - name: config
              mountPath: /var/phlex/config
            - name: data
              mountPath: /var/phlex/data
            - name: backups
              mountPath: /var/phlex/backups
            - name: logs
              mountPath: /var/phlex/logs
            - name: media
              mountPath: /media
              readOnly: true
      volumes:
        - name: config
          persistentVolumeClaim:
            claimName: {{ include "phlex.fullname" . }}-config
        - name: data
          persistentVolumeClaim:
            claimName: {{ include "phlex.fullname" . }}-data
        - name: backups
          persistentVolumeClaim:
            claimName: {{ include "phlex.fullname" . }}-backups
        - name: logs
          emptyDir: {}
        - name: media
          persistentVolumeClaim:
            claimName: {{ include "phlex.fullname" . }}-media
```

#### `templates/service.yaml`

```yaml
apiVersion: v1
kind: Service
metadata:
  name: {{ include "phlex.fullname" . }}
  labels:
    {{- include "phlex.labels" . | nindent 4 }}
spec:
  type: {{ .Values.service.type }}
  ports:
    - port: {{ .Values.service.http.port }}
      targetPort: http
      protocol: TCP
      name: http
    - port: {{ .Values.service.https.port }}
      targetPort: https
      protocol: TCP
      name: https
    - port: {{ .Values.service.websocket.port }}
      targetPort: websocket
      protocol: TCP
      name: websocket
  selector:
    {{- include "phlex.selectorLabels" . | nindent 4 }}
```

#### `templates/ingress.yaml`

```yaml
{{- if .Values.ingress.enabled }}
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ include "phlex.fullname" . }}
  labels:
    {{- include "phlex.labels" . | nindent 4 }}
  {{- with .Values.ingress.annotations }}
  annotations:
    {{- toYaml . | nindent 4 }}
  {{- end }}
spec:
  ingressClassName: {{ .Values.ingress.className }}
  {{- if .Values.ingress.tls }}
  tls:
    {{- range .Values.ingress.tls }}
    - hosts:
        {{- range .hosts }}
        - {{ . | quote }}
        {{- end }}
      secretName: {{ .secretName }}
    {{- end }}
  {{- end }}
  rules:
    {{- range .Values.ingress.hosts }}
    - host: {{ .host | quote }}
      http:
        paths:
          {{- range .paths }}
          - path: {{ .path }}
            pathType: {{ .pathType }}
            backend:
              service:
                name: {{ include "phlex.fullname" $ }}
                port:
                  number: {{ $.Values.service.http.port }}
          {{- end }}
    {{- end }}
{{- end }}
```

#### `templates/pvc.yaml`

```yaml
{{- if .Values.persistence.enabled }}
{{- range $name, $persistence := dict "config" .Values.persistence.config "data" .Values.persistence.data "backups" .Values.persistence.backups }}
{{- if $persistence.enabled }}
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ include "phlex.fullname" $ }}-{{ $name }}
  labels:
    {{- include "phlex.labels" $ | nindent 4 }}
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $persistence.size }}
  {{- if $persistence.storageClass }}
  storageClassName: {{ $persistence.storageClass }}
  {{- end }}
{{- end }}
{{- end }}
---
{{- if .Values.persistence.media.enabled }}
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ include "phlex.fullname" . }}-media
  labels:
    {{- include "phlex.labels" . | nindent 4 }}
spec:
  accessModes:
    - ReadOnlyMany
  resources:
    requests:
      storage: {{ .Values.persistence.media.size }}
  {{- if .Values.persistence.media.storageClass }}
  storageClassName: {{ .Values.persistence.media.storageClass }}
  {{- end }}
{{- end }}
{{- end }}
```

#### `templates/secrets.yaml`

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: {{ include "phlex.fullname" . }}
  labels:
    {{- include "phlex.labels" . | nindent 4 }}
type: Opaque
stringData:
  database-password: {{ required "config.database_password is required" .Values.config.database_password }}
  secret-key: {{ required "config.secret_key is required" .Values.config.secret_key }}
```

#### `templates/serviceaccount.yaml`

```yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: {{ include "phlex.fullname" . }}
  labels:
    {{- include "phlex.labels" . | nindent 4 }}
```

#### `templates/hpa.yaml`

```yaml
{{- if .Values.autoscaling.enabled }}
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: {{ include "phlex.fullname" . }}
  labels:
    {{- include "phlex.labels" . | nindent 4 }}
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: {{ include "phlex.fullname" . }}
  minReplicas: {{ .Values.autoscaling.minReplicas }}
  maxReplicas: {{ .Values.autoscaling.maxReplicas }}
  metrics:
    {{- toYaml .Values.autoscaling.metrics | nindent 4 }}
{{- end }}
```

### `k8s/helm/phlex/.helmignore`

```
# Patterns to ignore when building packages.
.git
.gitignore
.bzr
.bzrignore
.hg
.hgignore
.svn
*.swp
*.bak
*.tmp
*~
.DS_Store
```

### `k8s/README.md`

```markdown
# Phlex on Kubernetes

This directory contains Kubernetes manifests and Helm chart for deploying Phlex Media Server.

## Helm Chart

The recommended way to deploy Phlex on Kubernetes is via the Helm chart:

```bash
# Add the repository
helm repo add phlex https://charts.phlex.media
helm repo update

# Install the chart
helm install phlex phlex/phlex \
  --set config.database_password=your_password \
  --set config.secret_key=your_secret \
  --set ingress.enabled=true \
  --set ingress.hosts[0].host=phlex.example.com
```

## Requirements

- Kubernetes 1.19+
- Helm 3+
- Ingress controller (nginx-ingress recommended)
- cert-manager for TLS certificates

## Quick Start with kustomize

For a quick deployment without Helm:

```bash
kubectl apply -k k8s/overlays/dev
```
```

## 4. Approach

1. Branch from master: `git checkout -b o.3-k8s-helm`.
2. Create `k8s/` directory structure.
3. Create `k8s/helm/phlex/Chart.yaml` with chart metadata.
4. Create `k8s/helm/phlex/values.yaml` with all configuration options.
5. Create template files (deployment, service, ingress, PVC, secrets, etc.).
6. Create `_helpers.tpl` for label generation.
7. Create `NOTES.txt` for post-install information.
8. Create `k8s/README.md` with deployment instructions.
9. Validate Helm chart syntax with `helm lint`.
10. Write tests for chart configuration.
11. Verify: PHPStan level 9, PHPCS clean.
12. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `HelmChartTest::test_chart_yaml_syntax_valid`
2. `HelmChartTest::test_values_schema_valid`
3. `HelmChartTest::test_template_renders_without_error`
4. `HelmChartTest::test_ingress_template_valid`

## 6. Acceptance Criteria

- [ ] Helm chart at `k8s/helm/phlex/` passes `helm lint`.
- [ ] `values.yaml` includes all configuration options documented.
- [ ] Deployment template includes liveness/readiness/startup probes.
- [ ] Service template exposes HTTP, HTTPS, and WebSocket ports.
- [ ] Ingress template supports TLS and WebSocket proxying.
- [ ] PVC templates for config, data, backups, and media volumes.
- [ ] Secret template for database password and secret key.
- [ ] HPA template for horizontal pod autoscaling (optional).
- [ ] README explains Helm and kustomize deployment options.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b o.3-k8s-helm
# ... implement ...
helm lint k8s/helm/phlex/
./vendor/bin/phpstan analyze k8s/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 k8s/
git add -A
git commit -m "Step O.3: Kubernetes Helm chart"
unset GITHUB_TOKEN
gh pr create --title "Step O.3: Kubernetes Helm chart" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `o.3-k8s-helm-review.md`.

(End of file - total 285 lines)