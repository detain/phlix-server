# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added (Step F.4)

- `SkipButtonSpec` — immutable value object with `toArray()` serialization and
  `fromMarkerSet()` factory for client-facing JSON.
- `PlaybackMarkerService` — provides `getFullSpec()` and `getSkipSpec(id, position_ticks)`
  to return position-aware skip button specs.
- `WebPortalRouter::getPlaybackInfo()` — embeds `markers` key with
  `skip_intro_start`, `skip_intro_end`, `skip_outro_start`, `skip_outro_end`
  in the playback info response.
- `docs/reference/skip-button-protocol.md` — full protocol specification for
  client teams implementing skip button UI.
- `docs/clients/skip-button-integration-brief.md` — concise hand-off brief
  for Phase M client integration.
- `docs/reference/api.md` — updated with `GET /api/v1/media/{id}/playback`
  endpoint documentation including `markers` key.
- Unit tests: `SkipButtonSpecTest` (4 tests), `PlaybackMarkerServiceTest` (4 tests).

### Added (Step F.3)

- Marker storage columns and GET API for chapters, intro, and outro markers.
- `migrations/003_marker_columns.sql` — adds `intro_start_seconds`,
  `intro_end_seconds`, `outro_start_seconds`, `outro_end_seconds`,
  `chapters_json` columns to `media_items` table.
- `IntroMarker` / `OutroMarker` / `ChapterMarker` — immutable DTOs for marker
  segments with start/end times, confidence, and optional title.
- `MarkerSet` — aggregate DTO containing intro, outro, and chapters array with
  `hasMarkers()` and `toArray()` methods.
- `MarkerService` — service for reading/promoting markers; reads formal columns
  first, falls back to `metadata_json` candidates; exposes `getMarkers()`,
  `promoteCandidates()`, `promoteShowMarkers()`, and `getShowMarkers()`.
- `MarkerController` — HTTP controller with 4 GET endpoints:
  - `GET /api/v1/media/{id}/markers` — all markers for an item
  - `GET /api/v1/media/{id}/markers/intro` — intro marker only
  - `GET /api/v1/media/{id}/markers/outro` — outro marker only
  - `GET /api/v1/shows/{id}/markers/bulk` — all episode markers for a show
- `Router::markers()` — registers the 4 marker routes.
- `ItemRepository` — added `getIntroMarker()`, `getOutroMarker()`,
  `getChapters()`, and `updateMarkers()` methods for marker column access.
- `docs/reference/api.md` — API reference documentation for marker endpoints.
- Unit tests: `MarkerSetTest` (10 tests), `MarkerServiceTest` (9 tests),
  `MarkerControllerTest` (10 tests).

### Added (Step F.2)

- Intro/outro detection background job system using audio fingerprint clustering.
- `FingerprintClusterer` — Jaccard similarity-based clustering to detect shared
  intro/outro segments across episodes using audio fingerprints.
- `IntroDetectionJob` — orchestrates detection for all episodes of a TV show,
  clusters fingerprints, returns marker candidates.
- `IntroMarkerCandidate` / `OutroMarkerCandidate` — immutable DTOs for detected
  intro/outro segments with start/end times, fingerprint, and confidence score.
- `IntroDetectionResult` — result container for show-level detection results.
- `ClusteringResult` — result container for fingerprint clustering output.
- `StoredMarkers` — parses stored marker candidates from episode metadata.
- `MarkerCandidateRepository` — persists intro/outro candidates to
  `media_items.metadata_json` for consumption by F.3 API.
- `MarkerCandidateStore` — file-based job queue (`/tmp/phlex_marker_jobs/`)
  with one lock file per show being processed.
- `BackgroundDetectorWorker` — queue consumer loop that processes detection
  jobs continuously.
- `scripts/run-marker-detection-worker.php` — CLI entry point for running
  the background worker.
- `config/marker_detection.php` — configuration for intro/max duration,
  similarity threshold (0.85), minimum episodes (3), worker interval.
- `docs/developers/intro-outro-detection.md` — developer documentation
  covering the clustering algorithm, configuration, and usage.
- Unit tests: `IntroDetectionJobTest` (5 tests), `FingerprintClustererTest`
  (12 tests), `MarkerCandidateStoreTest` (10 tests),
  `MarkerCandidateRepositoryTest` (5 tests).

### Added (Step E.6)

- Subtitle burn-in (hardsubbing) pipeline for embedding subtitles directly
  in the video stream — required for players/devices that don't support
  external subtitle tracks (many smart TVs, game consoles, some mobile browsers).
- `SubtitleFormat` — enum with SRT, ASS, SSA, VTT, HDMV formats plus
  `getFfmpegFormat()` and `supportsFontstyle()` methods.
