-- Migration: 125_newsletter_queue_user_week_unique.sql
--
-- review deferred-item #54 (dba2 minor m2): the (user_id, week_start) index
-- on newsletter_queue shipped NON-UNIQUE (migration 020), so a re-fired
-- weekly campaign DOUBLE-QUEUED recipients — two pending rows meant two
-- emails. The queue writer (src/Admin/NewsletterSender.php::queueAll) now
-- relies on the unique key added here (INSERT IGNORE: a duplicate
-- (user, week) re-fire is a no-op). Existing duplicates must be collapsed
-- BEFORE the UNIQUE can land — MySQL refuses the index while any (user_id,
-- week_start) group holds 2+ rows — hence data-then-schema, one file, in
-- order.
--
-- Survivor rule: the EARLIEST queued row of each (user_id, week_start) group
-- wins — created_at first, CHAR(36) id as the deterministic tiebreak (two
-- INSERTs inside one second are realistic). Later re-fire rows are deleted.
-- Nothing references queue rows from outside the table, and the survivor
-- carries the group's full status/attempts history.
--
-- The dedup is a JOIN-formed multi-table self-delete — the 1093
-- "target table in FROM subquery" prohibition does NOT apply to this shape,
-- which is the pre-window-functions standard MySQL idiom for
-- "delete all but the keeper row per group".
--
-- Idempotence: ADD/DROP INDEX have no IF-[NOT-]EXISTS form, so both schema
-- steps follow the INFORMATION_SCHEMA + PREPARE guard idiom of migrations
-- 107/121/123/124 — a replay short-circuits each step to SELECT 1 and never
-- eats error 1061/1091 mid-file. The dedup DELETE is replay-safe by
-- construction: the first pass removes every duplicate, and once the UNIQUE
-- is live none can form. No dialect directive anywhere: per the M28 law a
-- directive is both unnecessary here and incapable of guarding the
-- SET + dynamic-prepare shape (124's measured MariaDB-10.6 venue proof of
-- this idiom covers every statement below verbatim).

-- 1. Collapse pre-existing re-fire duplicates (keep-earliest per group).
DELETE `dup`
FROM `newsletter_queue` `dup`
JOIN `newsletter_queue` `keeper`
  ON `keeper`.`user_id` = `dup`.`user_id`
 AND `keeper`.`week_start` = `dup`.`week_start`
 AND (
        `keeper`.`created_at` < `dup`.`created_at`
     OR (`keeper`.`created_at` = `dup`.`created_at` AND `keeper`.`id` < `dup`.`id`)
 );

-- 2. Retire the now-redundant non-UNIQUE pair index (the UNIQUE below
-- answers every read the old one served, at the same key order).
SET @dbname = DATABASE();
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'newsletter_queue' AND INDEX_NAME = 'idx_user_week') > 0,
    'ALTER TABLE newsletter_queue DROP INDEX idx_user_week',
    'SELECT 1'
));
PREPARE alterNewsletterQueueIndex FROM @preparedStatement;
EXECUTE alterNewsletterQueueIndex;
DEALLOCATE PREPARE alterNewsletterQueueIndex;

-- 3. Land the UNIQUE dedup gate (guarded separately so a replay after a
-- partial apply — steps 1+2 done, step 3 not — still completes).
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'newsletter_queue' AND INDEX_NAME = 'uk_newsletter_user_week') = 0,
    'ALTER TABLE newsletter_queue ADD UNIQUE KEY uk_newsletter_user_week (user_id, week_start)',
    'SELECT 1'
));
PREPARE addNewsletterUnique FROM @preparedStatement;
EXECUTE addNewsletterUnique;
DEALLOCATE PREPARE addNewsletterUnique;
