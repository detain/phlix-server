<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Server\Http\Middleware\HeaderMap;
use PHPUnit\Framework\TestCase;

final class HeaderMapTest extends TestCase
{
    public function testGetAndHasAreCaseInsensitive(): void
    {
        $h = ['vary' => 'Accept', 'X-Frame-Options' => 'DENY'];

        self::assertSame('Accept', HeaderMap::get($h, 'Vary'));
        self::assertSame('DENY', HeaderMap::get($h, 'x-frame-options'));
        self::assertTrue(HeaderMap::has($h, 'VARY'));
        self::assertTrue(HeaderMap::has($h, 'x-frame-options'));
    }

    public function testMissingHeader(): void
    {
        self::assertNull(HeaderMap::get(['A' => 'b'], 'C'));
        self::assertFalse(HeaderMap::has([], 'C'));
    }
}
