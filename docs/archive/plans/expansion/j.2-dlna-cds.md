# Step J.2 — DLNA ContentDirectory full

**Phase:** J (DLNA / Cast / Discovery)
**Step:** J.2
**Depends on:** J.1
**Review:** Yes — see `j.2-dlna-cds-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Complete the DLNA ContentDirectory Service (CDS) implementation by wiring the existing `ContentDirectory` class to SSDP discovery, connecting it to the real `ItemRepository` (library media), and implementing full Browse/Search against actual media items.

The existing `ContentDirectory` in `src/Dlna/ContentDirectory.php` is a framework with `browse()` / `search()` methods but returns stub data. This step:
- Replaces stub library containers with real library data from `ItemRepository`.
- Implements proper DIDL-Lite XML generation with real media resource URLs (HLS stream URLs).
- Wires the CDS into the SSDP discovery layer so DLNA renderers (TVs) can discover and browse Phlex's media library.
- Adds proper HTTP server endpoints for CDS control and device description.

## 2. Context (what already exists)

Read first, do not modify until §4:

- `src/Dlna/ContentDirectory.php` — existing framework with `browse()`, `search()`, `getScpdXml()`, DIDL generation. Needs real data wiring.
- `src/Dlna/DlnaServer.php` — existing server with SOAP handlers already wired to `ContentDirectory`. Needs SSDP integration.
- `src/Dlna/DlnaDevice.php` — device descriptor (UDN, type, friendly name, icons).
- `src/Dlna/DeviceRegistry.php` — registry for discovered devices.
- `src/Media/Library/ItemRepository.php` — real media item repository with `findById()`, `findByParent()`, `getRecentlyAdded()`, `getItemStreams()`.
- `src/Media/Streaming/HlsStreamer.php` — HLS stream URL generation (used for resource URLs in DIDL).
- `src/Discovery/Ssdp/SsdpDiscovery.php` — from J.1; use to announce the CDS server.
- `config/discovery.php` — from J.1; discovery config.
- `config/server.php` — existing server config.

## 3. Scope — files to create / modify

### Create

#### New classes — CDS HTTP endpoints

- `src/Dlna/CdsControlHandler.php` — HTTP SOAP endpoint for ContentDirectory:
  ```php
  class CdsControlHandler
  {
      public function __construct(
          private readonly ContentDirectory $contentDirectory,
          private readonly DlnaServer $server,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Handle a CDS SOAP POST request. Returns SOAP XML response body. */
      public function handle(string $soapBody): string {}

      /** Extract service name and action from SOAP Envelope. */
      private function parseSoapEnvelope(string $body): ?array {}
  }
  ```

- `src/Dlna/CdsServer.php` — full DLNA MediaServer with HTTP endpoints:
  ```php
  class CdsServer
  {
      public function __construct(
          private readonly DlnaServer $dlnaServer,
          private readonly DiscoveryManager $discoveryManager,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Start the CDS server (announce via SSDP + mDNS). */
      public function start(): void {}

      /** Handle incoming HTTP CDS request. */
      public function handleRequest(string $path, string $method, array $headers, string $body): ?string {}

      /** Get device description XML (/description.xml). */
      public function getDeviceDescriptionXml(): string {}

      /** Get SCPD XML for a service (/scpd/ContentDirectory.xml). */
      public function getScpdXml(string $service): ?string {}

      /** Process CDS control SOAP (/cds/control). */
      public function processControl(string $soapBody): string {}
  }
  ```

#### New classes — library integration

- `src/Dlna/LibraryBridge.php` — bridges `ItemRepository` to `ContentDirectory`:
  ```php
  class LibraryBridge
  {
      public function __construct(
          private readonly ItemRepository $itemRepository,
          private readonly HlsStreamer $hlsStreamer,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Get the root container with real library counts. */
      public function getRootContainers(): array {}

      /** Get children of a container (library, folder, playlist). */
      public function getContainerChildren(string $objectId): array {}

      /** Get a media item as CDS object. */
      public function getMediaObject(string $objectId): ?array {}

      /** Convert an ItemRepository item to CDS DIDL-Lite item. */
      public function itemToCdsObject(array $item): array {}

      /** Get the HLS stream URL for a media item. */
      public function getStreamUrl(string $itemId): string {}
  }
  ```

#### HTTP routes

- `src/Server/Http/Controllers/Dlna/DeviceDescriptionController.php` — serves `/description.xml`:
  ```php
  class DeviceDescriptionController
  {
      public function handle(Request $request, array $params): Response {}
  }
  ```

- `src/Server/Http/Controllers/Dlna/CdsControlController.php` — SOAP CDS control:
  ```php
  class CdsControlController
  {
      public function __construct(
          private readonly CdsServer $cdsServer,
      ) {}

      public function handle(Request $request, array $params): Response {}
  }
  ```

#### New HTTP routes in Router

- `GET /description.xml` → `DeviceDescriptionController`
- `POST /cds/control` → `CdsControlController`
- `GET /scpd/{service}.xml` → SCPD for ContentDirectory, AVTransport, ConnectionManager

#### Tests

- `tests/Unit/Dlna/LibraryBridgeTest.php`
- `tests/Unit/Dlna/CdsControlHandlerTest.php`
- `tests/Unit/Dlna/CdsServerTest.php`

#### Documentation

- `docs/developers/dlna-cds.md` — update existing doc (created J.1) to describe CDS Browse/Search flow and DIDL-Lite format.

### Modify

- `src/Dlna/ContentDirectory.php` — inject `LibraryBridge` to replace stub library containers with real data. Update `browse()`, `search()`, DIDL generation to use real stream URLs.
- `src/Dlna/DlnaServer.php` — remove stub `createDummyItemRepository()`. Accept real `ItemRepository` and `HlsStreamer` via constructor.
- `src/Server/Core/Application.php` — add CDS HTTP routes for `/description.xml`, `/cds/control`, `/scpd/*.xml`.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — add entry: "Added: DLNA ContentDirectory full — browse and search real media library".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch from master (after J.1 merged): `git checkout -b j.2-dlna-cds`.
2. **LibraryBridge.** Create `LibraryBridge` that wraps `ItemRepository` and `HlsStreamer`. `getRootContainers()` returns video/audio/image libraries with correct child counts from DB. `getContainerChildren()` uses `ItemRepository::findByParent()`. `itemToCdsObject()` maps DB fields to CDS object format.
3. **DIDL-Lite stream URLs.** For each media item, `getStreamUrl()` calls `$hlsStreamer->getStreamUrl($item)`. The DIDL-Lite `<res>` element includes `protocolInfo="http-get:*:video/mp4:*"` and the HLS URL.
4. **ContentDirectory wiring.** Remove stub `getLibraryContainers()` and `getLibraryItems()`. Inject `LibraryBridge`. `browse('0')` → root containers; `browse('library-video')` → movies from `ItemRepository::getByType('movie')`.
5. **CDS HTTP handler.** `CdsControlHandler::handle()` parses SOAP body, calls `ContentDirectory::browse()` or `search()`, builds SOAP response. `DlnaServer::processSoapRequest()` already does this — wire it to the CDS HTTP endpoint.
6. **Device description.** `DlnaServer::getDeviceDescriptionXml()` already generates device description XML. Expose it at `/description.xml`. Also add SCPD XML endpoints at `/scpd/{service}.xml`.
7. **HTTP routes.** Register `/description.xml`, `/cds/control`, `/scpd/{service}.xml` in `Application::loadApiRoutes()`.
8. **Tests.** Write three test files covering new classes.
9. **Verification bar.**
10. **Docs + CHANGELOG.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `LibraryBridgeTest::test_get_root_containers_returns_video_and_audio`
2. `LibraryBridgeTest::test_get_container_children_uses_item_repository`
3. `LibraryBridgeTest::test_item_to_cds_object_maps_all_fields`
4. `LibraryBridgeTest::test_get_stream_url_uses_hls_streamer`
5. `CdsControlHandlerTest::test_handle_parses_browse_action`
6. `CdsControlHandlerTest::test_handle_parses_search_action`
7. `CdsControlHandlerTest::test_handle_returns_soap_fault_on_invalid_action`
8. `CdsServerTest::test_get_device_description_xml_is_valid`
9. `CdsServerTest::test_get_scpd_xml_returns_content_directory_scpd`
10. `CdsServerTest::test_process_control_returns_browse_response`

**Coverage target:** `LibraryBridge` ≥ 85 %, `CdsControlHandler` ≥ 85 %, `CdsServer` ≥ 80 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (DLNA renderers can now browse the real media library).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `LibraryBridge` returns real library containers (Video, Audio, Images) from `ItemRepository` with correct child counts.
- [ ] `ContentDirectory::browse('library-video')` returns real movies from `ItemRepository::getByType('movie')`.
- [ ] `ContentDirectory::browse('library-audio')` returns real audio items from `ItemRepository::getByType('audio')`.
- [ ] DIDL-Lite XML includes `<res protocolInfo="http-get:*:video/mp4:*">` with HLS stream URL for each media item.
- [ ] `ContentDirectory::search()` with `dc:title contains "foo"` filters results correctly.
- [ ] `/description.xml` returns valid UPnP device description XML.
- [ ] `/cds/control` handles SOAP Browse and Search actions and returns correct SOAP responses.
- [ ] `/scpd/ContentDirectory.xml` returns the ContentDirectory SCPD XML.
- [ ] `DlnaServer` uses real `ItemRepository` (no stub).
- [ ] `./vendor/bin/phpunit` — green; ≥ 10 new tests.
- [ ] Coverage of `LibraryBridge` ≥ 85 %, `CdsControlHandler` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b j.2-dlna-cds

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'LibraryBridge|CdsControl|CdsServer'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step J.2: DLNA ContentDirectory — browse and search real media library"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step J.2 (DLNA): ContentDirectory full — real library browsing" \
  --body  "Completes DLNA ContentDirectory Service: LibraryBridge connects ItemRepository to ContentDirectory; real DIDL-Lite with HLS stream URLs; HTTP SOAP endpoints for Browse/Search; device description XML. Part of Phase J (Step J.2 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'j.2-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `j.2-dlna-cds-review.md`.

(End of file - total 327 lines)
