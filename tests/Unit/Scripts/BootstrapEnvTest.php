<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for scripts/bootstrap_env.php — the env-file loader that lets
 * manually-run CLI scripts pick up DB_PASSWORD (etc.) from /etc/phlix/env.
 */
final class BootstrapEnvTest extends TestCase
{
    private string $file;

    public static function setUpBeforeClass(): void
    {
        // Loads the function definitions. The auto-run at include is a no-op
        // because phpunit.xml sets DB_PASSWORD for the test env.
        require_once dirname(__DIR__, 3) . '/scripts/bootstrap_env.php';
    }

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phlix_env_');
        file_put_contents(
            $this->file,
            "# a comment\n"
            . "\n"
            . "DB_PASSWORD=s3cr3t\n"
            . "PHLIX_DOMAIN = example.com \n"      // surrounding spaces trimmed
            . "QUOTED=\"quoted value\"\n"
            . "SINGLE='single value'\n"
            . "NOT_A_PAIR\n"                        // skipped (no '=')
            . "WITH_EQUALS=a=b=c\n",                // only first '=' splits
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testParsesPairsCommentsBlanksAndQuotes(): void
    {
        $parsed = phlix_parse_env_file($this->file);

        $this->assertSame('s3cr3t', $parsed['DB_PASSWORD']);
        $this->assertSame('example.com', $parsed['PHLIX_DOMAIN']);
        $this->assertSame('quoted value', $parsed['QUOTED']);
        $this->assertSame('single value', $parsed['SINGLE']);
        $this->assertSame('a=b=c', $parsed['WITH_EQUALS']);
        $this->assertArrayNotHasKey('NOT_A_PAIR', $parsed);
    }

    public function testParseReturnsEmptyForMissingFile(): void
    {
        $this->assertSame([], phlix_parse_env_file('/no/such/phlix/env/file'));
    }

    public function testBootstrapIsNoopWhenDbPasswordAlreadySet(): void
    {
        // DB_PASSWORD is set by phpunit.xml, so the loader must NOT read the
        // file or overwrite anything — it returns no applied keys.
        $original = getenv('DB_PASSWORD');
        $this->assertNotFalse($original, 'phpunit.xml should provide DB_PASSWORD');

        $applied = phlix_bootstrap_cli_env($this->file);

        $this->assertSame([], $applied);
        $this->assertSame($original, getenv('DB_PASSWORD'), 'must not clobber an existing var');
    }
}
