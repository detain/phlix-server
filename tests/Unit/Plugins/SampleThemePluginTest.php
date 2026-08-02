<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use Phlix\Plugins\Manifest;
use Phlix\Server\Http\Controllers\ThemesController;
use Phlix\Server\Http\Request;
use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Theming\BuiltInThemes;
use Phlix\Theming\ThemeSourceInterface;
use Phlix\Theming\ThemeSourceRegistry;
use Phlix\Theming\ThemeTokenAllowlist;
use Phlix\Theming\ThemeTokenValidator;
use PHPUnit\Framework\TestCase;

/**
 * The S86 SAMPLE THEME PLUGIN, exercised through the real registration path.
 *
 * The subject is the shippable plugin at
 * `examples/plugins/phlix-plugin-sample-theme/` — a real directory with a real
 * `plugin.json`, not a class declared inside this file. Everything it touches
 * here is production code: the real {@see Manifest} parser + schema, the real
 * {@see ThemeSourceRegistry} (hence the real {@see ThemeTokenValidator}), and
 * the real {@see ThemesController}. Nothing is doubled.
 *
 * That matters because S84 already has a `SampleThemePlugin` declared inside
 * `PluginLoaderThemeSourceRegistryTest`, and a test class implementing the
 * interface proves only that the interface can be implemented. This one proves
 * that the artifact an operator installs, and a plugin author copies, actually
 * works.
 *
 * ## The golden body is a CROSS-REPO contract
 *
 * {@see testTheWireBodyMatchesTheGoldenSharedWithPhlixUi} pins the exact
 * `GET /api/v1/themes` body this plugin produces, and the SAME file is
 * committed in phlix-ui at `src/test/fixtures/themes-with-sample-plugin.golden.json`
 * where the SPA's consumption test reads it. Two repos, one byte-identical
 * artifact: the SPA test cannot pass against a shape this server does not emit,
 * and this test cannot pass against a shape the SPA was not written for. The
 * md5 is asserted explicitly so that changing the plugin fails LOUDLY here with
 * a message naming the sibling file, instead of silently desynchronising the
 * two repos.
 *
 * @covers \Phlix\Theming\ThemeSourceRegistry
 * @covers \Phlix\Server\Http\Controllers\ThemesController
 */
final class SampleThemePluginTest extends TestCase
{
    /** Absolute path to the shipped sample plugin. */
    private const PLUGIN_DIR = __DIR__ . '/../../../examples/plugins/phlix-plugin-sample-theme';

    /** The golden wire body, byte-identical to phlix-ui's copy. */
    private const GOLDEN = __DIR__ . '/../../Fixtures/Theming/themes-with-sample-plugin.golden.json';

    /**
     * Path of the phlix-ui twin, quoted in the failure message. Not resolvable
     * from this repo — naming it is the point.
     */
    private const UI_TWIN = 'phlix-ui/src/test/fixtures/themes-with-sample-plugin.golden.json';

    /** md5 of both copies. Update BOTH files together, then this constant. */
    private const GOLDEN_MD5 = '84656d05d42b6d11a92ac88e2b2a8791';

    /** The two theme ids the sample plugin contributes. */
    private const SAMPLE_IDS = ['sample-dusk', 'sample-dusk-high-contrast'];

    /**
     * Load the shipped entry class and build it, exactly as the loader would.
     *
     * The class is deliberately NOT in phlix-server's autoloader (a plugin's
     * namespace is autoloaded from its own `vendor/autoload.php` after install
     * — see `PluginLoader::enable()`), so the file is required by path here.
     * That require is itself an assertion: a syntax error or a renamed class in
     * the shipped sample turns this whole test class red.
     */
    private function samplePlugin(): object
    {
        require_once self::PLUGIN_DIR . '/src/SampleThemePlugin.php';

        $fqcn = $this->manifest()->entry;
        self::assertTrue(class_exists($fqcn), "Manifest entry class {$fqcn} must exist.");

        /** @var object $instance */
        $instance = new $fqcn();
        return $instance;
    }

    /** The real, on-disk manifest, through the real parser. */
    private function manifest(): Manifest
    {
        $json = file_get_contents(self::PLUGIN_DIR . '/plugin.json');
        self::assertIsString($json, 'The sample plugin must ship a readable plugin.json.');

        return Manifest::fromJson($json);
    }

    /** A registry with the sample plugin registered through the production path. */
    private function registryWithSample(): ThemeSourceRegistry
    {
        $registry = new ThemeSourceRegistry();
        $instance = $this->samplePlugin();
        self::assertInstanceOf(ThemeSourceInterface::class, $instance);
        $registry->register($instance);

        return $registry;
    }

    public function testTheShippedManifestIsValidAndDeclaresAUiTheme(): void
    {
        $manifest = $this->manifest();

        self::assertSame([], $manifest->validate(), 'plugin.json must satisfy the bundled manifest schema.');
        self::assertSame('phlix-plugin-sample-theme', $manifest->name);
        self::assertSame('ui-theme', $manifest->type);
        self::assertSame('Phlix\\PluginSampleTheme\\SampleThemePlugin', $manifest->entry);
    }

