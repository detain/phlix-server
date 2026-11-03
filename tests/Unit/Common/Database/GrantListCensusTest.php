<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;

/**
 * M26 (consolidated review) — the documented minimal GRANT set must actually
 * cover what the migration chain executes, in BOTH carriers of the recipe
 * (README "Manual install" SQL block and scripts/install.sh's provision
 * function).
 *
 * The defect this pins: for as long as I can remember, both grant lists
 * stopped at REFERENCES while the chain carried unconditional
 * ALTER TABLE ... DROP INDEX / DROP PRIMARY KEY swaps (051, 068, 087, 096,
 * 097, 100, 102, 103, 105, 119 — MySQL error 1142 "DROP command denied",
 * which is deliberately NOT in MigrationRunner::IDEMPOTENT_ERROR_CODES, so
 * under install.sh's `set -euo pipefail` the whole fresh install aborts
 * mid-migration). Docker and CI mask it (root/ALL PRIVILEGES), so only this
 * census sees it.
 *
 * The law is computed, never a frozen literal: the statement census over
 * migrations/*.sql (comments stripped) derives the REQUIRED privilege set,
 * and both grant lists are checked against it. A future migration that needs
 * RENAME or TRUNCATE reddens this test until the recipe grows that privilege
 * too — exactly the drift class (docs promising what code then refuses) the
 * review's owner repro walked.
 */
final class GrantListCensusTest extends TestCase
{
    /** Repo root, computed from this file's location. */
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Statement pattern => MySQL privilege it demands (schema-relevant set;
     * plain DML is listed too so the census is the FULL need, not just the
     * surprising half).
     *
     * @var array<string, string>
     */
    private const PATTERN_PRIVILEGES = [
        '/\bSELECT\b/i' => 'SELECT',
        '/\bINSERT\b/i' => 'INSERT',
        '/\bUPDATE\b/i' => 'UPDATE',
        '/\bDELETE\b/i' => 'DELETE',
        '/\bCREATE\b/i' => 'CREATE',
        '/\bALTER\b/i' => 'ALTER',
        '/\bINDEX\b/i' => 'INDEX',
        '/\bFOREIGN\s+KEY\b|\bREFERENCES\b/i' => 'REFERENCES',
        '/\bDROP\s+(INDEX|PRIMARY\s+KEY|TABLE|DATABASE)\b|\bTRUNCATE\b/i' => 'DROP',
        '/\bRENAME\b/i' => 'RENAME',
    ];

    /**
     * @return array<int, string> migration file paths
     */
    private function migrationFiles(): array
    {
        $files = glob(self::repoRoot() . '/migrations/*.sql');
        $this->assertIsArray($files, 'migrations/*.sql glob returned non-array');
        sort($files);
        $this->assertNotEmpty($files, 'the migration chain has no SQL files?');

        return $files;
    }

    /**
     * @param array<int, string> $files
     *
     * @return array<string, string> privilege => first file that demanded it
     */
    private function requiredPrivileges(array $files): array
    {
        $required = [];
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            // Strip line comments and block comments before token-matching:
            // prose in migration headers names these very statements while
            // explicitly vowing NOT to ship them (093, 094).
            $sql = (string) preg_replace('/--.*$/m', '', $sql);
            $sql = (string) preg_replace('/\/\*.*?\*\//s', '', $sql);
            foreach (self::PATTERN_PRIVILEGES as $pattern => $privilege) {
                if (preg_match($pattern, $sql) === 1 && !isset($required[$privilege])) {
                    $required[$privilege] = basename($file);
                }
            }
        }

        return $required;
    }

    /**
     * All privilege lists granted by the given recipe carrier, ON-to
     * boundaries parsed, comma sets normalized.
     *
     * @return array<int, array<int, string>> one privilege list per GRANT line
     */
    private function grantListsIn(string $file): array
    {
        $text = (string) file_get_contents($file);
        $this->assertMatchesRegularExpression(
            '/^GRANT .* ON /m',
            $text,
            $file . ' no longer carries a parseable GRANT ... ON line —'
            . ' the extractor must be rotated with the recipe, never silently starved'
        );
        preg_match_all('/^GRANT\s+([A-Za-z, ]+)\s+ON\s/m', $text, $matches);
        $lists = [];
        foreach ($matches[1] as $raw) {
            $privileges = array_values(array_filter(
                array_map('trim', explode(',', $raw)),
                static fn (string $p): bool => $p !== ''
            ));
            sort($privileges);
            $lists[] = $privileges;
        }
        $this->assertNotEmpty($lists, 'GRANT extractor matched nothing in ' . $file);

        return $lists;
    }

    public function testTheDropPrivilegeIsDemandedByTheChainToday(): void
    {
        // The premise of the whole pin: if this ever stops being true the
        // DROP grant in the recipe becomes removable — and this test tells
        // the author to say so out loud instead of rotting the pin.
        $required = $this->requiredPrivileges($this->migrationFiles());
        $this->assertArrayHasKey(
            'DROP',
            $required,
            'no DROP-family statement left the chain (last demander: none) —'
            . ' rotate this census test with the recipe if the DROP grant is being removed'
        );
    }

    public function testTheInstallerGrantListCoversTheMigrationsCensus(): void
    {
        $required = $this->requiredPrivileges($this->migrationFiles());
        foreach ($this->grantListsIn(self::repoRoot() . '/scripts/install.sh') as $granted) {
            $missing = array_keys(array_diff_key($required, array_flip($granted)));
            $demiders = implode(', ', array_map(
                static fn (string $p): string => $p . '=' . ($required[$p] ?? '?'),
                $missing
            ));
            $this->assertSame(
                [],
                $missing,
                'scripts/install.sh GRANT list lacks ' . implode(', ', $missing)
                . ' that migrations/*.sql demands (first demiders: ' . $demiders . ')'
                . ' — MySQL 1142 is not squelched by MigrationRunner and install.sh runs'
                . ' set -euo pipefail, so the fresh install aborts mid-chain'
            );
        }
    }

    public function testTheReadmeGrantRecipeCoversTheMigrationsCensus(): void
    {
        $required = $this->requiredPrivileges($this->migrationFiles());
        foreach ($this->grantListsIn(self::repoRoot() . '/README.md') as $granted) {
            $missing = array_keys(array_diff_key($required, array_flip($granted)));
            $this->assertSame(
                [],
                $missing,
                'README manual-install GRANT list lacks ' . implode(', ', $missing)
                . ' that migrations/*.sql demands — the reader who follows it literally gets 1142'
            );
        }
    }

    public function testBothCarriersTeachTheSameGrantSet(): void
    {
        $installer = $this->grantListsIn(self::repoRoot() . '/scripts/install.sh');
        $readme = $this->grantListsIn(self::repoRoot() . '/README.md');
        $this->assertSame(
            $installer[0],
            $readme[0],
            'the two carriers of the recipe drifted: README and install.sh must teach'
            . ' the same privilege set (one law, two readings)'
        );
    }
}
