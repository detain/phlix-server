<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

/**
 * Maps a `media_items.type` ENUM member to its UPnP AV `upnp:class`.
 *
 * Single source of truth for {@see ContentDirectory::getUpnpClass()} and
 * {@see LibraryBridge::determineUpnpClass()}, which previously carried two
 * separate `match` expressions over the same column — both incomplete, and
 * incomplete in different ways.
 *
 * Every returned value is a class defined by the UPnP AV ContentDirectory
 * spec (ContentDirectory:1 Appendix B). Renderers are entitled to reject an
 * object whose class they do not recognise, which is what an invented class
 * like `object.item.book` (or the old fallback's `object.item.unknown`) will
 * hit on stricter stacks.
 *
 * @package Phlix\Dlna
 * @since 0.12.0
 */
final class UpnpClassMap
{
    /**
     * Generic container class, used for DIDL container nodes that do not
     * correspond to a `media_items` row (library roots, storage folders).
     */
    public const CONTAINER = 'object.container';

    /**
     * Fallback for a type this map does not know. Deliberately the generic
     * `object.item` — a valid, universally-understood base class — rather
     * than an invented subclass built by string concatenation.
     */
    public const FALLBACK = 'object.item';

    /**
     * EXHAUSTIVE map of the `media_items.type` ENUM to UPnP classes.
     *
     * Every member of {@see \Phlix\Media\MediaItemType::ALL} MUST appear here as
     * a key; the key set is pinned against that constant (and against the ENUM
     * parsed out of the migration SQL) by
     * {@see \Phlix\Tests\Unit\Media\MediaItemTypeDriftTest}.
     *
     * @var array<string, string>
     */
    public const TYPE_TO_CLASS = [
        // Video items.
        'movie' => 'object.item.videoItem.movie',
        'video' => 'object.item.videoItem.movie',
        'series' => 'object.item.videoItem.videoBroadcast',
        'season' => 'object.item.videoItem.videoBroadcast',
        'episode' => 'object.item.videoItem.videoBroadcast',
        // Audio items.
        'track' => 'object.item.audioItem.musicTrack',
        'music' => 'object.item.audioItem.musicTrack',
        'audio' => 'object.item.audioItem.musicTrack',
        // `audioBook` is a first-class UPnP audioItem subclass — an audiobook
        // is not a musicTrack, and renderers use the distinction for shelving.
        'audiobook' => 'object.item.audioItem.audioBook',
        // Music grouping objects are genuine UPnP *containers*, not items.
        'album' => 'object.container.album.musicAlbum',
        'artist' => 'object.container.person.musicArtist',
        // Stills.
        'photo' => 'object.item.imageItem.photo',
        // Text. UPnP has no ebook subclass; `object.item.textItem` is the
        // spec-defined class for non-AV document content.
        'book' => 'object.item.textItem',
    ];

    /**
     * Non-ENUM `type` aliases that reach the DLNA layer.
     *
     * `container`/`folder` are DIDL node types rather than media types (the
     * DIDL builder sets them directly); `tvshow` is a legacy alias kept so
     * older callers keep resolving. Note there is deliberately no `image`
     * entry — the scanner emits `photo`, and the old `'image', 'photo' =>`
     * arm was dead.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'container' => self::CONTAINER,
        'folder' => self::CONTAINER,
        'tvshow' => 'object.item.videoItem.videoBroadcast',
    ];

    /**
     * Resolve the UPnP class for a `media_items.type` (or DIDL node type).
     *
     * @param string $type Media item type
     *
     * @return string A spec-defined UPnP class; {@see FALLBACK} when unknown
     *
     * @since 0.12.0
     */
    public static function forType(string $type): string
    {
        return self::TYPE_TO_CLASS[$type]
            ?? self::ALIASES[$type]
            ?? self::FALLBACK;
    }

    /**
     * Whether a UPnP class denotes a container rather than an item.
     *
     * DIDL-Lite requires an object whose class is `object.container…` to be
     * serialised as a `<container>` element; emitting one inside `<item>` is
     * malformed.
     *
     * @param string $upnpClass Resolved UPnP class
     *
     * @return bool True when the class is a container class
     *
     * @since 0.12.0
     */
    public static function isContainerClass(string $upnpClass): bool
    {
        return str_starts_with($upnpClass, self::CONTAINER);
    }
}
