<?php

declare(strict_types=1);

namespace Phlex\Plugins\Ldap;

use Phlex\Shared\Auth\UserInfo as SharedUserInfo;

final class LdapUserInfo
{
    private string $externalId;
    private ?string $username;
    private ?string $email;
    private ?string $displayName;
    private ?string $avatarUrl;
    /** @var array<string, mixed> */
    private array $rawAttributes;
    private bool $isAdmin;

    /**
     * @param array<string, mixed> $rawAttributes
     */
    public function __construct(
        string $externalId,
        ?string $username = null,
        ?string $email = null,
        ?string $displayName = null,
        ?string $avatarUrl = null,
        array $rawAttributes = [],
        bool $isAdmin = false,
    ) {
        $this->externalId = $externalId;
        $this->username = $username;
        $this->email = $email;
        $this->displayName = $displayName;
        $this->avatarUrl = $avatarUrl;
        $this->rawAttributes = $rawAttributes;
        $this->isAdmin = $isAdmin;
    }

    /**
     * @param array<string, mixed> $entry
     */
    /**
     * @param array<string, mixed> $entry
     */
    public static function fromLdapEntry(array $entry, string $baseDn, bool $isAdmin = false): self
    {
        $dnRaw = $entry['dn'] ?? '';
        $dn = is_string($dnRaw) ? $dnRaw : '';

        $username = self::getFirstStringValue($entry, ['uid', 'samaccountname', 'userprincipalname']);
        $email = self::getFirstStringValue($entry, ['mail', 'userprincipalname']);
        $displayName = self::getFirstStringValue($entry, ['displayname', 'cn']);
        $avatarUrl = self::getFirstBinaryValue($entry, ['jpegphoto', 'thumbnailphoto']);

        return new self(
            externalId: $dn !== '' ? $dn : ($username ?? ''),
            username: $username,
            email: $email,
            displayName: $displayName,
            avatarUrl: $avatarUrl,
            rawAttributes: $entry,
            isAdmin: $isAdmin,
        );
    }

    /**
     * @param array<string, mixed> $entry
     * @param string[] $candidates
     */
    private static function getFirstStringValue(array $entry, array $candidates): ?string
    {
        foreach ($candidates as $attr) {
            $value = self::getFirstArrayStringValue($entry, $attr);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @param string[] $candidates
     */
    private static function getFirstBinaryValue(array $entry, array $candidates): ?string
    {
        foreach ($candidates as $attr) {
            $value = self::getFirstArrayStringValue($entry, $attr);
            if ($value !== null) {
                return 'data:image/jpeg;base64,' . base64_encode($value);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function getFirstArrayStringValue(array $entry, string $attr): ?string
    {
        $key = self::findCaseInsensitiveKey($entry, $attr);
        if ($key === null) {
            return null;
        }

        $value = $entry[$key];
        if (!is_array($value) || !isset($value[0]) || !is_string($value[0])) {
            return null;
        }

        return $value[0];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function findCaseInsensitiveKey(array $entry, string $key): ?string
    {
        if (isset($entry[$key])) {
            return $key;
        }

        $lowerKey = strtolower($key);
        foreach (array_keys($entry) as $k) {
            if (strtolower($k) === $lowerKey) {
                return $k;
            }
        }

        return null;
    }

    public function toUserInfo(): SharedUserInfo
    {
        return new SharedUserInfo(
            externalId: $this->externalId,
            email: $this->email,
            displayName: $this->displayName,
            avatarUrl: $this->avatarUrl,
            rawAttributes: $this->rawAttributes,
        );
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawAttributes(): array
    {
        return $this->rawAttributes;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function hasEmail(): bool
    {
        return $this->email !== null;
    }

    public function hasDisplayName(): bool
    {
        return $this->displayName !== null;
    }

    public function hasAvatarUrl(): bool
    {
        return $this->avatarUrl !== null;
    }
}
