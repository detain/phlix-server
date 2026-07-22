<?php

/**
 * Metadata source-priority configuration (Feature 3).
 *
 * `provider_priority` is the per-media-type ORDERED list of metadata sources
 * the resolver walks for each canonical field, taking the FIRST source that
 * supplies a non-empty value ({@see \Phlix\Media\Metadata\Resolution\PriorityFieldResolver}
 * `sourceOrder`). Keys are media types; values are ordered source-name lists.
 *
 * `genres_mode` controls how the `genres` field is combined across sources:
 *  - `'first'` (default): take the genres from the first source in the order
 *    that supplies any (first-non-empty; behavior-preserving for live matching);
 *  - `'union'`: merge the distinct genres from every contributing source.
 *
 * This is the in-code default and the single source of truth that MUST mirror
 * the shared `metadata.provider_priority` / `metadata.genres_mode` schema
 * defaults BYTE-FOR-BYTE (so schema validation and GET defaults agree). Admins
 * may override either via the `metadata.provider_priority` / `metadata.genres_mode`
 * server settings; an absent media type in the override falls back to the
 * defaults below, and `genres_mode` falls back to `'first'`.
 *
 * NOTE: `series` deliberately omits `tvdb` (no TVDB provider is wired for series
 * matching; honoring the 3.3a carry-forward warning). Anime mirrors
 * {@see \Phlix\Media\Metadata\MetadataManager} (`['anidb','myanimelist','tvdb','fanart','local']`).
 *
 * S-F48/SV-4.10: {@see \Phlix\Media\Metadata\MetadataManager} also reads this
 * file's `provider_priority` as the default for its OWN (distinct) per-type
 * priority map — the ordered provider cascade `refreshItemMetadata()` walks,
 * stopping at the first provider that returns a full details blob. That is a
 * genuinely different concern from this file's primary consumer
 * (`PriorityFieldResolver`'s PER-FIELD source blending during matching), but
 * both now trace back to these SAME values rather than MetadataManager
 * hand-maintaining its own competing literal (which had silently diverged:
 * `movie => ['tmdb','local']` / `series => ['tvdb','fanart','local']` prior to
 * this fix). MetadataManager additionally defaults `episode` (a media type
 * this file's schema does not cover) from its own fallback — see
 * `MetadataManager::defaultProviderPriority()`. Music types
 * (`artist`/`album`/`track`) are intentionally NOT defaulted (F4): the native
 * host music path was removed; the event-driven `musicbrainz` plugin owns
 * music enrichment now.
 *
 * CAVEAT: that "same values" equivalence holds only for this file's STATIC
 * DEFAULT. `MetadataManager::defaultProviderPriority()` reads this file via a
 * raw `@include` and never consults `SettingsRepository`, so it does NOT see
 * a live `metadata.provider_priority` admin override — only
 * {@see \Phlix\Media\Metadata\Resolution\PriorityConfig} (built in
 * `MediaServicesProvider`'s DI factory via `SettingsRepository::getOverride()`)
 * honors overrides. The two subsystems diverge the moment an override is set.
 *
 * @since 0.22.0
 */

return [
    // Per-media-type ordered source priority. Keep in sync with the shared
    // server-settings.schema.json `metadata.provider_priority` default.
    'provider_priority' => [
        'movie'  => ['tmdb', 'imdb'],
        'series' => ['tmdb', 'imdb'],
        'anime'  => ['anidb', 'myanimelist', 'tvdb', 'fanart', 'local'],
    ],

    // 'first' (first-non-empty, behavior-preserving) or 'union'.
    'genres_mode' => 'first',

    // Whether a metadata (re)match may overwrite an item that already has
    // resolved metadata. TRUE (default) is the historical unconditional
    // `array_merge($existing, $resolved)` behaviour, so nothing changes at the
    // default. Read LIVE via SettingsRepository — this file is NOT composed into
    // config/server.php, so a boot $appConfig['metadata'] lookup would miss it —
    // and consumed by Phlix\Media\Metadata\MetadataOverwritePolicy at
    // LibraryMetadataMatcher::shouldSkipOverwrite(). Keep in sync with the shared
    // server-settings.schema.json `metadata.overwrite_existing` default.
    'overwrite_existing' => true,

    // F2b — background, throttled plugin-source enrichment. Consumed by
    // MediaServicesProvider to build the SourceRateLimiter + PluginEnrichmentQueue
    // that the library-scan worker's BackgroundEnrichmentSubscriber drains. These
    // are quota-safety knobs, NOT a feature toggle: the automatic trigger is gated
    // purely on whether any plugin metadata source is enabled/registered (RULE 7 —
    // no sources ⇒ nothing is ever enqueued and behaviour equals pre-F2b), so
    // there is no dead on/off key here.
    'background_enrichment' => [
        // Per-source MINIMUM spacing between dispatches, in seconds. Enforces a
        // conservative quota budget so a large library trickles through rather
        // than flooding: omdb 1000/day ⇒ 90s ≈ ~960/day; anidb ban risk ⇒ sparse;
        // myanimelist is friendlier. SourceRateLimiter clamps every value up to a
        // 1s courtesy floor, so these can only make a source SLOWER, never faster.
        'source_intervals' => [
            'omdb' => 90,
            'anidb' => 4,
            'myanimelist' => 2,
        ],
        // Spacing (seconds) for any source not named above.
        'default_interval' => 2,
        // Hard cap on the in-worker pending-item FIFO (resident-memory bound).
        'queue_max_size' => 10000,
        // Queue-level drain spacing (seconds); paces the drain loop against the
        // event loop. The per-source limiter above is the real quota guard.
        'queue_min_interval' => 1,
    ],
];
