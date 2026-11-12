<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use Phlix\Hub\HubUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SRV-43: hub URL scheme + cleartext-warning policy.
 */
final class HubUrlPolicyTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function schemeCases(): array
    {
        return [
            'https' => ['https://hub.example.com', true],
            'http' => ['http://hub.example.com:8080/x', true],
            'upper' => ['HTTPS://hub.example.com', true],
            'file' => ['file:///etc/passwd', false],
            'gopher' => ['gopher://hub.example.com', false],
            'ftp' => ['ftp://hub.example.com', false],
            'no scheme' => ['hub.example.com', false],
            'no host' => ['https://', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('schemeCases')]
    public function testIsHttpScheme(string $url, bool $expected): void
    {
        $this->assertSame($expected, HubUrlPolicy::isHttpScheme($url));
    }

    /** @return array<string, array{string, bool}> */
    public static function cleartextCases(): array
    {
        return [
            'http remote' => ['http://hub.example.com', true],
            'http lan' => ['http://192.168.1.5:8080', true],
            'http localhost' => ['http://localhost:8080', false],
            'http 127' => ['http://127.0.0.1', false],
            'http v6 loop' => ['http://[::1]:80', false],
            'https remote' => ['https://hub.example.com', false],
            'garbage' => ['not a url', false],
        ];
    }

    #[DataProvider('cleartextCases')]
    public function testIsCleartextNonLoopback(string $url, bool $expected): void
    {
        $this->assertSame($expected, HubUrlPolicy::isCleartextNonLoopback($url));
    }
}
