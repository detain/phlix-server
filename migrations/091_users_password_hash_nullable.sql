-- Migration 091: relax `users.password_hash` to allow NULL for external users.
--
-- THE BUG THIS FIXES: `UserRepository::findOrCreateByExternalId()` inserts a new
-- external-provider (OIDC/LDAP) user with `password_hash = NULL` — an external
-- identity has no local password. But `users.password_hash` was created
-- `VARCHAR(255) NOT NULL` in migration 001 and never relaxed, so the very first
-- OIDC/LDAP login threw `SQLSTATE[23000] 1048 Column 'password_hash' cannot be
-- null` and 500'd. Migration 009's own header already documents that a user
-- linked to an external provider has `password_hash = NULL`; the intent was
-- always nullable — the column was just never ALTERed to match.
--
-- The code deliberately keeps inserting NULL (not '') for password_hash so a
-- genuinely passwordless account can never be matched by password verification.
--
-- Idempotent: a plain `MODIFY` is harmless on re-run — it simply re-asserts the
-- same nullable definition. Local accounts continue to store a real Argon2ID
-- hash; relaxing the column to NULL does not change that (they still write a
-- non-null value on registration).

ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL;
