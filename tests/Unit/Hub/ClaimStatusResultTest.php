<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\ClaimStatusResult;

class ClaimStatusResultTest extends TestCase
{
    public function test_status_constants_exist(): void
    {
        $this->assertEquals('pending', ClaimStatusResult::STATUS_PENDING);
        $this->assertEquals('claimed', ClaimStatusResult::STATUS_CLAIMED);
        $this->assertEquals('expired', ClaimStatusResult::STATUS_EXPIRED);
    }

    public function test_pending_status(): void
    {
        $result = new ClaimStatusResult(ClaimStatusResult::STATUS_PENDING);

        $this->assertEquals(ClaimStatusResult::STATUS_PENDING, $result->status);
        $this->assertNull($result->enrollmentJwt);
        $this->assertNull($result->hubJwksUrl);
        $this->assertNull($result->serverId);
    }

    public function test_claimed_status(): void
    {
        $result = new ClaimStatusResult(
            status: ClaimStatusResult::STATUS_CLAIMED,
            enrollmentJwt: 'jwt-token',
            hubJwksUrl: 'https://hub.example.com/.well-known/jwks.json',
            serverId: 'server-uuid',
        );

        $this->assertEquals(ClaimStatusResult::STATUS_CLAIMED, $result->status);
        $this->assertEquals('jwt-token', $result->enrollmentJwt);
        $this->assertEquals('https://hub.example.com/.well-known/jwks.json', $result->hubJwksUrl);
        $this->assertEquals('server-uuid', $result->serverId);
    }

    public function test_expired_status(): void
    {
        $result = new ClaimStatusResult(ClaimStatusResult::STATUS_EXPIRED);

        $this->assertEquals(ClaimStatusResult::STATUS_EXPIRED, $result->status);
    }
}
