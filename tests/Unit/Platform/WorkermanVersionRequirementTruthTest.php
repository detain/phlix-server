<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;

/**
 * SRV-m18 — the README's Workerman requirement must state the floor Composer
 * actually enforces.
 *
 * The defect this guard was born from: README.md's Requirements bullet said
 * "**Workerman**: 5.0+ (bundled via Composer)" while composer.json pins
 * `"workerman/workerman": "~5.1"` — i.e. `>=5.1 <6.0`. A 5.0.x release
 * satisfies the README claim and the constraint's major, yet violates the
 * constraint the repo enforces. A stated requirement that is looser than the
 * shipped one is exactly the class of claim a reader trusts while auditing
 * the environment (this review's own opening paragraph credits the README's
 * version statements — the VERSION/Version.php/Chart.yaml trio — as verified;
 * that credibility is what the Workerman line squandered).
 *
 * The pin is structural, not a string echo: it parses the composer constraint
 * floor (tilde/caret/greater-equal forms) and compares it against the version
 * floor stated in the README bullet. If composer.json ever moves to ~5.2 or
 * ^6.0, this test reddens until the README sentence moves with it — the same
 * drift class VersionSourcesAgreeTest kills for the server's own version.
 */
final class WorkermanVersionRequirementTruthTest extends TestCase
{
    /**
     * The README Requirements bullet for Workerman.
     */
    private const README_BULLET_PATTERN = '/- \*\*Workerman\*\*: (\d+(?:\.\d+)*)\+/';

    public function testTheReadmeBulletStays(): void
    {
        $readme = self::readme();

        $this->assertMatchesRegularExpression(
            self::README_BULLET_PATTERN,
            $readme,
            'the README must keep its "- **Workerman**: X.Y+" requirement bullet — the'
            . ' Workerman floor pin is meaningless if the bullet is renamed away',
        );
    }

    public function testTheStatedFloorMatchesTheComposerConstraint(): void
    {
        $constraint = self::composerConstraint();
        $stated = self::readmeStatedFloor();

        $expected = self::constraintFloor($constraint);

        $this->assertSame(
            $expected,
            $stated,
            "README states Workerman {$stated}+ but composer.json pins '{$constraint}'"
            . ' (floor ' . $expected . ') — the stated requirement must match the'
            . ' constraint the repo enforces, never a looser era',
        );
    }

    public function testTheComposerConstraintStaysWithinThePinnedMajor(): void
    {
        $constraint = self::composerConstraint();
        $floor = self::constraintFloor($constraint);

        $this->assertSame(
            5,
            (int) explode('.', $floor)[0],
            "composer constraint '{$constraint}' left major 5 — a Workerman major bump"
            . ' is an architecture decision: the README requirement text, the start.php'
            . ' boot path and this pin all need a deliberate re-anchor together',
        );
    }

    /**
     * The "~5.1"-style constraint composer.json declares for workerman/workerman.
     */
    private static function composerConstraint(): string
    {
        $composer = json_decode((string) file_get_contents(self::repoRoot() . '/composer.json'), true);

        self::assertIsArray($composer, 'composer.json must be readable JSON');
        $constraint = $composer['require']['workerman/workerman'] ?? null;

        self::assertIsString(
            $constraint,
            'composer.json must keep requiring workerman/workerman with a string constraint',
        );

        return $constraint;
    }

    /**
     * The version floor the "~"/"^"/">=" operator admits — the first version literal.
     */
    private static function constraintFloor(string $constraint): string
    {
        $matches = [];

        self::assertSame(
            1,
            preg_match('/(?:~|\^|>=)\s*(\d+(?:\.\d+)*)/', $constraint, $matches),
            "unsupported composer constraint form '{$constraint}' — extend this parser"
            . ' (and the README wording it pins) deliberately, not silently',
        );

        return $matches[1];
    }

    /**
     * The "X.Y" before the "+" in the README bullet.
     */
    private static function readmeStatedFloor(): string
    {
        $matches = [];
        preg_match(self::README_BULLET_PATTERN, self::readme(), $matches);

        self::assertNotEmpty(
            $matches,
            'the README Workerman bullet must state a floor as "X.Y+"',
        );

        return $matches[1];
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/README.md');
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
