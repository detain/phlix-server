-- Phlix media server migration 066: Webhook Events System
--
-- Creates the webhook event dispatch queue with retry support:
-- - webhook_events: Stores individual webhook events with JSON payload
-- - webhook_deliveries: Tracks delivery attempts per subscription with retry scheduling
-- - webhook_subscriptions: Stores webhook endpoint subscriptions
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MIT
-- @author    Phlix Development Team
-- @version   0.38.0
-- @since     0.38.0

-- -----------------------------------------------------------------------------
-- webhook_events: Stores webhook events to be delivered
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type               VARCHAR(100) NOT NULL COMMENT 'Event type (e.g., media.scanned, transcode.completed)',
    payload                  JSON NOT NULL COMMENT 'Event payload data',
    created_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'Event creation timestamp',

    -- Indexes for common queries
    INDEX idx_created (created_at),
    INDEX idx_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- webhook_deliveries: Tracks individual delivery attempts for each subscription
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id                 BIGINT UNSIGNED NOT NULL COMMENT 'FK to webhook_events.id',
    webhook_url              VARCHAR(2048) NOT NULL COMMENT 'Target URL for delivery',
    attempt                  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Current attempt number (1-3)',
    status                   ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending' COMMENT 'Delivery status',
    response_code            INT UNSIGNED NULL COMMENT 'HTTP response code',
    response_body            TEXT NULL COMMENT 'HTTP response body (truncated)',
    next_retry_at            DATETIME(6) NULL COMMENT 'Next retry timestamp (exponential backoff: 30s, 300s, 1800s)',
    created_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'Delivery record creation',
    delivered_at             DATETIME(6) NULL COMMENT 'Successful delivery timestamp',

    -- Foreign key to webhook_events
    CONSTRAINT fk_deliveries_event FOREIGN KEY (event_id)
        REFERENCES webhook_events(id) ON DELETE CASCADE,

    -- Index for finding pending retries
    INDEX idx_status_retry (status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- webhook_subscriptions: Stores webhook endpoint subscriptions
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_subscriptions (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url                      VARCHAR(2048) NOT NULL COMMENT 'Webhook endpoint URL',
    events                   JSON NOT NULL COMMENT 'Array of subscribed event types',
    is_active                BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether subscription is active',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Subscription creation',
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update',

    -- Index for finding active subscriptions
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;