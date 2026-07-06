-- Migration: 048_oidc_state_store.sql
-- Description: Unified OAuth state store table for OIDC PKCE state management.
--
-- Replaces $_SESSION-backed SessionOidcStateStore which causes race conditions
-- under Workerman due to shared state between workers/requests.
--
-- Uses a unified `oauth_state_store` table with provider = 'oidc' and data JSON
-- containing {code_verifier, nonce}. This pattern is shared with Lastfm and Trakt
-- OAuth state stores.
--
-- Each entry has a 10-minute TTL to prevent stale state accumulation.

CREATE TABLE IF NOT EXISTS oauth_state_store (
    id CHAR(36) PRIMARY KEY,
    provider VARCHAR(50) NOT NULL DEFAULT 'oidc',
    state_value VARCHAR(255) NOT NULL,
    data JSON NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_provider_state (provider, state_value),
    INDEX idx_expires_at (expires_at),
    INDEX idx_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;