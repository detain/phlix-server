<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlex\Shared\Auth\UserInfo;

/**
 * @covers \Phlex\Shared\Auth\UserInfo
 */
final class UserInfoTest extends TestCase
{
    public function test_smoke(): void
    {
        $info = new UserInfo(
            externalId: 'https://accounts.google.com/12345',
            email: 'alice@example.com',
            displayName: 'Alice',
            avatarUrl: 'https://lh3.googleusercontent.com/photo.jpg',
            rawAttributes: ['sub' => '12345', 'email_verified' => true],
        );

        $this->assertSame('https://accounts.google.com/12345', $info->externalId);
        $this->assertSame('alice@example.com', $info->email);
        $this->assertSame('Alice', $info->displayName);
        $this->assertSame('https://lh3.googleusercontent.com/photo.jpg', $info->avatarUrl);
        $this->assertTrue($info->hasEmail());
        $this->assertTrue($info->hasDisplayName());
        $this->assertTrue($info->hasAvatarUrl());
        $this->assertSame('12345', $info->getClaim('sub'));
        $this->assertTrue($info->getClaim('email_verified'));
    }

    public function test_has_email_returns_false_when_null(): void
    {
        $info = new UserInfo(externalId: 'ext-1');

        $this->assertFalse($info->hasEmail());
        $this->assertNull($info->email);
    }

    public function test_has_display_name_returns_false_when_null(): void
    {
        $info = new UserInfo(externalId: 'ext-1');

        $this->assertFalse($info->hasDisplayName());
        $this->assertNull($info->displayName);
    }

    public function test_has_avatar_url_returns_false_when_null(): void
    {
        $info = new UserInfo(externalId: 'ext-1');

        $this->assertFalse($info->hasAvatarUrl());
        $this->assertNull($info->avatarUrl);
    }

    public function test_get_claim_returns_default_when_missing(): void
    {
        $info = new UserInfo(externalId: 'ext-1');

        $this->assertSame('default', $info->getClaim('missing', 'default'));
        $this->assertNull($info->getClaim('missing'));
    }

    public function test_minimal_user_info(): void
    {
        $info = new UserInfo(externalId: 'ext-minimal');

        $this->assertSame('ext-minimal', $info->externalId);
        $this->assertFalse($info->hasEmail());
        $this->assertFalse($info->hasDisplayName());
        $this->assertFalse($info->hasAvatarUrl());
    }
}
