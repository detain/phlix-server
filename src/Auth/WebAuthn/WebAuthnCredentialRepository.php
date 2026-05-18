<?php

declare(strict_types=1);

namespace Phlex\Auth\WebAuthn;

use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

final class WebAuthnCredentialRepository
{
    private Connection $db;
    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger = null;

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

        if (empty($result) || !is_array($result[0] ?? null)) {
            return null;
        }

        return WebAuthnCredential::fromDbRow($result[0]);
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

        $credentials = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $credentials[] = WebAuthnCredential::fromDbRow($row);
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

        if (empty($result)) {
            return null;
        }

        $credentials = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $credentials[] = WebAuthnCredential::fromDbRow($row);
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

        return $affected > 0;
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

        return is_array($result[0] ?? null) ? (int)(($result[0]['c'] ?? 0)) : 0;
    }
}
