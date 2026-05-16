<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events\Auth;

use Phlex\Common\Events\Auth\UserLoggedOut;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Common\Events\Auth\UserLoggedOut
 */
final class UserLoggedOutTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new UserLoggedOut('uid', 'sess-1', UserLoggedOut::REASON_EXPLICIT);
        $this->assertSame('uid', $event->userId);
        $this->assertSame('sess-1', $event->sessionId);
        $this->assertSame('explicit', $event->reason);
    }

    public function test_reason_constants_have_expected_values(): void
    {
        $this->assertSame('explicit', UserLoggedOut::REASON_EXPLICIT);
        $this->assertSame('expired', UserLoggedOut::REASON_EXPIRED);
        $this->assertSame('revoked', UserLoggedOut::REASON_REVOKED);
    }
}
