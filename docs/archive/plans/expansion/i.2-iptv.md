# Step I.2 — M3U / XMLTV IPTV tuner

**Phase:** I (Live TV / DVR / IPTV)
**Step:** I.2
**Depends on:** I.1
**Review:** Yes — see `i.2-iptv-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement an IPTV tuner driver that ingests M3U playlists (HTTP-fetched .m3u8
files containing channel URLs) and optional XMLTV guide data (XML format),
making IPTV streams available alongside HDHomeRun/DVB-T tuners in the unified
`LiveTvManager` pipeline.

## 2. Context (what already exists)

- `src/LiveTv/LiveTvManager.php` — after I.1, uses `TunerDriverInterface`.
  I.2 adds an `IptvTunerDriver` that also implements that interface.
- `src/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriver.php` — I.1's driver
  for reference.
- `src/LiveTv/ChannelManager.php` — channel CRUD; `createChannel()` accepts
  `tuner_id`, `service_id`.
- `config/livetv.php` — already has `hdhomerun` key from I.1; will add
  `iptv` key.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase I table — I.2 is the IPTV tuner step.
- `src/Media/Streaming/HlsStreamer.php` — existing HLS streamer; IPTV
  channels may need transcoding if not already HLS.

## 3. Scope — files to create / modify

### Create

#### New classes — IPTV driver

- `src/LiveTv/Tuners/Iptv/IptvDevice.php` — IPTV source descriptor:

  ```php
  class IptvDevice
  {
      public function __construct(
          public readonly string $sourceId,
          public readonly string $name,
          public readonly string $playlistUrl,
          public readonly ?string $epgUrl = null,
          public readonly bool $isEnabled = true,
      ) {}
  }
  ```

- `src/LiveTv/Tuners/Iptv/M3UParser.php` — parses M3U/M3U8 playlist files:

  ```php
  class M3UParser
  {
      /** Parse an M3U playlist from a string. Returns M3UEntry[]. */
      public function parse(string $content): array {}

      /** Fetch and parse an M3U playlist from a URL. */
      public function parseUrl(string $url, int $timeoutSecs = 10): array {}
  }

  class M3UEntry
  {
      public function __construct(
          public readonly string $url,
          public readonly ?string $name = null,
          public readonly ?int $tvgId = null,
          public readonly ?int $tvgChno = null,
          public readonly ?string $group = null,
          public readonly ?string $logo = null,
          public readonly bool $isRadio = false,
      ) {}
  }
  ```

- `src/LiveTv/Tuners/Iptv/XmlTvParser.php` — parses XMLTV (XMLTV-ng) guide data:

  ```php
  class XmlTvParser
  {
      /** Parse an XMLTV file from a string. Returns XmlTvProgramme[]. */
      public function parse(string $xml): array {}

      /** Fetch and parse an XMLTV file from a URL. */
      public function parseUrl(string $url, int $timeoutSecs = 30): array {}
  }

  class XmlTvProgramme
  {
      public function __construct(
          public readonly string $channelId,
          public readonly int $startTime,
          public readonly int $endTime,
          public readonly string $title,
          public readonly ?string $description = null,
          public readonly ?string $category = null,
          public readonly ?string $episodeNum = null,
          public readonly ?string $rating = null,
          public readonly ?int $year = null,
      ) {}
  }
  ```

- `src/LiveTv/Tuners/Iptv/IptvTunerDriver.php` — implements `TunerDriverInterface`:

  ```php
  class IptvTunerDriver implements TunerDriverInterface
  {
      public function __construct(
          private readonly M3UParser $m3uParser,
          private readonly XmlTvParser $xmlTvParser,
          private readonly IptvDevice $device,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function getName(): string { return 'iptv'; }
      public function discoverDevices(): array { return [$this->device]; }
      public function getChannelLineup(IptvDevice $device): array
          { /* parse M3U → channel list */ }
      public function scanChannels(IptvDevice $device): array
          { /* parse M3U and optionally refresh EPG */ }
      public function getStreamUrl(IptvDevice $device, int $channelNumber): string
          { /* return channel URL from parsed M3U */ }
  }
  ```

- `src/LiveTv/Tuners/Iptv/IptvTunerDriverFactory.php`:

  ```php
  final class IptvTunerDriverFactory
  {
      public static function build(
          array $config,
          ?LoggerInterface $logger = null,
      ): IptvTunerDriver {}
  }
  ```

#### Tests

- `tests/unit/LiveTv/Tuners/Iptv/M3UParserTest.php`
- `tests/unit/LiveTv/Tuners/Iptv/XmlTvParserTest.php`
- `tests/unit/LiveTv/Tuners/Iptv/IptvTunerDriverTest.php`

#### Documentation

- `docs/developers/iptv.md` — new doc: M3U format, XMLTV format,
  IPTV configuration keys, EPG ingestion.

### Modify

- `config/livetv.php` — add `iptv` key:
  ```php
  'iptv' => [
      'enabled' => true,
      'sources' => [
          [
              'name' => 'My IPTV',
              'playlist_url' => 'https://example.com/playlist.m3u8',
              'epg_url' => 'https://example.com/epg.xml',
          ],
      ],
  ],
  ```
- `src/LiveTv/LiveTvManager.php` — after I.1 refactor, also inject
  `IptvTunerDriver`; `discoverTuners()` union of hardware + IPTV sources.
  Register `iptv` tuner type in `livetv_tuners` DB table.
- `src/LiveTv/GuideManager.php` — extend `upsertProgram()` to accept
  `xmltv_id` for matching IPTV channel EPG entries.
- `CHANGELOG.md` — add entry: "Added: M3U/XMLTV IPTV tuner driver".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master, branch `i.2-iptv`.
2. **Parser first.** `M3UParser` handles extended M3U tags (`#EXTINF:-1`,
   `tvg-id`, `tvg-name`, `group-title`, `tvg-logo`).
   XMLTV parser handles `<programme>` elements.
