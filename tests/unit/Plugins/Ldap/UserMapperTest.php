<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Ldap;

use PHPUnit\Framework\TestCase;
use Phlex\Plugins\Ldap\UserMapper;

final class UserMapperTest extends TestCase
{
    public function test_map_openldap_entry(): void
    {
        $mapper = new UserMapper();

        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'cn' => ['Test User'],
            'mail' => ['testuser@example.com'],
            'jpegPhoto' => [],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertSame('ldap', $result['provider']);
        $this->assertSame('testuser', $result['username']);
        $this->assertSame('testuser@example.com', $result['email']);
        $this->assertSame('Test User', $result['name']);
    }

    public function test_map_active_directory_entry(): void
    {
        $mapper = new UserMapper();

        $ldapEntry = [
            'dn' => 'CN=Test User,OU=Users,DC=example,DC=com',
            'sAMAccountName' => ['testuser'],
            'displayName' => ['Test User'],
            'userPrincipalName' => ['testuser@example.com'],
            'mail' => ['testuser@example.com'],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertSame('ldap', $result['provider']);
        $this->assertSame('testuser', $result['username']);
        $this->assertSame('testuser@example.com', $result['email']);
        $this->assertSame('Test User', $result['name']);
    }

    public function test_map_with_jpeg_photo(): void
    {
        $mapper = new UserMapper();

        $photoData = 'fake-jpeg-data';
        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'cn' => ['Test User'],
            'mail' => ['testuser@example.com'],
            'jpegphoto' => [$photoData],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertSame('ldap', $result['provider']);
        $this->assertSame('testuser', $result['username']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $result['avatarUrl']);
    }

    public function test_map_with_thumbnail_photo(): void
    {
        $mapper = new UserMapper();

        $photoData = 'fake-thumbnail-data';
        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'cn' => ['Test User'],
            'mail' => ['testuser@example.com'],
            'thumbnailphoto' => [$photoData],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertSame('ldap', $result['provider']);
        $this->assertSame('testuser', $result['username']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $result['avatarUrl']);
    }

    public function test_map_prefers_mail_over_userprincipalname(): void
    {
        $mapper = new UserMapper();

        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'mail' => ['preferred@example.com'],
            'userprincipalname' => ['testuser@example.com'],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertSame('preferred@example.com', $result['email']);
    }

    public function test_map_prefers_displayname_over_cn(): void
    {
        $mapper = new UserMapper();

        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'displayname' => ['Preferred Name'],
            'cn' => ['Common Name'],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertSame('Preferred Name', $result['name']);
    }

    public function test_map_handles_missing_email(): void
    {
        $mapper = new UserMapper();

        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'cn' => ['Test User'],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertSame('ldap', $result['provider']);
        $this->assertSame('testuser', $result['username']);
        $this->assertArrayNotHasKey('email', $result);
    }

    public function test_map_handles_missing_display_name(): void
    {
        $mapper = new UserMapper();

        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'mail' => ['testuser@example.com'],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertSame('ldap', $result['provider']);
        $this->assertSame('testuser', $result['username']);
        $this->assertArrayNotHasKey('name', $result);
    }

    public function test_map_includes_raw_attributes(): void
    {
        $mapper = new UserMapper();

        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'cn' => ['Test User'],
            'mail' => ['testuser@example.com'],
            'objectclass' => ['top', 'person', 'organizationalPerson', 'inetOrgPerson'],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertArrayHasKey('rawAttributes', $result);
        $this->assertSame('testuser', $result['rawAttributes']['uid']);
        $this->assertSame('Test User', $result['rawAttributes']['cn']);
        $this->assertSame('testuser@example.com', $result['rawAttributes']['mail']);
    }

    public function test_map_excludes_dn_from_raw_attributes(): void
    {
        $mapper = new UserMapper();

        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'cn' => ['Test User'],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertArrayHasKey('rawAttributes', $result);
        $this->assertArrayNotHasKey('dn', $result['rawAttributes']);
    }

    public function test_avatar_download(): void
    {
        $mapper = new UserMapper();

        $jpegData = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoH');
        $ldapEntry = [
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'cn' => ['Test User'],
            'jpegphoto' => [$jpegData],
        ];

        $result = $mapper->map($ldapEntry);

        $this->assertStringStartsWith('data:image/jpeg;base64,', $result['avatarUrl']);
        $decoded = base64_decode(substr($result['avatarUrl'], strlen('data:image/jpeg;base64,')));
        $this->assertSame($jpegData, $decoded);
    }

    public function test_get_attribute_map(): void
    {
        $customMap = ['custom' => 'mapping'];
        $mapper = new UserMapper($customMap);

        $this->assertSame($customMap, $mapper->getAttributeMap());
    }
}