- `SubtitleTrack` — immutable value object with stream index, language code,
  display label, format, and file path.
- `SubtitleStyleOptions` — value object for burn-in styling (font, size,
  primary/outline colors, outline thickness, position, margin) with
  `toAssStyle()` and `toSrtStyle()` methods.
- `SubtitleBurner` — core class for subtitle stream detection, extraction,
  and FFmpeg filter graph generation for burn-in across all vendors.
- `SubtitleBurnerFactory` — factory for creating vendor-specific burners.
- `HwaccelCommandBuilder` — added `setSubtitleTrack()`, `setSubtitleStyle()`,
  and `setSubtitleBurner()` methods; integrates subtitle burn-in filter
  args into hardware transcoding commands.
- `StreamManager` — added `setSubtitleBurnIn()` and `getSubtitleBurnInConfig()`
  methods for configuring subtitle burn-in per streaming session.
- `StreamState` — added `subtitleBurnInIndex` and `forceSubtitleBurnIn` properties.
- `config/subtitles.php` — subtitle configuration with `enabled`, `default_language`,
  `burn_in_by_default`, `extract_to_dir`, and `style` options.
- `config/ffmpeg.php` — added `subtitles` key referencing `config/subtitles.php`.
- `docs/developers/subtitle-processing.md` — developer documentation covering
  soft vs. hard subtitling, vendor burn-in support matrix, styling reference,
  and usage examples.
- Unit tests: `SubtitleFormatTest` (11 tests), `SubtitleTrackTest` (4 tests),
  `SubtitleStyleOptionsTest` (6 tests), `SubtitleBurnerTest` (13 tests).

### Added (Step E.5)

- Trickplay (thumbnail seek / scrub preview) support for video progress bar
  hover preview using DASH-IF / HLS spec-compliant "BIF" (Bitmap Image Format)
  thumbnail grids.
- `TrickplayConfig` — value object with grid dimensions (8×4), thumbnail size
  (160×90px), interval (10s), image format (JPEG/PNG), and quality settings.
- `TrickplayResult` — result container with job ID, interval, grid dimensions,
  image file metadata (byte offsets for byte-range requests), and BIF index XML
  path.
- `TrickplayGenerator` — extracts frames at fixed intervals using FFmpeg batch
  extraction (`generateThumbnailBatch`), assembles frames into grid images via
  FFmpeg `tile` filter, generates BIF index XML with offset/length per thumbnail.
- `TrickplayController` — HTTP handler serving thumbnail grid images and BIF
  index XML with correct `Content-Type` headers.
- `StreamManager` — added `setTrickplay()` and `generateTrickplay()` methods,
  `TrickplayGenerator` and `TrickplayController` properties, and
  `getTrickplayController()` getter.
- `FfmpegRunner` — extended `generateThumbnail()` to accept `int|array` for
  batch extraction, added `generateThumbnailBatch()` for multiple timestamps in
  one command, added `getFfmpegPath()` accessor.
- `Router` — added `trickplay()` route registration for
  `GET /trickplay/{jobId}/thumb-{index}.jpg` and `GET /trickplay/{jobId}/index.xml`.
- `config/trickplay.php` — trickplay configuration with `enabled`, `interval_seconds`,
  `grid_columns`, `grid_rows`, `thumb_width`, `thumb_height`, `image_format`,
  `jpeg_quality`, `storage_dir`.
- `docs/developers/streaming-protocols.md` — added "Trickplay / Thumbnail Seek"
  section documenting BIF format, generation pipeline, configuration, and
  client-side usage.
- Unit tests: `TrickplayConfigTest` (15 tests), `TrickplayResultTest` (9 tests),
  `TrickplayGeneratorTest` (8 tests), `TrickplayControllerTest` (10 tests).

### Added (Step E.4)

- DASH (Dynamic Adaptive Streaming over HTTP) streaming support alongside
  existing HLS implementation.
- `DashStreamer` — DASH manifest generator and segment manager producing
  DASH-IF compliant MPD manifests with SegmentTemplate elements.
- `SegmentTemplate` — value object for DASH segment template handling
  (SegmentTemplate vs. SegmentList for efficient live streaming).
- `AdaptationSet` — value object representing DASH adaptation sets
  (video, audio, text) with codec/bandwidth metadata.
- `DashController` — HTTP endpoints for DASH streaming:
  `GET /dash/{jobId}/manifest.mpd`, `GET /dash/{jobId}/{setId}/manifest.mpd`,
  `GET /dash/{jobId}/{setId}/segment_{n}.m4s`.
