<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * S342 — the adoption guard S131 never built: every `->query()` call in `src/`
 * whose statement literal begins `INSERT`/`REPLACE` **and whose result is
 * consumed** must be consumed through
 * {@see \Phlix\Common\Database\WriteResult::wroteNothing()} (or its one
 * delegating alias, `MusicLibraryScanner::statementWroteNothing()`) — or be on
 * the named exception list below.
 *
 * ## Why this file exists
 *
 * S131 promoted `WriteResult::wroteNothing()` into the single predicate for
 * "did this write demonstrably write nothing", and migrated every then-known
 * insert-result consumer to it. The S131 AC audit (batch 13, 2026-08-13)
 * re-derived the inventory **by verb** and found two consumers that had slipped
 * through: `ScanJobRepository::startRunningIfIdle()` (S151, landed 8 days
 * before S131) and `ScanJobRepository::enqueueIfNoneActiveOfType()` (S284,
 * landed 4 days after S131). Nothing reddened when the 12th consumer was
 * hand-rolled, and nothing did. `git grep -ln WriteResult master -- tests`
 * returns 8 files, every one of which tests *behaviour at a site*; the one
 * anti-drift check present —
 * `WriteResultTest::testTheMusicLibraryScannerHelperDelegatesHereRatherThanForkingTheRule()` —
 * compares only the two predicates' **values**, so an identical hand-copied
 * fork passes it. This file is the missing enumeration guard, modelled on
 * {@see IntegrationDbGuardAdoptionTest} (S126).
 *
 * ## The threat model, stated so the rules can be judged against it
 *
 * This guard exists to stop **accidental** recurrence: someone opens the
 * nearest repository, writes `$result = $this->db->query('INSERT …');` and
 * tests it with a bare `$result === null` / `$result !== null` / `!$result`
 * — the S151/S284 shapes. It is **not** an adversarial sandbox, and a static
 * rule over source text can never be one: a determined author can always hide
 * the consumption one hop further than this scan looks.
 *
 * > **A false positive costs more than an escape.** An escape leaves the tree
 * > exactly as safe as it was before this file existed. A false positive gets
 * > the whole check deleted, and then nothing is caught at all — S120's lesson,
 * > learned here rather than reasoned about.
 *
 * So every rule below is deliberately narrow, and every narrowing is written
 * down (see "Known limits") instead of being papered over with a rule that
 * would fire on innocent code.
 *
 * ## What is scanned, and what the denominator means
 *
 * The scan tokenises every `*.php` under `src/` and finds every `->query(` /
 * `?->query(` call. For each it resolves the statement's leading keyword —
 * from a literal first argument, or through one hop to a `$sql = '…' . "…"`
 * assignment in the same method — and keeps only the `INSERT`/`REPLACE` ones.
 * It then decides whether the result is consumed:
 *
 *  - **assigned to a variable** (`$result = $this->db->query(…)`) **and that
 *    variable is referenced again later in the method** — consumed;
 *  - **inline** — the call is itself a direct argument of
 *    `WriteResult::wroteNothing(…)` / `statementWroteNothing(…)` — consumed.
 *
 * Every other shape — the result discarded outright, or assigned and never
 * read — is not a consumer and is out of scope (a genuine error throws; the
 * client has no silent failure return).
 *
 * The denominator the guard prints is the exact count it examined:
 * **94 `INSERT`/`REPLACE` `->query()` call tokens** (86 with a literal first
 * argument, 8 resolved through a `$sql` variable — including the try/catch
 * retry arms of the two ScanJobRepository sites), of which **14 consume the
 * result** (12 logical sites: the retry arms share one consumption). The plan
 * recorded 86/11; this scan is what a faithful token walk of the tree actually
 * finds, and it is pinned below so a change to the landscape reddens here
 * rather than silently drifting the inventory.
 *
 * ## The named exception — and why the exception is a NON-consumer
 *
 * {@see \Phlix\Access\StreamSessionService::updateStreamLimit()} must NOT adopt
 * the helper: for its `INSERT … ON DUPLICATE KEY UPDATE`, **both** `null` (an
 * idempotent no-op write) **and** `'0'` (a successful write to a CHAR(36)-PK
 * table) are SUCCESS — explained in the 30-line docblock at
 * `src/Access/StreamSessionService.php:410-445`. The site's correct shape is to
 * discard the result and `return true;`. The exception list carries the site
 * and its reason so the guard's silence about it is documented rather than
 * accidental; a separate rule asserts the site **still discards** its insert
 * result, so the tempting "completion" (`return !WriteResult::wroteNothing($result)`)
 * — which S131's docblock calls a REGRESSION — reddens here.
 *
 * ## Known limits — escapes that are accepted, not overlooked
 *
 *  - `$sql` resolution is one hop and straight-line only: an assignment in an
 *    `if`/`else`, or a statement built by a helper function, is not resolved,
 *    and the site then falls out of the `INSERT`/`REPLACE` count entirely.
 *  - Consumption is recognised as "variable assigned, then referenced later in
 *    the same method" or "inline in the helper". A consumption that reassigns
 *    the variable before the helper call, or passes it through a second local
 *    function, escapes.
 *  - An inline consumer as a NON-FINAL argument of a multi-argument enclosing
 *    call (`foo($this->db->query('INSERT …'), $x)`) is not tracked: the `,`
 *    after the call is treated as "not an inline consumer". No such shape
 *    exists in `src/` today, and the only legitimate inline consumers are the
 *    single-argument helpers.
 *  - A PREFIX operator on the call — `(bool) $this->db->query('INSERT …')`,
 *    `(int) …` — escapes: the next token after the call is `;`, so the call
 *    reads as discarded. The postfix accident family (comparisons, `!`,
 *    bare-truthiness in a statement paren group) is covered; a prefix cast is
 *    a shape no site in `src/` uses and is accepted here.
 *  - `src/` only. Test doubles and `tests/Support` deliberately mock
 *    `query()` with canned shapes and are not part of the production contract.
 *
 * ## Delivery
 *
 * An ordinary PHPUnit test, like S126's guard: it needs no DB, fails the suite
 * natively, and fires on the developer's machine at the moment the hand-rolled
 * consumer is written rather than in CI later.
 */
