<?php

/**
 * Subtitle processing configuration.
 *
 * Controls subtitle extraction, burn-in defaults, styling options,
 * and storage paths for the subtitle pipeline.
 *
 * @since 0.11.0
 */

return [
    'enabled' => true,

    // Default language for subtitle selection when multiple tracks exist
    'default_language' => 'en',

    // When true: burn in subtitles unless explicitly disabled
    // When false: prefer soft subtitles (external tracks) for players that support them
    'burn_in_by_default' => false,

    // Directory for extracted subtitle files
    'extract_to_dir' => '/var/subtitles',

    // Root directory for DOWNLOADED external subtitle files (Wave 3 / F3).
    //
    // The on-demand subtitle fetch pipeline ({@see \Phlix\Media\Subtitles\SubtitleFetchService})
    // persists each provider-downloaded subtitle under
    // `<storage_path>/<itemId>/<lang>[.hi].vtt` via
    // {@see \Phlix\Media\Subtitles\SubtitleStorage}, then attaches it as an
    // external `media_streams` subtitle row the player consumes exactly like an
    // embedded track. This path MUST be a systemd ReadWritePath (the unit's
    // `ReadWritePaths=` list) or `ProtectSystem=strict` keeps it read-only and
    // the write fails with "Read-only file system" (the /var/artwork lesson).
    //
    // Operator-overridable via SUBTITLE_STORAGE_PATH so self-hosted installs can
    // point it at a larger/faster volume; the directory is created on demand.
    'storage_path' => getenv('SUBTITLE_STORAGE_PATH') ?: '/var/subtitles',

    // subtitles.provider_priority — ordered list of subtitle SOURCE names
    // (plugin `SubtitleSourceInterface::getName()` values) the fetch pipeline
    // consults FIRST, in this order, before falling back to each source's own
    // `getPriority()` weight. MIRRORS `metadata.provider_priority` in intent.
    //
    // FOLLOW-UP: unlike metadata.provider_priority there is (deliberately) no
    // `subtitles.provider_priority` key in the phlix-shared server-settings JSON
    // schema yet (see phlix-shared 0.42.0 CHANGELOG), so the admin UI does NOT
    // expose it. The value is still read LIVE (config default + any DB override)
    // via SettingsRepository::getEffective('subtitles.provider_priority'); adding
    // the shared-schema key is a phlix-shared follow-up to surface it in the UI.
    'provider_priority' => [
        'opensubtitles',
    ],

    // Default styling for subtitle burn-in
    'style' => [
        'font_name' => 'Arial',
        'font_size' => 24,
        'primary_color' => '&H00FFFFFF', // ARGB hex (white)
        'outline_color' => '&H00000000',  // ARGB hex (black outline)
        'outline_thickness' => 2,
        'position' => 'bottom',           // 'top' | 'bottom' | 'absolute'
        'margin' => 10,
    ],
];
