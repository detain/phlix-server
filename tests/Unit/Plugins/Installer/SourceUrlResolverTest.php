<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Installer;

use Phlix\Plugins\Installer\SourceUrlResolver;
use PHPUnit\Framework\TestCase;

final class SourceUrlResolverTest extends TestCase
{
    /**
     * @dataProvider githubRepoUrls
     */
    public function test_rewrites_github_repository_urls_to_default_branch_tarball(string $input): void
    {
        self::assertSame(
            'https://github.com/detain/phlix-plugin-anidb/archive/HEAD.tar.gz',
            SourceUrlResolver::normalize($input),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function githubRepoUrls(): array
    {
        return [
            'https root'            => ['https://github.com/detain/phlix-plugin-anidb'],
            'https trailing slash'  => ['https://github.com/detain/phlix-plugin-anidb/'],
            'https .git suffix'     => ['https://github.com/detain/phlix-plugin-anidb.git'],
            'http upgraded'         => ['http://github.com/detain/phlix-plugin-anidb'],
            'scheme-less host'      => ['github.com/detain/phlix-plugin-anidb'],
            'www host'              => ['https://www.github.com/detain/phlix-plugin-anidb'],
            'ssh form'              => ['git@github.com:detain/phlix-plugin-anidb.git'],
            'ssh form no .git'      => ['git@github.com:detain/phlix-plugin-anidb'],
            'surrounding space'     => ['  https://github.com/detain/phlix-plugin-anidb  '],
        ];
    }

    public function test_tree_url_targets_the_named_branch(): void
    {
        self::assertSame(
            'https://github.com/detain/phlix-plugin-anidb/archive/develop.tar.gz',
            SourceUrlResolver::normalize('https://github.com/detain/phlix-plugin-anidb/tree/develop'),
        );
    }

    public function test_tree_url_preserves_slashed_branch_names(): void
    {
        self::assertSame(
            'https://github.com/detain/phlix-plugin-anidb/archive/feature/x.tar.gz',
            SourceUrlResolver::normalize('https://github.com/detain/phlix-plugin-anidb/tree/feature/x'),
        );
    }

    /**
     * @dataProvider passthroughUrls
     */
    public function test_leaves_non_repository_urls_unchanged(string $input): void
    {
        self::assertSame($input, SourceUrlResolver::normalize($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function passthroughUrls(): array
    {
        return [
            'direct tar.gz'        => ['https://example.com/plugins/phlix-plugin-foo.tar.gz'],
            'direct zip'           => ['https://example.com/plugins/phlix-plugin-foo.zip'],
            'direct tgz'           => ['https://cdn.example.com/p.tgz'],
            'json stub'            => ['https://example.com/phlix-plugin-foo.json'],
            'github release asset' => ['https://github.com/detain/phlix-plugin-anidb/releases/download/v1.0.0/plugin.tar.gz'],
            'github raw blob'      => ['https://github.com/detain/phlix-plugin-anidb/blob/master/plugin.json'],
            'github archive ref'   => ['https://github.com/detain/phlix-plugin-anidb/archive/refs/heads/master.tar.gz'],
            'file scheme'          => ['file:///tmp/staged/phlix-plugin-foo.tar.gz'],
            'absolute local path'  => ['/tmp/staged/phlix-plugin-foo.tar.gz'],
            'non-github host'      => ['https://gitlab.com/detain/phlix-plugin-foo'],
            'unknown sub-resource' => ['https://github.com/detain/phlix-plugin-anidb/wiki'],
            'host only'            => ['https://github.com/detain'],
            'empty string'         => [''],
        ];
    }

    public function test_normalisation_is_idempotent(): void
    {
        $once = SourceUrlResolver::normalize('https://github.com/detain/phlix-plugin-anidb');
        self::assertSame($once, SourceUrlResolver::normalize($once));
    }

    public function test_rejects_path_traversal_in_owner_or_repo(): void
    {
        // `..` is rejected as an owner/repo identifier, so the URL is left
        // untouched rather than rewritten into a `.../../...` tarball path.
        $input = 'https://github.com/../phlix-plugin-foo';
        self::assertSame($input, SourceUrlResolver::normalize($input));
    }
}
