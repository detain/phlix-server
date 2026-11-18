<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\ProfileNotOwnedException;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * The approve-time profile resolution quick-connect stores.
 *
 * An approval stores one concrete profile id, resolved when the approval is made. A session
 * that carries its own profile keeps it, once verified as owned. A session with no profile
 * claim resolves to the account's active profile, read the way the schedule gate reads it. A
 * stored id the account no longer owns is refused, never degraded to the default.
 */
final class QuickConnectApprovalProfileTest extends TestCase
{
    private const USER = 'user-1';

    /** @var list<string> Profile ids the account owns. */
    private array $owned = ['profile-own'];

    /** @var array<string, mixed>|null The active profile the account reports. */
    private ?array $active = null;

    private function manager(bool $withProfiles = true): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        $profiles = null;
        if ($withProfiles) {
            $profiles = $this->createMock(UserProfileManager::class);
            $profiles->method('getActiveProfile')->willReturnCallback(fn (): ?array => $this->active);
            $profiles->method('resolveProfileIdForUser')->willReturnCallback(
                function (string $userId, ?string $requested = null): string {
                    if ($requested !== null && in_array($requested, $this->owned, true)) {
                        return $requested;
                    }

                    throw ProfileNotOwnedException::forRequestedProfile($userId, (string) $requested);
                }
            );
        }

        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            new StructuredLogger('test', ['handlers' => []]),
            profileManager: $profiles,
        );
    }

    public function testASessionWithNoProfileClaimStoresTheAccountsActiveProfile(): void
    {
        $this->active = ['id' => 'profile-active'];

        self::assertSame('profile-active', $this->manager()->approvalProfileId(self::USER, null));
    }

    public function testASessionWithNoProfileAndNoActiveProfileResolvesToNothing(): void
    {
        $this->active = null;

        self::assertNull($this->manager()->approvalProfileId(self::USER, null));
    }

    public function testASessionOwnProfileIsStoredAfterAnOwnershipCheck(): void
    {
        self::assertSame('profile-own', $this->manager()->approvalProfileId(self::USER, 'profile-own'));
    }

    public function testASessionProfileTheAccountDoesNotOwnIsRefusedNotDefaulted(): void
    {
        $this->active = ['id' => 'profile-active'];

        $this->expectException(ProfileNotOwnedException::class);
        $this->manager()->approvalProfileId(self::USER, 'profile-stranger');
    }

    public function testWithNoProfileResolverTheApprovalResolvesToNothingAndNoOwnershipIsAssumed(): void
    {
        self::assertNull($this->manager(withProfiles: false)->approvalProfileId(self::USER, null));

        $this->expectException(ProfileNotOwnedException::class);
        $this->manager(withProfiles: false)->requireOwnedProfile(self::USER, 'profile-own');
    }
}
