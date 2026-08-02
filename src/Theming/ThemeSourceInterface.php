<?php

/**
 * Phlix media server component: Theming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Theming;

/**
 * First-class contract a theme-providing plugin implements so the host can
 * register its themes without sniffing manifests or `method_exists()`.
 *
 * It is the theming analogue of
 * {@see \Phlix\Shared\Metadata\MetadataSourceInterface} and
 * {@see \Phlix\Shared\Subtitle\SubtitleSourceInterface}: an entry class that
 * implements it is registered into {@see ThemeSourceRegistry} by
 * {@see \Phlix\Plugins\PluginLoader::enable()} and deregistered by
 * {@see \Phlix\Plugins\PluginLoader::disable()} — one interface, one registry,
 * one `instanceof` arm.
 *
 * ## Replaces the manifest `theme` key
 *
 * The predecessor path was {@see ThemeRegistry::registerFromPlugin()}: a
 * declarative `"theme": {"css": "..."}` block read straight out of
 * `plugin.json`, shipping a whole CSS FILE that the host injected into a
 * rendered page. It had no production caller, and a plugin-supplied
 * stylesheet is an unbounded injection surface. This contract narrows the
 * payload to a **token map** the host can validate exhaustively.
 *
 * ## Payload shape
 *
 * {@see providedThemes()} returns raw arrays, not objects, so a plugin can
 * build them from its own JSON without depending on a host value class:
 *
 * ```php
 * [
 *     'id'      => 'acme-noir',        // lowercase slug, unique, not a reserved built-in id
 *     'name'    => 'Acme Noir',        // 1..64 chars, no control characters
 *     'dark'    => true,               // strictly boolean
 *     'extends' => null,               // optional base theme id
 *     'tokens'  => [                   // keys MUST be on ThemeTokenAllowlist
 *         '--bg'      => '#08070a',
 *         '--surface' => '#12111a',
 *         '--accent'  => 'rgba(120, 190, 255, 0.95)',
 *     ],
 * ]
 * ```
 *
 * Every payload is validated by {@see ThemeTokenValidator} before it is
 * registered. Validation is **fail-closed and all-or-nothing**: one bad token
 * rejects the entire source (and, on the enable path, fails the plugin
 * enable) rather than silently dropping a theme.
 *
 * ## Resident-memory contract
 *
 * {@see providedThemes()} is called synchronously on the worker thread during
 * plugin enable, once per worker. It MUST be a cheap, non-blocking accessor —
 * return a literal/precomputed array. No network, no filesystem walk, no
 * `sleep()`; the same item-5c3 rule that governs `onEnable()`.
 *
 * @package Phlix\Theming
 * @since 0.44.0
 */
interface ThemeSourceInterface
{
    /**
     * The canonical, stable name of this theme source.
     *
     * The identity the host keys provenance on: re-registering the same name
     * REPLACES that source's themes (never appends), and deregistering it on
     * plugin-disable removes exactly the themes it contributed. Return a
     * lowercase, slug-style ASCII identifier and keep it constant, e.g.
     * `acme-themes`.
     *
     * Named `themeSourceName()` rather than the peer interfaces' `getName()`
     * so that one entry class can implement several capability contracts at
     * once (a metadata plugin that also ships a theme) without the two
     * identities colliding on a single method.
     *
     * @return string Canonical source name.
     *
     * @since 0.44.0
     */
    public function themeSourceName(): string;

    /**
     * The raw theme payloads this source contributes.
     *
     * Must be cheap and non-blocking — see the class docblock. Return an
     * empty list to contribute nothing (a source that is currently
     * unconfigured, say); that is a valid, leak-free registration.
     *
     * @return list<array<array-key, mixed>> Raw payloads in the shape
     *         documented on this interface; each is validated by the host.
     *
     * @since 0.44.0
     */
    public function providedThemes(): array;
}
