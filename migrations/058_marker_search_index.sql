-- Migration: 058_marker_search_index.sql
-- Purpose: Add composite index for marker search queries (P3B-S8)
--   - Supports GET /api/v1/media/search/by-marker queries filtering by marker_type
--   - Supports ranking by start_time_ms for "similar content" recommendations
--   - Speeds up chapter-based media discovery

CREATE INDEX idx_media_markers_type_start ON media_markers (marker_type, start_time_ms);
