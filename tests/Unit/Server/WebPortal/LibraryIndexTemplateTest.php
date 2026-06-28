<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebPortal\PageRenderer;

/**
 * Regression for the library filter page (`library/index.tpl`).
 *
 * The year-range filters previously used the non-existent Smarty 4 keyword
 * `{for $y=2026 downto 1970}`. Smarty has no `downto` keyword, so compiling the
 * template threw a `SmartyCompilerException` and the entire library filter page
 * 500'd. The fix uses the valid descending form `{for $y=2026 to 1970 step -1}`.
 *
 * This test renders the page through the real {@see PageRenderer} Smarty factory
 * — the same one S1 hardened with `escape_html = true` — so it reflects
 * production, and asserts both that no compile exception is thrown and that the
 * descending year option list (2026 down to 1970) renders.
 */
final class LibraryIndexTemplateTest extends TestCase
{
    private string $templateDir;
    private string $previousCwd = '';
    private string $compileCwd = '';
    /** @var array<string, mixed> */
    private array $previousGet = [];

    protected function setUp(): void
    {
        $this->previousGet = $_GET;
        $this->templateDir = dirname(__DIR__, 4) . '/public/templates';

        // PageRenderer's Smarty factory relies on Smarty's default compile dir
        // (`./templates_c/`) resolved against the cwd; chdir into a throwaway
        // temp dir so compiled templates never pollute the repo.
        $this->previousCwd = (string) getcwd();
        $this->compileCwd = sys_get_temp_dir() . '/phlix_libtpl_' . bin2hex(random_bytes(6));
        if (!is_dir($this->compileCwd) && !mkdir($this->compileCwd, 0o777, true) && !is_dir($this->compileCwd)) {
            self::fail('Could not create temp compile dir: ' . $this->compileCwd);
        }
        chdir($this->compileCwd);
    }

    protected function tearDown(): void
    {
        $_GET = $this->previousGet;
        if ($this->previousCwd !== '') {
            chdir($this->previousCwd);
        }
        if ($this->compileCwd !== '' && is_dir($this->compileCwd)) {
            $this->removeDir($this->compileCwd);
        }
    }

    /**
     * The page compiles + renders without a SmartyCompilerException and emits a
     * descending year option list from 2026 down to 1970.
     */
    public function testLibraryIndexRendersDescendingYearRange(): void
    {
        // The template reads filter values from `$smarty.get.*` (i.e. $_GET).
        // Seed the keys it consults so the render does not emit
        // "Undefined array key" notices for an unfiltered page load.
        $_GET = [
            'search'   => '',
            'genres'   => '',
            'yearFrom' => '',
            'yearTo'   => '',
            'ratings'  => '',
            'sort'     => '',
            'order'    => '',
        ];

        $html = PageRenderer::renderTemplate(
            $this->templateDir,
            'library/index.tpl',
            [
                'current_page' => 'library',
                'user'         => ['display_name' => 'User', 'is_admin' => false],
                'library'      => ['name' => 'All Libraries'],
                'items'        => [],
            ],
        );

        // Both boundary years of the descending {for ... to ... step -1} loop
        // must be present as <option> values.
        self::assertStringContainsString('<option value="2026"', $html);
        self::assertStringContainsString('<option value="1970"', $html);
        // The yearFrom and yearTo selects each emit the full range, so 2026
        // appears at least twice.
        self::assertGreaterThanOrEqual(2, substr_count($html, '<option value="2026"'));
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
