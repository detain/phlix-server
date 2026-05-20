<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Events\Auth;

use Phlix\Shared\Events\Auth\UserCreated;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Events\Auth\UserCreated
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
