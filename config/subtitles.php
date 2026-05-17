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
    'default_language' => 'eng',

    // When true: burn in subtitles unless explicitly disabled
    // When false: prefer soft subtitles (external tracks) for players that support them
    'burn_in_by_default' => false,

    // Directory for extracted subtitle files
    'extract_to_dir' => '/var/subtitles',

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
