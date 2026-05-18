-- Migration: 020_newsletter.sql
-- Description: Create newsletter_queue table for weekly newsletter email system

CREATE TABLE IF NOT EXISTS `newsletter_queue` (
    `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID identifier',
    `user_id` CHAR(36) NOT NULL COMMENT 'User UUID receiving this newsletter',
    `week_start` DATE NOT NULL COMMENT 'Start date of the week this newsletter covers',
    `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending' COMMENT 'Delivery status',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of delivery attempts',
    `last_attempt_at` DATETIME NULL COMMENT 'Timestamp of last delivery attempt',
    `sent_at` DATETIME NULL COMMENT 'Timestamp when email was successfully sent',
    `error_message` TEXT NULL COMMENT 'Error message if delivery failed',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    INDEX `idx_user_week` (`user_id`, `week_start`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
