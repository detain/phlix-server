<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use PHPUnit\Framework\TestCase;

/**
 * S227 dead-end gate: the pre-S84 theming island must stay deleted.
 *
 * S84–S86 replaced the stylesheet-URL theming subsystem with the token-map
 * capability arm ({@see \Phlix\Theming\ThemeSourceRegistry}), but left the old
 * classes in tree as a second, fake subsystem — zero implementors, zero
 * resolvers, and the name a reader reaches for first. S227 deleted
 * `ThemeRegistry`, `Theme`, `ThemePluginInterface`, their `config/themes.php`
 * feed, their unit test, and the container binding that constructed them.
 *
 * This guard pins the absence so the island cannot silently return: if any
 * deleted symbol resolves again — resurrected class, restored file, or a
 * container binding mentioning it — a lane that greps for a "registry"
 * gets the fake one again, which is exactly the confusion this step ended.
 */
final class ThemingIslandRemovedTest extends TestCase
{
    /**
     * Survival token (S227) — code-resident identity for this gate.
     */
    private const SURVIVAL_TOKEN = 'S227ISLANDEADGATEX3V9';

    private const ISLAND_CLASSES = [
        'Phlix\Theming\ThemeRegistry',
        'Phlix\Theming\Theme',
    ];

    private const ISLAND_INTERFACES = [
        'Phlix\Theming\ThemePluginInterface',
    ];

    private const ISLAND_FILES = [
        'src/Theming/ThemeRegistry.php',
        'src/Theming/Theme.php',
        'src/Theming/ThemePluginInterface.php',
        'config/themes.php',
        'tests/Unit/Theming/ThemeRegistryTest.php',
    ];

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testTokenIdentifiesThisGate(): void
    {
        self::assertMatchesRegularExpression('/^S227[A-Z0-9]{12,}$/', self::SURVIVAL_TOKEN);
    }

    /**
     * Resolution check without inclusion: a stale composer mapping or a
     * recreated source file is caught by the autoloader's own lookup, while
     * an autoload-true probe would merely emit include warnings here.
     */
    public function testIslandClassesDoNotExist(): void
    {
        $loader = null;
        foreach (spl_autoload_functions() as $callable) {
            if (is_array($callable) && $callable[0] instanceof \Composer\Autoload\ClassLoader) {
                $loader = $callable[0];
                break;
            }
        }
        self::assertInstanceOf(\Composer\Autoload\ClassLoader::class, $loader, 'composer autoloader not registered');

        foreach (self::ISLAND_CLASSES as $symbol) {
            // A deleted class is never *loaded* in a clean process, so an in-memory
            // class_exists() probe is statically vacuous here (level 4 proves it).
            // The authoritative guard against resurrection is autoload resolution:
            // a restored source file or a stale classmap entry is caught by findFile().
            $file = $loader->findFile($symbol);
            self::assertTrue($file === false || !is_file($file), $symbol . ' resolves again via autoload');
        }

        foreach (self::ISLAND_INTERFACES as $symbol) {
            self::assertFalse(interface_exists($symbol, false), $symbol . ' is loaded again');
            $file = $loader->findFile($symbol);
            self::assertTrue($file === false || !is_file($file), $symbol . ' resolves again via autoload');
        }
    }

    public function testIslandFilesAreAbsent(): void
    {
        foreach (self::ISLAND_FILES as $relative) {
            self::assertFileDoesNotExist($this->repoRoot() . '/' . $relative);
        }
    }

    /**
     * The one binding the island had in the live container is gone, and the
     * provider no longer names it; the real registry binding survived.
     */
    public function testThemingServicesProviderCarriesNoIslandTrace(): void
    {
        $provider = (string) file_get_contents(
            $this->repoRoot() . '/src/Common/Container/Providers/ThemingServicesProvider.php'
        );

        foreach (['ThemeRegistry', 'ThemePluginInterface', 'themes.php', 'DEFAULT_THEMES_DIR'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $provider);
        }

        self::assertStringContainsString('ThemeSourceRegistry::class => autowire()', $provider);
    }

    public function testRealThemingSubsystemSurvives(): void
    {
        self::assertTrue(class_exists('Phlix\Theming\ThemeSourceRegistry'));
        self::assertTrue(interface_exists('Phlix\Theming\ThemeSourceInterface'));
        self::assertTrue(class_exists('Phlix\Theming\BuiltInThemes'));
    }
}
