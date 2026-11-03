{{/*
Expand the name of the chart.
*/}}
{{- define "phlix.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
*/}}
{{- define "phlix.fullname" -}}
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
{{- define "phlix.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "phlix.labels" -}}
helm.sh/chart: {{ include "phlix.chart" . }}
{{ include "phlix.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "phlix.selectorLabels" -}}
app.kubernetes.io/name: {{ include "phlix.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
ServiceAccount name to use
*/}}
{{- define "phlix.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "phlix.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{/*
M29 (seceng major 1): a known placeholder / literal instruction in a secret
position is NOT a secret. values.example.yaml historically shipped exactly
such values ("CHANGE_ME", even "openssl rand -hex 64" — the COMMAND as the
VALUE) and `helm install -f values.example.yaml` succeeded verbatim, booting
with a publicly-known JWT signing key. The entrypoint's lowercase change_me / changeme / change-me prefix refusal never matched the uppercase forms,
and required() only proves non-emptiness. This guard applies the doctrine
docker/examples/.env.example codified (R7-04: "anyone could forge tokens")
to the chart: rendering FAILS on any known-placeholder shape.
Usage: include "phlix.rejectKnownPlaceholder" with a two-element list,
value-name then value (see secret.yaml).
*/}}
{{- define "phlix.rejectKnownPlaceholder" -}}
{{- $name := index . 0 -}}
{{- $value := index . 1 | toString -}}
{{- $dirty := list "change_me" "changeme" "change-me" "change me" "replace_me" "replaceme" "replace-me" "secret" "password" "test" "demo" "dev" "openssl rand -hex 64" -}}
{{- if has (lower $value) $dirty -}}
{{- fail (printf "phlix chart: %s is a copy-paste placeholder (%q), not a credential — generate a real one (e.g. `openssl rand -hex 64`) and set it before installing" $name $value) -}}
{{- end -}}
{{- $value -}}
{{- end -}}
