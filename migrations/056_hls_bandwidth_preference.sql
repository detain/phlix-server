-- Migration: 056_hls_bandwidth_preference.sql
-- P3B-S2: HLS bandwidth/rendition selection for relay sessions
-- Adds max_bandwidth_kbps column to livetv_relay_sessions for client-driven
-- HLS rendition selection. The server filters variant playlists to only
-- include renditions at or below the client's preferred bandwidth.

ALTER TABLE livetv_relay_sessions
    ADD COLUMN max_bandwidth_kbps INT UNSIGNED NULL AFTER bytes_relayed,
    ADD INDEX idx_livetv_relay_sessions_bandwidth (max_bandwidth_kbps);
