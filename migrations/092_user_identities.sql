-- Migration 092: introduce the `user_identities` join table for multi-identity
-- external auth (OAuth/OIDC/LDAP + a local password all on one account).
--
-- BACKGROUND: an external identity currently lives INLINE on `users`
-- (`users.provider` / `users.external_id` / `users.provider_data`, migration
-- 009, UNIQUE(provider, external_id)). That models exactly ONE external
-- identity per user. To support GitHub AND Google AND LDAP AND a local password
-- on a single account (S45 linking, S47 multi-instance, S48 GitHub provider),
-- identities move into their own row-per-identity table.
--
-- `provider_instance` (NULLable) distinguishes two configured instances of the
-- same provider family (e.g. okta-oidc vs azure-oidc, or github vs
-- github-enterprise) — S47. It is NULL for the single-instance identities
-- backfilled here. NOTE (MySQL): a UNIQUE index treats NULLs as DISTINCT, so
-- NULL-instance rows never collide with one another on the instance column
-- alone; they only collide when (provider, external_id) ALSO match — which is
-- precisely the duplicate we want to reject.
--
-- LOGIN IS PRESERVED: this migration does NOT touch the `users` table.
-- `users.provider` / `users.external_id` remain the authoritative login-lookup
-- columns (UserRepository::findByExternalId / findOrCreateByExternalId keep
-- reading `users`), so every user who could log in before this migration can
-- still log in after it — the login read path is byte-for-byte unchanged. This
-- table is the forward-looking home that S45 (linking) writes to and S47
-- (multi-instance + unlink) consumes. Repointing the login READ path onto
-- `user_identities` is deferred to S47.

-- Step 1: create the join table.
-- `provider_data` is typed JSON to match `users.provider_data` (migration 009).
-- The identity row carries its own CHAR(36) UUID primary key (consistent with
-- every other table's UUID PK) so it can be addressed/deleted directly (S47
-- unlink). ON DELETE CASCADE mirrors user_settings/user_profiles: an identity
-- has no meaning once its owning user is gone.
CREATE TABLE IF NOT EXISTS user_identities (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    provider VARCHAR(64) NOT NULL,
    provider_instance VARCHAR(64) NULL,
    external_id VARCHAR(255) NOT NULL,
    provider_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_identity (provider, provider_instance, external_id),
    INDEX idx_user_identities_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 2: backfill one `user_identities` row per existing external-identity
-- user. All pre-existing identities are single-instance, so provider_instance
-- is NULL.
--
-- PROVIDER DERIVATION for LEGACY rows: before S44 (bug #4),
-- findOrCreateByExternalId hardcoded `users.provider = 'external'` for every
-- external login regardless of which provider actually authenticated, while the
-- provider itself prefixed `external_id` with its family ('oidc.' / 'ldap.' —
-- e.g. LdapProvider returns 'ldap.' . $dn). Recover the REAL provider from that
-- prefix. Post-S44 rows already carry the correct `users.provider`
-- ('oidc' / 'ldap'), so they pass through the CASE unchanged. An 'external' row
-- whose external_id has NO known prefix is NOT dropped — it keeps
-- provider='external' (fallback branch of the CASE); we never silently lose an
-- identity row.
--
-- external_id and provider_data are copied AS-IS: `users.external_id` stays the
-- authoritative login key (login still reads `users`), so the identity row must
-- carry the identical value for S47 to repoint reads onto it without breaking
-- the round trip.
--
-- IDEMPOTENT: `INSERT ... SELECT ... WHERE NOT EXISTS` so re-running after a
-- partial failure never duplicates. The NOT EXISTS is keyed on
-- (user_id, external_id, provider_instance IS NULL) — the backfill emits at
-- most one NULL-instance row per users row, and `users` already enforces
-- UNIQUE(provider, external_id), so this key uniquely identifies an
-- already-backfilled identity. (The UNIQUE(provider, provider_instance,
-- external_id) index is a second line of defence.)
INSERT INTO user_identities (id, user_id, provider, provider_instance, external_id, provider_data)
SELECT
    UUID() AS id,
    u.id AS user_id,
    CASE
        WHEN u.provider = 'external' AND u.external_id LIKE 'oidc.%' THEN 'oidc'
        WHEN u.provider = 'external' AND u.external_id LIKE 'ldap.%' THEN 'ldap'
        ELSE u.provider
    END AS provider,
    NULL AS provider_instance,
    u.external_id AS external_id,
    u.provider_data AS provider_data
FROM users u
WHERE u.external_id IS NOT NULL
  AND u.provider IS NOT NULL
  AND NOT EXISTS (
        SELECT 1
        FROM user_identities ui
        WHERE ui.user_id = u.id
          AND ui.external_id = u.external_id
          AND ui.provider_instance IS NULL
  );
