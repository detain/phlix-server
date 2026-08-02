<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use Phlix\Theming\Exception\InvalidThemeDefinition;
use Phlix\Theming\ThemeSourceInterface;
use Phlix\Theming\ThemeSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * S84's capability registry.
 *
 * Pins the three properties the resident-memory host depends on: registration
 * is ALL-OR-NOTHING (a rejected token leaves nothing behind), an enable →
 * disable cycle leaks nothing, and no source can hijack another's theme id.
 *
 * @covers \Phlix\Theming\ThemeSourceRegistry
 */
final class ThemeSourceRegistryTest extends TestCase
{
    /**
     * @param list<array<array-key, mixed>> $themes
     */
    private function source(string $name, array $themes): ThemeSourceInterface
    {
        return new class ($name, $themes) implements ThemeSourceInterface {
            /**
             * @param list<array<array-key, mixed>> $themes
             */
            public function __construct(private string $name, private array $themes)
            {
            }

            public function themeSourceName(): string
            {
                return $this->name;
            }

            public function providedThemes(): array
            {
                return $this->themes;
            }
        };
    }

    /**
     * @param array<string, string> $tokens
     * @return array<array-key, mixed>
     */
    private function theme(string $id, array $tokens = ['--bg' => '#000'], ?string $extends = null): array
    {
        return [
            'id' => $id,
            'name' => ucfirst($id),
            'dark' => true,
            'extends' => $extends,
            'tokens' => $tokens,
        ];
    }

    public function testRegistersEveryThemeASourceProvides(): void
    {
        $registry = new ThemeSourceRegistry();

        $ids = $registry->register($this->source('acme-themes', [
            $this->theme('acme-noir'),
            $this->theme('acme-dawn', ['--bg' => '#ffffff']),
        ]));

        $this->assertSame(['acme-noir', 'acme-dawn'], $ids);
        $this->assertTrue($registry->has('acme-noir'));
        $this->assertTrue($registry->has('acme-dawn'));
        $this->assertCount(2, $registry->all());
        $this->assertSame(['acme-themes'], $registry->sourceNames());
        $this->assertSame('acme-themes', $registry->get('acme-noir')?->sourceName);
        $this->assertSame('#ffffff', $registry->get('acme-dawn')?->tokens['--bg']);
    }

    public function testAnUnknownThemeIdResolvesToNull(): void
    {
        $registry = new ThemeSourceRegistry();

        $this->assertNull($registry->get('nope'));
        $this->assertFalse($registry->has('nope'));
        $this->assertSame([], $registry->ids());
    }

