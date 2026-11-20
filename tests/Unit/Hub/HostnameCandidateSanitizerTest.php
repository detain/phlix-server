<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use Phlix\Hub\HostnameCandidateSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostnameCandidateSanitizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function hostnames(): array
    {
        return [
            'underscore mapped' => ['my_nas', 'my-nas'],
            'uppercase folded' => ['MyNAS', 'mynas'],
            'spaces and edges' => [' _nas box_ ', 'nas-box'],
            'dotted keeps labels' => ['a_b.example_x.com', 'a-b.example-x.com'],
            'non-ascii stripped to separator' => ["caf\u{e9}-nas", 'caf-nas'],
            'empty' => ['', ''],
            'only junk' => ['___', ''],
            'numeric tld refused' => ['host.123', ''],
            'long label truncated' => [str_repeat('a', 70), str_repeat('a', 63)],
        ];
    }

    #[DataProvider('hostnames')]
    public function testSanitizeHostname(string $in, string $out): void
    {
        $this->assertSame($out, HostnameCandidateSanitizer::sanitizeHostname($in));
    }

    public function testSanitizedHostnameAlwaysPassesTheCandidateGrammar(): void
    {
        foreach (self::hostnames() as [$in]) {
            $h = HostnameCandidateSanitizer::sanitizeHostname($in);
            if ($h !== '') {
                $this->assertTrue(HostnameCandidateSanitizer::isAcceptable('http://' . $h . '.local:8096'), $in);
            }
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function candidates(): array
    {
        return [
            'lan url' => ['http://192.168.1.5:8096', true],
            'https domain' => ['https://phlix.example.com', true],
            'bare host port' => ['nas.local:8096', true],
            'ipv6 bracket port' => ['[::1]:8096', true],
            'underscore host' => ['http://my_nas.local:8096', false],
            'userinfo' => ['http://a@evil.com', false],
            'query' => ['http://a.com/?x=1', false],
            'ftp scheme' => ['ftp://a.com', false],
            'port zero' => ['http://a.com:0', false],
            'space' => ['http://a b.com', false],
            'numeric tld' => ['http://1.2.3.999', false],
        ];
    }

    #[DataProvider('candidates')]
    public function testIsAcceptable(string $c, bool $ok): void
    {
        $this->assertSame($ok, HostnameCandidateSanitizer::isAcceptable($c));
    }

    public function testFilterDropsOnlyBadEntries(): void
    {
        $this->assertSame(
            ['http://10.0.0.2:8096'],
            HostnameCandidateSanitizer::filter(['http://my_nas.local:8096', 'http://10.0.0.2:8096']),
        );
    }

    /**
     * Ratified accept-set table for the delegated grammar (srv-C5b). Every
     * vector's verdict is identical under the retired in-repo twin and the
     * shared HostGrammar — the both-direction 55,938-vector differential
     * (gates/C1SRV-4b-differential.log) proved the fold moves no acceptance,
     * including the case shapes: the old local LABEL was lowercase-only but
     * carried /i, so uppercase was NEVER actually rejected. This table is the
     * committed extract of that proof for the estate ledger.
     *
     * @return array<string, array{string, bool}>
     */
    public static function ratifiedGrammarVectors(): array
    {
        return [
            'uppercase bare host' => ['HOST.EXAMPLE', true],
            'uppercase in url' => ['http://EXAMPLE.com:8096', true],
            'uppercase mixed label' => ['Phlix-Node.Local:8096', true],
            'bare ipv6' => ['::1', true],
            'bracketed ipv6' => ['[fe80::1]', true],
            'backslash in brackets' => ['[a\\b]', false],
            'backslash bare' => ['a\\b', false],
            'empty label' => ['x..y', false],
            'trailing hyphen label' => ['host-.example', false],
            'six-digit port' => ['host:123456', false],
            'port above range url' => ['https://a.com:65536', false],
            'url path allowed' => ['https://a.com/base', true],
            'empty url host' => ['http://', false],
        ];
    }

    #[DataProvider('ratifiedGrammarVectors')]
    public function testRatifiedGrammarVectors(string $candidate, bool $expected): void
    {
        $this->assertSame($expected, HostnameCandidateSanitizer::isAcceptable($candidate), $candidate);
    }

    /**
     * Extinction tripwire (srv-C5b): the sanitizer's candidate grammar now
     * lives ONLY in shared HostGrammar. Re-growing any local parsing — the
     * retired LABEL constant or isHost() method — reddens here before it can
     * fork the law the hub DTOs enforce at intake.
     */
    public function testCandidateGrammarLivesOnlyInSharedHostGrammar(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../../src/Hub/HostnameCandidateSanitizer.php');

        $this->assertStringNotContainsString('private const LABEL', $src, 'retired grammar LABEL re-grown in-repo');
        $this->assertStringNotContainsString('function isHost', $src, 'retired isHost() re-grown in-repo');
        $this->assertStringNotContainsString(
            'filter_var',
            $src,
            'validation machinery must not return to the sanitizer'
        );
        $this->assertSame(
            1,
            substr_count($src, 'HostGrammar::isValidCandidate('),
            'the delegation voice print must remain exactly one forward',
        );
    }
}
