<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Common\Uuid;
use Workerman\MySQL\Connection;

/**
 * Repository for the `user_identities` join table (migration 092).
 *
 * `user_identities` is the multi-identity home for external auth: a single
 * local account can carry several external identities (e.g. GitHub AND Google
 * AND LDAP) alongside a local password. Each row links a `user_id` to an
 * external `(provider, provider_instance, external_id)` triple, unique across
 * that triple.
 *
 * `provider_instance` identifies WHICH configured instance of a provider family
 * an identity belongs to (S47 multi-instance, e.g. okta-oidc vs azure-oidc). It
 * is `NOT NULL DEFAULT ''`: the empty string is the sentinel for a
 * single-instance / DEFAULT identity — the kind migration 092's backfill
 * creates and that {@see UserRepository::findOrCreateByExternalId()} dual-writes.
 *
 * The sentinel is DELIBERATELY not NULL. A multi-column MySQL/InnoDB UNIQUE
 * index treats NULLs as DISTINCT, so a NULLable `provider_instance` would let
 * two `(provider, NULL, external_id)` rows coexist and the
 * `UNIQUE (provider, provider_instance, external_id)` index (migration 092)
 * would NOT reject a duplicate identity. Storing '' makes the uniqueness
 * comparison real, so the DB genuinely enforces one identity per
 * `(provider, default-instance, external_id)`. Because '' is the canonical
 * default, this repository accepts `null` for a default-instance argument but
 * COERCES it to '' before touching the DB (never emitting a NULL predicate).
 *
 * Consumers:
 *  - S45 account-linking endpoint ({@see create()} / {@see findByProviderExternalId()}).
 *  - S47 multi-instance registry + unlink ({@see deleteById()} / {@see delete()}).
 *  - {@see UserRepository::findOrCreateByExternalId()} dual-write on first
 *    external login.
 *
 * The login READ path deliberately still reads `users.provider` /
 * `users.external_id` ({@see UserRepository::findByExternalId()}); repointing it
 * onto this table is deferred to S47. This repository is therefore write-mostly
 * for S46 and does not alter existing login behaviour.
 *
 * Idioms mirror {@see UserRepository}: constructor takes a single
 * {@see Connection}, every statement is parameterized, reads return plain PHP
 * arrays (never a ResultSet-narrowing wrapper), and UUIDs come from the shared
 * {@see Uuid} helper.
 *
 * @property Connection $db Database connection instance
 */
class UserIdentityRepository
{
    /** @var Connection Database connection for MySQL queries */
    private Connection $db;

    /**
     * Create a new UserIdentityRepository instance.
     *
     * @param Connection $db Workerman MySQL connection instance.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new external identity row for a user.
     *
     * @param string      $userId           Owning local user UUID (FK → users.id).
     * @param string      $provider         Provider family (e.g. "oidc", "ldap", "github").
     * @param string|null $providerInstance Configured-instance key (e.g. "okta-oidc").
     *                                       Pass '' (or null, which is coerced to '')
     *                                       for a single-instance / default provider —
     *                                       the column is NOT NULL, and '' is the
     *                                       uniqueness-enforcing default sentinel.
     * @param string      $externalId       Provider's unique identifier for the user.
     * @param array<string, mixed>|string|null $providerData Provider metadata: an array
     *                                       is JSON-encoded, a string is stored verbatim
     *                                       (assumed already-JSON), NULL stores NULL.
     *
     * @return string The new identity row UUID.
     */
    public function create(
        string $userId,
        string $provider,
        ?string $providerInstance,
        string $externalId,
        array|string|null $providerData = null
    ): string {
        $id = Uuid::v4();

        // provider_instance is NOT NULL DEFAULT '' (migration 092): a null
        // default-instance argument maps to the '' sentinel so the UNIQUE index
        // actually enforces (a NULL would make the index treat the row as
        // distinct from every other single-instance identity).
        $instance = $providerInstance ?? '';

        $this->db->query(
            "INSERT INTO user_identities
                (id, user_id, provider, provider_instance, external_id, provider_data)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $id,
                $userId,
                $provider,
                $instance,
                $externalId,
                $this->encodeProviderData($providerData),
            ],
        );

        return $id;
    }

    /**
     * Find an identity by its external `(provider, provider_instance, external_id)` key.
     *
     * `provider_instance` is NOT NULL DEFAULT '' (migration 092), so the lookup
     * is always a plain `provider_instance = ?` equality — no `IS NULL` special
     * case. A null default-instance argument is coerced to the '' sentinel, and
     * real S47 instance names are ordinary non-empty values.
     *
     * @param string      $provider         Provider family.
     * @param string|null $providerInstance Configured-instance key; '' or null (coerced
     *                                       to '') selects the default single instance.
     * @param string      $externalId       Provider's unique identifier.
     *
     * @return array<string, mixed>|null Identity row, or null if not found.
     */
    public function findByProviderExternalId(
        string $provider,
        ?string $providerInstance,
        string $externalId
    ): ?array {
        $result = $this->db->query(
            "SELECT * FROM user_identities
             WHERE provider = ? AND provider_instance = ? AND external_id = ?",
            [$provider, $providerInstance ?? '', $externalId],
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return $row;
    }

    /**
     * List all external identities linked to a user.
     *
     * @param string $userId Owning local user UUID.
     *
     * @return array<int, array<string, mixed>> Identity rows (may be empty).
     */
    public function findByUserId(string $userId): array
    {
        $result = $this->db->query(
            "SELECT * FROM user_identities WHERE user_id = ? ORDER BY created_at ASC",
            [$userId],
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = array_values(array_filter($result, 'is_array'));

        return $rows;
    }

    /**
     * Delete an identity by its row id.
     *
     * @param string $id Identity row UUID.
     *
     * @return void
     */
    public function deleteById(string $id): void
    {
        $this->db->query(
            "DELETE FROM user_identities WHERE id = ?",
            [$id],
        );
    }

    /**
     * Delete an identity by its external `(user_id, provider, provider_instance,
     * external_id)` key.
     *
     * Scoped by `user_id` as well so an unlink can only remove an identity the
     * requesting user actually owns. `provider_instance` is NOT NULL DEFAULT ''
     * (migration 092), so the match is always a plain `provider_instance = ?`
     * equality; a null default-instance argument is coerced to the '' sentinel.
     *
     * @param string      $userId           Owning local user UUID.
     * @param string      $provider         Provider family.
     * @param string|null $providerInstance Configured-instance key; '' or null (coerced
     *                                       to '') targets the default single instance.
     * @param string      $externalId       Provider's unique identifier.
     *
     * @return void
     */
    public function delete(
        string $userId,
        string $provider,
        ?string $providerInstance,
        string $externalId
    ): void {
        $this->db->query(
            "DELETE FROM user_identities
             WHERE user_id = ? AND provider = ? AND provider_instance = ? AND external_id = ?",
            [$userId, $provider, $providerInstance ?? '', $externalId],
        );
    }

    /**
     * Normalize the provider_data argument into a storable column value.
     *
     * @param array<string, mixed>|string|null $providerData Raw provider metadata.
     *
     * @return string|null JSON string for an array, the string verbatim, or NULL.
     */
    private function encodeProviderData(array|string|null $providerData): ?string
    {
        if ($providerData === null) {
            return null;
        }

        if (is_string($providerData)) {
            return $providerData;
        }

        $encoded = json_encode($providerData);

        return $encoded === false ? null : $encoded;
    }
}
