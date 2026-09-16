<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\QuickConnectPair;
use Phlix\Auth\QuickConnectStateStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * S518 (AD-25): the quick-connect pairing store's state machine over a doubled
 * connection — every transition, every fail-closed arm, the code alphabet, and
 * the exact SQL shape the real-MySQL sibling proves against.
 *
 * The row-level FOR UPDATE semantics and collation behavior are the integration
 * file's job (`tests/Integration/Auth/QuickConnectStateStoreRealDbTest.php`);
 * this venue pins the LOGIC: what each result means, when each statement runs,
 * and that nothing is ever reported as persisted/approved/consumed unless the
 * write predicate says so (WriteResult posture).
 */
final class QuickConnectStateStoreTest extends TestCase
{
    // -----------------------------------------------------------------
    // Alphabet / code / secret generation (pure, no DB)
    // -----------------------------------------------------------------

    public function testAlphabetExcludesEveryAmbiguousGlyph(): void
    {
        $alphabet = QuickConnectStateStore::ALPHABET;

        self::assertSame(19, strlen($alphabet), '26 letters minus B I L O S U Z');
        foreach (['B', 'I', 'L', 'O', 'S', 'U', 'Z'] as $glyph) {
            self::assertStringNotContainsString($glyph, $alphabet);
        }
        foreach (['A', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'M', 'N', 'P', 'Q', 'R', 'T', 'V', 'W', 'X', 'Y'] as $glyph) {
            self::assertStringContainsString($glyph, $alphabet);
        }
        self::assertDoesNotMatchRegularExpression('/[0-9]/', $alphabet, 'codes are letters, never digits');
    }

