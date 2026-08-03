<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Server\Http\Controllers\ThemesController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Theming\BuiltInThemes;
use Phlix\Theming\ThemeSourceInterface;
use Phlix\Theming\ThemeSourceRegistry;
use Phlix\Theming\ThemeTokenAllowlist;
use Phlix\Theming\ThemeTokenValidator;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the S85 theme catalogue endpoints, at the controller.
 *
 * Route reachability — i.e. that these methods are on a path an HTTP request
 * can actually take — is a separate concern and lives in
 * {@see \Phlix\Tests\Unit\Server\WebPortal\ThemeEndpointsReachabilityTest}.
 * Keeping the two apart matters here: a controller test that passes against a
 * controller nothing dispatches to is precisely the failure S94/S99 shipped.
 */
final class ThemesControllerTest extends TestCase
{
    /**
     * A plugin theme source, the shape {@see \Phlix\Plugins\PluginLoader}
     * registers on enable.
     *
     * @param list<array<string, mixed>> $themes
     */
    private function source(string $name, array $themes): ThemeSourceInterface
    {
        return new class ($name, $themes) implements ThemeSourceInterface {
            /**
             * @param list<array<string, mixed>> $themes
             */
            public function __construct(private string $name, private array $themes)
            {
            }

            public function themeSourceName(): string
            {
                return $this->name;
            }

            /**
             * @return list<array<array-key, mixed>>
             */
            public function providedThemes(): array
            {
                return $this->themes;
            }
        };
    }

