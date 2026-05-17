<?php

declare(strict_types=1);

namespace Phlex\Plugins\Ldap;

use RuntimeException;

final class UserMapper
{
    /** @var array<string, mixed> */
    private array $attributeMap;

    /**
     * @param array<string, mixed> $attributeMap
     */
    public function __construct(array $attributeMap = [])
    {
        $this->attributeMap = $attributeMap;
    }

    /**
     * @param array<string, mixed> $ldapEntry
     * @return array<string, mixed>
     */
    public function map(array $ldapEntry): array
    {
        $username = $this->extractUsername($ldapEntry);
        $email = $this->extractEmail($ldapEntry);
        $displayName = $this->extractDisplayName($ldapEntry);
        $avatarUrl = $this->extractAvatarUrl($ldapEntry);
        $rawAttributes = $this->extractRawAttributes($ldapEntry);

        $attributes = [
            'provider' => 'ldap',
        ];

        if ($username !== null) {
            $attributes['username'] = $username;
        }

        if ($email !== null) {
            $attributes['email'] = $email;
        }

        if ($displayName !== null) {
            $attributes['name'] = $displayName;
        }

        if ($avatarUrl !== null) {
            $attributes['avatarUrl'] = $avatarUrl;
        }

        if (!empty($rawAttributes)) {
            $attributes['rawAttributes'] = $rawAttributes;
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extractUsername(array $entry): ?string
    {
        $candidates = ['uid', 'samaccountname', 'userprincipalname', 'cn'];

        foreach ($candidates as $attr) {
            $value = $this->getFirstArrayStringValue($entry, $attr);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extractEmail(array $entry): ?string
    {
        $candidates = ['mail', 'userprincipalname', 'email'];

        foreach ($candidates as $attr) {
            $value = $this->getFirstArrayStringValue($entry, $attr);
            if ($value !== null && str_contains($value, '@')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extractDisplayName(array $entry): ?string
    {
        $candidates = ['displayname', 'cn', 'gecos', 'name'];

        foreach ($candidates as $attr) {
            $value = $this->getFirstArrayStringValue($entry, $attr);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extractAvatarUrl(array $entry): ?string
    {
        $jpegValue = $this->getFirstArrayStringValue($entry, 'jpegphoto');
        if ($jpegValue !== null) {
            return 'data:image/jpeg;base64,' . base64_encode($jpegValue);
        }

        $thumbValue = $this->getFirstArrayStringValue($entry, 'thumbnailphoto');
        if ($thumbValue !== null) {
            return 'data:image/jpeg;base64,' . base64_encode($thumbValue);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function findCaseInsensitiveKey(array $entry, string $key): ?string
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

    /**
     * @param array<string, mixed> $entry
     */
    private function getFirstArrayStringValue(array $entry, string $attr): ?string
    {
        $key = $this->findCaseInsensitiveKey($entry, $attr);
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
     * @return array<string, mixed>
     */
    private function extractRawAttributes(array $entry): array
    {
        $skipAttrs = ['dn', 'jpegphoto', 'thumbnailphoto'];
        $raw = [];

        foreach ($entry as $key => $value) {
            if (in_array($key, $skipAttrs, true)) {
                continue;
            }

            if (is_array($value) && count($value) === 1) {
                $raw[$key] = $value[0];
            } else {
                $raw[$key] = $value;
            }
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributeMap(): array
    {
        return $this->attributeMap;
    }
}