- `config/dash.php` — DASH-specific configuration with `enabled`,
  `manifest_refresh_seconds`, `min_buffer_time`, `min_buffer_time_live`,
  `time_shift_buffer_depth`, `default_codecs`.
- `config/ffmpeg.php` — added `dash` key with `enabled`, `segment_dir`,
  `default_codecs`.
- `HlsStreamer` — added `setSegmentContent()` method so segment writer
  can store once and both HLS and DASH streamers reference the same files.
- `StreamManager` — added `DashStreamer` property and `getManifestUrl()`
  method returning HLS or DASH manifest URL based on `$protocol` parameter.
- `Router` — added `dashStreaming()` route registration method.
- `docs/developers/streaming-protocols.md` — documentation covering HLS
  vs. DASH tradeoffs, manifest structure, client-side selection, and usage.
- Unit tests: `DashStreamerTest` (11 tests), `SegmentTemplateTest` (7 tests),
  `AdaptationSetTest` (8 tests).

### Added (Step E.1)

- Hardware acceleration probe system for detecting GPU encoders (NVENC,
  VAAPI, QSV, VideoToolbox, AMF, V4L2) at startup.
- `HwaccelCapability` — immutable value object representing hardware
  encoder capabilities (vendor, encoder/decoder names, supported codecs,
  HDR tone mapping support, resolution/bitrate limits).
- `HwaccelProbe` — runs vendor-specific probes via `ffmpeg -encoders`
  and `ffmpeg -decoders`, aggregates results into a capability map.
- `HwaccelRegistry` — lazy singleton holding probed capabilities;
  `getEncoder()` / `getDecoder()` use vendor priority for best-match
  selection.
- `VendorProbeInterface` + 7 concrete implementations:
  `NvencProbe`, `VaapiProbe`, `QsvProbe`, `VideoToolboxProbe`,
  `AmfProbe`, `V4L2Probe`, `SoftwareProbe` (always-available fallback).
- `config/hwaccel.php` — `enabled`, `prefer_hardware`,
  `vendor_priority`, `probe_timeout`, `test_clip_path`,
  `fallback_to_software` configuration.
- `config/ffmpeg.php` — added `hwaccel` key with `enabled`,
  `prefer_hardware`, `vendor_priority`.
- `FfmpegRunner` — added `HwaccelRegistry` property and
  `probeHardwareAcceleration()` + `buildHwaccelCommand()` methods.
- `docs/developers/hardware-acceleration.md` — architecture overview,
  capability fields, usage examples, and guide for adding new vendors.
- Unit tests: `HwaccelCapabilityTest` (6 tests),
  `HwaccelProbeTest` (9 tests), `HwaccelRegistryTest` (8 tests).
- No user-visible behavior change yet — transcode remains software-only
  until Step E.2 integrates hardware encoding into TranscodeManager.

### Added (Step D.5)

- Hub-side invite-link sharing (D.5). Invite links are generated on
  the hub and grant library access to recipients. Server-side is unchanged;
  library shares are synced via the existing hub heartbeat mechanism.

### Added (Step D.4)

- First-class passkey / WebAuthn support for passwordless login.
  Supports platform authenticators (Touch ID, Windows Hello, Face ID)
  and roaming FIDO2 tokens (YubiKey, etc.).
- `src/Auth/WebAuthn/WebAuthnManager` — orchestrates registration and
  authentication ceremonies; generates cryptographically random
  challenges; validates attestation and assertions.
- `src/Auth/WebAuthn/WebAuthnCredential` — entity for stored credentials
  with VARBINARY credential ID, sign counter, and device metadata.
- `src/Auth/WebAuthn/WebAuthnSettings` — RP configuration (ID, name,
  origin, attestation requirement).
- `src/Auth/WebAuthn/WebAuthnCredentialRepository` — data access for
  `webauthn_credentials` table; implements replay attack detection via
  sign counter validation.
- `src/Auth/WebAuthnProvider` — implements `ProviderInterface` for
  WebAuthn as an auth provider alongside OIDC/LDAP.
- `src/Server/Http/Controllers/WebAuthnController` — HTTP API with
  6 endpoints for registration, authentication, and credential
  management.
- Database migration `migrations/010_webauthn_credentials.sql` —
  creates `webauthn_credentials` table with VARBINARY credential_id
  and foreign key to users.
- Smarty template `public/templates/auth/webauthn-settings.tpl` —
  user-facing passkey management UI.
- Routes wired in `Application::loadApiRoutes()`:
  `POST /api/v1/auth/webauthn/register/options`,
  `POST /api/v1/auth/webauthn/register/verify`,
  `POST /api/v1/auth/webauthn/login/options`,
  `POST /api/v1/auth/webauthn/login/verify`,
  `GET /api/v1/me/webauthn/credentials`,
  `DELETE /api/v1/me/webauthn/credentials/{id}`.