3. **Device + driver.** `IptvDevice` holds source config; `IptvTunerDriver`
   implements `TunerDriverInterface` so `LiveTvManager` treats it uniformly.
4. **Factory.** `IptvTunerDriverFactory` reads `config/livetv.php` → builds
   one `IptvDevice` per source, one driver per device.
5. **Channel mapping.** Map M3U `tvg-id` (or `tvg-name`) to channel.
   Each M3U entry becomes a channel in `ChannelManager`.
6. **EPG sync.** `scanChannels()` optionally fetches XMLTV and calls
   `GuideManager::upsertProgram()` per programme.
7. **LiveTvManager.** After I.1, `discoverTuners()` gets both HDHomeRun
   and IPTV devices; the union is persisted.
8. **Tests.** Three test files per §5.
9. **Verification bar.**
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED)

Unit tests (coverage ≥ 85 % on every new class):

1. `M3UParserTest::test_parse_basic_m3u`
2. `M3UParserTest::test_parse_extended_m3u_with_all_tags`
3. `M3UParserTest::test_parse_radio_channel`
4. `M3UParserTest::test_parse_url_fetches_and_parses`
5. `XmlTvParserTest::test_parse_basic_xmltv`
6. `XmlTvParserTest::test_parse_programme_with_all_fields`
7. `XmlTvParserTest::test_parse_url_fetches_and_parses`
8. `XmlTvParserTest::test_parse_handles_empty_xml`
9. `IptvTunerDriverTest::test_get_name_returns_iptv`
10. `IptvTunerDriverTest::test_get_channel_lineup_parses_m3u`
11. `IptvTunerDriverTest::test_get_stream_url_returns_correct_url`

**Coverage target:** `M3UParser` ≥ 85 %, `XmlTvParser` ≥ 85 %,
`IptvTunerDriver` ≥ 80 %.

## 6. Documentation

Matrix rows that apply:
- **"Anything"** → `docs/developers/iptv.md` covers M3U format, XMLTV schema,
  IPTV config keys.
- **"New public class/method"** → all new classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria

- [ ] `M3UParser` parses extended M3U (`#EXTINF`) with all tag fields.
- [ ] `M3UParser::parseUrl()` fetches and parses a remote M3U.
- [ ] `XmlTvParser` parses `<programme>` elements into `XmlTvProgramme[]`.
- [ ] `XmlTvParser::parseUrl()` fetches and parses a remote XMLTV file.
- [ ] `IptvTunerDriver` implements `TunerDriverInterface`.
- [ ] `IptvTunerDriver::getChannelLineup()` returns channel list from M3U.
- [ ] `IptvTunerDriver::getStreamUrl()` returns the M3U entry's URL.
- [ ] `config/livetv.php` has `iptv.sources` array.
- [ ] `LiveTvManager` treats IPTV sources alongside HDHomeRun.
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests.
- [ ] Coverage targets met.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/iptv.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
cd /home/sites/phlex
git status --short && git branch --show-current && git pull --ff-only origin master
git checkout -b i.2-iptv
# ... implement ...
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'M3U|XmlTv|Iptv'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step I.2: M3U/XMLTV IPTV tuner driver"
unset GITHUB_TOKEN
gh pr create \
  --title "Step I.2 (Live TV): M3U/IPTV tuner driver" \
  --body  "Adds IPTV tuner: M3U playlist parser, XMLTV EPG parser, IptvTunerDriver wired into LiveTvManager. Part of Phase I (Step I.2 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
git status --short && git branch --show-current && git log --oneline -1 && git branch --list 'i.2-*'
```
