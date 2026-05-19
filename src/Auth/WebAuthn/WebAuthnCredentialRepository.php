<?php

declare(strict_types=1);

namespace Phlex\Auth\WebAuthn;

use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

final class WebAuthnCredentialRepository
{
    private Connection $db;
    private ?LoggerInterface $logger;

    public function __construct(Connection $db, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function findByCredentialId(string $credentialId): ?WebAuthnCredential
    {
        $result = $this->db->query(
            "SELECT * FROM webauthn_credentials WHERE credential_id = ?",
            [$credentialId]
        );

        if (!is_array($result) || $result === []) {
            return null;
        }

        $row = $result[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return WebAuthnCredential::fromDbRow(self::normalizeRow($row));
    }

    /**
     * @return array<WebAuthnCredential>
     */
    public function findByUserId(string $userId): array
    {
        $result = $this->db->query(
            "SELECT * FROM webauthn_credentials WHERE user_id = ? ORDER BY registered_at DESC",
            [$userId]
        );

        if (!is_array($result)) {
            return [];
        }

        $credentials = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $credentials[] = WebAuthnCredential::fromDbRow(self::normalizeRow($row));
            }
        }

        return $credentials;
    }

    /**
     * @return array<WebAuthnCredential>|null
     */
    public function findByUsername(string $username): ?array
    {
        $result = $this->db->query(
            "SELECT wc.* FROM webauthn_credentials wc INNER JOIN users u ON u.id = wc.user_id WHERE u.username = ?",
            [$username]
        );

        if (!is_array($result) || $result === []) {
            return null;
        }

        $credentials = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $credentials[] = WebAuthnCredential::fromDbRow(self::normalizeRow($row));
            }
        }

        return $credentials;
    }

    public function save(WebAuthnCredential $credential, string $id): void
    {
        $aaguid = $credential->aaguid;
        if ($aaguid !== null && strlen($aaguid) < 16) {
            $aaguid = str_pad($aaguid, 16, "\0", STR_PAD_RIGHT);
        }

        $this->db->query(
            "INSERT INTO webauthn_credentials (id, user_id, credential_id, public_key, counter, type, device_type, aaguid, registered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $credential->userId,
                $credential->credentialId,
                $credential->publicKey,
                $credential->counter,
                $credential->type,
                $credential->deviceType,
                $aaguid,
                $credential->registeredAt,
            ]
        );

        $this->logger?->info('webauthn.credential.saved', [
            'user_id' => $credential->userId,
            'credential_id' => base64_encode($credential->credentialId),
        ]);
    }

    public function updateCounter(string $credentialId, int $newCounter): void
    {
        $this->db->query(
            "UPDATE webauthn_credentials SET counter = ? WHERE credential_id = ?",
            [$newCounter, $credentialId]
        );
    }

    public function delete(string $credentialId, string $userId): bool
    {
        $affected = $this->db->query(
            "DELETE FROM webauthn_credentials WHERE credential_id = ? AND user_id = ?",
            [$credentialId, $userId]
        );

        return is_int($affected) && $affected > 0;
    }

    public function deleteAllForUser(string $userId): int
    {
        $result = $this->db->query(
            "DELETE FROM webauthn_credentials WHERE user_id = ?",
            [$userId]
        );

        return is_int($result) ? $result : 0;
    }

    public function countForUser(string $userId): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as c FROM webauthn_credentials WHERE user_id = ?",
            [$userId]
        );

        if (!is_array($result)) {
            return 0;
        }

        $row = $result[0] ?? null;
        if (!is_array($row)) {
            return 0;
        }

        $count = $row['c'] ?? 0;
        if (is_int($count)) {
            return $count;
        }
        if (is_string($count) && is_numeric($count)) {
            return (int) $count;
        }
        return 0;
    }

    /**
     * Ensures the row is keyed by string and types are predictable for
     * {@see WebAuthnCredential::fromDbRow()} (which expects
     * `array<string, mixed>`). Workerman's MySQL connection returns
     * `array<mixed, mixed>` in its declared return type.
     *
     * @param array<mixed, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }
}
