<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Metadata;

/**
 * Canonical catalogue of artwork/image types a library can fetch and store (M5).
 *
 * A single source of truth for the union of image types across every metadata
 * provider (TMDB, TVDB, Fanart.tv, Kodi/local NFO, plugins). Each library carries
 * a per-library selection under `options.image_types` controlling which types the
 * scan/match pipeline is allowed to fetch and persist; {@see isEnabled()} reads
 * that selection (falling back to {@see defaults()} so existing libraries with no
 * stored selection behave sensibly — NO migration required).
 *
 * Storage shape (chosen — documented for the UI/backend contract):
 *   `options.image_types` is a JSON OBJECT (map) of `{type: bool}` — e.g.
 *   `{"poster": true, "backdrop": true, "logo": false, ...}`. A `true` value
 *   enables the type; `false` (or absence) disables it. {@see normalize()}
 *   validates a caller-supplied selection against {@see all()} and drops unknown
 *   keys; {@see enabledFromMap()} derives the enabled list from such a map, and
 *   {@see isEnabled()} answers the per-type question with a defaults fallback.
 *
 * The map shape (over a bare enabled-list) is deliberate: it lets the UI persist
 * an EXPLICIT off state (a type the admin unchecked) distinctly from a type that
 * was never in the catalogue when the library was created — a newly-added type is
 * absent from an older map, so it falls back to its {@see defaults()} state rather
 * than being silently treated as disabled.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Canonical image-type catalogue + per-library enablement helpers
 * @see \Phlix\Media\Metadata\LibraryMetadataMatcher Enforces the selection on the flat poster/backdrop keys.
 * @see \Phlix\Media\Metadata\MetadataManager Enforces the selection on the per-provider image blobs.
 * @since 0.26.0
 */
final class ImageType
{
    /** Portrait cover art (a.k.a. cover). Primary card/grid artwork. */
    public const POSTER = 'poster';

    /** Wide background art (a.k.a. fanart / background). Full-bleed page background. */
    public const BACKDROP = 'backdrop';

    /** Transparent title logo (a.k.a. clearlogo). */
    public const LOGO = 'logo';

    /** Wide title banner. */
    public const BANNER = 'banner';

    /** Landscape thumbnail. */
    public const THUMB = 'thumb';

    /** Transparent character/clear art overlay. */
    public const CLEARART = 'clearart';

    /** Disc/CD art. */
    public const DISC = 'disc';

    /** Per-season portrait poster. */
    public const SEASON_POSTER = 'season_poster';

    /** Per-season landscape thumbnail. */
    public const SEASON_THUMB = 'season_thumb';

    /** Per-episode still frame. */
    public const EPISODE_STILL = 'episode_still';

    /** Per-character art. */
    public const CHARACTER_ART = 'character_art';

    /** Per-person profile photo. */
    public const PERSON_PROFILE = 'person_profile';

    /**
     * Human-readable label for each canonical type, keyed by the const value.
     * Surfaced in the library payload so the UI (U5) can render a labelled
     * checkbox per type without duplicating this vocabulary.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::POSTER => 'Poster',
        self::BACKDROP => 'Backdrop',
        self::LOGO => 'Logo',
        self::BANNER => 'Banner',
        self::THUMB => 'Thumbnail',
        self::CLEARART => 'Clear Art',
        self::DISC => 'Disc',
        self::SEASON_POSTER => 'Season Poster',
        self::SEASON_THUMB => 'Season Thumbnail',
        self::EPISODE_STILL => 'Episode Still',
        self::CHARACTER_ART => 'Character Art',
        self::PERSON_PROFILE => 'Person Profile',
    ];

    /**
     * Which providers can feed each canonical type (best-effort, from the M1
     * provider audit). Cheap metadata for the UI so it can hint "who supplies
     * this"; NOT used for enforcement. Order is not significant.
     *
     * @var array<string, list<string>>
     */
    private const PROVIDERS = [
        self::POSTER => ['tmdb', 'tvdb', 'fanart', 'local'],
        self::BACKDROP => ['tmdb', 'fanart', 'local'],
        self::LOGO => ['tmdb', 'fanart', 'local'],
        self::BANNER => ['tvdb', 'fanart', 'local'],
        self::THUMB => ['fanart', 'local'],
        self::CLEARART => ['fanart', 'local'],
        self::DISC => ['fanart', 'local'],
        self::SEASON_POSTER => ['tvdb', 'fanart', 'local'],
        self::SEASON_THUMB => ['tvdb', 'fanart', 'local'],
        self::EPISODE_STILL => ['tmdb', 'tvdb', 'local'],
        self::CHARACTER_ART => ['fanart', 'local'],
        self::PERSON_PROFILE => ['tmdb', 'local'],
    ];

