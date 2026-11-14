<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Smarty\Smarty;

/**
 * Review server__copywriter SRV-M1: the newsletter HTML leg said
 * "1 new items added to the library this week". The plain-text leg had
 * already fixed exactly this grammar class (the 0/1 guard in
 * NewsletterGenerator::generatePlainText(), per its M21 comment citing the
 * HTML play-count guard at newsletter.tpl:306), but the new-items label in
 * the HTML leg was left behind by that sweep. These tests render the
 * COMMITTED template directly so the grammar law is pinned at the shipped
 * bytes, not at a mocked pipeline.
 *
 * Review server__secaudit SRV-M6 rides the same harness: poster_url is
 * metadata_json content (a TMDB-supplied string the admin library can
 * influence) interpolated into a quoted src attribute — the template must
 * html-escape it, exactly as it already escapes the alt/name slots.
 *
 * @package Phlix\Tests\Unit\Admin
 */
final class NewsletterGrammarTruthTest extends TestCase
{
    private string $compileDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compileDir = '/tmp/opencode/srv-m1a-smarty-c/emails';
        if (!is_dir($this->compileDir)) {
            mkdir($this->compileDir, 0777, true);
        }
    }

    /**
     * @param array<int, array{name: string, poster_url: string|null, play_count: int}> $topMedia
     */
    private function render(int $newItemsCount, array $topMedia = [], ?string $linksBase = null): string
    {
        if (!is_dir('/tmp/opencode/srv-m1a-smarty-cache')) {
            mkdir('/tmp/opencode/srv-m1a-smarty-cache', 0777, true);
        }

        $smarty = new Smarty();
        $smarty->setTemplateDir(dirname(__DIR__, 3) . '/public/templates');
        $smarty->setCompileDir('/tmp/opencode/srv-m1a-smarty-c');
        $smarty->setCacheDir('/tmp/opencode/srv-m1a-smarty-cache');
        $smarty->assign([
            'week_start' => 'Jan 1, 2026',
            'week_end' => 'Jan 8, 2026',
            'week_watch_time_minutes' => 0,
            'week_watch_time_hours' => 0.0,
            'new_items_count' => $newItemsCount,
            'top_media' => $topMedia,
            'user_id' => 'user-123',
            'year' => 2026,
            'links_base' => $linksBase,
        ]);

        return $smarty->fetch('emails/newsletter.tpl') ?: '';
    }

    public function test_the_committed_template_declines_one_new_item_as_singular(): void
    {
        $html = $this->render(1);

        self::assertStringContainsString(
            'new item added to the library this week',
            $html,
            'a one-item week must read "1 new item added" — the HTML leg must mirror the text leg grammar (SRV-M1).'
        );
        self::assertStringNotContainsString(
            'new items added to the library this week',
            $html,
            'the plural label must not render when the count is exactly one.'
        );
    }

    public function test_the_committed_template_keeps_plural_above_one(): void
    {
        $html = $this->render(2);

        self::assertStringContainsString(
            'new items added to the library this week',
            $html,
            'multi-item weeks keep the plural sentence.'
        );
        self::assertStringNotContainsString(
            '>new item added',
            $html,
            'the singular label must not render for a count above one.'
        );
    }

    public function test_the_committed_template_escapes_poster_url_attributes(): void
    {
        $html = $this->render(0, [[
            'name' => 'Safe Name',
            'poster_url' => 'https://x.test/a.png" onerror="alert(1)',
            'play_count' => 1,
        ]]);

        self::assertStringNotContainsString(
            '" onerror="alert(1)',
            $html,
            'a quote-bearing poster_url must not break out of the src attribute (SRV-M6).'
        );
        self::assertStringContainsString(
            '&quot; onerror=&quot;alert(1)',
            $html,
            'the payload must survive only in escaped form inside the quoted attribute.'
        );
    }

    public function test_the_committed_template_leaves_wellformed_poster_urls_intact(): void
    {
        $html = $this->render(0, [[
            'name' => 'Safe Name',
            'poster_url' => 'https://image.tmdb.org/a.jpg',
            'play_count' => 1,
        ]]);

        self::assertStringContainsString(
            'src="https://image.tmdb.org/a.jpg"',
            $html,
            'escaping must not mangle ordinary scheme/host/path characters.'
        );
    }

    /**
     * SH-014 (review server__fedev #5): links_base is config-derived and
     * resolveLinksBase() only checks the scheme prefix, so a quote-bearing
     * base URL shipped straight into three href attributes unescaped — the
     * first `"` broke out of the attribute and the rest became live markup.
     */
    public function test_the_committed_template_escapes_links_base_inside_href_attributes(): void
    {
        $html = $this->render(0, [], 'https://x.test/" onerror="alert(1)');

        self::assertStringNotContainsString(
            '" onerror="alert(1)/app"',
            $html,
            'a quote-bearing links_base must not break out of the href attributes (fedev #5).'
        );
        self::assertStringContainsString(
            'href="https://x.test/&quot; onerror=&quot;alert(1)/app"',
            $html,
            'the payload must survive only in escaped form inside the quoted attribute.'
        );
        self::assertStringContainsString(
            'href="https://x.test/&quot; onerror=&quot;alert(1)/app/settings"',
            $html,
            'the Preferences leg carries the same escape law.'
        );
    }

    public function test_the_committed_template_leaves_wellformed_links_base_intact(): void
    {
        $html = $this->render(0, [], 'https://phlix.test/base');

        self::assertStringContainsString(
            'href="https://phlix.test/base/app"',
            $html,
            'escaping must not mangle ordinary scheme/host/path characters in links_base.'
        );
    }

    /**
     * SH-014 (review server__fedev #7): the poster placeholder cell prints
     * the item name's first character — it was the only media-derived value
     * interpolated raw while its siblings (poster_url, alt, media-name) all
     * carry |escape:'html'.
     */
    public function test_the_committed_template_escapes_the_poster_placeholder_initial(): void
    {
        $html = $this->render(0, [[
            'name' => '<b>Angle Bracket',
            'poster_url' => null,
            'play_count' => 1,
        ]]);

        $cell = preg_replace('/.*media-poster-placeholder">\s*(.*?)\s*<\/div>.*/s', '$1', $html);

        self::assertSame(
            '&lt;',
            is_string($cell) ? trim($cell) : $cell,
            'the placeholder initial must render escaped, matching every sibling field.'
        );
    }
}
