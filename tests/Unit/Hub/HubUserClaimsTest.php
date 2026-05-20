<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\HubUserClaims;

class HubUserClaimsTest extends TestCase
{
    public function testIsExpiredReturnsTrueWhenPast(): void
    {
        $claims = new HubUserClaims(
            userId: 'user-123',
            serverId: 'server-456',
            subject: 'user-123',
            issuer: 'phlix-hub',
            expiresAt: time() - 3600,
            scope: ['media:read'],
        );

        $this->assertTrue($claims->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenFuture(): void
    {
        $claims = new HubUserClaims(
            userId: 'user-123',
            serverId: 'server-456',
            subject: 'user-123',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: ['media:read'],
        );

        $this->assertFalse($claims->isExpired());
    }

    public function testHasScopeReturnsTrueWhenPresent(): void
    {
        $claims = new HubUserClaims(
            userId: 'user-123',
            serverId: 'server-456',
            subject: 'user-123',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: ['media:read', 'media:write'],
        );

        $this->assertTrue($claims->hasScope('media:read'));
        $this->assertTrue($claims->hasScope('media:write'));
    }

    public function testHasScopeReturnsFalseWhenAbsent(): void
    {
        $claims = new HubUserClaims(
            userId: 'user-123',
            serverId: 'server-456',
            subject: 'user-123',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: ['media:read'],
        );

        $this->assertFalse($claims->hasScope('media:write'));
        $this->assertFalse($claims->hasScope('admin'));
    }

    public function testHasScopeReturnsFalseWhenScopeArrayEmpty(): void
    {
        $claims = new HubUserClaims(
            userId: 'user-123',
            serverId: 'server-456',
            subject: 'user-123',
            issuer: 'phlix-hub',
            expiresAt: time() + 3600,
            scope: [],
        );

        $this->assertFalse($claims->hasScope('media:read'));
    }

    public function testPropertiesAreAccessible(): void
    {
        $claims = new HubUserClaims(
            userId: 'hub-user-abc',
            serverId: 'server-xyz',
            subject: 'hub-user-abc',
            issuer: 'phlix-hub',
            expiresAt: 1700000000,
            scope: ['scope1'],
        );

        $this->assertEquals('hub-user-abc', $claims->userId);
        $this->assertEquals('server-xyz', $claims->serverId);
        $this->assertEquals('hub-user-abc', $claims->subject);
        $this->assertEquals('phlix-hub', $claims->issuer);
        $this->assertEquals(1700000000, $claims->expiresAt);
        $this->assertEquals(['scope1'], $claims->scope);
    }
}