    public function testTheEntryClassImplementsBothContractsTheLoaderArmNeeds(): void
    {
        $instance = $this->samplePlugin();

        // PluginLoader::wire() refuses anything that is not a LifecycleInterface
        // BEFORE it ever looks for the theme capability, so both are required
        // for the plugin to be enable-able at all.
        self::assertInstanceOf(LifecycleInterface::class, $instance);
        self::assertInstanceOf(ThemeSourceInterface::class, $instance);
    }

    public function testItRegistersThroughTheRealValidatorWithNoLooseningAnywhere(): void
    {
        $registry = new ThemeSourceRegistry();
        $instance = $this->samplePlugin();
        self::assertInstanceOf(ThemeSourceInterface::class, $instance);

        // register() validates EVERY payload before committing ANY, so this
        // returning both ids is proof both passed the full S84 grammar.
        self::assertSame(self::SAMPLE_IDS, $registry->register($instance));
        self::assertSame(['sample-theme'], $registry->sourceNames());
    }

    public function testEverySampleTokenIsOnTheProductionAllowlistAndInsideTheProductionGrammar(): void
    {
        $registry = $this->registryWithSample();

        $checked = 0;
        foreach ($registry->all() as $id => $theme) {
            foreach ($theme->tokens as $key => $value) {
                // Asserted against the PRODUCTION allowlist/validator, never a
                // copy of the list made in this file.
                self::assertTrue(
                    ThemeTokenAllowlist::allows($key),
                    "Sample theme {$id} sets non-allowlisted token {$key}.",
                );
                self::assertTrue(
                    ThemeTokenValidator::isSafeValue($value),
                    "Sample theme {$id} sets {$key} to a value outside the grammar.",
                );
                $checked++;
            }
        }

        self::assertSame(45, $checked, '37 tokens on sample-dusk + 8 on the high-contrast variant.');
    }

    public function testTheSampleDeliberatelyExercisesBothKindsOfExtendsChain(): void
    {
        $registry = $this->registryWithSample();

        $dusk = $registry->get('sample-dusk');
        self::assertNotNull($dusk);
        // A BUILT-IN base: unresolvable server-side by construction, because the
        // server holds no stylesheet. The SPA layers it via `data-theme`.
        self::assertSame('midnight', $dusk->extends);
        self::assertContains($dusk->extends, BuiltInThemes::IDS);

        $contrast = $registry->get('sample-dusk-high-contrast');
        self::assertNotNull($contrast);
        // A PLUGIN base: resolvable, but only from the LIST response.
        self::assertSame('sample-dusk', $contrast->extends);
        self::assertNotContains($contrast->extends, BuiltInThemes::IDS);
    }

    public function testTheHighContrastVariantOnlyOverridesInkSoItsBackgroundMustComeFromItsBase(): void
    {
        $registry = $this->registryWithSample();

        $contrast = $registry->get('sample-dusk-high-contrast');
        self::assertNotNull($contrast);

        // This is the property that makes `/api/v1/themes/{id}` insufficient on
        // its own, and therefore the property the SPA's client-side flattening
        // has to handle. If the variant ever grew its own `--bg`, the sample
        // would stop proving anything about chains.
        self::assertArrayNotHasKey('--bg', $contrast->tokens);
        self::assertArrayHasKey('--text', $contrast->tokens);

        $dusk = $registry->get('sample-dusk');
        self::assertNotNull($dusk);
        self::assertArrayHasKey('--bg', $dusk->tokens);
    }

    public function testTheWireBodyMatchesTheGoldenSharedWithPhlixUi(): void
    {
        $registry = $this->registryWithSample();
        $body = (new ThemesController($registry))->index(new Request(), [])->body;

        $golden = file_get_contents(self::GOLDEN);
        self::assertIsString($golden);

        self::assertSame(
            self::GOLDEN_MD5,
            md5($golden),
            'The golden theme body changed. It is duplicated byte-for-byte in '
            . self::UI_TWIN . ' — update that copy and this md5 together, or the '
            . 'SPA consumption test is proving something this server no longer emits.',
        );

        self::assertSame(
            rtrim($golden, "\n"),
            $body,
            'GET /api/v1/themes no longer produces the golden body.',
        );
    }

    public function testTheGoldenCarriesTheThreeBuiltInsFirstThenTheTwoSampleThemes(): void
    {
        $golden = file_get_contents(self::GOLDEN);
        self::assertIsString($golden);
        $decoded = json_decode($golden, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['themes'] ?? null);

        /** @var list<array<string, mixed>> $themes */
        $themes = $decoded['themes'];
        $ids = array_map(static fn (array $t): mixed => $t['id'], $themes);

        self::assertSame([...BuiltInThemes::IDS, ...self::SAMPLE_IDS], $ids);

        foreach ($themes as $theme) {
            $isSample = in_array($theme['id'], self::SAMPLE_IDS, true);
            self::assertSame(!$isSample, $theme['builtIn']);
            self::assertSame($isSample ? 'sample-theme' : null, $theme['source']);
        }
    }
}
