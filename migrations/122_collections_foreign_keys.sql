-- Migration: 122_collections_foreign_keys.sql
-- Description: make the collections tables' claimed referential integrity real (minors M-SERVER-22, W-min102).
--
-- WHY: migration 005's column comments advertised a "FK to collections.id" /
-- "FK to media_items.id" and `collections` was described as library-owned, but
-- NO foreign key was ever declared on either table. Deleting a library, a
-- collection, or a media item therefore left `collection_items` / `collections`
-- rows behind FOREVER — dangling rows the read paths filter out by JOIN misses
-- while a naive COUNT or a later id-reuse (impossible with UUIDs, routine with
-- bug-report triage) met them. The cure is what the schema always claimed:
-- referential integrity, enforced by the server, with ON DELETE CASCADE so the
-- documented parent-child meaning (a collection dies with its library; its item
-- rows die with either endpoint) becomes executable fact.
--
-- ORDER: prune first, constrain second. Adding the FK to a table that still
-- carries orphans would fail 1452 on the integrity check MySQL performs over
-- existing rows, so every dangling edge is deleted BEFORE its constraint is
-- added — the same prune-then-constrain shape as migration 100 (D)->(H).
-- Prune order is parent-most first (collections-by-library, then item rows by
-- either endpoint) so a single pass converges with no re-run needed.
--
-- IDEMPOTENCY (replay law): the prunes delete what no longer exists on replay
-- (zero-row no-ops); each ADD CONSTRAINT is guarded by an
-- INFORMATION_SCHEMA.TABLE_CONSTRAINTS count on its EXPLICIT name (100's law:
-- a duplicate constraint name raises 1826, which is NOT on the runner's
-- idempotent-error list, so the guard is load-bearing, not cosmetic).
--
-- SCOPE: exactly the three edges named in the review. The `parent_id` (nesting)
-- and `smart_playlist_id` edges keep their constraint-free shape — the ask
-- names referential integrity for the advertised FKs only, and smart playlists
-- are nullable links with their own lifecycle; widening here would be an
-- un-asked behavior change. Index coverage needs no new indexes: the composite
-- PRIMARY KEY (collection_id, media_item_id) and idx_ci_media answer
-- `collection_items`, and idx_col_library answers `collections.library_id`.

SET @dbname = DATABASE();

-- (A) Prune collections whose library is gone (parent-most first — see header).
DELETE c FROM collections c
    LEFT JOIN libraries l ON c.library_id = l.id
    WHERE l.id IS NULL;

-- (B) Prune item rows whose collection is gone (includes rows orphaned by (A)).
DELETE ci FROM collection_items ci
    LEFT JOIN collections col ON ci.collection_id = col.id
    WHERE col.id IS NULL;

-- (C) Prune item rows whose media item is gone.
DELETE ci FROM collection_items ci
    LEFT JOIN media_items m ON ci.media_item_id = m.id
    WHERE m.id IS NULL;

-- (D) collections.library_id -> libraries.id, CASCADE (a library delete takes
-- its collections; their item rows follow via (E)).
SET @collectionsLibraryFkCount = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @dbname AND TABLE_NAME = 'collections'
      AND CONSTRAINT_NAME = 'fk_collections_library' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @addCollectionsLibraryFk = (SELECT IF(
    @collectionsLibraryFkCount = 0,
    'ALTER TABLE collections ADD CONSTRAINT fk_collections_library FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE',
    'SELECT 1'
));
PREPARE addCollectionsLibraryFk FROM @addCollectionsLibraryFk;
EXECUTE addCollectionsLibraryFk;
DEALLOCATE PREPARE addCollectionsLibraryFk;

-- (E) collection_items.collection_id -> collections.id, CASCADE (deleting a
-- collection removes its membership rows).
SET @collectionItemsCollectionFkCount = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @dbname AND TABLE_NAME = 'collection_items'
      AND CONSTRAINT_NAME = 'fk_collection_items_collection' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @addCollectionItemsCollectionFk = (SELECT IF(
    @collectionItemsCollectionFkCount = 0,
    'ALTER TABLE collection_items ADD CONSTRAINT fk_collection_items_collection FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE',
    'SELECT 1'
));
PREPARE addCollectionItemsCollectionFk FROM @addCollectionItemsCollectionFk;
EXECUTE addCollectionItemsCollectionFk;
DEALLOCATE PREPARE addCollectionItemsCollectionFk;

-- (F) collection_items.media_item_id -> media_items.id, CASCADE (deleting a
-- media item — e.g. a library-clear or a moved-away file removal — drops its
-- membership rows instead of leaving them dangling).
SET @collectionItemsMediaFkCount = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @dbname AND TABLE_NAME = 'collection_items'
      AND CONSTRAINT_NAME = 'fk_collection_items_media_item' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @addCollectionItemsMediaFk = (SELECT IF(
    @collectionItemsMediaFkCount = 0,
    'ALTER TABLE collection_items ADD CONSTRAINT fk_collection_items_media_item FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE',
    'SELECT 1'
));
PREPARE addCollectionItemsMediaFk FROM @addCollectionItemsMediaFk;
EXECUTE addCollectionItemsMediaFk;
DEALLOCATE PREPARE addCollectionItemsMediaFk;