    /**
     * Types enabled by default (when a library has no stored selection).
     *
     * The commonly-useful set: the artwork that materially improves the browse
     * and detail experience for most libraries. The remainder
     * ({@see CLEARART}, {@see DISC}, {@see SEASON_THUMB}, {@see CHARACTER_ART},
     * {@see PERSON_PROFILE}) are niche/Kodi-skin-oriented and default OFF to
     * avoid fetching artwork most deployments never render.
     *
     * @var list<string>
     */
    private const DEFAULTS = [
        self::POSTER,
        self::BACKDROP,
        self::LOGO,
        self::BANNER,
        self::THUMB,
        self::SEASON_POSTER,
        self::EPISODE_STILL,
    ];

    /**
     * Every canonical image type, in a stable catalogue order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * The image types enabled by default (used when a library has no stored
     * selection, so existing libraries need no migration).
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Whether a canonical type is enabled by default.
     */
    public static function isDefault(string $type): bool
    {
        return in_array($type, self::DEFAULTS, true);
    }

    /**
     * Whether a string is one of the canonical image types.
     */
    public static function isKnown(string $type): bool
    {
        return array_key_exists($type, self::LABELS);
    }

    /**
     * Human-readable label for a canonical type (the raw type when unknown).
     */
    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /**
     * Providers that can feed a canonical type (empty for an unknown type).
     *
     * @return list<string>
     */
    public static function providersFor(string $type): array
    {
        return self::PROVIDERS[$type] ?? [];
    }

