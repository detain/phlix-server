<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Requests\RequestNotification;

class RequestNotificationTest extends TestCase
{
    private RequestNotification $notification;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../config/logger.php');
        $this->notification = new RequestNotification();
    }

    public function testNotifyAvailableDoesNotThrow(): void
    {
        $this->notification->notifyAvailable('user-123', 'Test Movie');

        $this->assertTrue(true);
    }

    public function testNotifyRejectedDoesNotThrow(): void
    {
        $this->notification->notifyRejected('user-123', 'Test Movie', 'Not appropriate');

        $this->assertTrue(true);
    }

    public function testNotifyRejectedWithEmptyReason(): void
    {
        $this->notification->notifyRejected('user-123', 'Test Movie', '');

        $this->assertTrue(true);
    }

    public function testNotifyApprovedDoesNotThrow(): void
    {
        $this->notification->notifyApproved('user-123', 'Test Movie');

        $this->assertTrue(true);
    }

    public function testNotifySubmittedDoesNotThrow(): void
    {
        $this->notification->notifySubmitted('user-123', 'Test Movie');

        $this->assertTrue(true);
    }
}
