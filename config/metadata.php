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
 * this fix). MetadataManager additionally defaults `episode`/`artist`/`album`/
 * `track` (media types this file's schema does not cover) from its own
 * fallback — see `MetadataManager::defaultProviderPriority()`.
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
];
