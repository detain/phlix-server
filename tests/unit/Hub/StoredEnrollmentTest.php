<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\StoredEnrollment;

class StoredEnrollmentTest extends TestCase
{
    public function test_constructor_and_properties(): void
    {
        $enrollment = new StoredEnrollment(
            enrollmentJwt: 'jwt-token',
            hubJwksUrl: 'https://hub.example.com/.well-known/jwks.json',
            serverId: 'server-uuid',
            hubBaseUrl: 'https://hub.example.com',
            enrolledAt: 1747430400,
        );

        $this->assertEquals('jwt-token', $enrollment->enrollmentJwt);
        $this->assertEquals('https://hub.example.com/.well-known/jwks.json', $enrollment->hubJwksUrl);
        $this->assertEquals('server-uuid', $enrollment->serverId);
        $this->assertEquals('https://hub.example.com', $enrollment->hubBaseUrl);
        $this->assertEquals(1747430400, $enrollment->enrolledAt);
    }

    public function test_isExpired_returns_false_for_fresh_enrollment(): void
    {
        $enrollment = new StoredEnrollment(
            enrollmentJwt: 'jwt-token',
            hubJwksUrl: 'https://hub.example.com/.well-known/jwks.json',
            serverId: 'server-uuid',
            hubBaseUrl: 'https://hub.example.com',
            enrolledAt: time(),
        );

        $this->assertFalse($enrollment->isExpired());
    }

    public function test_isExpired_returns_true_for_old_enrollment(): void
    {
        $enrollment = new StoredEnrollment(
            enrollmentJwt: 'jwt-token',
            hubJwksUrl: 'https://hub.example.com/.well-known/jwks.json',
            serverId: 'server-uuid',
            hubBaseUrl: 'https://hub.example.com',
            enrolledAt: time() - (8 * 86400),
        );

        $this->assertTrue($enrollment->isExpired());
    }
}
