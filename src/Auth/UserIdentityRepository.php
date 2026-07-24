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
 * `provider_instance` is NULLable and identifies WHICH configured instance of a
 * provider family an identity belongs to (S47 multi-instance, e.g. okta-oidc vs
 * azure-oidc). It is NULL for the single-instance identities that migration
 * 092's backfill creates and that {@see UserRepository::findOrCreateByExternalId()}
 * dual-writes.
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
     * @param string|null $providerInstance Configured-instance key (e.g. "okta-oidc"),
     *                                       or NULL for a single-instance provider.
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

        $this->db->query(
            "INSERT INTO user_identities
                (id, user_id, provider, provider_instance, external_id, provider_data)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $id,
                $userId,
                $provider,
                $providerInstance,
                $externalId,
                $this->encodeProviderData($providerData),
            ],
        );

        return $id;
    }

    /**
     * Find an identity by its external `(provider, provider_instance, external_id)` key.
     *
     * A NULL `$providerInstance` matches rows whose `provider_instance IS NULL`
     * (single-instance identities) — SQL `= NULL` never matches, so the null
     * case is expressed with `IS NULL`.
     *
     * @param string      $provider         Provider family.
     * @param string|null $providerInstance Configured-instance key, or NULL.
     * @param string      $externalId       Provider's unique identifier.
     *
     * @return array<string, mixed>|null Identity row, or null if not found.
     */
    public function findByProviderExternalId(
        string $provider,
        ?string $providerInstance,
        string $externalId
    ): ?array {
        if ($providerInstance === null) {
            $result = $this->db->query(
                "SELECT * FROM user_identities
                 WHERE provider = ? AND provider_instance IS NULL AND external_id = ?",
                [$provider, $externalId],
            );
        } else {
            $result = $this->db->query(
                "SELECT * FROM user_identities
                 WHERE provider = ? AND provider_instance = ? AND external_id = ?",
                [$provider, $providerInstance, $externalId],
            );
        }

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
     * requesting user actually owns. A NULL `$providerInstance` matches
     * `provider_instance IS NULL`.
     *
     * @param string      $userId           Owning local user UUID.
     * @param string      $provider         Provider family.
     * @param string|null $providerInstance Configured-instance key, or NULL.
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
        if ($providerInstance === null) {
            $this->db->query(
                "DELETE FROM user_identities
                 WHERE user_id = ? AND provider = ? AND provider_instance IS NULL AND external_id = ?",
                [$userId, $provider, $externalId],
            );
        } else {
            $this->db->query(
                "DELETE FROM user_identities
                 WHERE user_id = ? AND provider = ? AND provider_instance = ? AND external_id = ?",
                [$userId, $provider, $providerInstance, $externalId],
            );
        }
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
