<?php

declare(strict_types=1);

namespace Phlex\Requests;

use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

/**
 * RequestNotification handles notifications for request status changes.
 *
 * Currently implements stub logging-based notifications.
 * In future could integrate with email, push notifications, or WebSocket.
 *
 * @since 0.12.0
 */
class RequestNotification
{
    /**
     * Notifies a user that their requested media is now available.
     *
     * @param string $userId The user ID to notify
     * @param string $title The title of the available media
     *
     * @return void
     */
    public function notifyAvailable(string $userId, string $title): void
    {
        $logger = LoggerFactory::get(LogChannels::MEDIA);
        $logger->info('Request available notification', [
            'user_id' => $userId,
            'title' => $title,
            'message' => "Your request '{$title}' is now available in your library.",
        ]);
    }

    /**
     * Notifies a user that their request was rejected.
     *
     * @param string $userId The user ID to notify
     * @param string $title The title of the rejected media
     * @param string $reason The rejection reason
     *
     * @return void
     */
    public function notifyRejected(string $userId, string $title, string $reason): void
    {
        $logger = LoggerFactory::get(LogChannels::MEDIA);

        $reasonText = $reason !== '' ? ": {$reason}" : '';

        $logger->info('Request rejected notification', [
            'user_id' => $userId,
            'title' => $title,
            'reason' => $reason,
            'message' => "Your request '{$title}' was rejected{$reasonText}.",
        ]);
    }

    /**
     * Notifies a user that their request was approved.
     *
     * @param string $userId The user ID to notify
     * @param string $title The title of the approved media
     *
     * @return void
     */
    public function notifyApproved(string $userId, string $title): void
    {
        $logger = LoggerFactory::get(LogChannels::MEDIA);

        $logger->info('Request approved notification', [
            'user_id' => $userId,
            'title' => $title,
            'message' => "Your request '{$title}' has been approved and is being processed.",
        ]);
    }

    /**
     * Notifies a user that their request was submitted.
     *
     * @param string $userId The user ID to notify
     * @param string $title The title of the requested media
     *
     * @return void
     */
    public function notifySubmitted(string $userId, string $title): void
    {
        $logger = LoggerFactory::get(LogChannels::MEDIA);

        $logger->info('Request submitted notification', [
            'user_id' => $userId,
            'title' => $title,
            'message' => "Your request for '{$title}' has been submitted and is pending review.",
        ]);
    }
}
