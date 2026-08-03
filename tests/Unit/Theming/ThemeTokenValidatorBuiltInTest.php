<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use Phlix\Theming\Exception\InvalidThemeDefinition;
use Phlix\Theming\ThemeTokenValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins {@see ThemeTokenValidator::validateBuiltIn()} — the S85 entry point that
 * lets the HOST mint the three reserved built-in themes, and lets nothing else
 * through.
 *
 * ## What this guards
 *
 * S84 deliberately made `nocturne` / `daylight` / `midnight` unclaimable, so
 * {@see ThemeTokenValidator::validate()} refuses them outright. S85 needs the
 * host to be able to produce exactly those three, and the tempting shortcut —
 * `new TokenTheme(...)` straight from a constant — would have created a SECOND
 * way into `GET /api/v1/themes`, one with no sanitiser on it.
 *
 * `validateBuiltIn()` is the narrow alternative. Two properties matter and both
 * are asserted below:
 *
 *  1. It differs from `validate()` in the id rule and in NOTHING else — the same
 *     allowlist, the same value grammar, the same field rules.
 *  2. Its id rule is a whitelist of the three reserved ids, i.e. it is TIGHTER
 *     than `validate()`'s slug rule, not merely different. It cannot be used to
 *     mint a host theme under an arbitrary id.
 *
 * And the S84 guarantee itself is re-asserted here: the plugin path still
 * refuses the reserved ids, including when it is called with a null source name
 * (the "host" origin), so the S85 refactor cannot have loosened it.
 */
final class ThemeTokenValidatorBuiltInTest extends TestCase
{
    /**
     * A minimal well-formed built-in payload.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'nocturne',
            'name' => 'Nocturne',
            'dark' => true,
            'extends' => null,
            'tokens' => ['--bg' => '#0b0a08', '--accent' => '#f5a524'],
        ], $overrides);
    }

    public function testAcceptsEachReservedBuiltInId(): void
    {
        foreach (ThemeTokenValidator::RESERVED_IDS as $id) {
            $theme = ThemeTokenValidator::validateBuiltIn($this->payload(['id' => $id]));

            $this->assertSame($id, $theme->id);
            $this->assertNull($theme->sourceName, 'a built-in has no plugin provenance');
            $this->assertSame(['--bg' => '#0b0a08', '--accent' => '#f5a524'], $theme->tokens);
        }
    }

    /**
     * The relaxation is also a tightening: an id that is a perfectly valid
     * plugin slug is still refused on the built-in path.
     */
    public function testRefusesAnyIdThatIsNotAReservedBuiltIn(): void
    {
        foreach (['acme-noir', 'phlix-dark', 'a', 'nocturne2', ''] as $id) {
            try {
                ThemeTokenValidator::validateBuiltIn($this->payload(['id' => $id]));
                $this->fail("validateBuiltIn() must refuse the non-built-in id \"{$id}\"");
            } catch (InvalidThemeDefinition $e) {
                $this->assertStringContainsString('Built-in theme', $e->getMessage());
            }
        }
    }

    public function testRefusesANonStringId(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        ThemeTokenValidator::validateBuiltIn($this->payload(['id' => 42]));
    }

    /**
     * Case-sensitive, like every other id comparison in the subsystem.
     */
    public function testRefusesAMisCasedBuiltInId(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        ThemeTokenValidator::validateBuiltIn($this->payload(['id' => 'Nocturne']));
    }

    /**
     * The value grammar is untouched by the relaxed id rule — the built-in path
     * is not a way to smuggle CSS in under the host's name.
     *
     * @dataProvider injectionPayloads
     */
    public function testRefusesACssInjectionPayloadInATokenValue(string $value): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        ThemeTokenValidator::validateBuiltIn($this->payload(['tokens' => ['--bg' => $value]]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionPayloads(): iterable
    {
        yield 'url() exfiltration'  => ['url(https://evil.example/beacon.png)'];
        yield 'case-varied url()'   => ['URL(https://evil.example/beacon.png)'];
        yield 'semicolon splice'    => ['#fff; background: url(https://evil.example)'];
        yield 'brace break-out'     => ['#fff}body{background:url(https://evil.example)'];
        yield 'IE expression()'     => ['expression(alert(1))'];
        yield 'var() indirection'   => ['var(--evil)'];
        yield 'markup break-out'    => ['</style><script>alert(1)</script>'];
        yield 'important override'  => ['#fff !important'];
    }

    /**
     * The key allowlist is untouched too.
     */
    public function testRefusesANonAllowlistedTokenKey(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        ThemeTokenValidator::validateBuiltIn($this->payload(['tokens' => ['--evil' => '#ffffff']]));
    }

    /**
     * Field rules are shared verbatim with the plugin path.
     */
    public function testShareTheFieldRulesWithThePluginPath(): void
    {
        $cases = [
            'unknown payload key' => ['css' => 'body{}'],
            'non-boolean dark' => ['dark' => 'yes'],
            'empty name' => ['name' => '   '],
            'non-array tokens' => ['tokens' => 'nope'],
            'self extends' => ['extends' => 'nocturne'],
        ];

        foreach ($cases as $label => $overrides) {
            try {
                ThemeTokenValidator::validateBuiltIn($this->payload($overrides));
                $this->fail("validateBuiltIn() must refuse: {$label}");
            } catch (InvalidThemeDefinition) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * S84's guarantee, re-asserted after the S85 refactor split `validate()`
     * and `validateBuiltIn()` onto a shared private implementation: the PLUGIN
     * path still refuses a reserved id, and it does so even when called with a
     * null source name — the same argument shape `validateBuiltIn()` uses
     * internally.
     */
    public function testThePluginPathStillRefusesEveryReservedIdIncludingWithANullSource(): void
    {
        foreach (ThemeTokenValidator::RESERVED_IDS as $id) {
            foreach ([null, 'acme-themes'] as $source) {
                try {
                    ThemeTokenValidator::validate($this->payload(['id' => $id]), $source);
                    $this->fail("validate() must refuse reserved id \"{$id}\" (source: " . var_export($source, true) . ')');
                } catch (InvalidThemeDefinition $e) {
                    $this->assertStringContainsString('reserved built-in theme id', $e->getMessage());
                }
            }
        }
    }
}
