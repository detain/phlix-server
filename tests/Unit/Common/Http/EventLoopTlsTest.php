<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Http\EventLoopTls;
use Workerman\Events\Select;
use Workerman\Events\Swoole;
use Workerman\Worker;

/**
 * Pins the routing predicate that sends https requests down the cURL fallback.
 *
 * This is one half of accepted blocking-I/O exception #2 (see
 * `docs/dev/BLOCKING_IO_EXCEPTIONS.md`); the other half — that the fallback is
 * actually bounded — is pinned by
 * {@see \Phlix\Tests\Unit\Plugins\OAuth2\OAuth2HttpClientTimeoutTest}.
 *
 * Every case carries a control that must come out the OTHER way, so a predicate
 * that has degenerated to "always true" or "always false" cannot pass.
 */
final class EventLoopTlsTest extends TestCase
{
    /** @var class-string|null */
    private ?string $originalEventLoop = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEventLoop = Worker::$eventLoopClass;
    }

    protected function tearDown(): void
    {
        Worker::$eventLoopClass = $this->originalEventLoop;
        parent::tearDown();
    }

    public function test_https_under_the_swoole_event_loop_requires_curl(): void
    {
        Worker::$eventLoopClass = Swoole::class;

        $this->assertTrue(EventLoopTls::requiresBlockingCurl('https://idp.example.com/token'));
        // Control: same loop, plain http -> async client is fine.
        $this->assertFalse(EventLoopTls::requiresBlockingCurl('http://idp.example.com/token'));
    }

    public function test_https_under_a_non_swoole_event_loop_does_not_require_curl(): void
    {
        Worker::$eventLoopClass = Select::class;

        // Control for the case above: it is the LOOP, not the scheme, that flips it.
        $this->assertFalse(EventLoopTls::requiresBlockingCurl('https://idp.example.com/token'));
        $this->assertFalse(EventLoopTls::requiresBlockingCurl('http://idp.example.com/token'));
    }

    public function test_scheme_match_is_case_insensitive_and_prefix_anchored(): void
    {
        Worker::$eventLoopClass = Swoole::class;

        $this->assertTrue(EventLoopTls::requiresBlockingCurl('HTTPS://idp.example.com/token'));
        // Not anchored at position 0 -> not an https request; a substring match
        // here would misroute plain-http URLs onto the cURL path forever.
        $this->assertFalse(EventLoopTls::requiresBlockingCurl('http://proxy.example.com/?to=https://idp'));
        $this->assertFalse(EventLoopTls::requiresBlockingCurl(''));
    }
}