    public function testDeregisterRemovesExactlyThatSourcesThemes(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [$this->theme('acme-noir')]));
        $registry->register($this->source('other-themes', [$this->theme('other-dusk')]));

        $registry->deregister('acme-themes');

        $this->assertFalse($registry->has('acme-noir'));
        $this->assertTrue($registry->has('other-dusk'), 'the other source must be untouched');
        $this->assertSame(['other-themes'], $registry->sourceNames());
    }

    public function testAnEnableDisableCycleLeavesTheRegistryExactlyAsItStarted(): void
    {
        $registry = new ThemeSourceRegistry();
        $source = $this->source('acme-themes', [$this->theme('acme-noir'), $this->theme('acme-dawn')]);

        for ($i = 0; $i < 5; $i++) {
            $registry->register($source);
            $this->assertCount(2, $registry->all());
            $registry->deregisterInstance($source);
            $this->assertCount(0, $registry->all(), 'no theme leaked on cycle ' . $i);
            $this->assertSame([], $registry->sourceNames(), 'no provenance leaked on cycle ' . $i);
        }
    }

    public function testReRegisteringTheSameSourceReplacesRatherThanAppends(): void
    {
        $registry = new ThemeSourceRegistry();

        $registry->register($this->source('acme-themes', [$this->theme('acme-noir'), $this->theme('acme-dawn')]));
        $registry->register($this->source('acme-themes', [$this->theme('acme-noir', ['--bg' => '#111111'])]));

        $this->assertSame(['acme-noir'], $registry->ids(), 'the replaced theme must be gone');
        $this->assertSame('#111111', $registry->get('acme-noir')?->tokens['--bg']);
    }

    // ------------------------------------------------------------ fail closed

    public function testOneBadTokenRejectsTheWholeSourceAndRegistersNothing(): void
    {
        $registry = new ThemeSourceRegistry();

        try {
            $registry->register($this->source('acme-themes', [
                $this->theme('acme-good'),
                $this->theme('acme-bad', ['--bg' => 'url(https://evil.example)']),
            ]));
            $this->fail('expected the source to be refused');
        } catch (InvalidThemeDefinition $e) {
            $this->assertStringContainsString('--bg', $e->getMessage());
        }

        $this->assertSame([], $registry->all(), 'the good theme must NOT have been committed');
        $this->assertSame([], $registry->sourceNames());
    }

    public function testAFailedReRegistrationDoesNotDisturbTheAlreadyRegisteredSources(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('other-themes', [$this->theme('other-dusk')]));

        $caught = null;
        try {
            $registry->register($this->source('acme-themes', [
                $this->theme('acme-bad', ['--bg' => '#fff}body{color:red']),
            ]));
        } catch (InvalidThemeDefinition $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(InvalidThemeDefinition::class, $caught, 'the bad source must be refused');
        $this->assertSame(['other-dusk'], $registry->ids());
        $this->assertSame(['other-themes'], $registry->sourceNames());
    }

    public function testASourceCannotHijackAThemeIdOwnedByAnotherSource(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [$this->theme('acme-noir', ['--bg' => '#000000'])]));

        try {
            $registry->register($this->source('evil-themes', [$this->theme('acme-noir', ['--bg' => '#ff0000'])]));
            $this->fail('expected the id hijack to be refused');
        } catch (InvalidThemeDefinition $e) {
            $this->assertStringContainsString('already registered by "acme-themes"', $e->getMessage());
        }

        $this->assertSame('#000000', $registry->get('acme-noir')?->tokens['--bg'], 'the original must survive');
        $this->assertSame(['acme-themes'], $registry->sourceNames());
    }

    public function testASourceCannotRegisterTheSameThemeIdTwiceInOneBatch(): void
    {
        $registry = new ThemeSourceRegistry();

        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('already registered by "acme-themes"');

        $registry->register($this->source('acme-themes', [
            $this->theme('acme-noir'),
            $this->theme('acme-noir', ['--bg' => '#ffffff']),
        ]));
    }

    public function testRejectsAMalformedSourceName(): void
    {
        $registry = new ThemeSourceRegistry();

        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('is not a lowercase slug');

        $registry->register($this->source('../../etc', [$this->theme('acme-noir')]));
    }

    public function testASourceProvidingNoThemesIsAValidLeakFreeRegistration(): void
    {
        $registry = new ThemeSourceRegistry();
        $source = $this->source('acme-themes', []);

        $this->assertSame([], $registry->register($source));
        $this->assertSame([], $registry->all());
        $this->assertSame(['acme-themes'], $registry->sourceNames());

        $registry->deregisterInstance($source);
        $this->assertSame([], $registry->sourceNames());
    }

    // ------------------------------------------------------------- resolution

    public function testResolveTokensLayersTheExtendsChainWithNearerThemesWinning(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [
            $this->theme('acme-base', ['--bg' => '#000000', '--text' => '#ffffff', '--accent' => '#f5a524']),
            $this->theme('acme-noir', ['--bg' => '#111111', '--surface' => '#222222'], 'acme-base'),
        ]));

        $this->assertSame(
            [
                '--bg' => '#111111',
                '--text' => '#ffffff',
                '--accent' => '#f5a524',
                '--surface' => '#222222',
            ],
            $registry->resolveTokens('acme-noir'),
        );
    }

    public function testResolveTokensStopsAtABaseThatIsNotRegisteredHere(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [
            $this->theme('acme-noir', ['--bg' => '#111111'], 'daylight'),
        ]));

        $this->assertSame(['--bg' => '#111111'], $registry->resolveTokens('acme-noir'));
    }

    public function testResolveTokensTerminatesOnACycle(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [
            $this->theme('acme-a', ['--bg' => '#0a0a0a'], 'acme-b'),
            $this->theme('acme-b', ['--bg' => '#0b0b0b', '--text' => '#ffffff'], 'acme-a'),
        ]));

        $this->assertSame(
            ['--bg' => '#0a0a0a', '--text' => '#ffffff'],
            $registry->resolveTokens('acme-a'),
            'the cycle must terminate with the requested theme still winning',
        );
    }

    public function testResolveTokensIsBoundedByMaxExtendsDepth(): void
    {
        $registry = new ThemeSourceRegistry();

        $themes = [];
        $links = ThemeSourceRegistry::MAX_EXTENDS_DEPTH + 4;
        for ($i = 0; $i < $links; $i++) {
            $themes[] = $this->theme(
                'acme-' . $i,
                ['--surface-' . (($i % 2) + 2) => '#00000' . ($i % 10)],
                $i + 1 < $links ? 'acme-' . ($i + 1) : null,
            );
        }
        $registry->register($this->source('acme-themes', $themes));

        $resolved = $registry->resolveTokens('acme-0');

        $this->assertLessThanOrEqual(2, count($resolved), 'only two distinct keys are used in the chain');
        $this->assertSame('#000000', $resolved['--surface-2'], 'the nearest link still wins');
    }

    public function testResolveTokensOnAnUnknownIdIsEmpty(): void
    {
        $this->assertSame([], (new ThemeSourceRegistry())->resolveTokens('nope'));
    }
}