- Composer dependency added: `web-auth/webauthn-lib: ^4.0`.
- Unit tests in `tests/unit/Auth/WebAuthn/`: `WebAuthnManagerTest`,
  `WebAuthnCredentialTest`, `WebAuthnControllerTest`,
  `WebAuthnProviderTest`.
- Documentation:
  - `docs/plugins/auth-providers.md` — passkeys section added.
  - `docs/reference/api/auth-webauthn.md` — new API endpoint reference.
  - `docs/security/passkeys.md` — user-facing passkey guide.

### Added (Step D.3)

- `phlex-plugin-ldap` — LDAP authentication provider plugin.
  Supports OpenLDAP and Active Directory via the LDAP protocol.
  Includes:
  - `LdapProvider` — implements `ProviderInterface` with bind
    authentication and user attribute mapping.
  - `LdapConnection` — wraps `directorytree/ldaprecord` Connection
    with request-scoped caching per host:port:ssl triple.
  - `UserMapper` — maps LDAP attributes to Phlex user fields
    (uid/sAMAccountName → username, mail → email, displayname/cn →
    display name, jpegPhoto/thumbnailPhoto → avatar_url).
  - `LdapUserInfo` — LDAP-specific user info carrier.
  - `LdapAdminController` — admin API for LDAP settings management
    and test-connection action.
  - Smarty settings form at `templates/ldap-settings.tpl`.
- Routes wired in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers/ldap/config`,
  `POST /api/v1/admin/auth-providers/ldap/config`,
  `POST /api/v1/admin/auth-providers/ldap/test`,
  `GET /api/v1/admin/auth-providers/ldap/schema`.
- Composer dependency added: `directorytree/ldaprecord: ^3.0`.

### Added (Step D.2)

- `phlex-plugin-oidc` — OIDC/OAuth2 authentication provider plugin.
  Supports any OIDC-compliant identity provider (Authelia, Authentik,
  Keycloak, Google, GitHub). Includes:
  - `OidcProvider` — implements `ProviderInterface` with authorization
    code flow and direct API token authentication.
  - `DiscoveryDocument` — cached OIDC discovery document (24 h TTL).
  - `IdTokenValidator` — RS256/RS384/RS512 token validation with
    cached JWKS.
  - `OidcCallbackController` — handles `/auth/oidc/authorize` and
    `/auth/oidc/callback` routes.
  - `OidcAdminController` — admin API for OIDC settings management.
  - Smarty settings form at `templates/oidc-settings.tpl`.
- Routes wired in `Router::oidcAuth()`:
  `GET /auth/oidc/authorize`, `GET /auth/oidc/callback`.
- Admin routes in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers/oidc/config`,
  `POST /api/v1/admin/auth-providers/oidc/config`,
  `GET /api/v1/admin/auth-providers/oidc/schema`.
- Composer dependencies added: `web-token/jwt-framework: ^3.0`,
  `phpseclib/phpseclib: ^3.0`.

### Added (Step D.1)

- `Phlex\Auth\AuthProviderRegistry` — singleton registry holding
  registered {@see \Phlex\Auth\ProviderInterface} instances; resolves
  provider-prefixed usernames to the correct external provider.
- `Phlex\Auth\ProviderManager` — bridges {@see AuthManager} to the
  registry; handles `provider:username` parsing and delegates to either
  an external provider or the standard password-based flow.
- `Phlex\Auth\AuthProviderNotFoundException` — thrown when a
  provider-prefix references an unregistered provider.
- `Phlex\Auth\AuthManager::loginWithProvider()` — authenticates a user
  via an external provider (OIDC, LDAP, SAML, passkey). On first login,
  automatically creates a local user row with `password_hash = NULL`.
- `Phlex\Auth\UserRepository::findByExternalId()`,
  `findOrCreateByExternalId()`, `updateProviderData()` — data access
  for provider-linked accounts.
- `Phlex\Server\Http\Controllers\AuthProviderController` — admin API
  for listing / enabling / disabling providers and retrieving their
  configuration JSON schema.
