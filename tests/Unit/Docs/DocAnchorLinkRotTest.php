<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Link-rot tripwire: every `quickstart.md#anchor` link in README.md and docs/
 * must resolve to a real heading in docs/user/quickstart.md (GitHub slug
 * rules). A renamed or renumbered heading fails here, naming the dead link.
 */
final class DocAnchorLinkRotTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function test_every_quickstart_anchor_link_resolves_to_a_heading(): void
    {
        $quick = (string) file_get_contents(self::ROOT . '/docs/user/quickstart.md');
        $slugs = self::slugs($quick);
        self::assertNotSame([], $slugs, 'quickstart.md has no headings: the slug extractor or file moved');

        $files = array_merge(
            [self::ROOT . '/README.md'],
            glob(self::ROOT . '/docs/*.md') ?: [],
            glob(self::ROOT . '/docs/*/*.md') ?: []
        );
        $checked = 0;
        $dead = [];
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            if (preg_match_all('/quickstart\.md#([A-Za-z0-9_-]+)/', $text, $m) === 0) {
                continue;
            }
            foreach ($m[1] as $anchor) {
                $checked++;
                if (!in_array($anchor, $slugs, true)) {
                    $dead[] = basename($file) . ' -> quickstart.md#' . $anchor;
                }
            }
        }
        self::assertGreaterThan(0, $checked, 'no quickstart.md# links found: the tripwire is vacuous');
        self::assertSame([], $dead, "Dead quickstart anchors:\n" . implode("\n", $dead));
    }

    /** @return list<string> */
    private static function slugs(string $md): array
    {
        $out = [];
        foreach (preg_split('/\R/', $md) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $line, $h) === 1) {
                $s = strtolower($h[1]);
                $s = (string) preg_replace('/[^a-z0-9 _-]/', '', $s);
                $out[] = str_replace(' ', '-', $s);
            }
        }
        return $out;
    }
}
