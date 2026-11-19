<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Env;

use Phlix\Common\Env\EnvConfig;
use PHPUnit\Framework\Attributes\DataProvider;
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
        'PHLIX_E04_BOOL',
        'PHLIX_E04_OPTOUT',
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

    /**
     * Pins the integer parser to the `(int)` cast for inputs an operator might set. Changing
     * one of these changes what the knob does on upgrade (for example `auto` and `off` read as
     * 0, which a cap treats as off, and `8G` reads as 8). Values were checked against master.
     *
     * @dataProvider castedIntegerProvider
     */
    public function testIntegerReadsTheValueTheIntCastReads(string $raw, int $expected): void
    {
        putenv('PHLIX_WMIN34_INT=' . $raw);

        self::assertSame($expected, EnvConfig::integer('PHLIX_WMIN34_INT', 8));
    }

    /** @return iterable<string, array{string, int}> */
    public static function castedIntegerProvider(): iterable
    {
        yield 'auto is 0' => ['auto', 0];
        yield 'off is 0' => ['off', 0];
        yield 'size suffix reads the number' => ['8G', 8];
        yield 'exponent' => ['1e3', 1000];
        yield 'fraction' => ['1.5', 1];
        yield 'hex is 0' => ['0x1A', 0];
        yield 'doubled sign is 0' => ['+-5', 0];
        yield 'space after sign is 0' => ['+ 5', 0];
        yield 'leading dot is 0' => ['.5', 0];
        yield 'true is 0' => ['true', 0];
        yield 'two numbers' => ['5 6', 5];
        yield 'underscore separator' => ['1_000', 1];
        yield 'trailing non-breaking space' => ["5\u{00A0}", 5];
        yield 'leading zero is decimal' => ['010', 10];
        yield 'zero then comment' => ['0 # off', 0];
        yield 'three then comment' => ['3 # three', 3];
        yield 'overflow saturates' => ['99999999999999999999', PHP_INT_MAX];
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

    // ---- bool() / boolOptOut() / boolValue() (E-04) ------------------------

    /**
     * E-04: the estate hand-rolled nine copies of
     * `in_array(strtolower(...), ['1','true','yes','on'])` with no shared
     * bool reader, and the copies drifted (RelayTunnelBoot trims the raw
     * value, AdminHubController did not — same `PHLIX_RELAY_DISABLED`, two
     * answers for `' false'`). The tests below ARE the law: one grammar,
     * trim + case fold, both word sets, junk answers the default and is
     * LOUD (one `error_log` line) without ever blocking the boot.
     */
    public function test_bool_falls_back_to_its_default_when_unset(): void
    {
        self::assertFalse(EnvConfig::bool('PHLIX_E04_BOOL'));
        self::assertTrue(EnvConfig::bool('PHLIX_E04_BOOL', true));
    }

    public function test_bool_falls_back_on_empty_string(): void
    {
        putenv('PHLIX_E04_BOOL=');

        self::assertFalse(EnvConfig::bool('PHLIX_E04_BOOL'));
        self::assertTrue(EnvConfig::bool('PHLIX_E04_BOOL', true));
    }

    /** A whitespace-only value reads as unset: default, and NO junk warning. */
    public function test_bool_treats_whitespace_only_as_unset_without_warning(): void
    {
        putenv('PHLIX_E04_BOOL=   ');

        $log = self::capturedErrorLog(static fn (): bool => EnvConfig::bool('PHLIX_E04_BOOL'));

        self::assertFalse(EnvConfig::bool('PHLIX_E04_BOOL'));
        self::assertSame('', $log, 'whitespace-only is unset-shaped, not junk — it must not warn');
    }

    /**
     * Every accepted spelling of the grammar — the full word set both ways,
     * case-folded, plus the whitespace shapes the drifted copies disagreed
     * on (the `' false'` leg IS E-04 drift #6: it must answer FALSE, the
     * same way on every former copy, once they share this law).
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function boolSpellings(): iterable
    {
        yield 'one' => ['1', true];
        yield 'true lower' => ['true', true];
        yield 'TRUE upper' => ['TRUE', true];
        yield 'True mixed' => ['True', true];
        yield 'yes' => ['yes', true];
        yield 'YES' => ['YES', true];
        yield 'Yes mixed' => ['Yes', true];
        yield 'on' => ['on', true];
        yield 'ON upper' => ['ON', true];
        yield 'zero' => ['0', false];
        yield 'false lower' => ['false', false];
        yield 'FALSE upper' => ['FALSE', false];
        yield 'no' => ['no', false];
        yield 'NO upper' => ['NO', false];
        yield 'off' => ['off', false];
        yield 'OFF upper' => ['OFF', false];
        yield 'padded true' => [' true ', true];
        yield 'leading-space false (E-04 drift case)' => [' false', false];
        yield 'tab-padded on' => ["\ton", true];
    }

    #[DataProvider('boolSpellings')]
    public function test_bool_reads_the_word_grammar(string $raw, bool $expected): void
    {
        putenv('PHLIX_E04_BOOL=' . $raw);

        self::assertSame($expected, EnvConfig::bool('PHLIX_E04_BOOL'));
    }

    public function test_bool_junk_answers_its_default_both_directions(): void
    {
        putenv('PHLIX_E04_BOOL=maybe');

        self::assertFalse(EnvConfig::bool('PHLIX_E04_BOOL'));
        self::assertTrue(EnvConfig::bool('PHLIX_E04_BOOL', true));
    }

    public function test_bool_junk_warns_one_error_log_line_naming_knob_value_and_default(): void
    {
        putenv('PHLIX_E04_BOOL=maybe');

        self::assertFalse(EnvConfig::bool('PHLIX_E04_BOOL'));
        $log = self::capturedErrorLog(
            static fn (): bool => EnvConfig::bool('PHLIX_E04_BOOL')
        );

        self::assertStringContainsString('PHLIX_E04_BOOL', $log);
        self::assertStringContainsString('maybe', $log);
        self::assertStringContainsString('false', $log, 'the warning names the default it answered');
        self::assertSame(1, substr_count($log, "\n"), 'the junk warning is exactly one line');
    }

    /** A junk value carrying a newline must still log a SINGLE line (log injection posture). */
    public function test_bool_junk_warning_stays_one_line_for_multiline_values(): void
    {
        putenv('PHLIX_E04_BOOL=multi' . "\n" . 'line');

        $log = self::capturedErrorLog(
            static fn (): bool => EnvConfig::bool('PHLIX_E04_BOOL')
        );

        self::assertSame(1, substr_count($log, "\n"));
    }

    /** The truthy/false reads never warn — only junk does. */
    public function test_bool_word_reads_log_nothing(): void
    {
        foreach (['on', 'off', ' false '] as $raw) {
            putenv('PHLIX_E04_BOOL=' . $raw);

            $log = self::capturedErrorLog(static fn (): bool => EnvConfig::bool('PHLIX_E04_BOOL'));

            self::assertSame('', $log, "value '{$raw}' is grammatical — it must not warn");
        }
    }

    /**
     * The opt-out knob shape: the default is ON and the operator spells a
     * FALSE word to turn the subsystem off (the PHLIX_RELAY_ENABLED style).
     */
    public function test_bool_opt_out_defaults_true_and_reads_false_words_as_off(): void
    {
        self::assertTrue(EnvConfig::boolOptOut('PHLIX_E04_OPTOUT'));

        foreach (['0', 'false', 'no', 'off', ' false '] as $raw) {
            putenv('PHLIX_E04_OPTOUT=' . $raw);
            self::assertFalse(EnvConfig::boolOptOut('PHLIX_E04_OPTOUT'), "'{$raw}' opts out");
        }

        foreach (['1', 'true', 'yes', 'on'] as $raw) {
            putenv('PHLIX_E04_OPTOUT=' . $raw);
            self::assertTrue(EnvConfig::boolOptOut('PHLIX_E04_OPTOUT'), "'{$raw}' keeps the knob on");
        }
    }

    public function test_bool_opt_out_junk_keeps_default_and_warns(): void
    {
        putenv('PHLIX_E04_OPTOUT=maybe');

        self::assertTrue(EnvConfig::boolOptOut('PHLIX_E04_OPTOUT'));

        $log = self::capturedErrorLog(
            static fn (): bool => EnvConfig::boolOptOut('PHLIX_E04_OPTOUT')
        );

        self::assertStringContainsString('PHLIX_E04_OPTOUT', $log);
        self::assertStringContainsString('maybe', $log);

        // The default is caller-supplied like bool()'s — the verb is not a constant-true.
        self::assertFalse(EnvConfig::boolOptOut('PHLIX_E04_OPTOUT', false));
    }

    /**
     * The raw seam: the same grammar over an already-read value (the shape
     * RelayTunnelBoot::envDisables needs — start.php runs outside PHPUnit,
     * so the boot gate is pinned by injecting raw values).
     */
    public function test_bool_value_raw_seam_drives_the_same_grammar(): void
    {
        self::assertFalse(EnvConfig::boolValue(false, false, 'PHLIX_E04_RAW'));
        self::assertFalse(EnvConfig::boolValue(null, false, 'PHLIX_E04_RAW'));
        self::assertTrue(EnvConfig::boolValue('', true, 'PHLIX_E04_RAW'));
        self::assertTrue(EnvConfig::boolValue(' on ', false, 'PHLIX_E04_RAW'));
        self::assertFalse(EnvConfig::boolValue(' false', false, 'PHLIX_E04_RAW'));

        $log = self::capturedErrorLog(
            static fn (): bool => EnvConfig::boolValue('maybe', false, 'PHLIX_E04_RAW')
        );

        self::assertFalse(EnvConfig::boolValue('maybe', false, 'PHLIX_E04_RAW'));
        self::assertStringContainsString('PHLIX_E04_RAW', $log);
    }

    /**
     * Run $read with `error_log` pointed at a temp file and return what it
     * logged. `error_log()` never throws and never reaches PHPUnit's
     * warning handling — the junk leg is a recorded complaint, not a halt.
     */
    private static function capturedErrorLog(callable $read): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phlix-envlog-');
        self::assertIsString($file);

        $previous = (string) ini_get('error_log');
        ini_set('error_log', $file);

        try {
            $read();

            return (string) file_get_contents($file);
        } finally {
            ini_set('error_log', $previous);
            unlink($file);
        }
    }
}