- Routes wired in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers`,
  `POST /api/v1/admin/auth-providers/{name}/enable`,
  `POST /api/v1/admin/auth-providers/{name}/disable`,
  `GET /api/v1/admin/auth-providers/{name}/config-schema`.
- Migration `009_auth_provider_schema.sql` adds `provider` (VARCHAR 64),
  `external_id` (VARCHAR 255), `provider_data` (JSON) columns to
  `users` table, with indexes `idx_provider` and `idx_external`.
- `detain/phlex-shared:^0.3.0` — new package version with
  `Phlex\Shared\Auth\ProviderInterface`, `AuthResult`, `UserInfo`.
- `docs/plugins/developer-guide.md` — added "Auth Provider Plugins"
  section (Section 13) covering the interface contract, result types,
  manifest, lifecycle hooks, and admin API.
- Unit tests: `AuthResultTest` (5 tests), `UserInfoTest` (6 tests),
  `AuthProviderRegistryTest` (5 tests), `ProviderManagerTest` (8 tests),
  `UserRepositoryExternalIdTest` (5 tests), `AuthProviderControllerTest` (6 tests).

### Added (Step C.9)

- `Phlex\Hub\HubClient::sendHeartbeat()` — now includes `library_count`,
  `total_size_bytes`, and `library-sharing` capability in heartbeat
  payload to advertise library information to the hub.

### Added (Step C.8)

- `Phlex\Hub\SubdomainResult` — DTO for subdomain allocation result with
  subdomain, fqdn, tlsCertPath, and tlsKeyPath fields.
- `Phlex\Hub\SubdomainClient` — client for claiming/releasing subdomains
  from the hub and storing TLS configuration locally.
- `Phlex\Hub\HttpClientInterface::delete()` — added DELETE method for
  subdomain release.
- `Phlex\Hub\HttpClient::delete()` — implements DELETE method.
- `Phlex\Hub\HubClient::getHttpClient()` — exposes HTTP client for use
  by SubdomainClient.
- `scripts/claim-subdomain.php` — CLI script for claiming a subdomain.
- `config/hub.php` — added `subdomain_auto_claim`, `tls_enabled`,
  `domain` configuration options.
- `docs/dev/tls-certificates.md` — guide covering TLS setup, certificate
  sources (hub-provisioned vs self-signed), and security considerations.
- `docs/reference/env-vars.md` — added `PHLEX_SUBDOMAIN_AUTO_CLAIM`,
  `PHLEX_TLS_ENABLED`, `PHLEX_DOMAIN` environment variables.

### Added (Step C.7)

- `Phlex\Network\UpnpIgdClient` — UPnP-IGD client using raw sockets.
  SSDP M-SEARCH discovery on `239.255.255.250:1900`, SOAP
  `AddPortMapping` / `GetExternalIPAddress` / `DeletePortMapping`
  actions for automatic port forwarding on compatible routers.
- `Phlex\Network\StunClient` — RFC 5389 STUN client for discovering
  the server's public IP address and testing port accessibility via
  TCP connect probe.
- `Phlex\Network\NatPmpClient` — RFC 6886 NAT-PMP client for Apple
  AirPort routers and other NAT-PMP-compatible gateways.
- `Phlex\Network\PortForwardService` — orchestrator that tries UPnP
  first, then NAT-PMP, then STUN for IP detection; falls back to
  manual port-forward instructions; stores result to
  `config/port-forward.json`.
- `scripts/port-forward.php` — CLI with `status`, `enable`,
  `disable`, `info`, and `help` commands.
- `src/Common\Container\Providers\NetworkServicesProvider` — registers
  `UpnpIgdClient`, `StunClient`, `NatPmpClient`, and
  `PortForwardService` in the PHP-DI container.
- `config/port-forward.php` — `PHLEX_PORT_FORWARD_AUTO`,
  `PHLEX_EXTERNAL_PORT`, `PHLEX_EXTERNAL_HTTP_PORT`,
  `PHLEX_EXTERNAL_HTTPS_PORT`, `PHLEX_UPNP_ENABLED`,
  `PHLEX_STUN_SERVER`, `PHLEX_STUN_PORT` configuration.
- `docs/hub/remote-access.md` — end-user guide covering UPnP, NAT-PMP,
  STUN, manual port forwarding setup, and troubleshooting.
- `docs/hub-admin/network.md` — hub admin guide covering port forwarding
  configuration, firewall rules, and network requirements.
- `docs/reference/env-vars.md` — documents port-forwarding and STUN
  environment variables.
- `docs/reference/cli.md` — documents `port-forward.php` CLI commands.
- Unit tests: `UpnpIgdClientTest` (5 tests), `StunClientTest` (8 tests),
  `NatPmpClientTest` (6 tests), `PortForwardServiceTest` (9 tests),
  `PortForwardScriptTest` (5 tests).

### Changed (Step C.7)

- `Phlex\Hub\HubClient` now injects `PortForwardService` and calls
  `discoverHostnameCandidates()` to augment heartbeat hostname
  candidates with LAN IP, mDNS, and public IP endpoints when available.
- `Phlex\Common\Container\ContainerFactory::defaultProviders()` now
  registers `NetworkServicesProvider`.

### Added (Step C.6)

- `Phlex\Hub\RelayMessageFramer` — binary framing for HTTP-over-WebSocket
  tunnel. Wire format: `[1-byte type][4-byte seq][4-byte payload_len][payload]`.
  Types: HTTP_REQUEST (1), HTTP_RESPONSE (2), PING (3), PONG (4).
  All payloads are JSON.
- `Phlex\Hub\RelayFrame` — immutable parsed frame DTO with accessors
  (`isRequest()`, `isResponse()`, `isPing()`, `isPong()`).
- `Phlex\Hub\RelayConfig` — relay tunnel configuration from environment
  variables (`PHLEX_RELAY_ENABLED`, `PHLEX_RELAY_HUB_URL`,
  `PHLEX_RELAY_TUNNEL_HOSTNAME`, etc.).
- `Phlex\Hub\RelayConsumer` — server-side Workerman consumer that opens a
  persistent WSS connection to the hub, receives framed HTTP requests,
  dispatches them to the local router, and sends responses back over the
  tunnel. Implements auto-reconnect with configurable delay and
  keep-alive ping/pong.
- `Phlex\Hub\RelayApplication` — thin Workerman Worker entry point
  (`text://` protocol, timer-driven) wrapping `RelayConsumer`.
