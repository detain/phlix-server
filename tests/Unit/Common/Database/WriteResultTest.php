<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Shared\Database\WriteResult;
use Phlix\Media\Music\MusicLibraryScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
     * The retired server-local twin, assembled from fragments so this guard
     * file is the only place the literal needle can never be found whole
     * (extinction scans exclude exactly this file — see SELF_PATH).
     */
    private const RETIRED_FQCN = 'Phlix' . '\\' . 'Common' . '\\' . 'Database' . '\\' . 'WriteResult';

    private const SELF_PATH = __DIR__ . '/WriteResultTest.php';

    /**
     * The two arms, and the values that must NOT be mistaken for them.
     *
     * The rows marked [v0.57-W3a] were added when this suite was re-aimed from
     * the retired server-local twin onto the shared kernel: they widen the
     * accept-set differential beyond the values any old pin happened to feed.
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

            // ── [v0.57-W3a] strict-identity arms at the re-aim boundary ────
            '0.0 (float) — falsy, strict-typed, still NOT one of the two arms' => [0.0, false],
            "'0.0' — a float render is falsy in (bool) but not === false" => ['0.0', false],
            '-1 — rowCount() for an uncountable statement is a real answer' => [-1, false],
            'stdClass — any object is a truthy write token' => [new \stdClass(), false],
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

        // The trap itself — `(bool) '0' === false` — is a language fact, fixed for
        // every supported PHP 8.x (verified: php -r 'var_dump((bool) "0");' → bool(false)),
        // so it is recorded as rationale rather than asserted; the live check above is
        // the one that can actually redden if the predicate ever learns the mistake.
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

    public function testTheRetiredLocalTwinStaysExtinctAsAClass(): void
    {
        // Autoload-aware: if any file resurrects the name where the composer
        // psr-4 map can find it, class_exists() reloads it and this reddens.
        self::assertFalse(
            class_exists(self::RETIRED_FQCN, true),
            'The server-local twin Phlix\\Common\\Database\\WriteResult was retired to the '
            . 'shared kernel in the v0.57 consumer wave (W3a) — nothing may re-create it.'
        );
    }

    public function testTheRetiredFqcnIsExtinctFromEverySourceFile(): void
    {
        $root = dirname(__DIR__, 4);
        $needle = 'Phlix' . '\\' . 'Common' . '\\' . 'Database' . '\\' . 'WriteResult';
        $offenders = [];
        $examined = 0;

        foreach (['src', 'tests'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if ($file->getPathname() === self::SELF_PATH) {
                    // This guard file names the retired FQCN in its docblock and
                    // its runtime-string fragments — the only sanctioned seats.
                    continue;
                }

                $examined++;

                if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        // Assert own coverage: a scan that examined nothing must not pass
        // silently (root-path bug = free green — S342 denominator law).
        self::assertGreaterThan(
            2000,
            $examined,
            'The extinction scan examined only ' . $examined . ' PHP files — the tree walk '
            . 'is broken, which would let the retired FQCN return unnoticed.'
        );

        self::assertSame(
            [],
            $offenders,
            "The retired FQCN appears in source text (" . implode(', ', $offenders) . '). '
            . 'Repoint those consumers to Phlix\\Shared\\Database\\WriteResult instead.'
        );
    }
}
