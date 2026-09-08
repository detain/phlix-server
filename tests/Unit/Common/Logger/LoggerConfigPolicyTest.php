<?php

/**
 * Phlix media server component: Logger — shipped config policy (S130).
 *
 * Reads the REAL `config/logger.php` (not a hand-built mirror) and pins the two
 * decisions S130 made: the general app.log runs at a deliberate production level
 * (not the inherited `debug` default), and every file log is size-bounded. These
 * are the assertions that go RED if the policy is quietly reverted.
 *
 * MUTATION PROOF:
 *   - change the `file` handler level back to `debug`  -> test_appLogFileLevelIsDeliberate RED;
 *   - change any handler type off `size_rotating`, or drop its `max_file_size_mb`
 *     -> test_everyFileHandlerIsSizeBounded RED.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Logger;

use PHPUnit\Framework\TestCase;

class LoggerConfigPolicyTest extends TestCase
{
    /**
     * @return array{handlers: array<string, array<string, mixed>>}
     */
    private function loadConfig(): array
    {
        $config = require __DIR__ . '/../../../../config/logger.php';
        $this->assertIsArray($config);
        $this->assertArrayHasKey('handlers', $config);
        /** @var array{handlers: array<string, array<string, mixed>>} $config */
        return $config;
    }

    public function test_appLogFileLevelIsDeliberateNotDebugDefault(): void
    {
        $file = $this->loadConfig()['handlers']['file'];

        // info is the deliberate S130 production choice: keep the scanners'
        // info + warning + error, drop only the verbose per-item debug chatter.
        $this->assertSame('info', $file['level'], 'app.log level must be the deliberate production value, not debug');
    }

    public function test_everyFileHandlerIsSizeBounded(): void
    {
        foreach ($this->loadConfig()['handlers'] as $name => $handler) {
            $this->assertSame(
                'size_rotating',
                $handler['type'] ?? null,
                "handler '{$name}' must be size_rotating so its on-disk size is capped",
            );
            $this->assertArrayHasKey('max_file_size_mb', $handler, "handler '{$name}' must declare a size ceiling");
            $this->assertIsInt($handler['max_file_size_mb']);
            $this->assertGreaterThan(0, $handler['max_file_size_mb'], "handler '{$name}' ceiling must be positive");
            $shardCount = (int) ($handler['max_files'] ?? -1);
            $this->assertGreaterThanOrEqual(0, $shardCount, "handler '{$name}' shard count must be >= 0");
        }
    }

    public function test_appLogDocumentedWorstCaseFootprintIsBounded(): void
    {
        $file = $this->loadConfig()['handlers']['file'];

        // The (max_files + 1) * max_file_size_mb arithmetic the config header
        // documents for app.log; a ceiling change must update that prose too.
        $worstCaseMb = ((int) $file['max_files'] + 1) * (int) $file['max_file_size_mb'];
        $this->assertSame(240, $worstCaseMb, 'app.log worst-case footprint is pinned to the documented 240 MB');
    }
}
