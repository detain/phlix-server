-- Migration: 108_first_admin_election.sql
-- Description: single-row election sentinel that serialises first-admin bootstrap (L-3, security audit 2026-09-29).
--
-- WHY THIS TABLE EXISTS:
-- AuthManager::register() decided "I am the first user" with
-- `countUsers() === 0` OUTSIDE the registration transaction, then promoted the
-- winner to admin. Two concurrent first registrations on a fresh install both
-- saw zero users and BOTH became admin — and on a `disabled` signup gate that
-- election check was also the only bypass, so the race could mint two
-- accounts on a closed server.
--
-- FIX SHAPE (mirrors the sentinel approach shipped in phlix-hub):
-- one guarded row, claimed with INSERT IGNORE inside the registration
-- transaction. InnoDB serialises the duplicate-key probes: the loser's INSERT
-- IGNORE blocks on the winner's uncommitted row until the winner commits, then
-- reports 0 affected rows, so exactly one transaction ever observes an
-- unclaimed sentinel. The winner stamps its own user id into `user_id` in the
-- SAME transaction as the user INSERT — a crash or rollback releases the claim
-- (the row vanishes with the transaction), so a failed first registration
-- never bricks the election.
--
-- `id` is fixed at 1; more than one row would itself be a bug (there is only
-- ever one first user).

CREATE TABLE IF NOT EXISTS first_admin_election (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id CHAR(36) NULL,
    claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
