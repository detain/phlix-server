-- Step D.4: webauthn_credentials table for passkey storage
-- Stores FIDO2/WebAuthn credential records linked to users.
-- credential_id stored as VARBINARY for efficient binary comparison.
-- sign counter (counter) used for replay attack detection.

CREATE TABLE webauthn_credentials (
    id              CHAR(36) PRIMARY KEY,
    user_id         CHAR(36) NOT NULL,
    credential_id   VARBINARY(255) NOT NULL,
    public_key      VARBINARY(512) NOT NULL,
    counter         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    type            VARCHAR(32) NOT NULL DEFAULT 'public-key',
    device_type     VARCHAR(64) NULL,
    aaguid          BINARY(16) NULL,
    registered_at   INT UNSIGNED NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_cred_id (credential_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
