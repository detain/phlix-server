<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\WriteResult;
use Phlix\Media\Music\MusicLibraryScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The value table for {@see WriteResult::wroteNothing()} — S131.
 *
 * Every row is asserted on its own, because that is the failure mode this
 * predicate has already had once: S96 review r3 deleted `|| $result === null`
 * from the private ancestor of this method and an entire scanner + library +
 * command + integration selection stayed byte-identically GREEN, because no
 * double in the suite could produce `null`. A suite that only ever feeds one
 * falsy value leaves the other arm dead in test.
 */
final class WriteResultTest extends TestCase
{
    /**
     * The two arms, and the values that must NOT be mistaken for them.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function resultValues(): array
    {
        return [
            // ── the two arms ───────────────────────────────────────────────
            'null — a zero-row INSERT, or an unrecognised leading keyword' => [null, true],
            'false — the defensive arm; this client never produces it' => [false, true],

            // ── 🔴 the falsy value that means SUCCESS ───────────────────────
            "'0' — lastInsertId() on a CHAR(36) PK table: a SUCCESSFUL insert" => ['0', false],

            // ── everything else ────────────────────────────────────────────
            "'42' — lastInsertId() on an AUTO_INCREMENT table" => ['42', false],
            '0 (int) — rowCount() for a DELETE that matched nothing' => [0, false],
            '1 (int) — rowCount() for a DELETE that removed one row' => [1, false],
            "'' — an empty string is falsy but is not a no-write signal" => ['', false],
            '[] — fetchAll() on a SELECT that matched nothing' => [[], false],
            'true — what several older test doubles answer for a write' => [true, false],
        ];
    }

    #[DataProvider('resultValues')]
    public function testTheValueTableIsPinnedRowByRow(mixed $result, bool $expected): void
    {
        $this->assertSame(
            $expected,
            WriteResult::wroteNothing($result),
            'the insert-result contract moved: '
            . var_export($result, true)
            . ' must ' . ($expected ? '' : 'NOT ')
            . 'be read as "wrote nothing"',
        );
    }

    /**
     * 🔴 The single most important row, restated as its own named test so a
     * regression here cannot hide inside a data-provider summary line.
     *
     * `PDO::lastInsertId()` answers `'0'` on a table with no `AUTO_INCREMENT`
     * column, which is nearly every Phlix table (`CHAR(36)` UUIDs minted in
     * PHP). `'0'` is falsy, so `if (!$result)` reads a SUCCESSFUL insert as a
     * failure. This predicate must not repeat that mistake.
     */
    public function testTheFalsyStringZeroIsASuccessfulInsertNotAFailure(): void
    {
        $this->assertFalse(
            WriteResult::wroteNothing('0'),
            "'0' is what a successful INSERT into a CHAR(36)-PK table returns; "
            . 'reading it as "wrote nothing" reports every such write as a failure',
        );

        // The reason it is dangerous, asserted rather than asserted-about.
        $this->assertFalse((bool) '0', "PHP still says '0' is falsy — that is the whole trap");
    }

    /**
     * `int 0` from a DELETE's `rowCount()` is a DIFFERENT question, and this
     * predicate deliberately does not answer it.
     *
     * "wrote nothing because the write did not happen" (`null`) and "ran fine
     * and matched no rows" (`int 0`) are not the same event. Sites that care
     * about the second must test it themselves — see the comment in
     * {@see \Phlix\Plugins\Oidc\DbOidcStateStore}.
     */
    public function testAnIntZeroRowCountIsNotWroteNothing(): void
    {
        $this->assertFalse(WriteResult::wroteNothing(0));
        $this->assertTrue(WriteResult::wroteNothing(null));
    }

    /**
     * There is ONE predicate, not two.
     *
     * S96 added `MusicLibraryScanner::statementWroteNothing()` privately and
     * S131 promoted it here. The private method survives as a delegate so the
     * scanner's six call sites and their pins keep working — but it must stay
     * a delegate. If it grows its own copy of the comparison, a fix applied
     * here stops reaching the scanner, which is the drift S131 exists to end.
     */
    public function testTheMusicLibraryScannerHelperDelegatesHereRatherThanForkingTheRule(): void
    {
        $method = new ReflectionMethod(MusicLibraryScanner::class, 'statementWroteNothing');

        foreach (self::resultValues() as $label => [$value, $expected]) {
            $this->assertSame(
                $expected,
                $method->invoke(null, $value),
                "MusicLibraryScanner::statementWroteNothing() disagrees with WriteResult on: {$label}",
            );
            $this->assertSame(
                WriteResult::wroteNothing($value),
                $method->invoke(null, $value),
                "the two predicates have forked on: {$label}",
            );
        }
    }
}
