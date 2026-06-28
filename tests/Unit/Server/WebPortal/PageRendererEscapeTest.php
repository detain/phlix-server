<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebPortal\PageRenderer;

/**
 * S1 — verifies the global Smarty HTML auto-escape policy actually escapes
 * untrusted output (stored + reflected XSS) through the real rendering path,
 * and does NOT double-escape values that previously carried a manual
 * `|escape` modifier (which the template sweep removed).
 */
final class PageRendererEscapeTest extends TestCase
{
    private string $templateDir;
    private string $previousCwd = '';
    private string $compileCwd = '';

    protected function setUp(): void
    {
        $this->templateDir = dirname(__DIR__, 4) . '/public/templates';

        // PageRenderer's Smarty factory does not configure a compile dir (it
        // relies on Smarty's default `./templates_c/`, resolved relative to the
        // current working directory). chdir into a throwaway temp dir so the
        // compiled-template cache lands there and never pollutes the repo.
        $this->previousCwd = (string) getcwd();
        $this->compileCwd = sys_get_temp_dir() . '/phlix_s1_' . bin2hex(random_bytes(6));
        if (!is_dir($this->compileCwd) && !mkdir($this->compileCwd, 0o777, true) && !is_dir($this->compileCwd)) {
            self::fail('Could not create temp compile dir: ' . $this->compileCwd);
        }
        chdir($this->compileCwd);
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== '') {
            chdir($this->previousCwd);
        }
        if ($this->compileCwd !== '' && is_dir($this->compileCwd)) {
            $this->removeDir($this->compileCwd);
        }
    }

    /**
     * Reflected XSS: a `?q=` search term containing a script tag must be
     * HTML-escaped in the rendered search page (and not double-escaped).
     */
    public function testSearchQueryIsHtmlEscaped(): void
    {
        $html = PageRenderer::renderTemplate(
            $this->templateDir,
            'search/index.tpl',
            [
                'current_page' => 'search',
                'user'         => ['display_name' => 'User', 'is_admin' => false],
                'query'        => '<script>alert(1)</script>',
                'results'      => [],
            ],
        );

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        // Must NOT be double-escaped (the old `|escape:'html'` was removed).
        self::assertStringNotContainsString('&amp;lt;', $html);
    }

    /**
     * Stored XSS: a media item whose name is `<script>alert(1)</script>` must
     * be HTML-escaped when rendered through the media card partial.
     */
    public function testStoredMediaItemNameIsHtmlEscaped(): void
    {
        $html = PageRenderer::renderTemplate(
            $this->templateDir,
            'partials/media_card.tpl',
            [
                'item' => [
                    'id'        => 'item-1',
                    'type'      => 'movie',
                    'name'      => '<script>alert(1)</script>',
                    'metadata'  => ['poster_url' => '', 'year' => 2020, 'runtime_ticks' => 0],
                    'user_data' => ['resume_position_ticks' => 0],
                ],
            ],
        );

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('&amp;lt;', $html);
    }

    /**
     * Admin plugins page: a plugin name carrying HTML is escaped (the manual
     * `|escape:'html'` modifiers were removed in favour of auto-escape, so this
     * proves the sweep did not regress the admin surface or double-escape it).
     */
    public function testAdminPluginNameIsHtmlEscapedExactlyOnce(): void
    {
        $html = PageRenderer::renderTemplate(
            $this->templateDir,
            'admin/plugins/index.tpl',
            [
                'current_page' => 'admin_plugins',
                'user'         => ['display_name' => 'Admin', 'is_admin' => true],
                'plugins'      => [[
                    'name'         => '<img src=x onerror=alert(1)>',
                    'version'      => '1.0.0',
                    'type'         => 'metadata',
                    'installed_at' => '2026-01-01',
                    'signed'       => true,
                    'enabled'      => true,
                ]],
            ],
        );

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('&amp;lt;', $html);
    }

    /**
     * The auto-escape policy is set in exactly one place (the factory) and is
     * actually enabled on the Smarty instance the renderer hands out.
     */
    public function testFactoryEnablesHtmlAutoEscape(): void
    {
        $ref = new \ReflectionMethod(PageRenderer::class, 'newSmartyFor');
        $ref->setAccessible(true);
        /** @var \Smarty $smarty */
        $smarty = $ref->invoke(null, $this->templateDir);

        self::assertTrue($smarty->escape_html, 'Factory must enable escape_html (S1).');
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