    /**
     * @param array<string, string> $tokens
     * @return array<string, mixed>
     */
    private function themePayload(string $id, string $name, array $tokens, ?string $extends = null): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'dark' => true,
            'extends' => $extends,
            'tokens' => $tokens,
        ];
    }

    /**
     * @return array<string, mixed> Decoded JSON body.
     */
    private function body(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function themes(Response $response): array
    {
        $themes = $this->body($response)['themes'] ?? null;
        $this->assertIsArray($themes);

        /** @var list<array<string, mixed>> $themes */
        return $themes;
    }

    /**
     * @param list<array<string, mixed>> $themes
     * @return list<string>
     */
    private function ids(array $themes): array
    {
        return array_map(
            function (array $theme): string {
                $this->assertIsString($theme['id'] ?? null);
                /** @var string $id */
                $id = $theme['id'];
                return $id;
            },
            $themes,
        );
    }

    public function testTheListAlwaysCarriesTheThreeBuiltInsFirstInDeclarationOrder(): void
    {
        $controller = new ThemesController(new ThemeSourceRegistry());

        $themes = $this->themes($controller->index(new Request(), []));

        $this->assertSame(['nocturne', 'daylight', 'midnight'], $this->ids($themes));
    }

    public function testABuiltInIsListedWithItsCompleteTokenMap(): void
    {
        $controller = new ThemesController(new ThemeSourceRegistry());

        $themes = $this->themes($controller->index(new Request(), []));
        $nocturne = $themes[0];

        $this->assertSame('nocturne', $nocturne['id']);
        $this->assertSame('Nocturne', $nocturne['name']);
        $this->assertTrue($nocturne['dark']);
        $this->assertNull($nocturne['extends']);
        $this->assertNull($nocturne['source']);
        $this->assertTrue($nocturne['builtIn']);
        $this->assertSame(
            BuiltInThemes::all()['nocturne']->tokens,
            $nocturne['tokens'],
            'the AC requires built-ins to be listed WITH their token maps, not as bare descriptors',
        );
        $this->assertIsArray($nocturne['tokens']);
        $this->assertCount(53, $nocturne['tokens']);
    }

    public function testAPluginRegisteredThemeIsListedWithItsTokenMapAndProvenance(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [
            $this->themePayload('acme-noir', 'Acme Noir', [
                '--bg' => '#08070a',
                '--accent' => 'rgba(120, 190, 255, 0.95)',
            ], 'nocturne'),
        ]));

        $themes = $this->themes((new ThemesController($registry))->index(new Request(), []));

        $this->assertSame(['nocturne', 'daylight', 'midnight', 'acme-noir'], $this->ids($themes));

        $acme = $themes[3];
        $this->assertSame('Acme Noir', $acme['name']);
        $this->assertTrue($acme['dark']);
        $this->assertSame('nocturne', $acme['extends']);
        $this->assertSame('acme-themes', $acme['source']);
        $this->assertFalse($acme['builtIn']);
        $this->assertSame(
            ['--bg' => '#08070a', '--accent' => 'rgba(120, 190, 255, 0.95)'],
            $acme['tokens'],
        );
    }

    /**
     * Registry insertion order follows plugin enable order, which is not stable
     * across workers; the catalogue must not inherit that.
     */
    public function testPluginThemesAreOrderedByIdNotByRegistrationOrder(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('zeta-themes', [
            $this->themePayload('zeta-one', 'Zeta One', ['--bg' => '#010101']),
        ]));
        $registry->register($this->source('alpha-themes', [
            $this->themePayload('alpha-one', 'Alpha One', ['--bg' => '#020202']),
        ]));

        $themes = $this->themes((new ThemesController($registry))->index(new Request(), []));

        $this->assertSame(
            ['nocturne', 'daylight', 'midnight', 'alpha-one', 'zeta-one'],
            $this->ids($themes),
        );
    }

    public function testOneSourceMayContributeSeveralThemes(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [
            $this->themePayload('acme-noir', 'Acme Noir', ['--bg' => '#08070a']),
            $this->themePayload('acme-day', 'Acme Day', ['--bg' => '#fefefe']),
        ]));

        $themes = $this->themes((new ThemesController($registry))->index(new Request(), []));

        $this->assertSame(
            ['nocturne', 'daylight', 'midnight', 'acme-day', 'acme-noir'],
            $this->ids($themes),
        );
    }

    /**
     * Disabling the plugin drops its themes from the catalogue — the endpoint
     * reads the live registry, it does not snapshot it at construction.
     */
    public function testDeregisteringASourceRemovesItsThemesFromTheCatalogue(): void
    {
        $registry = new ThemeSourceRegistry();
        $source = $this->source('acme-themes', [
            $this->themePayload('acme-noir', 'Acme Noir', ['--bg' => '#08070a']),
        ]);
        $registry->register($source);
        $controller = new ThemesController($registry);

        $this->assertContains('acme-noir', $this->ids($this->themes($controller->index(new Request(), []))));

        $registry->deregisterInstance($source);

        $this->assertSame(
            ['nocturne', 'daylight', 'midnight'],
            $this->ids($this->themes($controller->index(new Request(), []))),
        );
        $this->assertSame(404, $controller->show(new Request(), ['id' => 'acme-noir'])->statusCode);
    }

    public function testShowReturnsABuiltInById(): void
    {
        $controller = new ThemesController(new ThemeSourceRegistry());

        foreach (BuiltInThemes::IDS as $id) {
            $response = $controller->show(new Request(), ['id' => $id]);

            $this->assertSame(200, $response->statusCode);
            $theme = $this->body($response)['theme'] ?? null;
            $this->assertIsArray($theme);
            $this->assertSame($id, $theme['id']);
            $this->assertTrue($theme['builtIn']);
            $this->assertSame(BuiltInThemes::all()[$id]->tokens, $theme['tokens']);
        }
    }

    public function testShowReturnsAPluginThemeById(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [
            $this->themePayload('acme-noir', 'Acme Noir', ['--bg' => '#08070a']),
        ]));

        $response = (new ThemesController($registry))->show(new Request(), ['id' => 'acme-noir']);

        $this->assertSame(200, $response->statusCode);
        $theme = $this->body($response)['theme'] ?? null;
        $this->assertIsArray($theme);
        $this->assertSame('acme-noir', $theme['id']);
        $this->assertSame('acme-themes', $theme['source']);
        $this->assertFalse($theme['builtIn']);
        $this->assertSame(['--bg' => '#08070a'], $theme['tokens']);
    }

    /**
     * The 404 contract: status AND body, so a change to either is a visible
     * diff rather than a silent client break.
     *
     * @dataProvider unknownIds
     */
    public function testShowReturnsA404WithTheStandardErrorShapeForAnUnknownId(string $id): void
    {
        $response = (new ThemesController(new ThemeSourceRegistry()))->show(new Request(), ['id' => $id]);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'Theme not found'], $this->body($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'never existed'      => ['does-not-exist'];
        yield 'mis-cased built-in' => ['Nocturne'];
        yield 'config-era theme'   => ['phlix-dark'];
        yield 'empty'              => [''];
        yield 'traversal shaped'   => ['..'];
    }

    /**
     * A missing `{id}` route parameter cannot reach the handler through the
     * router, but the handler must not depend on that to stay safe.
     */
    public function testShowWithoutAnIdParameterIsA404NotAnError(): void
    {
        $response = (new ThemesController(new ThemeSourceRegistry()))->show(new Request(), []);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'Theme not found'], $this->body($response));
    }

    /**
     * SECURITY: everything the endpoint emits has been through the S84
     * sanitiser — built-ins and plugin themes alike, keys and values.
     *
     * This is the property the S85 brief asked to be checked: that no theme can
     * reach the output on a path that skipped validation.
     */
    public function testEveryTokenServedIsAllowlistedAndGrammarSafe(): void
    {
        $registry = new ThemeSourceRegistry();
        $registry->register($this->source('acme-themes', [
            $this->themePayload('acme-noir', 'Acme Noir', [
                '--bg' => '  #08070a  ',
                '--grain-opacity' => '0.07',
                '--accent' => 'hsla(210deg, 50%, 40%, .5)',
            ]),
        ]));

        $themes = $this->themes((new ThemesController($registry))->index(new Request(), []));
        $this->assertCount(4, $themes);

        $checked = 0;
        foreach ($themes as $theme) {
            $tokens = $theme['tokens'];
            $this->assertIsArray($tokens);
            foreach ($tokens as $key => $value) {
                $this->assertIsString($key);
                $this->assertIsString($value);
                $this->assertTrue(ThemeTokenAllowlist::allows($key), "served non-allowlisted token {$key}");
                $this->assertTrue(ThemeTokenValidator::isSafeValue($value), "served unsafe value for {$key}");
                $checked++;
            }
        }

        $this->assertSame(3 * 53 + 3, $checked, 'every token of every listed theme must be checked');

        // The sanitiser's trim is observable on the wire, not just internally.
        $this->assertSame('#08070a', $themes[3]['tokens']['--bg']);
    }

    /**
     * A plugin cannot displace a built-in: the S84 validator refuses a reserved
     * id at registration, so the enable fails outright and the catalogue is
     * unchanged.
     */
    public function testAPluginCannotClaimABuiltInThemeId(): void
    {
        $registry = new ThemeSourceRegistry();

        try {
            $registry->register($this->source('evil-themes', [
                $this->themePayload('nocturne', 'Not Nocturne', ['--bg' => '#ff0000']),
            ]));
            $this->fail('a plugin claiming a reserved built-in id must be refused');
        } catch (\Phlix\Theming\Exception\InvalidThemeDefinition $e) {
            $this->assertStringContainsString('reserved built-in theme id', $e->getMessage());
        }

        $themes = $this->themes((new ThemesController($registry))->index(new Request(), []));
        $this->assertSame(['nocturne', 'daylight', 'midnight'], $this->ids($themes));
        $this->assertSame(BuiltInThemes::all()['nocturne']->tokens, $themes[0]['tokens']);
    }

    /**
     * The catalogue is server-wide, so neither endpoint may vary with the
     * caller. (It is auth-gated, but that is an access decision, not a
     * personalisation one — see the controller docblock.)
     */
    public function testTheCatalogueDoesNotVaryByUser(): void
    {
        $controller = new ThemesController(new ThemeSourceRegistry());

        $anonymous = new Request();
        $alice = new Request();
        $alice->userId = 'alice';

        $this->assertSame(
            $controller->index($anonymous, [])->body,
            $controller->index($alice, [])->body,
        );
    }
}
