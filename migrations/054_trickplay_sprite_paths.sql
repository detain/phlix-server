ALTER TABLE media_items
  ADD COLUMN trickplay_sprite_path VARCHAR(500) NULL AFTER chapters_json,
  ADD COLUMN trickplay_timeline_path VARCHAR(500) NULL;

CREATE INDEX idx_media_items_trickplay ON media_items (trickplay_sprite_path);
