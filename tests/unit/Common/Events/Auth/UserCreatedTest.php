<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events\Auth;

use Phlex\Common\Events\Auth\UserCreated;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Common\Events\Auth\UserCreated
 */
final class UserCreatedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new UserCreated('uid', 'alice', 'alice@example.com');
        $this->assertSame('uid', $event->userId);
        $this->assertSame('alice', $event->username);
        $this->assertSame('alice@example.com', $event->email);
    }
}
