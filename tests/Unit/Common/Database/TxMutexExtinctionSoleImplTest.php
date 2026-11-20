<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;

/**
 * TXMUTEX-B retrofit sole-impl tripwires for the server connection.
 *
 * The transaction-mutex mechanics (holder identity, nesting ledger, poison
 * mark, end-claim ladder, hand-off ordering, refusal/cancellation wording)
 * retired into the shared machine `Phlix\Shared\Database\MysqlTxLockState`
 * / `TxEnd` / `MysqlRetryPolicy`. Design law (D-02): the estate must carry
 * ONE implementation of those laws, so this file asserts — over the real
 * repo tree, statically, no I/O — that the retired local names never come
 * back and the shared wordings are never re-inlined.
 *
 * Own-coverage law: the test asserts its own existence at its canonical
 * path first, so renaming or deleting this file reddens the suite from
 * inside the suite (a sibling copy of the hub-side twin exists because
 * each repo guards its own consumer wiring).
 */
final class TxMutexExtinctionSoleImplTest extends TestCase
{
    private const CONNECTION_FILE = __DIR__ . '/../../../../src/Common/Database/PhlixMySQLConnection.php';

    /** Retired members of PhlixMySQLConnection (state moved into the machine). */
    private const RETIRED_MEMBERS = [
        '$this->transLockHolder',
        '$this->transNesting',
        '$this->txPoisoned',
        'releaseTransLock(',
    ];

    /** Phrases the shared machine owns — no src/ THROW may carry them inline. */
    private const MACHINE_OWNED_MESSAGES = [
        'There is already an active transaction',
        'Could not acquire the transaction mutex: the coroutine was cancelled while waiting',
        'Transaction was lost when the connection dropped; refusing to commit.',
        '(connection lost inside a transaction; not retried)',
    ];

    public function testTheTripwireItselfIsPresentAtItsCanonicalPath(): void
    {
        self::assertFileExists(
            __DIR__ . '/TxMutexExtinctionSoleImplTest.php',
            'the TXMUTEX-B sole-impl tripwire must live at tests/Unit/Common/Database/'
        );
    }

    public function testRetiredTransactionLedgerMembersAreGoneFromTheConnection(): void
    {
        $code = self::connectionCodeWithoutComments();

        foreach (self::RETIRED_MEMBERS as $member) {
            self::assertStringNotContainsString(
                $member,
                $code,
                "TXMUTEX-B retired '$member' — the ledger lives in MysqlTxLockState; "
                . 're-adding a local copy forks the state machine.'
            );
        }

        // The fields themselves must not be declared either (covers $txState
        // consumers reading raw ledger props via other shapes).
        foreach (['transLockHolder', 'transNesting', 'txPoisoned'] as $name) {
            self::assertStringNotContainsString(
                'private bool $' . $name,
                $code,
                "the $name property declaration must stay retired"
            );
            self::assertStringNotContainsString(
                'private int $' . $name,
                $code,
                "the $name property declaration must stay retired"
            );
        }
    }

    public function testTheConnectionDelegatesThroughTheSharedMachineApi(): void
    {
        // Comment-stripped (register M2 lesson): the needles below are CODE
        // facts; scanning raw source let a mutation pass by leaving the same
        // words in an explanatory comment.
        $code = self::connectionCodeWithoutComments();

        // Positive pins (assert own coverage): if these ever read as false the
        // consumer has quietly re-grown its own mechanics or lost the wiring.
        // V059 row 3 re-aim: the begin ceremony's acquire/markOpen/fold arms
        // moved INSIDE the shared lease (differentially proven zero-delta,
        // SRV59-item3-diff-pre-swap.log), so the needles track the new wiring
        // — the lease construction is the delegation shape now. The nested
        // arm's markOpen() stays a code fact at the connection (vendor-throw
        // identity posture, landed D-2); handOff is pinned in its cid-bound
        // two-argument form.
        foreach (
            [
                'use Phlix\Shared\Database\MysqlTxLockState;',
                'use Phlix\Shared\Database\TxEnd;',
                'use Phlix\Shared\Database\TxLease;',
                'use Phlix\Shared\Database\MysqlRetryPolicy;',
                'claimCommit(',
                'claimRollback(',
                'new TxLease(',
                '$lease->begin(',
                'holdingTransactionFor(',
                '->markOpen(',
            ] as $needle
        ) {
            self::assertStringContainsString($needle, $code, "the retrofit wiring '$needle' must stay present");
        }

        self::assertStringContainsString(
            'handOff($this->pushTransToken(...), $cid)',
            $code,
            'the transaction-end releases must stay bound to the ceremony coroutine id '
            . '(v0.59 bank row 1: a release whose holder moved is inert, not clobbering)'
        );
        self::assertStringContainsString(
            'fn(): bool => parent::beginTrans()',
            $code,
            'the begin ceremony must stay delegated to the shared lease '
            . '(its acquire / catch clearPoison:false / fold / markOpen laws are pinned by '
            . 'the machine characterization suite + the landing differential)'
        );
    }

    public function testMachineOwnedMessageWordingsAreNeverReInlinedInThrowStatements(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../../src', \FilesystemIterator::SKIP_DOTS)
        );

        $scanned = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            $code = self::stripComments((string)file_get_contents($file->getPathname()));
            if ($code === '') {
                continue;
            }

            // Every `throw new PDOException(...)` argument list in the file.
            preg_match_all('/throw\s+new\s+\\\\?PDOException\s*\((.*?)\)\s*;/s', $code, $throws);

            foreach ($throws[1] as $argumentList) {
                foreach (self::MACHINE_OWNED_MESSAGES as $message) {
                    self::assertStringNotContainsString(
                        $message,
                        $argumentList,
                        'the wording ' . json_encode($message) . ' is owned by the shared machine '
                        . '(MysqlTxLockState consts / MysqlRetryVerdict::lostInTransactionSuffix()) — '
                        . 'throwing a re-inlined copy in ' . $file->getPath() . ' forks the contract'
                    );
                }
            }
        }

        // Measured 2026-11-19: 806 PHP files under src/. The floor exists to
        // catch a silently-broken scan (cwd/iterator regression), not to pin
        // the corpus size — keep generous headroom below the measured value.
        self::assertGreaterThan(500, $scanned, 'the src/ corpus scan must actually run');
    }

    /**
     * The connection class source with comments stripped, so the historical
     * prose that NAMES the retired fields (incident narratives on 2026-07-31
     * and the field-mapping docblock) stays readable while code regressions
     * of the same names still redden the test.
     */
    private static function connectionCodeWithoutComments(): string
    {
        $code = file_get_contents(self::CONNECTION_FILE);
        self::assertIsString($code);

        return self::stripComments($code);
    }

    private static function stripComments(string $code): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $code);
        self::assertIsString($out);
        $out = preg_replace('!//[^\n]*!', '', $out);
        self::assertIsString($out);

        return $out;
    }
}