    /**
     * The full catalogue of available types for the library API payload (U5):
     * one entry per canonical type with its label, default state, and feeding
     * providers so the UI can render every checkbox.
     *
     * @return list<array{type: string, label: string, default: bool, providers: list<string>}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::all() as $type) {
            $out[] = [
                'type' => $type,
                'label' => self::label($type),
                'default' => self::isDefault($type),
                'providers' => self::providersFor($type),
            ];
        }
        return $out;
    }

    /**
     * Validate a caller-supplied selection against {@see all()}.
     *
     * Accepts EITHER shape and returns the canonical enabled LIST:
     *  - a `{type: bool}` map — enabled = keys whose (loosely-truthy) value is on;
     *  - a `list<string>` of enabled type names.
     * Unknown/blank types are dropped; the result is de-duplicated and returned
     * in the stable catalogue order of {@see all()}.
     *
     * @param array<int|string, mixed> $selected Raw selection (map or list).
     *
     * @return list<string> Canonical enabled types (catalogue-ordered, deduped).
     */
    public static function normalize(array $selected): array
    {
        $enabled = [];
        foreach ($selected as $key => $value) {
            if (is_string($key)) {
                // Map shape: {type: bool}. The key is the type; enable on truthy.
                if (self::isKnown($key) && self::truthy($value)) {
                    $enabled[$key] = true;
                }
                continue;
            }
            // List shape: [type, type, ...]. The value is the type name.
            if (is_string($value) && self::isKnown($value)) {
                $enabled[$value] = true;
            }
        }

        // Return in the stable catalogue order, de-duplicated.
        $out = [];
        foreach (self::all() as $type) {
            if (isset($enabled[$type])) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Build the canonical `options.image_types` MAP (`{type: bool}` for every
     * canonical type) from a caller-supplied selection. Every known type is
     * present with an explicit true/false so the stored state is unambiguous;
     * unknown types are dropped by {@see normalize()}.
     *
     * @param array<int|string, mixed> $selected Raw selection (map or list).
     *
     * @return array<string, bool> `{type: bool}` for every canonical type.
     */
    public static function toStorageMap(array $selected): array
    {
        $enabled = self::normalize($selected);
        $map = [];
        foreach (self::all() as $type) {
            $map[$type] = in_array($type, $enabled, true);
        }
        return $map;
    }

    /**
     * Derive the enabled type list from a decoded `options.image_types` value.
     *
     * @param mixed $imageTypes The decoded `options.image_types` (map or list) or null.
     *
     * @return list<string> Enabled canonical types (catalogue-ordered).
     */
    public static function enabledFrom(mixed $imageTypes): array
    {
        if (!is_array($imageTypes)) {
            return self::defaults();
        }
        return self::normalize($imageTypes);
    }

    /**
     * The enabled types for a library, read from its decoded `options` blob.
     *
     * When `options.image_types` is absent the library falls back to
     * {@see defaults()} (so existing libraries behave sensibly, no migration).
     *
     * @param array<string, mixed> $options Decoded library options blob.
     *
     * @return list<string> Enabled canonical types (catalogue-ordered).
     */
    public static function enabledForOptions(array $options): array
    {
        if (!array_key_exists('image_types', $options)) {
            return self::defaults();
        }
        return self::enabledFrom($options['image_types']);
    }

    /**
     * Whether a given image type is enabled for a library's `options` blob.
     *
     * Reads `options.image_types`; when that key is absent, falls back to
     * {@see defaults()} so existing (un-migrated) libraries enable the sensible
     * default set. An unknown `$type` is never enabled.
     *
     * @param array<string, mixed> $options Decoded library options blob.
     * @param string               $type    Canonical image type to test.
     */
    public static function isEnabled(array $options, string $type): bool
    {
        if (!self::isKnown($type)) {
            return false;
        }
        return in_array($type, self::enabledForOptions($options), true);
    }

    /**
     * Map of the per-provider image-set BLOB KEY (as returned by each
     * {@see MetadataProviderInterface::getImages()} and stored under
     * `metadata_json.images.{provider}`) => the canonical {@see ImageType} const
     * it carries. Covers the TMDB / TVDB / Fanart.tv key vocabularies audited in
     * M1. A blob key NOT in this map is UNMAPPED — {@see filterProviderImages()}
     * passes it through unchanged rather than dropping it (the M5 "pass through
     * unmapped" rule), so a new provider key is never silently lost.
     *
     * @var array<string, string>
     */
    private const PROVIDER_IMAGE_KEY_TYPES = [
        // Posters
        'posters' => self::POSTER,
        'tv_posters' => self::POSTER,
        // Backdrops / fanart / backgrounds
        'backdrops' => self::BACKDROP,
        'show_backdrops' => self::BACKDROP,
        // Logos
        'logos' => self::LOGO,
        'hd_logos' => self::LOGO,
        'tv_logos' => self::LOGO,
        'hd_tv_logos' => self::LOGO,
        // Banners
        'banners' => self::BANNER,
        // Thumbnails
        'thumbs' => self::THUMB,
        'tv_thumbs' => self::THUMB,
        'movie_thumbs' => self::THUMB,
        // Clear art
        'clear_arts' => self::CLEARART,
        'tv_clouds' => self::CLEARART,
        // Per-season art
        'season_posters' => self::SEASON_POSTER,
        'season_backdrops' => self::SEASON_POSTER,
        'season_thumbs' => self::SEASON_THUMB,
        // Per-episode stills
        'episode_stills' => self::EPISODE_STILL,
        'stills' => self::EPISODE_STILL,
    ];

    /**
     * The canonical {@see ImageType} a per-provider image-set blob key carries,
     * or null when the key is unmapped (unknown vocabulary — pass through).
     */
    public static function typeForProviderKey(string $providerKey): ?string
    {
        return self::PROVIDER_IMAGE_KEY_TYPES[$providerKey] ?? null;
    }

    /**
     * Filter a per-provider image-set blob (the `{key: images[]}` map a provider
     * returns from `getImages()`) to the enabled image types (M5).
     *
     * A blob key whose canonical type is DISABLED is dropped; a key with no
     * canonical mapping (unknown vocabulary) is KEPT (pass through unmapped). The
     * value shape is preserved untouched.
     *
     * @param array<string, mixed> $images  The provider image-set blob.
     * @param list<string>         $enabled Enabled canonical image types.
     *
     * @return array<string, mixed> The blob with disabled mapped keys removed.
     */
    public static function filterProviderImages(array $images, array $enabled): array
    {
        $out = [];
        foreach ($images as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $type = self::typeForProviderKey($key);
            // Unmapped keys pass through; mapped keys only when enabled.
            if ($type === null || in_array($type, $enabled, true)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Loosely-typed truthiness for a map value (bool, int 1, or the strings
     * "1"/"true"/"yes"/"on"). Everything else is false.
     */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }
}
