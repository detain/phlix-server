<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Time;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Time\HttpDate;

/**
 * E-16: the RFC 1123 HTTP-date mint was hand-built at four sites
 * (TranscodeFileServer ×2, MediaItemController chapter thumbs,
 * SsdpSearchResponder DATE).
 * Cache validators (If-Modified-Since / If-Range) compare these strings BYTE-
 * EXACTLY — `trim($ims) === $lastModified`, `$ifRangeValue !== $rangeValidator` —
 * so centralization must reproduce the old inline grammar character-for-
 * character. This suite pins the bytes at fixed timestamps BEFORE any site is
 * moved (red-first), and the moved files stay pinned by a per-file delegation
 * census so an inline format cannot silently grow back where SSOT was adopted.
 */
final class HttpDateTest extends TestCase
{
    /**
     * The RFC 1123 IMF-fixdate grammar every minted stamp must match.
     */
    private const GRAMMAR_PATTERN =
        '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} '
        . '(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) '
        . '\d{4} \d{2}:\d{2}:\d{2} GMT$/';
    /**
     * Byte-identity vs the exact old inline expression, at fixed timestamps.
     *
     * @return array<string, array{int, string}>
     */
    public static function timestampProvider(): array
    {
        return [
            // RFC 7231 §7.1.1.1 canonical example (same instant the If-Range
            // corpus pins as its STALE validator).
            'rfc-example' => [1445412480, 'Wed, 21 Oct 2015 07:28:00 GMT'],
            'epoch' => [0, 'Thu, 01 Jan 1970 00:00:00 GMT'],
            // Two-digit day ('01', not '1') and two-digit hour are part of the
            // grammar: a client byte-comparing If-Modified-Since sees the padded form.
            'new-year-midnight' => [1704067200, 'Mon, 01 Jan 2024 00:00:00 GMT'],
            'utc-year-end' => [1735689599, 'Tue, 31 Dec 2024 23:59:59 GMT'],
            // Epoch 0 is exactly what an unset/failed mtime coerced to (int)
            // would format as; pinning it documents what a zero stamp looks like.
            'leap-day' => [1709164800, 'Thu, 29 Feb 2024 00:00:00 GMT'],
            // 'D' is the 3-letter day name — the full 'Sunday' form (a drift the
            // scan named in hand-rolled stamps) must never appear.
            'sunday-short-name' => [1735430400, 'Sun, 29 Dec 2024 00:00:00 GMT'],
        ];
    }

    #[DataProvider('timestampProvider')]
    public function test_gmt_is_byte_identical_to_the_old_inline_expression(int $unixTime, string $expected): void
    {
        $this->assertSame($expected, HttpDate::gmt($unixTime));
        $this->assertSame(
            gmdate('D, d M Y H:i:s', $unixTime) . ' GMT',
            HttpDate::gmt($unixTime),
            'HttpDate::gmt() must be byte-identical to the inline expression it replaces.'
        );
    }

    public function test_gmt_uses_gmt_never_local_time_for_the_same_instant(): void
    {
        // A non-UTC default TZ must not shift the stamp (gmdate law): the SSOT
        // is timezone-agnostic by construction, which is why the old inline
        // calls used gmdate and not date.
        $instant = 1445412480;
        $saved = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $this->assertSame('Wed, 21 Oct 2015 07:28:00 GMT', HttpDate::gmt($instant));
        } finally {
            date_default_timezone_set($saved);
        }
    }

    public function test_from_now_matches_the_rfc1123_gmt_grammar_and_is_now(): void
    {
        $stamp = HttpDate::fromNow();

        $this->assertMatchesRegularExpression(
            self::GRAMMAR_PATTERN,
            $stamp,
            'fromNow() must emit the same grammar the four migrated sites emitted.'
        );
        $this->assertEqualsWithDelta(
            time(),
            (int) strtotime($stamp),
            2.0,
            'fromNow() must be the current instant (±2s slack for a second rollover).'
        );
    }

    /**
     * Delegation census: each migrated carrier must route through HttpDate and
     * must not keep a hand-built copy of the grammar. File-scoped on purpose —
     * other carriers of the same inline form exist outside E-16's authoritative
     * line list (disclosed in the SRV-MICRO register as follow-up rows).
     */
    public function test_the_four_migrated_sites_delegate_to_httpdate(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $sites = [
            'src/Server/Http/Controllers/TranscodeFileServer.php',
            'src/Server/Http/Controllers/MediaItemController.php',
            'src/Dlna/SsdpSearchResponder.php',
        ];

        foreach ($sites as $relative) {
            $path = $repoRoot . '/' . $relative;
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression(
                self::grammar_intrusion_pattern(),
                $source,
                $relative . ' must not hand-build the HTTP-date grammar anymore.'
            );
            $this->assertStringContainsString(
                'HttpDate::',
                $source,
                $relative . ' must mint its HTTP dates through the SSOT.'
            );
        }
    }

    /**
     * M4 canary (review SRV-MICRO-r3 Minor): the census must catch a re-inline
     * spelled with ANY PHP quoting, not only the historical single-quoted one.
     * PSR-12 permits both spellings, so a double-quoted `gmdate("D, d M ...")`
     * copy is a silent-growth vector the census exists to close.
     */
    public function test_the_census_pattern_catches_a_double_quoted_re_inline(): void
    {
        $canary = "<?php\n\$lastModified = gmdate(\"D, d M Y H:i:s\", \$mtime) . ' GMT';\n";

        $this->assertMatchesRegularExpression(
            self::grammar_intrusion_pattern(),
            $canary,
            'A double-quoted grammar copy must trip the census (M4 class).'
        );
    }

    /**
     * Grammar intrusion pattern used by BOTH the carrier census and its canary
     * leg, so the two can never drift apart. Deliberately quote-agnostic (r3
     * review Minor / M4 probe): the grammar bytes appearing ANYWHERE in a
     * migrated carrier are a hand-built copy, whichever PHP quoting the
     * re-inline chose — PSR-12 permits both spellings.
     */
    private static function grammar_intrusion_pattern(): string
    {
        return '/D, d M Y H:i:s/';
    }
}
