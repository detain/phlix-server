<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Storage\ArtworkDownloadPolicy;
use Phlix\Media\Storage\ArtworkStorage;
use PHPUnit\Framework\TestCase;

/**
 * Consequence tests for `artwork.download_enabled`.
 *
 * Per the settings-program rule, these assert the OBSERVABLE EFFECT of an
 * override — that {@see ArtworkStorage::downloadAndStore()} and
 * {@see ArtworkStorage::downloadAndStoreLogo()} are genuinely NOT CALLED — not
 * that a getter returns the configured value. A test that only checked
 * `downloadsEnabled() === false` would stay green if the guard were deleted
 * from {@see LibraryMetadataMatcher}, which is precisely the half-wired failure
 * this program exists to prevent.
 *
 * The override value used throughout is FALSE, which differs from the shipped
 * default of TRUE, so a matcher left on the literal produces a visibly
 * different result (downloads happen) rather than coincidentally agreeing.
 */
final class ArtworkDownloadPolicyTest extends TestCase
{
    private const TMDB_POSTER = 'https://image.tmdb.org/t/p/w500/poster.jpg';
    private const TMDB_LOGO   = 'https://image.tmdb.org/t/p/original/logo.png';

    // ─────────────────────────────────────────────────────────────────
    // policy behaviour
    // ─────────────────────────────────────────────────────────────────

