<?php

/**
 * Filename → metadata matching configuration.
 *
 * `noise_suffixes` is the list of trailing "edition"/release phrases peeled off
 * a parsed title before it is used as a TMDB/IMDb lookup query (e.g. "Directors
 * Cut", "UNCUT & UNRATED", "YIFY", "DC"). The list is ordered LONGEST-FIRST so
 * multi-word phrases match before their shorter prefixes; each entry is matched
 * end-anchored, case-insensitively, on a word boundary, after optional ` -._`
 * separators.
 *
 * This is the in-code default and the single source of truth mirrored by
 * {@see \Phlix\Media\Metadata\TitleSuffixStripper::NOISE_SUFFIXES}. Admins may
 * extend the list via the `matching.noise_suffixes` server setting; an empty or
 * absent override falls back to these defaults (it never blanks the list).
 *
 * @since 0.22.0
 */

return [
    // Ordered LONGEST-FIRST. Keep in sync with
    // Phlix\Media\Metadata\TitleSuffixStripper::NOISE_SUFFIXES.
    'noise_suffixes' => [
        'unrated directors cut',
        'uncut & unrated',
        'alternate ending',
        'extended cut',
        'directors cut',
        "director's cut",
        'theatrical cut',
        'remastered',
        'extended',
        'uncut',
        'yify',
        'dc',
    ],
];
