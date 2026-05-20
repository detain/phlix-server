<?php

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserProfileManager;
use Workerman\MySQL\Connection;

class UserProfileManagerTest extends TestCase
{
    private UserProfileManager $manager;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->manager = new UserProfileManager($this->db);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->manager->findById('non-existent-id');

        $this->assertNull($result);
    }

    public function testFindByIdReturnsProfileWhenFound(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'profile-1',
                'user_id' => 'user-1',
                'name' => 'Kids Profile',
                'avatar_url' => null,
                'is_active' => true,
                'is_admin' => false,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
                'content_rating' => 'G',
            ]
        ]);

        $result = $this->manager->findById('profile-1');

        $this->assertIsArray($result);
        $this->assertEquals('profile-1', $result['id']);
        $this->assertEquals('Kids Profile', $result['name']);
        $this->assertTrue($result['is_active']);
    }

    public function testFindByUserIdReturnsAllProfiles(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'profile-1',
                'user_id' => 'user-1',
                'name' => 'Profile 1',
                'avatar_url' => null,
                'is_active' => true,
                'is_admin' => true,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
                'content_rating' => 'R',
            ],
            [
                'id' => 'profile-2',
                'user_id' => 'user-1',
                'name' => 'Profile 2',
                'avatar_url' => null,
                'is_active' => false,
                'is_admin' => false,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
                'content_rating' => 'PG',
            ],
        ]);

        $result = $this->manager->findByUserId('user-1');

        $this->assertCount(2, $result);
        $this->assertEquals('profile-1', $result[0]['id']);
        $this->assertEquals('profile-2', $result[1]['id']);
    }

    public function testGetActiveProfileReturnsActiveProfile(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'profile-1',
                'user_id' => 'user-1',
                'name' => 'Active Profile',
                'avatar_url' => null,
                'is_active' => true,
                'is_admin' => true,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
                'content_rating' => 'R',
            ]
        ]);

        $result = $this->manager->getActiveProfile('user-1');

        $this->assertIsArray($result);
        $this->assertTrue($result['is_active']);
        $this->assertEquals('Active Profile', $result['name']);
    }

    public function testGetActiveProfileReturnsNullWhenNoActiveProfile(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->manager->getActiveProfile('user-1');

        $this->assertNull($result);
    }

    public function testCreateProfileSuccessfully(): void
    {
        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params) {
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return [['count' => 0]];
                }
                return [];
            });

        $id = $this->manager->create('user-1', [
            'name' => 'New Profile',
            'content_rating' => 'PG',
        ]);

        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{4}[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}[0-9a-f]{4}[0-9a-f]{4}$/',
            $id
        );
    }

    public function testCreateProfileThrowsExceptionWhenMaxReached(): void
    {
        $this->db->method('query')->willReturn([['count' => UserProfileManager::MAX_PROFILES_PER_USER]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of profiles');

        $this->manager->create('user-1', ['name' => 'Too Many Profiles']);
    }

    public function testCreateProfileThrowsExceptionForInvalidName(): void
    {
        $this->db->method('query')->willReturn([['count' => 0]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Profile name must be 1-100 characters');

        $this->manager->create('user-1', ['name' => '']);
    }

    public function testUpdateProfile(): void
    {
        $this->db->method('query')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT') !== false) {
                    return [[
                        'id' => 'profile-1',
                        'user_id' => 'user-1',
                        'name' => 'Old Name',
                        'avatar_url' => null,
                        'is_active' => false,
                        'is_admin' => false,
                        'created_at' => '2024-01-01 00:00:00',
                        'updated_at' => '2024-01-01 00:00:00',
                    ]];
                }
                return [];
            });

        $this->manager->update('profile-1', ['name' => 'New Name']);

        // Verify profile was found (no exception thrown)
        $this->assertTrue(true);
    }

    public function testUpdateProfileThrowsExceptionWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Profile not found');

        $this->manager->update('non-existent', ['name' => 'New Name']);
    }

    public function testSwitchProfile(): void
    {
        $this->db->method('query')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT') !== false) {
                    return [[
                        'id' => 'profile-1',
                        'user_id' => 'user-1',
                        'name' => 'Profile 1',
                        'avatar_url' => null,
                        'is_active' => false,
                        'is_admin' => false,
                        'created_at' => '2024-01-01 00:00:00',
                        'updated_at' => '2024-01-01 00:00:00',
                    ]];
                }
                return [];
            });

        $result = $this->manager->switchProfile('user-1', 'profile-1');

        $this->assertTrue($result);
    }

    public function testSwitchProfileReturnsFalseWhenProfileNotOwned(): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'profile-1',
                'user_id' => 'other-user',
                'name' => 'Profile 1',
                'avatar_url' => null,
                'is_active' => false,
                'is_admin' => false,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]
        ]);

        $result = $this->manager->switchProfile('user-1', 'profile-1');

        $this->assertFalse($result);
    }

    public function testDeleteProfile(): void
    {
        $this->db->method('query')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT') !== false) {
                    return [[
                        'id' => 'profile-1',
                        'user_id' => 'user-1',
                        'name' => 'Profile 1',
                        'avatar_url' => null,
                        'is_active' => false,
                        'is_admin' => false,
                        'created_at' => '2024-01-01 00:00:00',
                        'updated_at' => '2024-01-01 00:00:00',
                    ]];
                }
                return [];
            });

        $this->manager->delete('profile-1');

        // Verify delete was called (no exception thrown)
        $this->assertTrue(true);
    }

    public function testDeleteProfileThrowsExceptionWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Profile not found');

        $this->manager->delete('non-existent');
    }

    public function testVerifyPinReturnsTrueWhenNoPinSet(): void
    {
        $this->db->method('query')->willReturn([['pin_hash' => null]]);

        $result = $this->manager->verifyPin('profile-1', '1234');

        $this->assertTrue($result);
    }

    public function testVerifyPinReturnsTrueForCorrectPin(): void
    {
        $pinHash = password_hash('1234', PASSWORD_ARGON2ID);
        $this->db->method('query')->willReturn([['pin_hash' => $pinHash]]);

        $result = $this->manager->verifyPin('profile-1', '1234');

        $this->assertTrue($result);
    }

    public function testVerifyPinReturnsFalseForIncorrectPin(): void
    {
        $pinHash = password_hash('1234', PASSWORD_ARGON2ID);
        $this->db->method('query')->willReturn([['pin_hash' => $pinHash]]);

        $result = $this->manager->verifyPin('profile-1', '5678');

        $this->assertFalse($result);
    }

    public function testSetPin(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE profile_settings'),
                $this->anything()
            );

        $this->manager->setPin('profile-1', '1234');
    }

    public function testSetPinThrowsExceptionForInvalidLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PIN must be 4 or 6 digits');

        $this->manager->setPin('profile-1', '12345');
    }

    public function testSetPinThrowsExceptionForNonDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PIN must contain only digits');

        $this->manager->setPin('profile-1', '12ab');
    }

    public function testRemovePin(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE profile_settings SET pin_hash'),
                ['profile-1']
            );

        $this->manager->removePin('profile-1');
    }

    public function testIsContentRatingAllowed(): void
    {
        $this->db->method('query')->willReturn([
            ['content_rating' => 'PG', 'allow_unrated' => false]
        ]);

        // PG profile should allow G and PG
        $this->assertTrue($this->manager->isContentRatingAllowed('profile-1', 'G'));
        $this->assertTrue($this->manager->isContentRatingAllowed('profile-1', 'PG'));
        // But not R
        $this->assertFalse($this->manager->isContentRatingAllowed('profile-1', 'R'));
    }

    public function testIsContentRatingAllowedWithUnratedEnabled(): void
    {
        $this->db->method('query')->willReturn([
            ['content_rating' => 'PG', 'allow_unrated' => true]
        ]);

        $this->assertTrue($this->manager->isContentRatingAllowed('profile-1', 'UNRATED'));
    }

    public function testIsContentRatingAllowedReturnsTrueWhenNoSettings(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->manager->isContentRatingAllowed('profile-1', 'R');

        $this->assertTrue($result);
    }

    public function testGetAllowedRatings(): void
    {
        $this->db->method('query')->willReturn([
            ['content_rating' => 'PG-13', 'allow_unrated' => true]
        ]);

        $allowed = $this->manager->getAllowedRatings('profile-1');

        $this->assertContains('G', $allowed);
        $this->assertContains('PG', $allowed);
        $this->assertContains('PG-13', $allowed);
        $this->assertNotContains('R', $allowed);
        $this->assertContains('UNRATED', $allowed);
    }

    public function testGetAllowedRatingsReturnsAllWhenNoSettings(): void
    {
        $this->db->method('query')->willReturn([]);

        $allowed = $this->manager->getAllowedRatings('profile-1');

        $this->assertCount(7, $allowed);
    }
}
