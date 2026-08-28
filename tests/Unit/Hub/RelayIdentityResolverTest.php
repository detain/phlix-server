<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use Phlix\Auth\UserIdentityRepository;
use Phlix\Hub\RelayIdentityResolver;
use PHPUnit\Framework\TestCase;

/**
 * S301 — the server-side hub-user → server-user resolution.
 *
 * The resolver reads ONLY the `user_identities` table (provider `hub`): a row
 * exists only after the account owner linked their hub identity by presenting a
 * hub JWT the server verified cryptographically. There is no other way in.
 */
final class RelayIdentityResolverTest extends TestCase
{
    private function resolver(?array $row): RelayIdentityResolver
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn($row);

        return new RelayIdentityResolver($identities);
    }

    public function testMappedPrincipalResolvesToTheServerUserId(): void
    {
        $resolver = $this->resolver(['user_id' => 'server-user-9']);

        self::assertSame('server-user-9', $resolver->resolve('hub-uuid-1'));
    }

    public function testUnmappedPrincipalResolvesToNull(): void
    {
        $resolver = $this->resolver(null);

        self::assertNull($resolver->resolve('hub-uuid-unknown'));
    }

    public function testRowWithoutUserIdResolvesToNull(): void
    {
        $resolver = $this->resolver(['provider' => 'hub', 'external_id' => 'x']);

        self::assertNull($resolver->resolve('x'));
    }

    /** The empty principal is refused before any query — absent header, no lookup. */
    public function testEmptyPrincipalResolvesToNullWithoutQuerying(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects(self::never())->method('findByProviderExternalId');

        $resolver = new RelayIdentityResolver($identities);

        self::assertNull($resolver->resolve(''));
    }

    /**
     * The lookup key is the provider family + the exact stamped principal —
     * nothing else can ever match, so a client-supplied server-user id cannot
     * alias a hub linkage.
     */
    public function testTheLookupIsScopedToTheHubProviderFamily(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects(self::once())
            ->method('findByProviderExternalId')
            ->with('hub', '', 'hub-uuid-1')
            ->willReturn(['user_id' => 'server-user-9']);

        $resolver = new RelayIdentityResolver($identities);

        self::assertSame('server-user-9', $resolver->resolve('hub-uuid-1'));
    }
}