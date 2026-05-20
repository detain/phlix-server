<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Events\Auth;

use Phlix\Shared\Events\Auth\UserLoggedIn;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Events\Auth\UserLoggedIn
 */
final class UserLoggedInTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new UserLoggedIn('uid', 'sess-1', '10.0.0.5', 'curl/8.0');
        $this->assertSame('uid', $event->userId);
        $this->assertSame('sess-1', $event->sessionId);
        $this->assertSame('10.0.0.5', $event->ipAddress);
        $this->assertSame('curl/8.0', $event->userAgent);
    }

    public function test_empty_ip_and_ua_allowed(): void
    {
        $event = new UserLoggedIn('uid', 'sess-1', '', '');
        $this->assertSame('', $event->ipAddress);
        $this->assertSame('', $event->userAgent);
    }
}
