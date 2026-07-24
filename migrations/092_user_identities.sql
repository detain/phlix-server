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
-- `provider_instance` distinguishes two configured instances of the same
-- provider family (e.g. okta-oidc vs azure-oidc, or github vs
-- github-enterprise) — S47. It is `NOT NULL DEFAULT ''`: the empty string is
-- the sentinel for a single-instance / DEFAULT identity (the kind this
-- migration backfills and that S45 first-login dual-writes). Real, non-empty
-- instance names arrive with S47 and are ordinary values.
--
-- WHY NOT NULL (this is load-bearing — the UNIQUE below depends on it): in
-- MySQL/InnoDB a multi-column UNIQUE index treats NULLs as DISTINCT. Two rows
-- (oidc, NULL, X) and (oidc, NULL, X) are BOTH accepted, because NULL is never
-- equal to NULL for the uniqueness comparison. So if `provider_instance` were
-- NULL for every single-instance row (as an earlier draft of this migration
-- had it), UNIQUE(provider, provider_instance, external_id) would NOT reject a
-- duplicate (provider, external_id) — the exact duplicate that S45 linking and
-- this backfill MUST reject (two local accounts linking the same external
-- identity; a backfill inserting the same identity twice). Storing the non-NULL
-- sentinel '' makes the comparison ('' = '') a real equality, so the UNIQUE
-- index genuinely enforces one identity per (provider, default-instance,
-- external_id). Non-empty S47 instance names are compared the same way.
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
    provider_instance VARCHAR(64) NOT NULL DEFAULT '',
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
-- is the default-instance sentinel '' (NOT NULL — see the DDL note above).
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
-- IDEMPOTENT + DUPLICATE-SAFE: two guards work together.
--
--  (1) `WHERE NOT EXISTS` (per user) — re-running after a partial failure never
--      re-inserts a row this user already has. The NOT EXISTS is keyed on
--      (user_id, external_id, provider_instance = '') — the backfill emits at
--      most one default-instance row per users row, and `users` already
--      enforces UNIQUE(provider, external_id).
--
--  (2) `INSERT IGNORE` — collapses LEGACY DUPLICATE external accounts to a
--      single identity row. Now that the UNIQUE index actually enforces (the
--      sentinel is the non-NULL ''), two DIFFERENT users can derive to the SAME
--      identity key: a legacy `provider='external'` row with
--      external_id='oidc.<sub>' and a post-S44 twin `provider='oidc'` row with
--      the SAME external_id both derive to (oidc, '', 'oidc.<sub>').
--      `users.UNIQUE(provider, external_id)` lets both users exist (the provider
--      column differs), so a plain INSERT...SELECT would attempt two rows with
--      the same identity key and FAIL the whole migration on the UNIQUE
--      violation at deploy time. INSERT IGNORE keeps whichever row InnoDB
--      reaches first and silently skips the duplicate, so AT MOST ONE
--      user_identities row exists per (derived_provider, '', external_id). The
--      "losing" account is NOT locked out: login still reads
--      `users.provider`/`users.external_id` (S46 leaves `users` authoritative)
--      and that users row is untouched. This is a pre-existing data-quality
--      edge, realistically empty in production because the OIDC/LDAP login path
--      was dead before S44 (so no post-S44 twin of a legacy 'external' row can
--      have been created yet).
INSERT IGNORE INTO user_identities (id, user_id, provider, provider_instance, external_id, provider_data)
SELECT
    UUID() AS id,
    u.id AS user_id,
    CASE
        WHEN u.provider = 'external' AND u.external_id LIKE 'oidc.%' THEN 'oidc'
        WHEN u.provider = 'external' AND u.external_id LIKE 'ldap.%' THEN 'ldap'
        ELSE u.provider
    END AS provider,
    '' AS provider_instance,
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
          AND ui.provider_instance = ''
  );
