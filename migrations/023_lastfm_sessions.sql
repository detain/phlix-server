-- Migration: 023_lastfm_sessions.sql
-- Step G.3 (post-O.7 wave 4): per-user Last.fm session-key store.
-- Each row ties one Phlex user to one Last.fm session_key obtained
-- via the auth.getSession handshake. Session keys do not expire
-- unless the user revokes them in their Last.fm settings.

CREATE TABLE IF NOT EXISTS lastfm_sessions (
    user_id         CHAR(36) NOT NULL,
    session_key     VARCHAR(64) NOT NULL,
    connected_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
