<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\HubApplication;
use Phlix\Hub\HubClient;
use Phlix\Hub\StoredEnrollment;

class HubApplicationTest extends TestCase
{
    public function test_isEnrolled_is_false_when_no_enrollment(): void
    {
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('loadEnrollment')->willReturn(null);

        $app = new HubApplication($hubClient, new StructuredLogger('hub', []));

        $this->assertFalse($app->isEnrolled());
        $this->assertFalse($app->isRunning());
    }

    public function test_isEnrolled_is_true_when_enrollment_present(): void
    {
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('loadEnrollment')->willReturn(
            new StoredEnrollment('jwt', 'https://hub/jwks', 'server-1', 'https://hub', time()),
        );

        $app = new HubApplication($hubClient, new StructuredLogger('hub', []));

        $this->assertTrue($app->isEnrolled());
    }

    public function test_start_arms_heartbeat_loop_when_enrolled(): void
    {
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('loadEnrollment')->willReturn(
            new StoredEnrollment('jwt', 'https://hub/jwks', 'server-1', 'https://hub', time()),
        );
        $hubClient->expects($this->once())->method('startHeartbeatLoop');

        $app = new HubApplication($hubClient, new StructuredLogger('hub', []));
        $app->start();

        $this->assertTrue($app->isRunning());
    }

    public function test_start_is_noop_when_not_enrolled(): void
    {
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('loadEnrollment')->willReturn(null);
        $hubClient->expects($this->never())->method('startHeartbeatLoop');

        $app = new HubApplication($hubClient, new StructuredLogger('hub', []));
        $app->start();

        $this->assertFalse($app->isRunning());
    }
}