- `config/relay.php` — `PHLEX_RELAY_ENABLED`, `PHLEX_RELAY_HUB_URL`,
  `PHLEX_RELAY_TUNNEL_HOSTNAME`, `PHLEX_RELAY_RECONNECT_DELAY`,
  `PHLEX_RELAY_PING_INTERVAL`, `PHLEX_RELAY_PING_TIMEOUT`.
- `config/hub.php` — added `relay` capability to heartbeat payload.
- `docs/dev/relay-protocol.md` — wire protocol reference for the
  HTTP-over-WebSocket relay tunnel.
- `docs/reference/env-vars.md` — documents relay env vars.
- Unit tests: `RelayMessageFramerTest` (13 tests covering frame round-trips,
  ping/pong, invalid/incomplete frames), `RelayConsumerTest` (11 tests
  covering config, routing, connection state).

### Changed (Step C.6)

- `Phlex\Hub\HubClient::sendHeartbeat()` now advertises `relay`
  in the server capabilities list.
- `Phlex\Server\Core\Application` now starts `RelayApplication`
  automatically when `config/hub-enrollment.json` exists and
  `PHLEX_RELAY_ENABLED=true`.
- `Phlex\Common\Container\Providers\HubServicesProvider` now registers
  `RelayConfig`, `RelayMessageFramer`, `RelayConsumer`, and
  `RelayApplication` in the PHP-DI container.

### Added (Step C.2)

- `Phlex\Hub\HubClient` — server-side orchestrator for server↔hub pairing,
  heartbeat loop, re-enrollment, and JWKS exposure. Implements the protocol
  defined in `docs/dev/pairing-protocol.md`.
- `Phlex\Hub\Ed25519KeyManager` — generates, stores, loads, and rotates
  Ed25519 keypairs (libsodium `sodium_crypto_sign_*`). Key stored at
  `config/hub-server-key.pem` (mode 0600). Key ID is SHA-256 first 8 bytes
  of the public key (base64url).
- `Phlex\Hub\HttpClient` — cURL-based HTTP client for hub API communication.
  Always sends `Accept-Phlex-Protocol: v1` header.
- `Phlex\Hub\HubApplication` — thin Workerman Worker wrapper for the
  background heartbeat loop (`text://` protocol, timer-driven).
- `Phlex\Server\Http\Controllers\HubJwksController` — serves
  `GET /.well-known/jwks.json` with the server's Ed25519 JWK(s).
  Cache-Control: public, max-age=3600.
- `scripts/pair-with-hub.php` — CLI pairing script. Initiates claim request,
  displays claim code, polls until claimed, stores enrollment, starts
  heartbeat loop.
- `config/hub.php` — hub subsystem configuration (`PHLEX_HUB_URL`,
  `PHLEX_HUB_HEARTBEAT_INTERVAL`, key/enrollment paths).
- `Phlex\Common\Container\Providers\HubServicesProvider` — registers
  Ed25519KeyManager, HubClient, HubJwksController, HubApplication in
  the PHP-DI container.
- `docs/reference/api/hub-jwks.yaml` — OpenAPI 3.0 spec for
  `/.well-known/jwks.json`.
