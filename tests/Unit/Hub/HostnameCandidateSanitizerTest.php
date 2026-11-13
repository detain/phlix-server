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
}
