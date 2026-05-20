# Phase K — *arr Integration

**Phase goal:** Integrate with Sonarr, Radarr, Bazarr, Prowlarr, Jellyseerr, and TRaSH-Guides to bring professional-grade media management to Phlex.

## Steps

| # | Step | Plan file | Review |
|---|------|-----------|--------|
| K.1 | Sonarr/Radarr API clients (in phlex-shared) | `k.1-arr-clients.md` | Yes |
| K.2 | Bazarr + Prowlarr clients | `k.2-bazarr-prowlarr.md` | Yes |
| K.3 | Jellyseerr-class request UI on hub | `k.3-request-ui.md` | Yes |
| K.4 | TRaSH-Guides custom-format sync | `k.4-trash-guides.md` | Yes |

## K.1 — Design step

K.1 is the **design step** that produces the implementation plans for K.2–K.4. This avoids the anti-pattern of creating detailed plans for things that may be infeasible given the existing architecture.

## Non-goals

- No direct database writes to Sonarr/Radarr/Bazarr/Prowlarr DBs — API only.
- No porting of full *arr functionality — only the integration surface Phlex needs.

(End of file - total 24 lines)