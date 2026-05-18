# Step J.2 — DLNA ContentDirectory full: Review Checklist

## Reviewer: run these commands and check every box before merging.

```bash
cd /home/sites/phlex

# ── 1. PHPUnit ──────────────────────────────────────────────
./vendor/bin/phpunit
# MUST be green; ≥ 10 new tests

# ── 2. Coverage ─────────────────────────────────────────────
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'LibraryBridge|CdsControl|CdsServer'
# LibraryBridge ≥ 85%, CdsControlHandler ≥ 85%, CdsServer ≥ 80%

# ── 3. PHPStan level 9 ─────────────────────────────────────
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
# Zero new errors

# ── 4. PHPCS PSR-12 ──────────────────────────────────────────
./vendor/bin/phpcs --standard=PSR12 src/
# Clean

# ── 5. Syntax check ─────────────────────────────────────────
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Empty output

# ── 6. HTTP endpoints ───────────────────────────────────────
# Verify these routes are registered (check Application.php):
grep -c "description.xml" src/Server/Core/Application.php
grep -c "cds/control" src/Server/Core/Application.php
grep -c "scpd" src/Server/Core/Application.php
```

## Acceptance Criteria — verify each:

- [ ] `LibraryBridge` is constructed with `ItemRepository` and `HlsStreamer`.
- [ ] `LibraryBridge::getRootContainers()` returns Video, Audio, Images containers with correct `child_count`.
- [ ] `LibraryBridge::getContainerChildren('library-video')` returns items from `ItemRepository::getByType('movie')`.
- [ ] `LibraryBridge::itemToCdsObject()` maps `name`, `artist`, `album`, `duration`, `thumbnail`, `mime_type` to CDS object fields.
- [ ] `LibraryBridge::getStreamUrl()` calls `$this->hlsStreamer->getStreamUrl($item)` and returns a full HLS URL.
- [ ] `ContentDirectory::browse('0')` returns root containers (not stub data).
- [ ] `ContentDirectory::browse()` DIDL-Lite includes `<res protocolInfo="http-get:*:video/mp4:*">` with HLS URL.
- [ ] `ContentDirectory::search('dc:title contains "foo"')` filters items by title (case-insensitive).
- [ ] `/description.xml` route returns valid UPnP device description XML with correct UDN, device type, services.
- [ ] `/cds/control` route handles SOAP POST with Browse action and returns SOAP BrowseResponse.
- [ ] `/cds/control` route handles SOAP POST with Search action and returns SOAP SearchResponse.
- [ ] `/scpd/ContentDirectory.xml` route returns valid SCPD XML.
- [ ] `DlnaServer` no longer uses `createDummyItemRepository()`.
- [ ] ≥ 10 new unit tests pass.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.
- [ ] CHANGELOG.md has DLNA ContentDirectory entry.

## Non-obvious points to verify:

- DIDL-Lite `<res>` element must include `protocolInfo` attribute (e.g., `http-get:*:video/mp4:DLNA.ORG_PN=AVC_MP4_MP_HD`).
- `ContentDirectory::browse()` with `BrowseFlag=BrowseMetadata` returns a single item with full DIDL-Lite metadata (including resources).
- `ContentDirectory::browse()` with `BrowseFlag=BrowseDirectChildren` returns container's children.
- Root object ID `"0"` returns library containers; `"library-video"`, `"library-audio"`, `"library-images"` return real items.
- Pagination (`StartingIndex`, `RequestedCount`) works correctly — `RequestedCount=0` means return all.
