<?php

/**
 * Phlix media server component: Tmdb.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Tmdb;

use Phlix\Media\Metadata\Dto\MetadataValue;

/**
 * Single-sourced builder for TMDB image CDN URLs (finding E-07).
 *
 * Before this class the `https://image.tmdb.org/t/p` base plus a `/size`
 * join lived in five byte-identical copies (TmdbProvider,
 * LibraryMetadataMatcher, SeriesMetadataResolver, FuzzyMatcher, and the
 * Resolution Builder join arm). Every method here reproduces exactly one
 * legacy shape; none of them "fixes" legacy edge behaviour, because each
 * output is wire-visible and pinned by the consumer suites.
 *
 * Quirks deliberately preserved (see ImageUrlTest for the byte-pins):
 *  - An already-absolute path is concatenated anyway (`...w500https://...`).
 *    No legacy site detected this; guarding it would change wire output.
 *  - {@see build()} on an empty path yields the dangling size-terminated
 *    URL (`https://image.tmdb.org/t/p/w500`) — the legacy answer at the
 *    unguarded formatImages()/selectLogo()/Builder sites.
 *  - {@see buildMixedOrNull()} folds int/float through
 *    MetadataValue::asNullableString(), so 42 becomes `.../w50042` — the
 *    legacy LibraryMetadataMatcher/SeriesMetadataResolver quirk.
 *
 * The builder is the mint side of the pair; ProviderUrlAllowlist is the
 * validation side for the same shape. Their literal parity is guarded by
 * ImageUrlTest::testBuiltUrlsPassTheProviderUrlAllowlistRoundTrip().
 *
 * @author Phlix Development Team
 * @since Wave 5 E-07
 */
final class ImageUrl
{
    /** @var string Shared TMDB image CDN base, byte-identical to every legacy literal. */
    public const BASE = 'https://image.tmdb.org/t/p';

    private function __construct()
    {
    }

    /**
     * Raw join: BASE . '/' . size . path — the unguarded legacy shape used by
     * TmdbProvider::formatImages()/selectLogo() and Resolution\Builder, whose
     * callers guarantee a string path (an empty path still joins, dangling).
     */
    public static function build(string $size, string $path): string
    {
        return self::BASE . '/' . $size . $path;
    }

    /**
     * Guarded join for the `?string` sites: TmdbProvider::profileUrl()
     * (w185), logoUrl() (w185), and FuzzyMatcher::imageUrl() (w500) all
     * returned null for a null or empty path.
     */
    public static function buildOrNull(string $size, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return self::build($size, $path);
    }

    /**
     * Guarded join for the `mixed` sites: LibraryMetadataMatcher::imageUrl()
     * and SeriesMetadataResolver::imageUrl() narrowed through
     * MetadataValue::asNullableString() first, then returned null or joined.
     */
    public static function buildMixedOrNull(string $size, mixed $path): ?string
    {
        return self::buildOrNull($size, MetadataValue::asNullableString($path));
    }
}