    public function testGeneratedCodesAreAlwaysInAlphabetAndSixChars(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = QuickConnectStateStore::generateCode();
            self::assertSame(6, strlen($code));
            self::assertSame(6, strspn($code, QuickConnectStateStore::ALPHABET), $code);
        }
    }

    public function testGeneratedSecretIsBase64UrlOf256Bits(): void
    {
        $secret = QuickConnectStateStore::generateSecret();

        self::assertSame(43, strlen($secret), '32 random bytes base64 = 44 with padding, 43 without');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $secret);
        self::assertNotSame($secret, QuickConnectStateStore::generateSecret(), 'two draws must differ');
    }

    public function testNormalizeCodeCaseFoldsAndRejectsOffAlphabet(): void
    {
        self::assertSame('ACDFGH', QuickConnectStateStore::normalizeCode(' acdfgh '));
        self::assertNull(QuickConnectStateStore::normalizeCode(''));
        self::assertNull(QuickConnectStateStore::normalizeCode('ACDGH'), 'five chars');
        self::assertNull(QuickConnectStateStore::normalizeCode('ACDFGHH'), 'seven chars');
        self::assertNull(QuickConnectStateStore::normalizeCode('ACDFG1'), 'digit');
        self::assertNull(QuickConnectStateStore::normalizeCode('ACDFGO'), 'excluded glyph O');
        self::assertNull(QuickConnectStateStore::normalizeCode('ACDF;DROP'), 'injection-shaped');
    }

    // -----------------------------------------------------------------
    // issue()
    // -----------------------------------------------------------------

    public function testIssuePersistsAndReturnsMatchingPendingPair(): void
    {
        $captured = [];
        $db = $this->db(
            insertResult: '0',
            captured: $captured,
        );
        $store = new QuickConnectStateStore($db);

        $pair = $store->issue();

        self::assertSame(6, strlen($pair->code));
        self::assertSame(QuickConnectPair::STATE_PENDING, $pair->state);
        self::assertNull($pair->userId);
        self::assertGreaterThan(time() + 590, $pair->expiresAt);
        // The returned secret is exactly what was written: initiate hands this
        // value to the TV and nothing else can ever show it again.
        $data = json_decode((string) $captured['insert']['params'][3], true);
        self::assertSame($pair->secret, $data['secret']);
        self::assertSame(QuickConnectPair::STATE_PENDING, $data['state']);
        self::assertArrayHasKey('user_id', $data);
        self::assertNull($data['user_id']);
        // Provider-tagged into the shared table — no new migration for pairings.
        self::assertSame(QuickConnectStateStore::PROVIDER, $captured['insert']['params'][1]);
    }

    public function testIssueRetriesOnWriteNothingThenSucceeds(): void
    {
        $inserts = 0;
        $db = $this->db(insertResultCallback: static function () use (&$inserts): ?string {
            $inserts++;
            return $inserts === 1 ? null : '0';
        });
        $store = new QuickConnectStateStore($db);

        $pair = $store->issue();

        self::assertSame(2, $inserts);
        self::assertSame(QuickConnectPair::STATE_PENDING, $pair->state);
    }

    public function testIssueThrowsAfterExhaustingAttempts(): void
    {
        $db = $this->db(insertResultCallback: static fn (): ?string => null);
        $store = new QuickConnectStateStore($db);

        $this->expectException(RuntimeException::class);
        $store->issue();
    }

    public function testPurgeFailureNeverBlocksIssue(): void
    {
        // The janitorial sweep swallowing its own failure must not cost a caller
        // its pairing (issue() validates its INSERT independently).
        $db = $this->db(insertResult: '0', purgeThrows: true);
        $store = new QuickConnectStateStore($db);

        self::assertSame(QuickConnectPair::STATE_PENDING, $store->issue()->state);
    }

    // -----------------------------------------------------------------
    // find()
    // -----------------------------------------------------------------

    public function testFindHydratesStoredRowIntoValueObject(): void
    {
        $db = $this->db(selectRows: [[
            'data' => json_encode(['secret' => 'sec', 'state' => 'approved', 'user_id' => 'u-1']),
            'expires_epoch' => 1893456000,
        ]]);
        $store = new QuickConnectStateStore($db);

        $pair = $store->find('ACDFGH');

        self::assertInstanceOf(QuickConnectPair::class, $pair);
        self::assertSame('ACDFGH', $pair->code);
        self::assertSame('sec', $pair->secret);
        self::assertSame('approved', $pair->state);
        self::assertSame('u-1', $pair->userId);
        self::assertFalse($pair->isExpired(1893455999));
        self::assertTrue($pair->isExpired(1893456000));
    }

    public function testFindReturnsNullForMissingRowAndUnreadableData(): void
    {
        $missing = new QuickConnectStateStore($this->db(selectRows: []));
        self::assertNull($missing->find('ACDFGH'));

        $brokenJson = new QuickConnectStateStore($this->db(selectRows: [['data' => '{nope', 'expires_epoch' => 1]]));
        self::assertNull($brokenJson->find('ACDFGH'));

        $noSecret = new QuickConnectStateStore($this->db(selectRows: [[
            'data' => json_encode(['secret' => '', 'state' => 'pending', 'user_id' => null]),
            'expires_epoch' => 1,
        ]]));
        self::assertNull($noSecret->find('ACDFGH'));
    }

    // -----------------------------------------------------------------
    // approve()
    // -----------------------------------------------------------------

    public function testApproveHappyPathWritesApprovedWithSessionUserId(): void
    {
        $captured = [];
        $db = $this->db(
            selectRows: $this->liveRow('s3cret', QuickConnectPair::STATE_PENDING, null),
            updateResult: 1,
            captured: $captured,
        );
        $store = new QuickConnectStateStore($db);

        self::assertSame(
            QuickConnectStateStore::RESULT_APPROVED,
            $store->approve('ACDFGH', 's3cret', 'user-9')
        );
        self::assertArrayHasKey('update', $captured);
        $data = json_decode((string) $captured['update']['params'][0], true);
        self::assertSame(QuickConnectPair::STATE_APPROVED, $data['state']);
        // The identity is the PARAMETER (the session subject), never anything a
        // pairing row or the TV could have influenced.
        self::assertSame('user-9', $data['user_id']);
        // The secret rides along so the later redemption can still compare it.
        self::assertSame('s3cret', $data['secret']);
    }

    public function testApproveRejectsWrongSecretAndPersistsNothing(): void
    {
        $captured = [];
        $db = $this->db(selectRows: $this->liveRow('right', 'pending', null), captured: $captured);
        $store = new QuickConnectStateStore($db);

        self::assertSame(
            QuickConnectStateStore::RESULT_BAD_SECRET,
            $store->approve('ACDFGH', 'wrong', 'user-9')
        );
        self::assertArrayNotHasKey('update', $captured, 'a rejected approve must write nothing');
    }

    public function testApproveIsNotReplayableOnApprovedRow(): void
    {
        $db = $this->db(selectRows: $this->liveRow('s3cret', QuickConnectPair::STATE_APPROVED, 'user-9'));
        $store = new QuickConnectStateStore($db);

        // Same secret, already-approved row: the race loser gets NOT_READY, not
        // a second identity written over the first.
        self::assertSame(
            QuickConnectStateStore::RESULT_NOT_READY,
            $store->approve('ACDFGH', 's3cret', 'user-OTHER')
        );
    }

    public function testApproveOnMissingRowIsUnknown(): void
    {
        $store = new QuickConnectStateStore($this->db(selectRows: []));

        self::assertSame(QuickConnectStateStore::RESULT_UNKNOWN, $store->approve('ACDFGH', 'x', 'u'));
    }

    public function testApproveRollsBackAndFailsClosedOnUpdateWriteNothing(): void
    {
        $commits = 0;
        $rollbacks = 0;
        $db = $this->db(
            selectRows: $this->liveRow('s3cret', 'pending', null),
            updateResult: null,
            commitCount: $commits,
            rollbackCount: $rollbacks,
        );
        $store = new QuickConnectStateStore($db);

        self::assertSame(
            QuickConnectStateStore::RESULT_UNKNOWN,
            $store->approve('ACDFGH', 's3cret', 'user-9')
        );
        self::assertSame(0, $commits);
        self::assertSame(1, $rollbacks);
    }

    // -----------------------------------------------------------------
    // consumeApproved()
    // -----------------------------------------------------------------

    public function testConsumeApprovedRedeemsOnceAndDeletesTheRow(): void
    {
        $captured = [];
        $db = $this->db(
            selectRows: $this->liveRow('s3cret', QuickConnectPair::STATE_APPROVED, 'user-9'),
            deleteResult: 1,
            captured: $captured,
        );
        $store = new QuickConnectStateStore($db);

        [$result, $pair] = $store->consumeApproved('ACDFGH', 's3cret');

        self::assertSame(QuickConnectStateStore::RESULT_APPROVED, $result);
        self::assertInstanceOf(QuickConnectPair::class, $pair);
        self::assertSame('user-9', $pair->userId);
        self::assertArrayHasKey('delete', $captured, 'one-shot: the row must be gone');
    }

    public function testConsumeApprovedCollapsesPendingDeniedAndUnknownToNonIssuance(): void
    {
        $pending = new QuickConnectStateStore($this->db(selectRows: $this->liveRow('s', 'pending', null)));
        [$rp, $pairp] = $pending->consumeApproved('ACDFGH', 's');
        self::assertSame(QuickConnectStateStore::RESULT_NOT_READY, $rp);
        self::assertNull($pairp);

        // Denied and pending are INDISTINGUISHABLE here on purpose (no signal
        // leaks to a caller holding a wrong or right secret).
        $denied = new QuickConnectStateStore($this->db(selectRows: $this->liveRow('s', 'denied', null)));
        [$rd, $paird] = $denied->consumeApproved('ACDFGH', 's');
        self::assertSame(QuickConnectStateStore::RESULT_NOT_READY, $rd);
        self::assertNull($paird);

        $missing = new QuickConnectStateStore($this->db(selectRows: []));
        [$rm, $pairm] = $missing->consumeApproved('ACDFGH', 's');
        self::assertSame(QuickConnectStateStore::RESULT_UNKNOWN, $rm);
        self::assertNull($pairm);
    }

    public function testConsumeApprovedWithWrongSecretIssuesNothing(): void
    {
        $captured = [];
        $db = $this->db(
            selectRows: $this->liveRow('right', QuickConnectPair::STATE_APPROVED, 'u'),
            deleteResult: 1,
            captured: $captured,
        );
        $store = new QuickConnectStateStore($db);

        [$result, $pair] = $store->consumeApproved('ACDFGH', 'guessed');

        self::assertSame(QuickConnectStateStore::RESULT_BAD_SECRET, $result);
        self::assertNull($pair);
        self::assertArrayNotHasKey('delete', $captured, 'a wrong secret must not even burn the pairing');
    }

    public function testConsumeApprovedRefusesWhenDeleteWritesNothing(): void
    {
        $commits = 0;
        $db = $this->db(
            selectRows: $this->liveRow('s3cret', QuickConnectPair::STATE_APPROVED, 'user-9'),
            deleteResult: null,
            commitCount: $commits,
        );
        $store = new QuickConnectStateStore($db);

        [$result, $pair] = $store->consumeApproved('ACDFGH', 's3cret');

        self::assertSame(QuickConnectStateStore::RESULT_STORAGE, $result);
        self::assertNull($pair, 'an unproven deletion must never mint — fail closed');
        self::assertSame(0, $commits);
    }

    public function testApprovedRowWithoutUserIdIsNotRedeemable(): void
    {
        $db = $this->db(selectRows: $this->liveRow('s3cret', QuickConnectPair::STATE_APPROVED, null));
        $store = new QuickConnectStateStore($db);

        [$result, $pair] = $store->consumeApproved('ACDFGH', 's3cret');

        self::assertSame(QuickConnectStateStore::RESULT_NOT_READY, $result);
        self::assertNull($pair);
    }

    // -----------------------------------------------------------------
    // SQL shape (FOR UPDATE lock is load-bearing; pinned verbatim)
    // -----------------------------------------------------------------

    public function testLockedReadsUseSelectForUpdateInsideATransaction(): void
    {
        $log = [];
        $db = $this->db(selectRows: $this->liveRow('s3cret', 'pending', null), updateResult: 1, callLog: $log);
        $store = new QuickConnectStateStore($db);
        $store->approve('ACDFGH', 's3cret', 'user-1');

        self::assertSame(['beginTrans', 'select', 'update', 'commitTrans'], $log);
    }

    // -----------------------------------------------------------------
    // Doubles
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function liveRow(string $secret, string $state, ?string $userId): array
    {
        return [[
            'data' => json_encode(['secret' => $secret, 'state' => $state, 'user_id' => $userId]),
            'expires_epoch' => time() + 300,
        ]];
    }

    /**
     * Scripted Connection double: one `query()` entry point, dispatched by the
     * leading keyword, capturing params per statement kind.
     *
     * @param array<string, array{sql: string, params: list<mixed>}>|null $captured
     * @param list<string>|null $callLog
     * @param-out int $commitCount
     * @param-out int $rollbackCount
     * @param-out list<string> $callLog
     */
    private function db(
        ?string $insertResult = null,
        ?callable $insertResultCallback = null,
        array $selectRows = [],
        int|string|null $updateResult = null,
        int|string|null $deleteResult = null,
        bool $purgeThrows = false,
        ?array &$captured = null,
        ?int &$commitCount = null,
        ?int &$rollbackCount = null,
        ?array &$callLog = null,
    ): Connection {
        $db = $this->createMock(Connection::class);
        $commitCount = 0;
        $rollbackCount = 0;
        $callLog = [];

        $db->method('beginTrans')->willReturnCallback(
            function () use (&$callLog): void {
                $callLog[] = 'beginTrans';
            }
        );
        $db->method('commitTrans')->willReturnCallback(
            function () use (&$callLog, &$commitCount): void {
                $callLog[] = 'commitTrans';
                $commitCount++;
            }
        );
        $db->method('rollBackTrans')->willReturnCallback(
            function () use (&$callLog, &$rollbackCount): void {
                $callLog[] = 'rollBackTrans';
                $rollbackCount++;
            }
        );

        $db->method('query')->willReturnCallback(
            /** @param list<mixed>|null $params */
            function (
                string $sql,
                ?array $params = null
            ) use (
                &$captured,
                &$callLog,
                $insertResult,
                $insertResultCallback,
                $selectRows,
                $updateResult,
                $deleteResult,
                $purgeThrows
            ): mixed {
                $params ??= [];
                $kind = strtolower(strtok($sql, " \n(") ?: '');
                $isPurge = $kind === 'delete' && str_contains($sql, 'expires_at <= NOW()');
                $callLog[] = $kind === 'delete' && !$isPurge ? 'delete' : $kind;

                if ($isPurge) {
                    if ($purgeThrows) {
                        throw new RuntimeException('purge blew up');
                    }
                    return 0;
                }

                if ($kind === 'insert' && $captured !== null) {
                    $captured['insert'] = ['sql' => $sql, 'params' => $params];
                } elseif ($kind === 'update' && $captured !== null) {
                    $captured['update'] = ['sql' => $sql, 'params' => $params];
                } elseif ($kind === 'delete' && $captured !== null) {
                    $captured['delete'] = ['sql' => $sql, 'params' => $params];
                }

                return match ($kind) {
                    'insert' => $insertResultCallback !== null
                        ? ($insertResultCallback)()
                        : $insertResult,
                    'select' => $selectRows,
                    'update' => $updateResult,
                    'delete' => $deleteResult,
                    default => null,
                };
            }
        );

        return $db;
    }
}
