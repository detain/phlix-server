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
use Smarty;

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
    private function render(int $newItemsCount, array $topMedia = []): string
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
            'links_base' => null,
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
}
