<?php

declare(strict_types=1);

/**
 * Newsletter configuration.
 *
 * Controls weekly newsletter email delivery settings including
 * scheduling, batch processing, and sender information.
 */

return [
    /**
     * Enable or disable the newsletter system.
     *
     * @var bool
     */
    'enabled' => false,

    /**
     * Day of the week to send newsletters (0=Sunday through 6=Saturday).
     *
     * @var int
     */
    'send_day' => 0,

    /**
     * Hour of the day to send newsletters (0-23, server timezone).
     *
     * @var int
     */
    'send_hour' => 9,

    /**
     * Number of emails to process per batch.
     *
     * @var int
     */
    'batch_size' => 50,

    /**
     * Sender email address for newsletters.
     *
     * @var string
     */
    'from_email' => 'phlix@example.com',

    /**
     * Sender name for newsletters.
     *
     * @var string
     */
    'from_name' => 'Phlix Media Server',

    /**
     * Email subject template.
     *
     * @var string
     */
    'subject_template' => 'Your Phlix Weekly Watch Report',

    /**
     * Number of days after last login to consider a user active.
     *
     * @var int
     */
    'active_user_days' => 30,

    /**
     * Maximum number of top media items to include in newsletter.
     *
     * @var int
     */
    'top_media_limit' => 5,
];
