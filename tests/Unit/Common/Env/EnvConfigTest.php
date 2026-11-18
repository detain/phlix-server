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
        'PHLIX_WMIN34_RPT',
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

    public function testIntegerUsesTheDefaultForAPartlyNumericValue(): void
    {
        // Was `(int)`-cast to 12 before; a value that is not a whole number now falls
        // back to the default instead of being read as a prefix.
        putenv('PHLIX_WMIN34_INT=12x');

        self::assertSame(8, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
        self::assertContains('PHLIX_WMIN34_INT', EnvConfig::invalidIntegerNames());
    }

    public function testIntegerNeverTurnsANonNumericValueIntoZero(): void
    {
        putenv('PHLIX_WMIN34_INT=abc');

        self::assertSame(2, EnvConfig::integer('PHLIX_WMIN34_INT', 2), 'a non-numeric value is not 0');
        self::assertContains('PHLIX_WMIN34_INT', EnvConfig::invalidIntegerNames());
    }

    public function testIntegerAcceptsAPaddedOrNegativeWholeNumber(): void
    {
        putenv('PHLIX_WMIN34_INT= 42 ');
        self::assertSame(42, EnvConfig::integer('PHLIX_WMIN34_INT', 8));

        putenv('PHLIX_WMIN34_INT=-3');
        self::assertSame(-3, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    /**
     * Upgrade rule: systemd EnvironmentFile and docker env_file do not strip inline comments, so
     * `PHLIX_X=0 # off` must read 0 as the old `(int)` cast did, not fall back to the default.
     *
     * @dataProvider inlineCommentProvider
     */
    public function testIntegerReadsTheWholeIntegerBeforeAnInlineComment(string $raw, int $expected): void
    {
        putenv('PHLIX_WMIN34_INT=' . $raw);

        self::assertSame($expected, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    /** @return iterable<string, array{string, int}> */
    public static function inlineCommentProvider(): iterable
    {
        yield 'zero then comment (prewarm off)' => ['0 # off', 0];
        yield 'three then comment' => ['3 # three segments', 3];
        yield 'tab before comment' => ["0\t#off", 0];
        yield 'no space before comment' => ['8#note', 8];
        yield 'padded then comment' => ['  12   # padded  ', 12];
    }

    /**
     * Upgrade rule (b): every whole-integer value the old `(int)` cast read keeps the SAME
     * integer. Each case is asserted against the literal `(int)` cast of the same string.
     *
     * @dataProvider preservedWholeIntegerProvider
     */
    public function testIntegerPreservesTheValueTheOldCastRead(string $raw): void
    {
        putenv('PHLIX_WMIN34_INT=' . $raw);

        self::assertSame((int) $raw, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    /** @return iterable<string, array{string}> */
    public static function preservedWholeIntegerProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'double zero' => ['00'];
        yield 'leading zero is decimal' => ['010'];
        yield 'explicit plus' => ['+5'];
        yield 'negative zero' => ['-0'];
        yield 'padded' => [' 42 '];
        yield 'tab padded' => ["\t7\n"];
        yield 'overflow saturates like the old cast' => ['99999999999999999999'];
    }

    /**
     * Upgrade rule (c): a value that is not an integer must not be read as a prefix (`8G` was 8
     * bytes) or as 0 (`auto` disabled a cap). It uses the default and is recorded as invalid.
     *
     * @dataProvider nonIntegerProvider
     */
    public function testIntegerUsesTheDefaultForAValueThatIsNotAnInteger(string $raw): void
    {
        putenv('PHLIX_WMIN34_INT=' . $raw);

        self::assertSame(8, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
        self::assertContains('PHLIX_WMIN34_INT', EnvConfig::invalidIntegerNames());
    }

    /** @return iterable<string, array{string}> */
    public static function nonIntegerProvider(): iterable
    {
        yield 'auto' => ['auto'];
        yield 'size suffix' => ['8G'];
        yield 'exponent' => ['1e3'];
        yield 'fraction' => ['1.5'];
        yield 'hex' => ['0x1A'];
        yield 'comment only' => ['# off'];
        yield 'two numbers' => ['12 13'];
        yield 'whitespace only' => ['   '];
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

    // ---- availableCpuCount() -----------------------------------------------

    /**
     * Wave C (playback-lag): the portable core count behind the software-encode
     * thread default. The venue guarantee this pins is "a sane positive integer"
     * on every executor (nproc → /proc/cpuinfo → 1), plus memoization — the
     * probe must run once, so two reads are the identical value and cheap.
     */
    public function test_available_cpu_count_is_a_positive_int_and_memoized(): void
    {
        $first = EnvConfig::availableCpuCount();

        self::assertGreaterThanOrEqual(1, $first);
        self::assertSame($first, EnvConfig::availableCpuCount());
    }

    // ---- reportInvalidIntegers() -----------------------------------------

    /**
     * A non-integer knob is reported once per process with its name, and a second report
     * in the same process does not repeat it.
     */
    public function testReportLogsANonIntegerKnobOnceAndNotAgain(): void
    {
        putenv('PHLIX_WMIN34_RPT=auto');
        self::assertSame(5, EnvConfig::integer('PHLIX_WMIN34_RPT', 5));

        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $envs = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning' && isset($context['env'])) {
                    $this->envs[] = (string) $context['env'];
                }
            }
        };

        EnvConfig::reportInvalidIntegers($logger);
        EnvConfig::reportInvalidIntegers($logger);

        self::assertSame(1, count(array_keys($logger->envs, 'PHLIX_WMIN34_RPT', true)));
    }
}