    /**
     * Build a policy over a settings store returning `$value` for the key.
     */
    private function policyFor(mixed $value): ArtworkDownloadPolicy
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === ArtworkDownloadPolicy::SETTING_KEY ? $value : null
        );

        return new ArtworkDownloadPolicy($repo);
    }

    public function test_without_a_store_downloads_are_enabled(): void
    {
        $this->assertTrue((new ArtworkDownloadPolicy())->downloadsEnabled());
    }

    public function test_the_shipped_default_is_enabled(): void
    {
        // Pinned so turning artwork off by default becomes a deliberate edit.
        $this->assertTrue(ArtworkDownloadPolicy::DEFAULT_ENABLED);
    }

    public function test_an_explicit_false_override_disables_downloads(): void
    {
        $this->assertFalse($this->policyFor(false)->downloadsEnabled());
    }

    public function test_an_explicit_true_override_enables_downloads(): void
    {
        $this->assertTrue($this->policyFor(true)->downloadsEnabled());
    }

    /**
     * Textual spellings a hand-written or restored `server_settings` row can
     * carry must be read as the operator meant them.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function coercibleValues(): array
    {
        return [
            'string zero'      => ['0', false],
            'string false'     => ['false', false],
            'string FALSE'     => ['FALSE', false],
            'string no'        => ['no', false],
            'string off'       => [' off ', false],
            'empty string'     => ['', false],
            'string one'       => ['1', true],
            'string true'      => ['true', true],
            'string yes'       => ['yes', true],
            'int zero'         => [0, false],
            'int one'          => [1, true],
        ];
    }

    /**
     * @dataProvider coercibleValues
     */
    public function test_textual_and_numeric_values_are_coerced(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, $this->policyFor($value)->downloadsEnabled());
    }

    /**
     * Hostile / unparseable input must degrade to ENABLED, never to disabled.
     *
     * Disabling on garbage would present as "artwork silently stopped working"
     * with the admin control still reading `true`. Note `'nope'` in particular:
     * PHP's loose `(bool) 'nope'` is TRUE and `(bool) 'false'` is ALSO true —
     * the coercion is explicit precisely so neither is decided by accident.
     *
     * @return array<string, array{mixed}>
     */
    public static function hostileValues(): array
    {
        return [
            'unrecognised word' => ['nope'],
            'array'             => [['false']],
            'null'              => [null],
            'float'             => [0.0],
            'object'            => [new \stdClass()],
        ];
    }

    /**
     * @dataProvider hostileValues
     */
    public function test_unparseable_values_degrade_to_enabled(mixed $value): void
    {
        $this->assertTrue($this->policyFor($value)->downloadsEnabled());
    }

    public function test_a_throwing_settings_store_degrades_to_enabled(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        $this->assertTrue(
            (new ArtworkDownloadPolicy($repo))->downloadsEnabled(),
            'A settings-store outage must not masquerade as "the operator turned artwork off".'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // consequence: the downloads actually stop
    // ─────────────────────────────────────────────────────────────────

    /**
     * Drive a real movie match through {@see LibraryMetadataMatcher::matchLibrary()}
     * — the public entry point that funnels into `persistMetadata()` and thence
     * to both artwork choke points.
     *
     * @return array<string, array<string, mixed>> Captured metadata_json per item id.
     */
    private function runMovieMatch(
        ArtworkStorage $artwork,
        ?ArtworkDownloadPolicy $policy
    ): array {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'm1', 'type' => 'movie', 'name' => 'The Matrix', 'metadata' => []]],
            []
        );

        $updates = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '603'],
            'poster_url'   => self::TMDB_POSTER,
            'logo_url'     => self::TMDB_LOGO,
            'sources'      => ['tmdb'],
        ]);

        // Positional ctor: items, resolver, seriesResolver, logger, tmdb,
        // noiseSuffixes, libraries, priorityResolver, themeMusic, fuzzyMatcher,
        // artworkStorage, forceRefresh, artworkDownloadPolicy.
        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->createMock(StructuredLogger::class),
            null,
            null,
            null,
            null,
            null,
            null,
            $artwork,
            false,
            $policy
        );
        $matcher->matchLibrary('lib-1');

        return $updates;
    }

    /**
     * THE test. With the override off, neither download method may be invoked,
     * and the persisted metadata must keep the REMOTE provider URLs (the
     * documented "what still works" contract) rather than being blanked.
     */
    public function test_disabled_downloads_never_reach_artwork_storage(): void
    {
        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->expects($this->never())
            ->method('downloadAndStore');
        $artwork->expects($this->never())
            ->method('downloadAndStoreLogo');

        $updates = $this->runMovieMatch($artwork, $this->policyFor(false));

        $this->assertArrayHasKey('m1', $updates);
        $meta = $updates['m1'];

        // The remote URLs survive untouched — the item is still usable, it just
        // is not cached locally.
        $this->assertSame(self::TMDB_POSTER, $meta['poster_url'] ?? null);
        $this->assertSame(self::TMDB_LOGO, $meta['logo_url'] ?? null);

        // And nothing local was written into the metadata.
        $this->assertArrayNotHasKey('poster_srcset', $meta);
        $this->assertArrayNotHasKey('poster_path', $meta);
    }

    /**
     * The discriminating counterpart: the SAME fixture with the override ON
     * does download and does rewrite to local URLs. Without this, a guard that
     * always returned false would pass the test above.
     */
    public function test_enabled_downloads_do_reach_artwork_storage(): void
    {
        $signed = '/api/v1/artwork/m1?size=w500&sig=abc123';

        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->expects($this->once())
            ->method('downloadAndStore')
            ->with('m1', '/poster.jpg')
            ->willReturn(['w185', 'w342', 'w500']);
        $artwork->expects($this->once())
            ->method('downloadAndStoreLogo')
            ->willReturn('/var/artwork/m1/logo.png');
        $artwork->method('srcset')->willReturn('/api/v1/artwork/m1?size=w185 185w');
        $artwork->method('relativePath')->willReturn('/api/v1/artwork/m1?size=w500');
        $artwork->method('url')->willReturn($signed);

        $updates = $this->runMovieMatch($artwork, $this->policyFor(true));

        $meta = $updates['m1'] ?? [];
        $this->assertSame($signed, $meta['poster_url'] ?? null);
        $this->assertSame('/poster.jpg', $meta['poster_path'] ?? null);
    }

    /**
     * A legacy construction that passes no policy at all must behave exactly as
     * it did before the setting existed: downloads happen. This pins the
     * "safe degradation" half of the contract at the enforcement point rather
     * than only on the policy object.
     */
    public function test_a_matcher_built_without_a_policy_still_downloads(): void
    {
        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->expects($this->once())
            ->method('downloadAndStore')
            ->willReturn(['w500']);
        $artwork->method('srcset')->willReturn(null);
        $artwork->method('relativePath')->willReturn(null);
        $artwork->method('downloadAndStoreLogo')->willReturn(null);

        $this->runMovieMatch($artwork, null);
    }

    /**
     * A settings store that throws mid-match must not break matching NOR stop
     * artwork: the match completes and the download still happens.
     */
    public function test_a_throwing_store_does_not_break_the_match(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->expects($this->once())
            ->method('downloadAndStore')
            ->willReturn(['w500']);
        $artwork->method('srcset')->willReturn(null);
        $artwork->method('relativePath')->willReturn(null);
        $artwork->method('downloadAndStoreLogo')->willReturn(null);

        $updates = $this->runMovieMatch($artwork, new ArtworkDownloadPolicy($repo));

        $this->assertArrayHasKey('m1', $updates, 'The match must still persist.');
    }
}