final class WriteResultAdoptionGuardTest extends TestCase
{
    /**
     * The named exception list carrying the one site where the helper is the
     * WRONG predicate, with its reason. Keyed by `relative/path.php::method`.
     *
     * The rule is **not** a blanket carve-out: a site on this list is asserted
     * to be a NON-consumer of its insert result (see
     * {@see testTheNamedExceptionSiteStillDiscardsItsInsertResult()}), so the
     * entry exists to document why the guard is silent about the site — not to
     * excuse a hand-rolled consumer that happens to share its file.
     *
     * ⚠ By construction this mechanism can only carry NON-consumers: a site
     * that legitimately consumes an INSERT result for a purpose other than
     * "wrote nothing" cannot be exempted without reddening both the offender
     * check and the non-consumer assertion. That is correct for the one site
     * the AC names (`updateStreamLimit`), whose correct shape is to discard.
     *
     * @var array<string, string>
     */
    private const EXCEPTIONS = [
        'src/Access/StreamSessionService.php::updateStreamLimit' =>
            'INSERT ... ON DUPLICATE KEY UPDATE: BOTH null (an idempotent no-op write) '
            . 'and \'0\' (a successful write to the CHAR(36)-PK profile_stream_limits '
            . 'table) are SUCCESS here — see the 30-line docblock at '
            . 'src/Access/StreamSessionService.php:410-445. The site must stay a '
            . 'non-consumer; "finishing the job" with '
            . 'return !WriteResult::wroteNothing($result) is the documented S131 regression.',
    ];

    /**
     * The denominator, part 1: every `->query()` call token in `src/` whose
     * statement literal begins `INSERT`/`REPLACE` (literal first argument, or
     * resolved one hop through a `$sql` variable). Measured on master
     * `0ad7a080` + S342: 86 literal-first-argument sites + 8 resolved through
     * `$sql` (`Admin/SettingsRepository.php:194`, `Auth/UserRepository.php:689`,
     * `Media/Metadata/Imdb/ImdbDatasetImporter.php:573,616`, and the four
     * ScanJobRepository try/catch retry arms) = 94. The plan recorded 86; the
     * delta is sites its script did not classify. A change to the insert
     * landscape — new or removed `->query()` INSERT/REPLACE call — updates this
     * number in the same commit; that is the point (S126's EXPECTED_ADOPTERS
     * precedent).
     */
    private const EXPECTED_TOTAL_INSERT_CALLS = 94;

