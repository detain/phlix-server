-- Step D.1: add provider/external_id/provider_data columns to users table
-- These columns support pluggable external auth providers (OIDC, LDAP, SAML, passkeys).
-- A local user linked to an external provider has password_hash = NULL and
-- provider/external_id set. provider_data stores JSON metadata from the provider.

ALTER TABLE users
    ADD COLUMN provider      VARCHAR(64)     NULL AFTER password_hash,
    ADD COLUMN external_id   VARCHAR(255)    NULL,
    ADD COLUMN provider_data  JSON            NULL,
    ADD INDEX idx_provider (provider),
    ADD UNIQUE INDEX idx_external (provider, external_id);
