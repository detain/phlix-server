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
     * @dataProvider githubRepoUrls
     */
    public function test_rewrites_github_repo_to_raw_plugins_json(string $input): void
    {
        self::assertSame(
            'https://raw.githubusercontent.com/detain/phlix-plugins/HEAD/plugins.json',
            CatalogSourceResolver::normalize($input),
        );
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
