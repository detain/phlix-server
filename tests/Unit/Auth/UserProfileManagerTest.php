<?php

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserProfileManager;
use Workerman\MySQL\Connection;

class UserProfileManagerTest extends TestCase
{
    private UserProfileManager $manager;
    /** @var Connection&MockObject */
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

    /**
     * FINDING 2: hydrateProfile() must expose the client-facing `rating` int as
     * the CANONICAL 0-based rank of the profile's content_rating (a KEY lookup
     * into RATING_ORDER, with NO `-1` offset). The old array_search-against-int
     * code always missed and defaulted every profile to 6.
     *
     * @dataProvider profileRatingRankCases
     */
    public function testFindByIdExposesCanonicalRatingRank(string $contentRating, int $expectedRank): void
    {
        $this->db->method('query')->willReturn([
            [
                'id' => 'profile-1',
                'user_id' => 'user-1',
                'name' => 'Profile',
                'avatar_url' => null,
                'is_active' => true,
                'is_admin' => false,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
                'content_rating' => $contentRating,
            ]
        ]);

        // findByIdWithSettings() runs the row through hydrateProfile().
        $result = $this->manager->findByIdWithSettings('profile-1');

        $this->assertIsArray($result);
        $this->assertSame($expectedRank, $result['rating']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function profileRatingRankCases(): array
    {
        return [
            'G is 0' => ['G', 0],
            'PG is 2' => ['PG', 2],
            'PG-13 is 3' => ['PG-13', 3],
            'TV-14 is 3' => ['TV-14', 3],
            'R is 4' => ['R', 4],
            'TV-MA is 4' => ['TV-MA', 4],
            'NC-17 is 5' => ['NC-17', 5],
            'X is 6' => ['X', 6],
            'UNRATED is 7' => ['UNRATED', 7],
        ];
    }

    public function testFindByIdDefaultsUnknownRatingToUnratedRank(): void
    {
        // An unrecognized content_rating defaults to the most-restrictive
        // UNRATED rank (7), not a lenient value.
        $this->db->method('query')->willReturn([
            [
                'id' => 'profile-1',
                'user_id' => 'user-1',
                'name' => 'Profile',
                'avatar_url' => null,
                'is_active' => true,
                'is_admin' => false,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
                'content_rating' => 'BOGUS',
            ]
        ]);

        $result = $this->manager->findByIdWithSettings('profile-1');

        $this->assertIsArray($result);
        $this->assertSame(7, $result['rating']);
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
        $capturedSql = [];
        $this->db->method('query')
            ->willReturnCallback(function ($sql) use (&$capturedSql) {
                $capturedSql[] = $sql;
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

        // update() reads the profile to confirm it exists, then issues the UPDATE
        $this->assertGreaterThanOrEqual(2, count($capturedSql));
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
        $capturedSql = [];
        $this->db->method('query')
            ->willReturnCallback(function ($sql) use (&$capturedSql) {
                $capturedSql[] = $sql;
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

        // delete() reads the profile to confirm it exists, then issues the delete
        $this->assertGreaterThanOrEqual(2, count($capturedSql));
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

    /**
     * Wire the mock DB for getActiveRatingFilter(): the first query
     * (user_profiles JOIN) resolves the active profile, the second
     * (profile_settings) resolves its cap. Either can be forced empty.
     *
     * @param array<string, mixed>|null $profileRow  Active-profile row, or null.
     * @param array<string, mixed>|null $settingsRow profile_settings row, or null.
     */
    private function wireRatingFilterDb(?array $profileRow, ?array $settingsRow): void
    {
        $this->db->method('query')->willReturnCallback(
            function (string $sql) use ($profileRow, $settingsRow) {
                if (str_contains($sql, 'FROM profile_settings')) {
                    return $settingsRow === null ? [] : [$settingsRow];
                }
                // getActiveProfile()'s user_profiles JOIN query.
                return $profileRow === null ? [] : [$profileRow];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function activeProfileRow(bool $isAdmin = false): array
    {
        return [
            'id' => 'profile-1',
            'user_id' => 'user-1',
            'name' => 'Kid',
            'avatar_url' => null,
            'is_active' => true,
            'is_admin' => $isAdmin,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
            'content_rating' => 'PG-13',
        ];
    }

    public function testGetActiveRatingFilterReturnsNullWhenNoActiveProfile(): void
    {
        // No active profile → permissive (owner/no-profile default).
        $this->wireRatingFilterDb(null, null);

        $this->assertNull($this->manager->getActiveRatingFilter('user-1'));
    }

    public function testGetActiveRatingFilterReturnsNullForAdminProfile(): void
    {
        // The account owner / admin profile must never be rating-filtered.
        $this->wireRatingFilterDb($this->activeProfileRow(true), [
            'content_rating' => 'PG-13',
            'allow_unrated' => 0,
        ]);

        $this->assertNull($this->manager->getActiveRatingFilter('user-1'));
    }

    public function testGetActiveRatingFilterReturnsNullWhenNoSettingsRow(): void
    {
        // Active non-admin profile but no parental-controls row → no cap.
        $this->wireRatingFilterDb($this->activeProfileRow(false), null);

        $this->assertNull($this->manager->getActiveRatingFilter('user-1'));
    }

    public function testGetActiveRatingFilterReturnsNullForMostPermissiveCap(): void
    {
        // A cap of UNRATED (max rank) allows everything → treated as no filter.
        $this->wireRatingFilterDb($this->activeProfileRow(false), [
            'content_rating' => 'UNRATED',
            'allow_unrated' => 1,
        ]);

        $this->assertNull($this->manager->getActiveRatingFilter('user-1'));
    }

    public function testGetActiveRatingFilterBuildsPg13AllowListInterleavingTv(): void
    {
        // A PG-13 cap allows TV-14 (same rank) and everything below; excludes
        // R/TV-MA and above, and never lists the UNRATED string.
        $this->wireRatingFilterDb($this->activeProfileRow(false), [
            'content_rating' => 'PG-13',
            'allow_unrated' => 1,
        ]);

        $filter = $this->manager->getActiveRatingFilter('user-1');

        $this->assertIsArray($filter);
        $this->assertTrue($filter['allowUnrated']);
        foreach (['G', 'TV-Y', 'TV-G', 'TV-Y7', 'PG', 'TV-PG', 'PG-13', 'TV-14'] as $rating) {
            $this->assertContains($rating, $filter['allowedRatings']);
        }
        foreach (['R', 'TV-MA', 'NC-17', 'X', 'UNRATED'] as $rating) {
            $this->assertNotContains($rating, $filter['allowedRatings']);
        }
    }

    public function testGetActiveRatingFilterHonorsAllowUnratedFalse(): void
    {
        $this->wireRatingFilterDb($this->activeProfileRow(false), [
            'content_rating' => 'PG',
            'allow_unrated' => 0,
        ]);

        $filter = $this->manager->getActiveRatingFilter('user-1');

        $this->assertIsArray($filter);
        $this->assertFalse($filter['allowUnrated']);
        $this->assertContains('PG', $filter['allowedRatings']);
        $this->assertContains('TV-PG', $filter['allowedRatings']);
        $this->assertNotContains('PG-13', $filter['allowedRatings']);
    }
}
