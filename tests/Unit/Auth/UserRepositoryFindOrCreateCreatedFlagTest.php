<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S81 blocker #5 — `findOrCreateByExternalId()`'s `created` out-param must
 * report TRUE ONLY on the path that actually INSERTED a user.
 *
 * The out-param is the single signal AuthManager uses to decide whether to
 * mint the new account's FIRST profile. A mis-set flag has two failure
 * directions, both pinned here:
 *
 *  - TRUE on an EXISTING identity → an external user who already has profiles
 *    gets a duplicate 'Main' row every login (the flag must stay false on the
 *    `user_identities` join path AND the legacy `users` fallback path);
 *  - FALSE on a CREATE → a brand-new external account logs in profile-less
 *    (exactly the hole the flag exists to close).
 */
final class UserRepositoryFindOrCreateCreatedFlagTest extends TestCase
{
    /**
     * A Connection whose `query()` answers by SQL shape:
     *  - SELECT ... FROM user_identities → $identityRows
     *  - SELECT ... FROM users           → $userRows
     *  - INSERT/transaction otherwise    → benign.
     *
     * @param list<array<string, mixed>> $identityRows
     * @param list<array<string, mixed>> $userRows
     */
    private function db(array $identityRows, array $userRows, ?array &$txCalls = null): Connection
    {
        $txCalls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use ($identityRows, $userRows, &$txCalls) {
                if (str_contains($sql, 'FROM user_identities')) {
                    return $identityRows;
                }
                if (str_contains($sql, 'FROM users')) {
                    return $userRows;
                }
                if (str_starts_with(trim($sql), 'INSERT')) {
                    $txCalls[] = $sql;

                    return 1;
                }

                return [];
            },
        );
        $db->method('beginTrans')->willReturn(true);
        $db->method('commitTrans')->willReturn(true);
        $db->method('rollBackTrans')->willReturn(true);

        return $db;
    }

    public function test_createdFalseWhenIdentityJoinResolvesAnOwner(): void
    {
        $db = $this->db([['user_id' => 'user-owner']], []);
        $repo = new UserRepository($db);

        $created = null;
        $userId = $repo->findOrCreateByExternalId('oidc', 'ext-1', null, null, $created);

        $this->assertSame('user-owner', $userId);
        $this->assertFalse((bool)$created, 'an identity that resolves to an owner was NOT created');
    }

    public function test_createdFalseWhenLegacyUsersFallbackResolvesAnOwner(): void
    {
        $db = $this->db([], [['id' => 'user-legacy']]);
        $repo = new UserRepository($db);

        $created = null;
        $userId = $repo->findOrCreateByExternalId('oidc', 'ext-2', null, null, $created);

        $this->assertSame('user-legacy', $userId);
        $this->assertFalse((bool)$created, 'the fallback path found an existing user — not created');
    }

    public function test_createdTrueOnlyOnTheInsertPath(): void
    {
        $inserts = null;
        $db = $this->db([], [], $inserts);
        $repo = new UserRepository($db);

        $created = false;
        $userId = $repo->findOrCreateByExternalId('oidc', 'ext-new', null, null, $created);

        $this->assertNotSame('', $userId);
        $this->assertTrue($created, 'the insert path must report created=true');
        $this->assertIsArray($inserts);
        $this->assertCount(3, $inserts, 'users + user_settings + user_identities dual-write');
    }

    public function test_flagIsOptionalForExistingCallers(): void
    {
        // Four-arg calls (every pre-S81 caller) must still work unchanged.
        $db = $this->db([['user_id' => 'user-x']], []);
        $repo = new UserRepository($db);

        $this->assertSame('user-x', $repo->findOrCreateByExternalId('ldap', 'ext-x'));
    }
}
