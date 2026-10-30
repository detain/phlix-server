<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Env;

use Phlix\Common\Env\EnvConfig;
use PHPUnit\Framework\TestCase;

/**
 * M-SERVER-34: the `getenv(X) ?:` trap swallows the literal string "0"
 * (PHP's `?:` treats "0" as falsy), so an operator who explicitly sets a
 * numeric knob to 0 silently gets the DEFAULT. config/database.php warns
 * about this in-place; EnvConfig generalizes the cure.
 *
 * The load-bearing pins below are the "0"-survival cases — the exact inputs
 * the old pattern lost. (Run red against the old `?:` sites to confirm.)
 */
final class EnvConfigTest extends TestCase
{
    private const NAMES = [
        'PHLIX_WMIN34_INT',
        'PHLIX_WMIN34_TEXT',
        'PHLIX_WMIN34_NULL',
    ];

    protected function tearDown(): void
    {
        foreach (self::NAMES as $name) {
            putenv($name);
        }
    }

    // ---- integer() -------------------------------------------------------

    public function test_integer_falls_back_when_unset(): void
    {
        self::assertSame(8, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    public function test_integer_falls_back_on_empty_string(): void
    {
        putenv('PHLIX_WMIN34_INT=');

        self::assertSame(8, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    /**
     * THE cure. `RATE_LIMIT_<surface>_MAX=0` is a deny-all operator intent
     * the old `?: 8` silently reverted to 8.
     */
    public function test_integer_keeps_an_explicit_zero(): void
    {
        putenv('PHLIX_WMIN34_INT=0');

        self::assertSame(0, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    public function test_integer_reads_a_positive_value(): void
    {
        putenv('PHLIX_WMIN34_INT=42');

        self::assertSame(42, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    public function test_integer_casts_like_the_old_site_for_non_numeric_input(): void
    {
        putenv('PHLIX_WMIN34_INT=12x');

        self::assertSame(12, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    // ---- text() ----------------------------------------------------------

    public function test_text_falls_back_when_unset(): void
    {
        $caBundle = '/etc/ssl/certs/ca-certificates.crt';

        self::assertSame($caBundle, EnvConfig::text('PHLIX_WMIN34_TEXT', $caBundle));
    }

    public function test_text_falls_back_on_empty_string(): void
    {
        putenv('PHLIX_WMIN34_TEXT=');

        self::assertSame('default', EnvConfig::text('PHLIX_WMIN34_TEXT', 'default'));
    }

    /** "0" is a legitimate directory/URL segment only in a caller's grammar; the helper preserves it verbatim. */
    public function test_text_keeps_an_explicit_zero(): void
    {
        putenv('PHLIX_WMIN34_TEXT=0');

        self::assertSame('0', EnvConfig::text('PHLIX_WMIN34_TEXT', 'default'));
    }

    public function test_text_returns_the_literal_value(): void
    {
        putenv('PHLIX_WMIN34_TEXT=https://hub.example/');

        self::assertSame('https://hub.example/', EnvConfig::text('PHLIX_WMIN34_TEXT', 'default'));
    }

    // ---- nullableText() --------------------------------------------------

    public function test_nullable_text_is_null_when_unset(): void
    {
        self::assertNull(EnvConfig::nullableText('PHLIX_WMIN34_NULL'));
    }

    public function test_nullable_text_is_null_on_empty_string(): void
    {
        putenv('PHLIX_WMIN34_NULL=');

        self::assertNull(EnvConfig::nullableText('PHLIX_WMIN34_NULL'));
    }

    public function test_nullable_text_returns_the_present_value(): void
    {
        putenv('PHLIX_WMIN34_NULL=eyJhbGci');

        self::assertSame('eyJhbGci', EnvConfig::nullableText('PHLIX_WMIN34_NULL'));
    }

    /** A secret/token that is literally "0" is preserved, not treated as absent. */
    public function test_nullable_text_keeps_an_explicit_zero(): void
    {
        putenv('PHLIX_WMIN34_NULL=0');

        self::assertSame('0', EnvConfig::nullableText('PHLIX_WMIN34_NULL'));
    }
}
