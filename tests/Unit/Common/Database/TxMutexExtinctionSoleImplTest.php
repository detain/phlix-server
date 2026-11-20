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
 *
 * V059 row 4 upgraded both scans from regex to token_get_all walking
 * (estate exemplar: SharedDelegationCensusTest's grammar classifier):
 * the wording law is now class-agnostic (a machine wording thrown under
 * ANY exception class convicts), join-aware (dot-split literals convict),
 * heredoc- and interpolation-aware, and comment-proof — comment prose can
 * neither smuggle a conviction nor forge a false positive, because
 * comments are simply not tokens the scanner ever reads.
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
        $root = __DIR__ . '/../../../../src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $scanned = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;

            // V059 row 4: the token scanner replaced the old comment-strip +
            // '/throw new PDOException\((.*?)\);/s' regex law, which had real
            // bypasses — a machine wording thrown under a DIFFERENT exception
            // class, one split across a dot concatenation, one wrapped in a
            // heredoc, or prose smuggled past the strip (`#` comments were
            // invisible to it entirely). Every throw STATEMENT is now read
            // class-agnostically to its top-level ';', and its literal
            // payloads are joined — while non-throw uses of the wordings
            // (message-comparison prose, a variable hold) never convict.
            $tokens = token_get_all((string)file_get_contents($file->getPathname()));
            foreach (self::throwStatementTexts($tokens) as [$line, $text]) {
                foreach (self::MACHINE_OWNED_MESSAGES as $message) {
                    self::assertStringNotContainsString(
                        $message,
                        $text,
                        'the wording ' . json_encode($message) . ' is owned by the shared machine '
                        . '(MysqlTxLockState consts / MysqlRetryVerdict::lostInTransactionSuffix()) — '
                        . 'a throw statement at ' . substr($file->getPathname(), strlen($root) + 1) . ':' . $line
                        . ' carries a re-inlined copy, which forks the contract'
                    );
                }
            }
        }

        // Measured 2026-11-23 at the V059 tip: 806 PHP files under src/
        // (781 throw-statement literal sites, zero convictions under the
        // broadened law). The floor exists to catch a silently-broken scan
        // (cwd/iterator regression), not to pin the corpus size — keep
        // generous headroom below the measured value.
        self::assertGreaterThan(500, $scanned, 'the src/ corpus scan must actually run');
    }

    /**
     * The law's own teeth (V059 row 4): synthetic plants run through the
     * same scanner the src/ sweep uses — the source tree is never dirtied.
     * The first block names the bypasses the old regex law let through;
     * the second names the shapes the new law must refuse to convict.
     */
    public function testCanaryThrowScanShapes(): void
    {
        $convicting = [
            'plain-PDOException' => <<<'PHP'
                <?php
                throw new \PDOException('There is already an active transaction');
                PHP,
            'other-exception-class' => <<<'PHP'
                <?php
                throw new \RuntimeException('Could not acquire the transaction mutex: the coroutine was cancelled while waiting');
                PHP,
            'dot-split-literal' => <<<'PHP'
                <?php
                throw new \LogicException('There is already an active ' . 'transaction');
                PHP,
            'interpolated-dq' => <<<'PHP'
                <?php
                $trace = 'x';
                throw new \RuntimeException("Transaction was lost when the connection dropped; refusing to commit. $trace");
                PHP,
            'heredoc' => <<<'PHP'
                <?php
                throw new \RuntimeException(<<<TXT
                (connection lost inside a transaction; not retried)
                TXT);
                PHP,
            'nested-call-argument' => <<<'PHP'
                <?php
                $id = 1;
                throw new \RuntimeException(sprintf('%s for %s', 'There is already an active transaction', $id));
                PHP,
        ];
        foreach ($convicting as $shape => $source) {
            self::assertNotEmpty(
                self::machineWordingHits($source),
                "canary: the '$shape' throw shape must convict under the token law"
            );
        }

        $clean = [
            'hash-comment-smuggler' => <<<'PHP'
                <?php
                # throw new \PDOException('There is already an active transaction');
                $x = 1;
                PHP,
            'line-comment-smuggler' => <<<'PHP'
                <?php
                // throw new \PDOException('There is already an active transaction');
                $x = 1;
                PHP,
            'block-comment-smuggler' => <<<'PHP'
                <?php
                /*
                 * throw new \PDOException('There is already an active transaction');
                 */
                $x = 1;
                PHP,
            'doc-comment-smuggler' => <<<'PHP'
                <?php
                /** throw new \PDOException('There is already an active transaction'); */
                $x = 1;
                PHP,
            'comment-inside-throw-args' => <<<'PHP'
                <?php
                throw new \RuntimeException('boom' /* 'There is already an active transaction' */);
                PHP,
            'variable-hold-indirection' => <<<'PHP'
                <?php
                $reason = 'There is already an active transaction';
                throw new \RuntimeException($reason);
                PHP,
            'message-comparison-prose' => <<<'PHP'
                <?php
                if ($e->getMessage() === 'There is already an active transaction') {
                    throw new \LogicException('rethrown elsewhere');
                }
                PHP,
            'bare-rethrow' => <<<'PHP'
                <?php
                throw $previous;
                PHP,
        ];
        foreach ($clean as $shape => $source) {
            self::assertSame(
                [],
                self::machineWordingHits($source),
                "canary: the '$shape' shape must never convict"
            );
        }
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

    /**
     * Token-based comment removal (V059 row 4). The old regex pair missed
     * `#` line comments entirely and was blind to heredocs — payload that
     * merely looked like a comment opener, or comment prose carrying
     * close-delimiter-like spans, corrupted the reconstructed code either
     * way. Dropping T_COMMENT/T_DOC_COMMENT and rejoining the rest is
     * exact; every removed comment leaves a newline behind so two code
     * lines can never splice into a false substring adjacency.
     */
    private static function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $out .= $token;
                continue;
            }
            $out .= $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT ? "\n" : $token[1];
        }

        return $out;
    }

    /**
     * The throw-region law's reader: every THROW statement's assembled
     * literal text, class-agnostically, walking to the statement's
     * top-level ';' (depth-tracked), returning [line, joined text] for
     * each throw that carries at least one string literal.
     *
     * Scope law (honest limits, same spirit as the census classifier):
     * BYTE wordings in throw regions convict; indirection — a variable
     * hold, a helper call returning the shared const — is the blessed
     * form and is not chased statically; escape sequences are NOT
     * decoded, which is safe because no character of the four law
     * wordings is escape-representable, so a byte-identical re-inline
     * cannot hide behind one.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{0:int,1:string}>
     */
    private static function throwStatementTexts(array $tokens): array
    {
        $sites = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_THROW) {
                continue;
            }

            $text = '';
            $carriesLiteral = false;
            $depth = 0;
            $j = $i + 1;
            for (; $j < $count; $j++) {
                $inner = $tokens[$j];
                if (is_string($inner)) {
                    if ($inner === ';' && $depth === 0) {
                        break;
                    }
                    if ($inner === '(' || $inner === '[' || $inner === '{') {
                        $depth++;
                    } elseif ($inner === ')' || $inner === ']' || $inner === '}') {
                        $depth = max(0, $depth - 1);
                    }
                    continue;
                }
                if ($inner[0] === T_CONSTANT_ENCAPSED_STRING) {
                    // substr(1, -1) strips the quote delimiters; the
                    // interior bytes are the literal's payload. Fragments
                    // are joined with no separator, so a wording split
                    // across a concatenation reassembles for the check.
                    $text .= substr($inner[1], 1, -1);
                    $carriesLiteral = true;
                } elseif ($inner[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $text .= $inner[1];
                    $carriesLiteral = true;
                }
            }

            if ($carriesLiteral) {
                $sites[] = [$token[2], $text];
            }
            $i = $j;
        }

        return $sites;
    }

    /**
     * @return list<string> "<wording>@line<line>" for every machine wording
     *                      carried by a throw statement in the source
     */
    private static function machineWordingHits(string $source): array
    {
        $hits = [];
        foreach (self::throwStatementTexts(token_get_all($source)) as [$line, $text]) {
            foreach (self::MACHINE_OWNED_MESSAGES as $message) {
                if (str_contains($text, $message)) {
                    $hits[] = $message . '@line' . $line;
                }
            }
        }

        return $hits;
    }
}
