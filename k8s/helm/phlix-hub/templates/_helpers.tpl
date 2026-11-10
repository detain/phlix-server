{{/*
Expand the name of the chart.
*/}}
{{- define "phlix-hub.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
*/}}
{{- define "phlix-hub.fullname" -}}
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
Chart name and version label
*/}}
{{- define "phlix-hub.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "phlix-hub.labels" -}}
helm.sh/chart: {{ include "phlix-hub.chart" . }}
{{ include "phlix-hub.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "phlix-hub.selectorLabels" -}}
app.kubernetes.io/name: {{ include "phlix-hub.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
ServiceAccount name
*/}}
{{- define "phlix-hub.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "phlix-hub.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{/*
SH-027 (seceng major 1), a verbatim port of the server chart's M29 guard: a
known placeholder / literal instruction in a secret position is NOT a secret.
values.example.yaml ships "CHANGE_ME" and "openssl rand -hex 64" (the COMMAND
as the VALUE) and the hub secret template only wrapped them in required(),
which proves non-emptiness — `helm install -f values.example.yaml` booted a
hub with a publicly-known JWT signing key and DB password. Rendering now
FAILS on any known-placeholder shape, keeping the example file as loud bait.
Dirty list pinned identical to k8s/helm/phlix/templates/_helpers.tpl by
HubChartPlaceholderSecretGuardTest.
Usage: include "phlix-hub.rejectKnownPlaceholder" with a two-element list,
value-name then value (see secret.yaml).
*/}}
{{- define "phlix-hub.rejectKnownPlaceholder" -}}
{{- $name := index . 0 -}}
{{- $value := index . 1 | toString -}}
{{- $dirty := list "change_me" "changeme" "change-me" "change me" "replace_me" "replaceme" "replace-me" "secret" "password" "test" "demo" "dev" "openssl rand -hex 64" -}}
{{- if has (lower $value) $dirty -}}
{{- fail (printf "phlix-hub chart: %s is a copy-paste placeholder (%q), not a credential — generate a real one (e.g. `openssl rand -hex 64`) and set it before installing" $name $value) -}}
{{- end -}}
{{- $value -}}
{{- end -}}
