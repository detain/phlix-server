<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Signature;

use InvalidArgumentException;
use Phlix\Plugins\Signature\TrustedSignaturesConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TrustedSignaturesConfigTest extends TestCase
{
    private string $entryA = '';

    private string $entryB = '';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private string $tmpFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->entryA = 'sha256:' . hash('sha256', 'plugin-a');
        $this->entryB = 'sha256:' . hash('sha256', 'plugin-b');
        $this->savedEnv = [
            TrustedSignaturesConfig::ENV_LIST => getenv(TrustedSignaturesConfig::ENV_LIST),
            TrustedSignaturesConfig::ENV_FILE => getenv(TrustedSignaturesConfig::ENV_FILE),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        if ($this->tmpFile !== '' && is_file($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
        $this->tmpFile = '';
        parent::tearDown();
    }

    public function test_both_seams_unset_yields_empty_allowlist(): void
    {
        putenv(TrustedSignaturesConfig::ENV_LIST);
        putenv(TrustedSignaturesConfig::ENV_FILE);

        $this->assertSame([], TrustedSignaturesConfig::fromEnv());
    }

    public function test_env_list_is_trimmed_deduplicated_and_order_preserved(): void
    {
        $raw = ' ' . $this->entryB . ' ,, ' . $this->entryA . ' , ' . $this->entryB . ' ';
        putenv(TrustedSignaturesConfig::ENV_LIST . '=' . $raw);
        putenv(TrustedSignaturesConfig::ENV_FILE);

        $this->assertSame([$this->entryB, $this->entryA], TrustedSignaturesConfig::fromEnv());
    }

    public function test_malformed_env_entry_fails_loud(): void
    {
        putenv(TrustedSignaturesConfig::ENV_LIST . '=sha256:deadbeef');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed trusted-signature entry');

        TrustedSignaturesConfig::fromEnv();
    }

    public function test_uppercase_hex_is_a_valid_entry(): void
    {
        $upper = 'sha256:' . strtoupper(hash('sha256', 'plugin-a'));

        $this->assertSame([$upper], TrustedSignaturesConfig::parseList($upper));
    }

    public function test_json_file_seam_supplies_and_merges_entries(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/phlix_trustedsig_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->tmpFile, json_encode([$this->entryA, $this->entryB]));

        putenv(TrustedSignaturesConfig::ENV_LIST . '=' . $this->entryB);
        putenv(TrustedSignaturesConfig::ENV_FILE . '=' . $this->tmpFile);

        $this->assertSame([$this->entryB, $this->entryA], TrustedSignaturesConfig::fromEnv());
    }

    public function test_missing_json_file_fails_loud(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unreadable file');

        TrustedSignaturesConfig::parseFile('/nonexistent/phlix-trusted-signatures.json');
    }

    public function test_invalid_json_fails_loud(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        TrustedSignaturesConfig::parseJsonList('{not json', 'broken.json');
    }

    public function test_json_object_instead_of_list_fails_loud(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must contain a JSON array');

        TrustedSignaturesConfig::parseJsonList('{"a": "' . $this->entryA . '"}', 'map.json');
    }

    public function test_non_string_json_member_fails_loud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a string');

        TrustedSignaturesConfig::parseJsonList('[42]', 'nums.json');
    }
}
