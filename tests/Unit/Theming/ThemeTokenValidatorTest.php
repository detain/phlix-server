<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use Phlix\Theming\Exception\InvalidThemeDefinition;
use Phlix\Theming\ThemeTokenAllowlist;
use Phlix\Theming\ThemeTokenValidator;
use Phlix\Theming\TokenTheme;
use PHPUnit\Framework\TestCase;

/**
 * S84's security boundary.
 *
 * A plugin-supplied token value is emitted as the value half of a CSS custom
 * property, so escaping the value position is arbitrary CSS injection. These
 * tests vary the SHAPE of the escape attempt, not the count: casing, CSS
 * identifier escapes, comment splitting, newline splitting, `;` continuation,
 * `}` rule-termination, `!important`, and functional-notation smuggling. They
 * pass because {@see ThemeTokenValidator} accepts only four narrow grammars —
 * there is no substring blocklist to out-spell.
 */
final class ThemeTokenValidatorTest extends TestCase
{
    /**
     * A minimal well-formed payload the rejection tests mutate one field of.
     *
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'acme-noir',
            'name' => 'Acme Noir',
            'dark' => true,
            'extends' => null,
            'tokens' => ['--bg' => '#08070a'],
        ];
    }

    // ---------------------------------------------------------------- accept

    public function testAcceptsAWellFormedTokenMapTheme(): void
    {
        $theme = ThemeTokenValidator::validate($this->payload([
            'tokens' => [
                '--bg' => '#08070a',
                '--surface' => '#12111A',
                '--accent' => 'rgba(120, 190, 255, 0.95)',
                '--accent-soft' => 'hsla(210deg, 50%, 40%, .5)',
                '--grain-opacity' => '0.05',
                '--vignette' => 'transparent',
                '--color-primary' => 'currentColor',
            ],
        ]), 'acme-themes');

        $this->assertInstanceOf(TokenTheme::class, $theme);
        $this->assertSame('acme-noir', $theme->id);
        $this->assertSame('Acme Noir', $theme->name);
        $this->assertTrue($theme->dark);
        $this->assertNull($theme->extends);
        $this->assertSame('acme-themes', $theme->sourceName);
        $this->assertCount(7, $theme->tokens);
        $this->assertSame('#08070a', $theme->tokens['--bg']);
    }

    public function testTrimsSurroundingWhitespaceFromValuesAndStoresTheTrimmedForm(): void
    {
        $theme = ThemeTokenValidator::validate($this->payload([
            'tokens' => ['--bg' => "  #08070a\n"],
        ]));

        $this->assertSame('#08070a', $theme->tokens['--bg'], 'the stored value carries no stray whitespace');
    }

    public function testAcceptsAnEmptyTokenMapForAThemeThatOnlyExtends(): void
    {
        $theme = ThemeTokenValidator::validate($this->payload([
            'extends' => 'acme-base',
            'tokens' => [],
        ]));

        $this->assertSame([], $theme->tokens);
        $this->assertSame('acme-base', $theme->extends);
    }

    public function testDefaultsDarkToFalseAndExtendsToNullWhenOmitted(): void
    {
        $theme = ThemeTokenValidator::validate([
            'id' => 'acme-noir',
            'name' => 'Acme Noir',
            'tokens' => ['--bg' => '#000'],
        ]);

        $this->assertFalse($theme->dark);
        $this->assertNull($theme->extends);
        $this->assertNull($theme->sourceName, 'a host-registered theme has no plugin provenance');
    }

    public function testToArrayIsTheWireShape(): void
    {
        $theme = ThemeTokenValidator::validate($this->payload(), 'acme-themes');

        $this->assertSame(
            [
                'id' => 'acme-noir',
                'name' => 'Acme Noir',
                'dark' => true,
                'extends' => null,
                'tokens' => ['--bg' => '#08070a'],
                'source' => 'acme-themes',
            ],
            $theme->toArray(),
        );
    }

    // ------------------------------------------------- the CSS-injection matrix

    /**
     * Every shape of "get out of the value position" this reviewer could think
     * of, asserted one payload at a time so a failure names the shape.
     *
     * @return iterable<string, array{string}>
     */
    public static function injectionPayloads(): iterable
    {
        yield 'url() exfiltration'          => ['url(https://evil.example/beacon.png)'];
        yield 'url() upper-cased'           => ['URL(https://evil.example/beacon.png)'];
        yield 'url() mixed-cased'           => ['Url(//evil.example)'];
        yield 'url() space before paren'    => ['url (https://evil.example)'];
        yield 'CSS hex escape for u'        => ['\\75 rl(https://evil.example)'];
        yield 'CSS zero-padded hex escape'  => ['\\000075rl(https://evil.example)'];
        yield 'comment-split url'           => ['u/**/rl(https://evil.example)'];
        yield 'trailing comment'            => ['#fff/*'];
        yield 'semicolon continuation'      => ['#fff; background: url(https://evil.example)'];
        yield 'semicolon only'              => ['#fff;'];
        yield 'brace closes the rule'       => ['#fff}body{background:url(https://evil.example)'];
        yield 'brace only'                  => ['#fff}'];
        yield 'newline-split declaration'   => ["#fff\n;color:red"];
        yield 'CRLF-split declaration'      => ["#fff\r\n}"];
        yield 'IE expression()'             => ['expression(alert(1))'];
        yield 'legacy -moz-binding'         => ['-moz-binding:url(x)'];
        yield 'image-set wrapping url'      => ['image-set(url(x))'];
        yield 'var() indirection'           => ['var(--evil)'];
        yield 'attr() indirection'          => ['attr(data-x)'];
        yield 'element() reference'         => ['element(#evil)'];
        yield 'specificity via !important'  => ['#fff !important'];
        yield 'unicode escape'              => ['\\0075\\0072\\006c(x)'];
        yield 'html-ish angle brackets'     => ['</style><script>alert(1)</script>'];
        yield 'backslash newline splice'    => ["#ff\\\nf"];
        yield 'NUL in the middle'           => ["#f\x00ff"];
        yield 'non-ascii bytes'             => ["\xff\xfe#fff"];
        yield 'over-long malformed value'   => ['#' . str_repeat('a', 200)];
        // Grammar-VALID but over the length cap: only MAX_VALUE_LENGTH can
        // refuse this one, so it is what pins the cap.
        yield 'over-long but valid shape'   => ['rgb(' . str_repeat('0', 200) . ',0,0)'];
        // Only the literal-space separator (rather than \s) refuses these:
        // an accepted value must never be able to carry a newline or tab.
        yield 'newline between arguments'   => ["rgba(1,\n2, 3, 0.5)"];
        yield 'tab between arguments'       => ["rgba(1,\t2, 3, 0.5)"];
        yield 'newline after open paren'    => ["rgb(\n0, 0, 0)"];
        yield 'empty value'                 => [''];
        yield 'whitespace-only value'       => ['   '];
        yield 'bare keyword red'            => ['red'];
        yield 'malformed hex'               => ['#fffff'];
        yield 'rgb with too few arguments'  => ['rgb(0,0)'];
        yield 'rgb with too many arguments' => ['rgba(0,0,0,0,0,0)'];
    }