    /**
     * The denominator, part 2: how many of those 94 consume their result.
     * 14 tokens = 12 logical sites (the two ScanJobRepository sites each
     * contribute a try arm and a retry arm; both arms feed one consumption).
     * All 14 must be consumed through `WriteResult::wroteNothing()` /
     * `statementWroteNothing()`.
     */
    private const EXPECTED_CONSUMED_INSERT_RESULTS = 14;

    /**
     * Helper call names whose first argument is the consumed result, plus the
     * one delegating alias that must stay a delegate
     * (`MusicLibraryScanner::statementWroteNothing()`).
     */
    private const HELPER_CALL_NAMES = ['wroteNothing', 'statementWroteNothing'];

    /** Tokens that never carry meaning for any rule here. */
    private const IGNORED_TOKENS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * The whole-tree scan, computed once per PHPUnit process.
     *
     * @var array{total: int, consumed: list<string>, consumedSites: list<string>, offenders: list<string>}|null
     */
    private static ?array $scan = null;

    public function testEveryConsumedInsertOrReplaceResultAdoptsTheWriteResultHelper(): void
    {
        $offenders = self::scan()['offenders'];

        $this->assertSame(
            [],
            $offenders,
            'S342: a `->query()` call whose statement begins INSERT/REPLACE has its result '
            . "consumed by something other than WriteResult::wroteNothing().\n"
            . 'The client returns `null` for a zero-row INSERT and the falsy string \'0\' for a '
            . "SUCCESSFUL insert into a CHAR(36)-PK table — so a bare `=== null` / `!== null` / "
            . '`!$result` comparison is the exact S151/S284 hand-rolled shape this guard exists '
            . "to end.\n"
            . 'Remedy: route the decision through WriteResult::wroteNothing($result) '
            . "(or MusicLibraryScanner::statementWroteNothing(\$result), which must stay a "
            . "delegate). If the site genuinely must not use the helper, add it to the "
            . "EXCEPTIONS list WITH its reason — the list is not a blanket rule.\n"
            . 'Offending sites (file:line method): ' . implode('; ', $offenders),
        );
    }

    /**
     * The named exception must remain a NON-consumer.
     *
     * `updateStreamLimit()` discards its insert result and `return true;` —
     * that is the correct shape for an `INSERT … ON DUPLICATE KEY UPDATE`
     * where both `null` and `'0'` are SUCCESS. If it ever starts consuming its
     * result — via the helper (`return !WriteResult::wroteNothing($result)`,
     * the S131-documented regression) or via a bare comparison — this reddens
     * with the exception's own reason.
     */
    public function testTheNamedExceptionSiteStillDiscardsItsInsertResult(): void
    {
        $consumedSites = self::scan()['consumedSites'];

        foreach (self::EXCEPTIONS as $site => $reason) {
            $this->assertNotContains(
                $site,
                $consumedSites,
                'S342: the named exception site ' . $site . ' now CONSUMES its insert result, '
                . "which contradicts the reason it is exempted.\n{$reason}\n"
                . 'The site must keep discarding the result (return true). If a future change '
                . 'legitimately needs to consume it, the exception reason must be rewritten in '
                . 'the same commit — a silent consumption change here is how the S131 '
                . '"completion" regression ships.',
            );
        }
    }

