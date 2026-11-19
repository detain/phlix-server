<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Tmdb;

use Phlix\Common\Net\ProviderUrlAllowlist;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Media\Metadata\Tmdb\ImageUrl;
use PHPUnit\Framework\TestCase;

/**
 * Byte-identical pins for the single-sourced TMDB image-URL join (E-07).
 *
 * Every assertion below encodes the exact output one of the five legacy
 * string-concat copies produced before delegation:
 *
 *  - guarded `w185` joins        → TmdbProvider::profileUrl()/logoUrl()
 *  - unguarded `w500`/`original` → TmdbProvider::formatImages()/selectLogo()
 *  - mixed-fold `w500`           → LibraryMetadataMatcher::imageUrl(),
 *                                  SeriesMetadataResolver::imageUrl()
 *  - guarded `w500`              → FuzzyMatcher::imageUrl()
 *  - unguarded `w500`            → Resolution\Builder::tmdbImage() join arm
 *
 * The builder and ProviderUrlAllowlist's validator are the two halves of the
 * same CDN shape; the round-trip test is the drift guard called for by the
 * finding (if TMDB changes hosts, the literal-parity pin fails loudly).
 */
final class ImageUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    public function testBaseIsByteIdenticalToTheLegacyLiterals(): void
    {
        $this->assertSame('https://image.tmdb.org/t/p', ImageUrl::BASE);
    }

    public function testBuildJoinsSizeAndSlashPrefixedPathVerbatim(): void
    {
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/abc123.jpg',
            ImageUrl::build('w500', '/abc123.jpg'),
        );
        $this->assertSame(
            'https://image.tmdb.org/t/p/w185/kiefer.jpg',
            ImageUrl::build('w185', '/kiefer.jpg'),
        );
        $this->assertSame(
            'https://image.tmdb.org/t/p/original/abc123.png',
            ImageUrl::build('original', '/abc123.png'),
        );
    }

    public function testBuildPreservesLegacyUnguardedEmptyPathDanglingEdge(): void
    {
        // Legacy answer at the unguarded sites (formatImages url/url_original,
        // selectLogo, Builder join arm): an empty path yields the size-
        // terminated dangling base, never null. Pinned, not "fixed".
        $this->assertSame('https://image.tmdb.org/t/p/w500', ImageUrl::build('w500', ''));
        $this->assertSame('https://image.tmdb.org/t/p/original', ImageUrl::build('original', ''));
    }

    public function testBuildPreservesLegacyAlreadyAbsoluteUrlConcatGarbage(): void
    {
        // No legacy site detected already-absolute paths; concatenation just
        // ran. The behaviour is pinned here and disclosed in the VO docblock —
        // changing it would change wire output, which this refactor forbids.
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500https://cdn.example.test/a.jpg',
            ImageUrl::build('w500', 'https://cdn.example.test/a.jpg'),
        );
    }

    public function testBuildOrNullMatchesGuardedProfileLogoAndFuzzySites(): void
    {
        $this->assertNull(ImageUrl::buildOrNull('w185', null));
        $this->assertNull(ImageUrl::buildOrNull('w185', ''));
        $this->assertSame(
            'https://image.tmdb.org/t/p/w185/someone.jpg',
            ImageUrl::buildOrNull('w185', '/someone.jpg'),
        );
        $this->assertNull(ImageUrl::buildOrNull('w500', null));
        $this->assertNull(ImageUrl::buildOrNull('w500', ''));
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/poster.jpg',
            ImageUrl::buildOrNull('w500', '/poster.jpg'),
        );
    }

    public function testBuildMixedOrNullMatchesLegacyNullableStringFoldSites(): void
    {
        // LibraryMetadataMatcher/SeriesMetadataResolver folded mixed through
        // MetadataValue::asNullableString() before joining: null/''/arrays
        // yield null, while int/float fold to their decimal string — the
        // quirk that made '42' produce a path-less 'w50042' URL. Pinned.
        $this->assertNull(ImageUrl::buildMixedOrNull('w500', null));
        $this->assertNull(ImageUrl::buildMixedOrNull('w500', ''));
        $this->assertNull(ImageUrl::buildMixedOrNull('w500', ['x']));
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/poster.jpg',
            ImageUrl::buildMixedOrNull('w500', '/poster.jpg'),
        );
        $this->assertSame('https://image.tmdb.org/t/p/w50042', ImageUrl::buildMixedOrNull('w500', 42));
        $this->assertSame('https://image.tmdb.org/t/p/w5004.5', ImageUrl::buildMixedOrNull('w500', 4.5));
    }

    public function testBuiltUrlsPassTheProviderUrlAllowlistRoundTrip(): void
    {
        SsrfGuard::setResolver(static fn(string $host): array => ['151.101.4.137']);

        ProviderUrlAllowlist::assertFetchable(ImageUrl::build('w500', '/abc123.jpg'));
        ProviderUrlAllowlist::assertFetchable(ImageUrl::build('w185', '/abc123.jpg'));
        ProviderUrlAllowlist::assertFetchable(ImageUrl::build('original', '/abc123.png'));

        // Literal-parity drift tripwire: the builder host and the validator's
        // allowlisted host must stay the same string.
        $this->assertSame('https://' . ProviderUrlAllowlist::HOSTS[0] . '/t/p', ImageUrl::BASE);
    }
}
