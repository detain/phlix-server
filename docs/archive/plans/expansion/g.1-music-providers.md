# Step G.1 — MusicBrainz + AudioDB metadata providers

**Phase:** G (Music / Photos / Books / Audiobooks)
**Step:** G.1
**Depends on:** A.4
**Review:** Yes — see `g.1-music-providers-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement two in-core metadata providers — MusicBrainz and AudioDB — following
the existing plugin-shaped provider pattern, so that when a user adds a music
library the server can look up artist, album, and track metadata without
requiring an external plugin install. Both providers are shipped in-core
as examples of the `metadata-provider` plugin type.

## 2. Context (what already exists)

- `src/Media/Metadata/MetadataProviderInterface.php` — defines
  `search()`, `getAlbum()`, `getArtist()`, `getTrack()` methods plus
  `supports()` that returns a media type mask.
- `src/Media/Metadata/MetadataManager.php` — orchestration layer with
  24-hour cache (`metadata_refreshed_at`); priority stack:
  `tmdb → local` for movies, `tvdb → fanart → local` for series.
  Will be extended for music types.
- `src/Media/Metadata/TmdbProvider.php` — reference implementation of a
  metadata provider; used as the template for music providers.
- `src/Media/Metadata/MetadataHttpClient.php` — shared HTTP client with
  rate limiting and cache headers; all providers use this.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Music providers (MusicBrainz, AudioDB)"
  is **Missing**.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase G table — G.1 is the first music
  provider step.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Metadata/Provider/MusicBrainzProvider.php` — MusicBrainz
  API v1 wrapper:

  ```php
  class MusicBrainzProvider implements MetadataProviderInterface
  {
      public function __construct(
          private readonly MetadataHttpClient $http,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function supports(string $media_type): bool {}
      // Returns self::MEDIA_TYPE_ALBUM | self::MEDIA_TYPE_ARTIST | self::MEDIA_TYPE_TRACK

      public function search(string $query, int $limit = 20): array {}
      // Returns array of {id, name, type, year, score}

      public function getArtist(string $mbid): ?array {}
      // {mbid, name, sort_name, country, disambiguation, tags, biography}

      public function getAlbum(string $mbid): ?array {}
      // {mbid, title, artist_mbid, artist_name, year, genre, tracks: [{mbid, title, duration, position}]}

      public function getTrack(string $mbid): ?array {}
      // {mbid, title, duration, artist_mbid, artist_name, album_mbid, position}
  }
  ```

- `src/Media/Metadata/Provider/AudioDbProvider.php` — AudioDB API v2
  wrapper:

  ```php
  class AudioDbProvider implements MetadataProviderInterface
  {
      public function __construct(
          private readonly MetadataHttpClient $http,
          private readonly string $api_key,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function supports(string $media_type): bool {}
      // Returns self::MEDIA_TYPE_ALBUM | self::MEDIA_TYPE_ARTIST | self::MEDIA_TYPE_TRACK

      public function search(string $query, int $limit = 20): array {}
      // Returns array of {id, name, type, year, thumb}

      public function getArtist(string $audiodb_id): ?array {}
      // {id, name, country, genre, biography, thumb, fanart}

      public function getAlbum(string $audiodb_id): ?array {}
      // {id, title, artist_id, artist_name, year, genre, thumb, tracks}

      public function getTrack(string $audiodb_id): ?array {}
      // {id, title, duration, artist_name, album_name, position}
  }
  ```