- `docs/reference/cli.md` — documents `php scripts/pair-with-hub.php`.
- `docs/reference/env-vars.md` — documents `PHLEX_HUB_URL`,
  `PHLEX_HUB_ENROLLMENT_TOKEN`, `PHLEX_HUB_HEARTBEAT_INTERVAL`.

### Changed (Step C.2)

- `src/Server/Core/Application` now starts the hub heartbeat background
  worker automatically when `config/hub-enrollment.json` exists.
- `src/Common\Container\ContainerFactory` now wires `HubServicesProvider`
  into the default provider list.

### Added (Step C.5)

- `Phlex\Hub\HubJwtValidator` — validates JWTs issued by the Phlex Hub
  using the hub's JWKS. Supports Ed25519 signature verification via
  `sodium_crypto_sign_verify_detached`, automatic JWKS caching with TTL,
  and key rotation (refetches JWKS once on unknown `kid`).
- `Phlex\Hub\HubUserClaims` — immutable DTO for extracted hub JWT claims
  (`userId`, `serverId`, `subject`, `issuer`, `expiresAt`, `scope`).
- `Phlex\Hub\JwksCache` — in-memory JWKS cache with TTL support.
- `Phlex\Hub\HttpClientFactory` — factory for creating HTTP clients used
  by `HubJwtValidator` to fetch JWKS (enables testability).
- `Phlex\Server\Http\Middleware\HubJwtMiddleware` — validates hub JWTs on
  routes that support hub-mediated access. Populates `$request->hubUser`
  with `HubUserClaims` on success; returns 401 on invalid/expired tokens.
- `Phlex\Server\Http\Controllers\HubTokenController` — exchanges a hub JWT
  for a server-issued session token via `POST /api/v1/auth/hub-token`.
  Provides backward compatibility for older clients that present a hub
  JWT to get a server session token.
- `Phlex\Server\Http\Request::$hubUser` — new property holding
  `HubUserClaims` when the request was authenticated via hub JWT.
- `config/hub.php` — added `hub_jwks_url` key (`PHLEX_HUB_JWKS_URL`
  env var) for the hub's JWKS endpoint.
- `docs/reference/env-vars.md` — documents `PHLEX_HUB_JWKS_URL`.
- Unit tests: `HubJwtValidatorTest`, `HubUserClaimsTest`,
  `JwksCacheTest`, `HubJwtMiddlewareTest` (18 new tests).

### Changed (Step C.5)

- `Phlex\Common\Container\Providers\HubServicesProvider` now registers
  `HubJwtValidator`, `HubTokenController`, `HubJwtMiddleware`,
  `HttpClientFactory`, and `JwksCache`.
- `Phlex\Server\Core\Application` now registers the
  `POST /api/v1/auth/hub-token` route.

## [0.11.0] — 2026-05-17

### Changed

- Repository moved from `github.com/detain/phlex` to
  `github.com/detain/phlex-server`. The local working directory stays
  `/home/sites/phlex` per the expansion plan; only the `origin` remote
  URL changes. Update your local clone with
  `git remote set-url origin git@github.com:detain/phlex-server.git`.
  The old `detain/phlex` repo is archived (B.4b) with a README pointing
  at the new home.
- Refactored to depend on `detain/phlex-shared:^0.2`. The
  `LifecycleInterface`, manifest DTOs, event DTOs, and `EventNameMap`
  now live in the shared package. Old FQCNs
  (`Phlex\Plugins\Contract\LifecycleInterface`,
  `Phlex\Plugins\Manifest`, `Phlex\Plugins\ManifestType`,
  `Phlex\Plugins\ManifestValidationError`,
  `Phlex\Plugins\EventNameMap`, `Phlex\Common\Events\*`) remain as
  deprecated aliases through 0.11.x; removed in 0.12.0.
- Manifest schema validation extracted to
  `Phlex\Plugins\Manifest\ManifestSchema`.

### Added

- Composer require on `detain/phlex-shared:^0.2.0` via a VCS
  repositories entry.
- `src/Plugins/AliasCompatShim.php` registers the 16 `class_alias`
  entries for the moved classes.
