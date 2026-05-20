<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\HeartbeatResult;

class HeartbeatResultTest extends TestCase
{
    public function test_success_result(): void
    {
        $result = new HeartbeatResult(true);

        $this->assertTrue($result->ok);
        $this->assertNull($result->error);
        $this->assertNull($result->errorCode);
    }

    public function test_failure_result(): void
    {
        $result = new HeartbeatResult(false, 'Network error', 'NETWORK_ERROR');

        $this->assertFalse($result->ok);
        $this->assertEquals('Network error', $result->error);
        $this->assertEquals('NETWORK_ERROR', $result->errorCode);
    }
}