    /**
     * @dataProvider injectionPayloads
     */
    public function testRejectsACssInjectionPayloadInATokenValue(string $hostile): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('sets token "--bg" to a value that is not a plain colour or number');

        ThemeTokenValidator::validate($this->payload(['tokens' => ['--bg' => $hostile]]));
    }

    /**
     * The same matrix straight at the predicate, so a failure here isolates the
     * grammar from the payload plumbing around it.
     *
     * @dataProvider injectionPayloads
     */
    public function testTheValueGrammarItselfRefusesTheInjectionPayload(string $hostile): void
    {
        $this->assertFalse(
            ThemeTokenValidator::isSafeValue($hostile),
            'the value grammar accepted a hostile value',
        );
    }

    /**
     * The three shapes the acceptance criterion names explicitly, asserted
     * separately from the provider so they cannot be lost in a refactor of it.
     */
    public function testTheAcceptanceCriterionsThreePayloadsAreRejected(): void
    {
        foreach (['url(https://evil.example/x)', '#fff;color:red', '#fff}'] as $hostile) {
            $this->assertFalse(ThemeTokenValidator::isSafeValue($hostile), $hostile . ' must be rejected');
        }
    }

    /**
     * Every value the built-in themes actually use must survive the grammar,
     * or the allowlist is unusable for the themes it was derived from.
     *
     * @return iterable<string, array{string}>
     */
    public static function realTokenValues(): iterable
    {
        yield 'nocturne --bg'         => ['#0b0a08'];
        yield 'daylight --surface'    => ['#fffdf8'];
        yield 'midnight --bg'         => ['#000000'];
        yield 'short hex'             => ['#fff'];
        yield 'hex with alpha'        => ['#ffffffaa'];
        yield 'short hex with alpha'  => ['#abcd'];
        yield 'upper-case hex'        => ['#F5A524'];
        yield 'accent-soft rgba'      => ['rgba(245, 165, 36, 0.14)'];
        yield 'tight rgb'             => ['rgb(0,0,0)'];
        yield 'hsl percentages'       => ['hsl(210, 50%, 40%)'];
        yield 'hsla with deg + .5'    => ['hsla(210deg, 50%, 40%, .5)'];
        yield 'slash-separated alpha' => ['rgb(255 / 0 / 0)'];
        yield 'grain-opacity'         => ['0.05'];
        yield 'zero'                  => ['0'];
        yield 'one'                   => ['1'];
        yield 'leading-dot number'    => ['.5'];
        yield 'negative number'       => ['-0.2'];
        yield 'transparent'           => ['transparent'];
        yield 'currentColor'          => ['currentColor'];
        yield 'CURRENTCOLOR'          => ['CURRENTCOLOR'];
    }

    /**
     * @dataProvider realTokenValues
     */
    public function testAcceptsTheValueShapesTheBuiltInThemesUse(string $value): void
    {
        $this->assertTrue(ThemeTokenValidator::isSafeValue($value), $value . ' must be accepted');
    }

    // ----------------------------------------------------------- key allowlist

    public function testRejectsANonAllowlistedTokenKey(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('sets the non-allowlisted token "--evil"');

        ThemeTokenValidator::validate($this->payload(['tokens' => ['--evil' => '#fff']]));
    }

    public function testRejectsARealCssPropertyMasqueradingAsAToken(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('non-allowlisted token "background-image"');

        ThemeTokenValidator::validate($this->payload([
            'tokens' => ['background-image' => '#fff'],
        ]));
    }

    public function testTokenKeysAreCaseSensitiveSoAMisCasedAllowlistEntryIsRejected(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('non-allowlisted token "--BG"');

        ThemeTokenValidator::validate($this->payload(['tokens' => ['--BG' => '#fff']]));
    }

    public function testRejectsAWhitespacePaddedAllowlistEntry(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('non-allowlisted token " --bg"');

        ThemeTokenValidator::validate($this->payload(['tokens' => [' --bg' => '#fff']]));
    }

    public function testRejectsTheThemeInvariantAmberRamp(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('non-allowlisted token "--amber-500"');

        ThemeTokenValidator::validate($this->payload(['tokens' => ['--amber-500' => '#fff']]));
    }

    public function testRejectsALayoutTokenBecauseLayoutIsOutOfScope(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('non-allowlisted token "--space-4"');

        ThemeTokenValidator::validate($this->payload(['tokens' => ['--space-4' => '0.5']]));
    }

    public function testRejectsANumericTokenKey(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('non-allowlisted token "0"');

        ThemeTokenValidator::validate($this->payload(['tokens' => ['#fff']]));
    }

    public function testEveryAllowlistedTokenIsActuallySettable(): void
    {
        $tokens = [];
        foreach (ThemeTokenAllowlist::all() as $token) {
            $tokens[$token] = '#123456';
        }

        $theme = ThemeTokenValidator::validate($this->payload(['tokens' => $tokens]));

        $this->assertCount(count(ThemeTokenAllowlist::all()), $theme->tokens);
    }

    // ------------------------------------------------------------ field checks

    public function testRejectsAnUnknownPayloadKey(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('unknown payload key "css"');

        ThemeTokenValidator::validate($this->payload(['css' => 'https://evil.example/x.css']));
    }

    public function testRejectsAMissingId(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('invalid "id"');

        ThemeTokenValidator::validate(['name' => 'x', 'tokens' => []]);
    }

    public function testRejectsAnUpperCaseId(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('invalid "id"');

        ThemeTokenValidator::validate($this->payload(['id' => 'Acme-Noir']));
    }

    public function testRejectsAnIdWithAPathTraversalShape(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('invalid "id"');

        ThemeTokenValidator::validate($this->payload(['id' => '../../etc/passwd']));
    }

    /**
     * A plugin must not be able to replace a shipped theme by claiming its id.
     */
    public function testRejectsAReservedBuiltInThemeId(): void
    {
        foreach (ThemeTokenValidator::RESERVED_IDS as $reserved) {
            try {
                ThemeTokenValidator::validate($this->payload(['id' => $reserved]));
                $this->fail('reserved id "' . $reserved . '" must be refused');
            } catch (InvalidThemeDefinition $e) {
                $this->assertStringContainsString('reserved built-in theme id', $e->getMessage());
            }
        }
    }

    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('invalid "name"');

        ThemeTokenValidator::validate($this->payload(['name' => '   ']));
    }

    public function testRejectsANameCarryingControlCharacters(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('invalid "name"');

        ThemeTokenValidator::validate($this->payload(['name' => "Acme\x07Noir"]));
    }

    public function testRejectsAnOverLongName(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('invalid "name"');

        ThemeTokenValidator::validate($this->payload(['name' => str_repeat('n', 65)]));
    }

    public function testRejectsANonBooleanDarkRatherThanCoercingIt(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('non-boolean "dark" (string)');

        ThemeTokenValidator::validate($this->payload(['dark' => 'yes']));
    }

    public function testRejectsASelfExtendingTheme(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('extends itself');

        ThemeTokenValidator::validate($this->payload(['extends' => 'acme-noir']));
    }

    public function testRejectsAMalformedExtends(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('invalid "extends"');

        ThemeTokenValidator::validate($this->payload(['extends' => 'Acme Base']));
    }

    public function testRejectsNonArrayTokens(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('non-array "tokens" (string)');

        ThemeTokenValidator::validate($this->payload(['tokens' => 'https://evil.example/x.css']));
    }

    public function testRejectsANonStringTokenValue(): void
    {
        $this->expectException(InvalidThemeDefinition::class);
        $this->expectExceptionMessage('sets token "--bg" to a value that is not a plain colour or number');

        ThemeTokenValidator::validate($this->payload(['tokens' => ['--bg' => ['#fff']]]));
    }

    /**
     * The error message must name the offending source, theme and token, or an
     * operator cannot act on it.
     */
    public function testTheRejectionMessageNamesTheSourceTheThemeAndTheToken(): void
    {
        try {
            ThemeTokenValidator::validate(
                $this->payload(['tokens' => ['--bg' => 'url(https://evil.example)']]),
                'acme-themes',
            );
            $this->fail('expected a rejection');
        } catch (InvalidThemeDefinition $e) {
            $this->assertStringContainsString("plugin source 'acme-themes'", $e->getMessage());
            $this->assertStringContainsString('"acme-noir"', $e->getMessage());
            $this->assertStringContainsString('"--bg"', $e->getMessage());
        }
    }
}
