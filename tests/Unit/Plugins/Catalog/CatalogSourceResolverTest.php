<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use Phlix\Plugins\Catalog\CatalogSourceResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\Catalog\CatalogSourceResolver
 */
final class CatalogSourceResolverTest extends TestCase
{
    /**
     * SV-S2b: the OFFICIAL catalog repo (detain/phlix-plugins) resolves to the
     * configured PINNED ref, never the moving `HEAD` default branch.
     *
     * @dataProvider githubRepoUrls
     */
    public function test_official_repo_resolves_to_pinned_ref_not_head(string $input): void
    {
        $pinned = CatalogSourceResolver::OFFICIAL_PINNED_REF;
        self::assertSame(
            'https://raw.githubusercontent.com/detain/phlix-plugins/' . $pinned . '/plugins.json',
            CatalogSourceResolver::normalize($input),
        );
        // Explicit guard against regression to HEAD.
        self::assertStringNotContainsString('/HEAD/', CatalogSourceResolver::normalize($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function githubRepoUrls(): array
    {
        return [
            'https root'           => ['https://github.com/detain/phlix-plugins'],
            'https trailing slash' => ['https://github.com/detain/phlix-plugins/'],
            'https .git suffix'    => ['https://github.com/detain/phlix-plugins.git'],
            'http upgraded'        => ['http://github.com/detain/phlix-plugins'],
            'scheme-less host'     => ['github.com/detain/phlix-plugins'],
            'www host'             => ['https://www.github.com/detain/phlix-plugins'],
            'ssh form'             => ['git@github.com:detain/phlix-plugins.git'],
            'surrounding space'    => ['  https://github.com/detain/phlix-plugins  '],
        ];
    }

    public function test_operator_added_repo_keeps_head(): void
    {
        // A non-official (operator-added) catalog repo is NOT auto-pinned; it
        // keeps HEAD (its entries are still subject to install-time default-deny).
        self::assertSame(
            'https://raw.githubusercontent.com/someorg/their-catalog/HEAD/plugins.json',
            CatalogSourceResolver::normalize('https://github.com/someorg/their-catalog'),
        );
    }

    public function test_official_pinned_ref_is_overridable_via_env(): void
    {
        putenv(CatalogSourceResolver::PINNED_REF_ENV . '=v9.9.9');
        try {
            self::assertSame('v9.9.9', CatalogSourceResolver::officialPinnedRef());
            self::assertSame(
                'https://raw.githubusercontent.com/detain/phlix-plugins/v9.9.9/plugins.json',
                CatalogSourceResolver::normalize('https://github.com/detain/phlix-plugins'),
            );
        } finally {
            putenv(CatalogSourceResolver::PINNED_REF_ENV);
        }
    }

    /**
     * S27: the `dev` channel resolves the OFFICIAL catalog to the moving
     * `master` branch (via {@see CatalogSourceResolver::refForChannel()}),
     * while `stable`/unknown keeps the audited pinned ref.
     */
    public function test_dev_channel_resolves_official_repo_to_master(): void
    {
        $devRef = CatalogSourceResolver::refForChannel(CatalogSourceResolver::CHANNEL_DEV);
        self::assertSame(CatalogSourceResolver::DEV_REF, $devRef);
        self::assertSame(
            'https://raw.githubusercontent.com/detain/phlix-plugins/master/plugins.json',
            CatalogSourceResolver::normalize('https://github.com/detain/phlix-plugins', 'plugins.json', $devRef),
        );
    }

    public function test_stable_channel_keeps_pinned_ref(): void
    {
        // Stable (and any unknown value) maps to null → the audited pinned ref.
        self::assertNull(CatalogSourceResolver::refForChannel(CatalogSourceResolver::CHANNEL_STABLE));
        self::assertNull(CatalogSourceResolver::refForChannel('bogus'));
        $stableRef = CatalogSourceResolver::refForChannel(CatalogSourceResolver::CHANNEL_STABLE);
        self::assertSame(
            'https://raw.githubusercontent.com/detain/phlix-plugins/'
                . CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json',
            CatalogSourceResolver::normalize('https://github.com/detain/phlix-plugins', 'plugins.json', $stableRef),
        );
    }

    /**
     * S27: precedence is env > setting(channel) > default. Even with the `dev`
     * channel selected, the {@see CatalogSourceResolver::PINNED_REF_ENV} override
     * wins.
     */
    public function test_env_override_beats_the_dev_channel(): void
    {
        putenv(CatalogSourceResolver::PINNED_REF_ENV . '=v9.9.9');
        try {
            $devRef = CatalogSourceResolver::refForChannel(CatalogSourceResolver::CHANNEL_DEV);
            self::assertSame('v9.9.9', CatalogSourceResolver::officialPinnedRef($devRef));
            self::assertSame(
                'https://raw.githubusercontent.com/detain/phlix-plugins/v9.9.9/plugins.json',
                CatalogSourceResolver::normalize('https://github.com/detain/phlix-plugins', 'plugins.json', $devRef),
            );
        } finally {
            putenv(CatalogSourceResolver::PINNED_REF_ENV);
        }
    }

    public function test_channel_does_not_affect_operator_added_repos(): void
    {
        // Non-official repos keep HEAD regardless of the channel ref supplied.
        $devRef = CatalogSourceResolver::refForChannel(CatalogSourceResolver::CHANNEL_DEV);
        self::assertSame(
            'https://raw.githubusercontent.com/someorg/their-catalog/HEAD/plugins.json',
            CatalogSourceResolver::normalize('https://github.com/someorg/their-catalog', 'plugins.json', $devRef),
        );
    }

    public function test_tree_url_targets_the_named_branch(): void
    {
        self::assertSame(
            'https://raw.githubusercontent.com/detain/phlix-plugins/develop/plugins.json',
            CatalogSourceResolver::normalize('https://github.com/detain/phlix-plugins/tree/develop'),
        );
    }

    public function test_tree_url_preserves_slashed_branch_names(): void
    {
        self::assertSame(
            'https://raw.githubusercontent.com/detain/phlix-plugins/feature/x/plugins.json',
            CatalogSourceResolver::normalize('https://github.com/detain/phlix-plugins/tree/feature/x'),
        );
    }

    /**
     * @dataProvider directJsonUrls
     */
    public function test_direct_json_urls_pass_through_unchanged(string $input): void
    {
        self::assertSame(trim($input), CatalogSourceResolver::normalize($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function directJsonUrls(): array
    {
        return [
            'raw github blob'   => ['https://raw.githubusercontent.com/detain/phlix-plugins/HEAD/plugins.json'],
            'self-hosted json'  => ['https://example.com/catalog/plugins.json'],
            'json with space'   => ['  https://example.com/c.json  '],
        ];
    }

    /**
     * @dataProvider unrecognisedUrls
     */
    public function test_unrecognised_urls_pass_through_unchanged(string $input): void
    {
        self::assertSame($input, CatalogSourceResolver::normalize($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unrecognisedUrls(): array
    {
        return [
            'empty'               => [''],
            'gitlab host'         => ['https://gitlab.com/owner/repo'],
            'local path'          => ['/var/lib/phlix/catalog'],
            'owner only'          => ['https://github.com/detain'],
            'release sub-resource' => ['https://github.com/detain/phlix-plugins/releases'],
            'blob sub-resource'   => ['https://github.com/detain/phlix-plugins/blob/main/README.md'],
            'path traversal'      => ['https://github.com/detain/..%2f..'],
        ];
    }
}