- Three-line interface bridge at
  `src/Plugins/Contract/LifecycleInterface.php` (extends the shared
  interface — `class_alias` doesn't work for interfaces).

- Complete plugin developer documentation
  ([`docs/plugins/developer-guide.md`](docs/plugins/developer-guide.md))
  covering plugin types, manifest, lifecycle, event subscription,
  settings, signing, packaging, local testing, and publishing — plus a
  matching server-internals reference for contributors extending the
  loader ([`docs/dev/plugin-sdk.md`](docs/dev/plugin-sdk.md)). Phase A
  is now functionally complete; the plugin system is ready for
  external authors. `docs/plugins/install-from-catalog.md` rewritten
  to set expectations about the catalog's Phase L delivery; README
  promotes the developer guide and the reference plugin.
- Plugin manifest specification (`docs/plugins/manifest.md`,
  `docs/plugins/manifest.schema.json`) and the
  `Phlex\Plugins\Manifest` value object that parses and validates
  `plugin.json` files. The eleven plugin types from
  `PHLEX_EXPANSION_PLAN.md` §5 are codified as the
  `Phlex\Plugins\ManifestType` enum. No loader yet — see Step A.4.
  Adds `justinrainbow/json-schema:^5.2` as a runtime dependency.
- PSR-11 dependency injection container (PHP-DI). Application services are
  now auto-wired; the legacy ConnectionPool / LoggerFactory statics remain
  for backwards compatibility but are wrapped behind container bindings.
- `phpstan/phpstan` (level 9) and `squizlabs/php_codesniffer` (PSR-12) added
  as require-dev so the documented "minimum bar" is actually enforceable.
  A `phpstan-baseline.neon` absorbs pre-existing errors so new code is held
  to the bar without forcing a repo-wide refactor.
- `docs/dev/architecture-server.md` and `docs/reference/env-vars.md`.
- PSR-14 event dispatcher (Crell\Tukio). Playback, library-scan, and
  auth lifecycle events are now published from `PlaybackController`,
  `MediaScanner`, and `AuthManager`; plugins will be able to subscribe in
  Phase A.4. Twelve typed `readonly` event DTOs ship in
  `src/Common/Events/`. New env var `PHLEX_DEBUG_EVENTS` and `events`
  log channel. Canonical catalog in `docs/dev/event-reference.md`.
- Plugin loader (`Phlex\Plugins\PluginLoader`) with the full
  install / enable / disable / uninstall lifecycle. Plugins can be
  installed from a URL (HTTPS + `file://` by default; HTTP behind
  `PHLEX_PLUGINS_ALLOW_HTTP=1`) or from a local directory; each plugin
  gets its own Composer-resolved `vendor/` tree under
  `var/plugins/<name>/`. The lifecycle contract lives in
  `Phlex\Plugins\Contract\LifecycleInterface` (temporary home — moves to
  `Phlex\Shared\Plugin` in B.1). New table `plugins` (migration
  `migrations/003_plugins.sql`). New `plugins` log channel and config
  key. New env vars: `PHLEX_PLUGINS_ALLOW_HTTP`,
  `PHLEX_PLUGINS_REQUIRE_SIGNATURE`, `PHLEX_PLUGINS_COMPOSER_TIMEOUT`.
  Adds `symfony/process:^7.0`.
  See `docs/plugins/developer-guide.md` for the lifecycle diagram and
  a sample `LifecycleInterface` implementation.
- Plugin admin UI at `/admin/plugins` and JSON API under
  `/api/v1/admin/plugins/*` (list / install / enable / disable /
  uninstall). All routes gated by a new `AdminMiddleware` that reads
  the new `users.is_admin` flag (migration `004_admin_user_flag.sql`).
  The first user registered after the migration is auto-promoted to
  admin; subsequent users default to `is_admin = 0`. Adds runtime
  Composer dep `smarty/smarty:^4.0` (already used at runtime; now
  declared). OpenAPI spec at `docs/reference/api/admin-plugins.yaml`;
  end-user docs at `docs/plugins/install-from-url.md`. Editable
  settings UI deferred to a later phase — A.5 renders settings
  read-only with `secret: true` fields masked.
- Reference plugin
  [`phlex-plugin-example`](https://github.com/detain/phlex-plugin-example)
  — the first community-shaped Phlex plugin, published as its own
  public GitHub repo. Implements
  `Phlex\Plugins\Contract\LifecycleInterface` as a
  `metadata-provider` that returns `['title' => 'Hello, World']` for a
  fixed fixture path, and ships unsigned by design as the canonical
  fork-as-starter template for plugin authors. Installable through the
  A.5 admin UI by pasting
  `https://raw.githubusercontent.com/detain/phlex-plugin-example/main/plugin.json`
  into **Install from URL**. Server-side wiring: new fixture
  `tests/fixtures/plugins/example-manifest.json` mirrors the published
  manifest so the loader's URL-install test can use a `file://` URL,
  and `docs/plugins/install-from-url.md` /
  `docs/plugins/trusted-plugin-list.md` now reference the live
  example URL.

### Deprecated

- `Phlex\Server\Core\Application::getInstance()` — resolve services from
  the PSR-11 container instead. Slated for removal in Phase B.