    /**
     * The denominator is pinned so a scan that examined nothing cannot pass
     * silently: 94 `INSERT`/`REPLACE` `->query()` call tokens, 14 of which
     * consume the result.
     */
    public function testTheInsertConsumerInventoryDenominatorIsPinned(): void
    {
        $findings = self::scan();

        $this->assertSame(
            self::EXPECTED_TOTAL_INSERT_CALLS,
            $findings['total'],
            sprintf(
                'S342: the guard examined %d INSERT/REPLACE ->query() call tokens in src/, '
                . 'expected %d. The insert landscape changed — add or remove an INSERT/REPLACE '
                . '->query() call and update EXPECTED_TOTAL_INSERT_CALLS in the same commit '
                . '(that is the point: the enumeration must not drift silently).',
                $findings['total'],
                self::EXPECTED_TOTAL_INSERT_CALLS,
            ),
        );

        $this->assertSame(
            self::EXPECTED_CONSUMED_INSERT_RESULTS,
            count($findings['consumed']),
            sprintf(
                'S342: %d of the %d INSERT/REPLACE ->query() call tokens consume their result, '
                . 'expected %d. A consumed-result site was added or removed — update '
                . 'EXPECTED_CONSUMED_INSERT_RESULTS in the same commit.',
                count($findings['consumed']),
                $findings['total'],
                self::EXPECTED_CONSUMED_INSERT_RESULTS,
            ),
        );
    }

    /**
     * One tokenise-inspect-discard pass over every `*.php` under `src/`.
     *
     * @return array{total: int, consumed: list<string>, consumedSites: list<string>, offenders: list<string>}
     */
    private static function scan(): array
    {
        if (self::$scan !== null) {
            return self::$scan;
        }

        $root = dirname(__DIR__, 3);

        $total = 0;
        $consumed = [];
        $consumedSites = [];
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
            $tokens = token_get_all((string) file_get_contents($path));

            foreach (self::insertQueryCalls($tokens, $relative) as $call) {
                $total++;
                if ($call['consumedBy'] === null) {
                    continue;
                }

                $label = $relative . ':' . $call['line'] . ' ' . $call['method'];
                $consumed[] = $label;
                $consumedSites[] = $relative . '::' . $call['method'];

                if (!$call['adopted'] && !isset(self::EXCEPTIONS[$relative . '::' . $call['method']])) {
                    $offenders[] = $label;
                }
            }

            unset($tokens);
        }

        sort($consumed);
        sort($consumedSites);
        sort($offenders);

        self::$scan = [
            'total' => $total,
            'consumed' => $consumed,
            'consumedSites' => $consumedSites,
            'offenders' => $offenders,
        ];