- `src/Media/Metadata/Provider/MusicMetadataProviderTrait.php` — shared
  logic (rate-limit backoff, user-agent, mb-user-agent header for
  MusicBrainz's requirement):

  ```php
  trait MusicMetadataProviderTrait
  {
      protected function rateLimit(float $seconds): void {}
      protected function mbHeaders(): array {}  // MusicBrainz required headers
  }
  ```

- `config/music_providers.php` — default config:

  ```php
  return [
      'musicbrainz' => [
          'enabled'    => true,
          'rate_limit' => 1.0,        // seconds between requests (MusicBrainz requirement)
          'user_agent' => 'Phlex/1.0 (https://phlex.media)',
          'use_fallback' => true,       // fall back to AudioDB if MusicBrainz fails
      ],
      'audiodb' => [
          'enabled'  => true,
          'api_key'   => '',           // user supplies their own key
          'rate_limit' => 0.5,
      ],
  ];
  ```

- `tests/Unit/Media/Metadata/Provider/MusicBrainzProviderTest.php`
- `tests/Unit/Media/Metadata/Provider/AudioDbProviderTest.php`

#### Documentation

- `docs/developers/music-providers.md` — new doc explaining provider
  architecture, config keys, how to add a third music provider, and
  MusicBrainz's rate-limit + user-agent requirements.

### Modify

- `src/Media/Metadata/MetadataManager.php` — add
  `MEDIA_TYPE_ALBUM | MEDIA_TYPE_ARTIST | MEDIA_TYPE_TRACK` constants;
  register music providers in the priority stack; extend
  `fetchMetadata()` to handle music types.
- `src/Media/Metadata/MetadataProviderInterface.php` — add the three
  constants above; add `getSourceName()` method returning `'musicbrainz'`
  or `'audiodb'`.
- `config/music_providers.php` — create (new config file).
- `composer.json` — no new runtime dependencies.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b g.1-music-providers`.
2. **Interface first.** Add constants + `getSourceName()` to
   `MetadataProviderInterface`.
3. **Shared trait.** Write `MusicMetadataProviderTrait` with rate-limit
   sleep and MusicBrainz required headers.
4. **MusicBrainz provider.** Full implementation of both search and
   detail methods using the MusicBrainz API (no OAuth; public API).
   Handle 503s with retry-after header; store rate-limit timestamp in
   instance.
5. **AudioDB provider.** Same shape; uses `api_key` from config; less
   strict rate-limiting.
6. **MetadataManager integration.** Register both providers; extend
   `fetchMetadata()` for music types; when `use_fallback` is true and
   MusicBrainz returns no results, fall back to AudioDB automatically.
7. **Config.** Write `config/music_providers.php`.
8. **Tests.** Write both test files per §5. Mock `MetadataHttpClient`
   per project conventions.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `MusicBrainzProviderTest::test_supports_music_types`
2. `MusicBrainzProviderTest::test_search_returns_array`
3. `MusicBrainzProviderTest::test_search_empty_on_error`
4. `MusicBrainzProviderTest::test_get_artist_returns_array`
5. `MusicBrainzProviderTest::test_get_album_returns_array_with_tracks`
6. `MusicBrainzProviderTest::test_get_track_returns_array`
7. `MusicBrainzProviderTest::test_rate_limit_backoff`
8. `MusicBrainzProviderTest::test_mb_headers_includes_ua`
9. `AudioDbProviderTest::test_supports_music_types`
10. `AudioDbProviderTest::test_search_returns_array`
11. `AudioDbProviderTest::test_get_artist_returns_array`
12. `AudioDbProviderTest::test_get_album_returns_array`
13. `AudioDbProviderTest::test_rate_limit_applied`

**Coverage target:** `MusicBrainzProvider` ≥ 85 %, `AudioDbProvider` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New library type (music/photos/books/audiobooks)"** → deferred to
  G.2 (this step only adds providers, not library wiring).
- **"Anything"** → `docs/developers/music-providers.md` (new) covers
  provider architecture, config keys, MusicBrainz rate-limit rules.
- **"New public class/method"** → all new public classes get PHPDoc
  with `@since 0.13.0`.
- **"User-visible behavior change"** → CHANGELOG entry (providers added;
  no user-visible change until library scanning is wired in G.2).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `MetadataProviderInterface` has `MEDIA_TYPE_ALBUM`,
      `MEDIA_TYPE_ARTIST`, `MEDIA_TYPE_TRACK` constants.
- [ ] `MusicBrainzProvider` implements all interface methods.
- [ ] `MusicBrainzProvider::search()` returns array; handles HTTP errors.
- [ ] `MusicBrainzProvider::getArtist()` / `getAlbum()` / `getTrack()`
      return structured arrays.
- [ ] `AudioDbProvider` implements all interface methods.
- [ ] `AudioDbProvider::search()` returns array; handles missing API key.
- [ ] `MusicMetadataProviderTrait::rateLimit()` applies configurable delay.
- [ ] `MusicMetadataProviderTrait::mbHeaders()` returns required
      MusicBrainz user-agent + content-type headers.
- [ ] `MetadataManager::fetchMetadata()` routes music types to music
      providers; respects fallback chain.
- [ ] `config/music_providers.php` exists with all required keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 13 new tests.
- [ ] Coverage of `MusicBrainzProvider` ≥ 85 %, `AudioDbProvider` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/music-providers.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b g.1-music-providers

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'MusicBrainzProvider|AudioDbProvider'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step G.1: MusicBrainz + AudioDB metadata providers"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step G.1: MusicBrainz + AudioDB metadata providers" \
  --body  "Adds MusicBrainzProvider and AudioDbProvider implementing MetadataProviderInterface, MusicMetadataProviderTrait, config/music_providers.php. Part of Phase G (Step G.1 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'g.1-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `g.1-music-providers-review.md`.

Non-obvious points:
- MusicBrainz requires a `User-Agent` header with contact info and
  enforces a 1-request/second rate limit — both are enforced by
  `MusicMetadataProviderTrait`.
- AudioDB requires a per-user API key; the provider degrades gracefully
  when `api_key` is empty (returns empty results, does not throw).
- Both providers are registered in the same priority stack so
  `MetadataManager::fetchMetadata('track', $id)` tries MusicBrainz first,
  then AudioDB as fallback per `use_fallback` config.
