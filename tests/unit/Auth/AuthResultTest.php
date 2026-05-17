<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlex\Shared\Auth\AuthResult;

/**
 * @covers \Phlex\Shared\Auth\AuthResult
 */
final class AuthResultTest extends TestCase
{
    public function test_success_result(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-uuid-123',
            externalId: 'https://accounts.google.com/12345',
            attributes: ['email' => 'alice@example.com', 'name' => 'Alice'],
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame('user-uuid-123', $result->userId);
        $this->assertSame('https://accounts.google.com/12345', $result->externalId);
        $this->assertNull($result->error);
    }

    public function test_failure_result(): void
    {
        $result = new AuthResult(
            success: false,
            error: 'token_expired',
        );

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
        $this->assertNull($result->userId);
        $this->assertNull($result->externalId);
        $this->assertSame('token_expired', $result->error);
    }

    public function test_attributes_access(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-uuid-123',
            externalId: 'oidc:alice',
            attributes: [
                'email' => 'alice@example.com',
                'name' => 'Alice',
                'avatarUrl' => 'https://example.com/avatar.png',
            ],
        );

        $this->assertSame('alice@example.com', $result->getEmail());
        $this->assertSame('Alice', $result->getDisplayName());
        $this->assertSame('https://example.com/avatar.png', $result->getAvatarUrl());
        $this->assertSame(['email' => 'alice@example.com', 'name' => 'Alice', 'avatarUrl' => 'https://example.com/avatar.png'], $result->attributes);
    }

    public function test_attributes_return_null_when_missing(): void
    {
        $result = new AuthResult(success: true, userId: 'user-1');

        $this->assertNull($result->getEmail());
        $this->assertNull($result->getDisplayName());
        $this->assertNull($result->getAvatarUrl());
    }

    public function test_attributes_filtered_when_wrong_type(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-1',
            attributes: [
                'email' => 12345,
                'name' => ['not', 'a', 'string'],
                'avatarUrl' => null,
            ],
        );

        $this->assertNull($result->getEmail());
        $this->assertNull($result->getDisplayName());
        $this->assertNull($result->getAvatarUrl());
    }
}