        return self::$scan;
    }

    /**
     * Every `->query(` call in one file whose statement begins INSERT/REPLACE.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{line: int, method: string, consumedBy: string|null, adopted: bool}>
     */
    private static function insertQueryCalls(array $tokens, string $relative): array
    {
        $calls = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'query') {
                continue;
            }

            $prev = self::significantToken($tokens, $i, -1);
            if (!is_array($prev) || !in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $paren = self::significantIndex($tokens, $i + 1);
            if ($paren === null || $tokens[$paren] !== '(') {
                continue;
            }

            [$method, $bodyOpen, $bodyClose] = self::enclosingMethod($tokens, $i);
            if ($bodyOpen === null || $bodyClose === null) {
                continue;
            }

            $stmt = self::firstArgString($tokens, $paren);
            if ($stmt === null) {
                $stmt = self::resolveSqlVariable($tokens, $paren, $bodyOpen);
            }
            if ($stmt === null) {
                continue;
            }

            $verb = strtoupper((string) strtok(trim($stmt), " \t\r\n"));
            if ($verb !== 'INSERT' && $verb !== 'REPLACE') {
                continue;
            }

            $assignVar = self::assignedVariable($tokens, $i);
            $close = self::matchingParen($tokens, $paren);
            if ($close === null) {
                continue;
            }

            $inlineConsumer = self::inlineConsumer($tokens, $close);
            $consumedBy = null;
            $adopted = false;

            if ($inlineConsumer !== null) {
                $consumedBy = $inlineConsumer;
                $adopted = in_array(self::lastNameSegment($inlineConsumer), self::HELPER_CALL_NAMES, true);
            } elseif ($assignVar !== null && self::variableUsedLater($tokens, $close, $bodyClose, $assignVar)) {
                $consumedBy = $assignVar;
                $adopted = self::variableAdoptedByHelper($tokens, $bodyOpen, $bodyClose, $assignVar);
            }

            $calls[] = [
                'line' => $token[2],
                'method' => $method ?? '',
                'consumedBy' => $consumedBy,
                'adopted' => $adopted,
            ];
        }

        return $calls;
    }

    /**
     * The statement text of the first argument when it is a string literal
     * (or a `.`-concatenation of string literals), else null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function firstArgString(array $tokens, int $paren): ?string
    {
        $parts = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $paren + 1; $i < $count; $i++) {
            $t = $tokens[$i];

            if ($t === '(') {
                $depth++;
                continue;
            }
            if ($t === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                continue;
            }
            if ($depth === 0 && $t === ',') {
                break;
            }

            if (is_array($t)) {
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $parts[] = self::stringLiteralValue($t);
                } elseif ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $parts[] = $t[1];
                } elseif ($t[0] === T_WHITESPACE) {
                    $parts[] = ' ';
                }
            } elseif ($t === '.') {
                $parts[] = ' ';
            }
        }

        $joined = preg_replace('/\s+/', ' ', trim(implode('', $parts))) ?? '';

        return $joined === '' ? null : $joined;
    }

    /**
     * One-hop `$sql` resolution: the first argument is a variable assigned a
     * `.`-concatenation of string literals somewhere earlier in the method.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function resolveSqlVariable(array $tokens, int $paren, int $bodyOpen): ?string
    {
        $first = self::significantIndex($tokens, $paren + 1);
        if ($first === null || !is_array($tokens[$first]) || $tokens[$first][0] !== T_VARIABLE) {
            return null;
        }
        $var = $tokens[$first][1];
        $count = count($tokens);

        // Walk BACKWARD from the call so the NEAREST assignment wins — a method
        // that reassigns `$sql` before the call is classified by the last write,
        // which is the one the call actually sees.
        for ($i = $paren - 1; $i > $bodyOpen; $i--) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== $var) {
                continue;
            }

            $eq = self::significantIndex($tokens, $i + 1);
            if ($eq === null || $tokens[$eq] !== '=') {
                continue;
            }

            $parts = [];
            $depth = 0;
            for ($j = $eq + 1; $j < $paren; $j++) {
                $q = $tokens[$j];

                if ($q === '(') {
                    $depth++;
                    continue;
                }
                if ($q === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                    continue;
                }
                if ($depth === 0 && ($q === ';' || $q === ',')) {
                    break;
                }

                if (is_array($q)) {
                    if ($q[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $parts[] = self::stringLiteralValue($q);
                    } elseif ($q[0] === T_ENCAPSED_AND_WHITESPACE) {
                        $parts[] = $q[1];
                    } elseif ($q[0] === T_WHITESPACE) {
                        $parts[] = ' ';
                    } else {
                        // A non-literal element (variable, function call…) — stop.
                        break;
                    }
                } elseif ($q === '.') {
                    $parts[] = ' ';
                } else {
                    break;
                }
            }

            $joined = preg_replace('/\s+/', ' ', trim(implode('', $parts))) ?? '';
            if ($joined !== '') {
                return $joined;
            }
        }

        return null;
    }

    /**
     * The variable the call's result is assigned to, or null.
     *
     * Walks back through the `$obj->prop->query(` chain to its base variable,
     * then looks for a plain `=` before it and returns the assignment target.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function assignedVariable(array $tokens, int $queryIndex): ?string
    {
        $count = count($tokens);
        $chainBase = null;

        for ($i = $queryIndex - 1; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], self::IGNORED_TOKENS, true)) {
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                continue;
            }
            if (is_array($t) && $t[0] === T_VARIABLE) {
                $chainBase = $i;
                break;
            }
            break;
        }

        if ($chainBase === null) {
            return null;
        }

        for ($i = $chainBase - 1; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], self::IGNORED_TOKENS, true)) {
                continue;
            }
            if ($t !== '=') {
                return null;
            }

            $prevIdx = $i - 1;
            while (
                $prevIdx >= 0
                && is_array($tokens[$prevIdx])
                && in_array($tokens[$prevIdx][0], self::IGNORED_TOKENS, true)
            ) {
                $prevIdx--;
            }
            $prev = $tokens[$prevIdx] ?? null;

            if (
                is_array($prev)
                && in_array($prev[0], [
                    T_IS_EQUAL,
                    T_IS_IDENTICAL,
                    T_DOUBLE_ARROW,
                    T_PLUS_EQUAL,
                    T_MINUS_EQUAL,
                    T_MUL_EQUAL,
                    T_DIV_EQUAL,
                    T_CONCAT_EQUAL,
                    T_BOOLEAN_AND,
                    T_BOOLEAN_OR,
                    T_COALESCE,
                ], true)
            ) {
                return null;
            }
            if ($prev === '>' || $prev === '!' || $prev === '<' || $prev === '=') {
                return null;
            }

            for ($j = $i - 1; $j >= 0; $j--) {
                $q = $tokens[$j];
                if (is_array($q) && in_array($q[0], self::IGNORED_TOKENS, true)) {
                    continue;
                }
                if (is_array($q) && $q[0] === T_VARIABLE) {
                    return $q[1];
                }
                if ($q === ';' || $q === '{' || $q === '}' || $q === ',' || $q === '(' || $q === '[') {
                    return null;
                }
                return null;
            }
        }

        return null;
    }

    /**
     * Whether the variable is referenced again after the call — i.e. the
     * result is consumed, not discarded.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function variableUsedLater(array $tokens, int $afterIndex, int $bodyClose, string $var): bool
    {
        $count = count($tokens);
        for ($i = $afterIndex + 1; $i <= $bodyClose; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === $var) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the variable is used as a DIRECT first argument of a helper call
     * somewhere in the method — the adoption this guard asserts.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function variableAdoptedByHelper(array $tokens, int $bodyOpen, int $bodyClose, string $var): bool
    {
        $count = count($tokens);
        for ($i = $bodyOpen + 1; $i < $bodyClose; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], self::HELPER_CALL_NAMES, true)) {
                continue;
            }

            $paren = self::significantIndex($tokens, $i + 1);
            if ($paren === null || $tokens[$paren] !== '(') {
                continue;
            }

            $first = self::significantIndex($tokens, $paren + 1);
            if (
                $first !== null
                && is_array($tokens[$first])
                && $tokens[$first][0] === T_VARIABLE
                && $tokens[$first][1] === $var
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the call's result is consumed WITHOUT an assigned variable, a label
     * naming the consumption shape, else null.
     *
     *  - nested directly inside another call's argument list → that call's
     *    name (e.g. `WriteResult::wroteNothing` for the helper-inline shape);
     *  - followed by an identity/loose comparison, a negation, a ternary or a
     *    logical operator → a marker label. These are the S151/S284 accident
     *    family written inline (`if ($this->db->query('INSERT …') === null)`),
     *    and they must redden like an assigned-then-bare-compared consumer.
     *
     * A `;` after the call means the result is discarded and is not a consumer.
     * A `,` — the call as a NON-FINAL argument of an enclosing call — is also
     * not tracked as an inline consumer (see the multi-arg case in Known
     * limits).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function inlineConsumer(array $tokens, int $close): ?string
    {
        $after = self::significantIndex($tokens, $close + 1);
        if ($after === null) {
            return null;
        }

        $next = $tokens[$after];

        // Discarded: end of the statement, or end of an enclosing argument list.
        if ($next === ';' || $next === ',') {
            return null;
        }

        if ($next === ')') {
            // `$after` IS the enclosing close paren: walking from the query's
            // own close paren would pair it with the query's own open paren and
            // resolve the consumer to the query method itself.
            $depth = 0;
            $openParen = null;
            for ($i = $after; $i >= 0; $i--) {
                if ($tokens[$i] === ')') {
                    $depth++;
                } elseif ($tokens[$i] === '(') {
                    $depth--;
                    if ($depth === 0) {
                        $openParen = $i;
                        break;
                    }
                }
            }
            if ($openParen === null) {
                return null;
            }

            $name = self::significantToken($tokens, $openParen, -1);

            // A statement keyword (`if (...)`, `return ...`, ...) means the
            // result is tested bare / passed on — the `!$result` accident
            // family written inline.
            if (is_array($name) && in_array($name[0], [T_IF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_RETURN, T_ECHO, T_PRINT, T_ISSET, T_EMPTY], true)) {
                return 'inline bare-truthiness';
            }

            if (!is_array($name) || $name[0] !== T_STRING) {
                return null;
            }

            $beforeName = self::significantToken($tokens, $openParen, -2);
            if (is_array($beforeName) && $beforeName[0] === T_DOUBLE_COLON) {
                $class = self::significantToken($tokens, $openParen, -3);

                return (is_array($class) ? $class[1] : '?') . '::' . $name[1];
            }

            return $name[1];
        }

        // Any other continuation consumes the result inline.
        if (is_array($next)) {
            if (in_array($next[0], [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL], true)) {
                return 'inline comparison';
            }
            if (in_array($next[0], [T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR], true)) {
                return 'inline logical';
            }
        } elseif ($next === '!') {
            return 'inline negation';
        } elseif ($next === '?') {
            return 'inline ternary';
        }

        return null;
    }

    /**
     * The nearest enclosing named function/method: [name, bodyOpen, bodyClose].
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: string|null, 1: int|null, 2: int|null}
     */
    private static function enclosingMethod(array $tokens, int $index): array
    {
        $best = [null, null, null];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_FUNCTION) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], self::IGNORED_TOKENS, true)) {
                $j++;
            }
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;
            }
            $name = $tokens[$j][1];

            $depth = 0;
            $open = null;
            for ($k = $j + 1; $k < $count; $k++) {
                if ($tokens[$k] === '(') {
                    $depth++;
                } elseif ($tokens[$k] === ')') {
                    $depth--;
                } elseif ($depth === 0 && $tokens[$k] === '{') {
                    $open = $k;
                    break;
                }
            }
            if ($open === null) {
                continue;
            }

            $close = self::matchingBrace($tokens, $open);
            if ($close !== null && $open <= $index && $index <= $close) {
                $best = [$name, $open, $close];
            }
        }

        return $best;
    }

    /** Index of the `)` matching the `(` at `$open`, or null. */
    private static function matchingParen(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Index of the `}` closing the `{` at `$open`, or null. */
    private static function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            // A double-quoted interpolation `"{$var}"` emits T_CURLY_OPEN and a
            // plain `}` — both must count or the closing brace of the string is
            // mistaken for the end of the enclosing block.
            if (
                $tokens[$i] === '{'
                || (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $depth++;
            } elseif ($tokens[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Index of the next non-ignored token at/after `$from`, or null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function significantIndex(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], self::IGNORED_TOKENS, true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * The next non-ignored token at/after `$from`, or null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function significantToken(array $tokens, int $from, int $direction = 1): mixed
    {
        $count = count($tokens);
        if ($direction < 0) {
            for ($i = $from + $direction; $i >= 0; $i--) {
                $t = $tokens[$i];
                if (is_array($t) && in_array($t[0], self::IGNORED_TOKENS, true)) {
                    continue;
                }

                return $t;
            }
        } else {
            for ($i = $from + $direction; $i < $count; $i++) {
                $t = $tokens[$i];
                if (is_array($t) && in_array($t[0], self::IGNORED_TOKENS, true)) {
                    continue;
                }

                return $t;
            }
        }

        return null;
    }

    /**
     * The text of a string-literal token with its surrounding quotes removed.
     *
     * @param array{0: int, 1: string, 2: int} $token
     */
    private static function stringLiteralValue(array $token): string
    {
        $quote = substr($token[1], 0, 1);

        return $quote === "'" || $quote === '"' ? substr($token[1], 1, -1) : $token[1];
    }

    /**
     * Last segment of a possibly-qualified name token — after either a
     * namespace backslash (`\Phlix\Common\Database\WriteResult`) or a
     * `::` method qualifier (`WriteResult::wroteNothing`).
     */
    private static function lastNameSegment(string $name): string
    {
        $pos = max(strrpos($name, '\\') ?: -1, strrpos($name, '::') ?: -1);

        return $pos < 0 ? $name : substr($name, $pos + (strpos($name, '::', $pos) === $pos ? 2 : 1));
    }
}
