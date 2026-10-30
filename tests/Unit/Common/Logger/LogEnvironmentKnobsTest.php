<?php

/**
 * Phlix media server test: logger environment knobs (R14-09 / R3-08).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Logger;

use Monolog\Handler\GroupHandler;
use Monolog\Handler\StreamHandler;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins the two environment seams the SRV-OPS lane wired:
 *
 *   - R14-09 `PHLIX_LOG_STDOUT`: shipped config carries a stdout stream
 *     handler gated OFF by default; truthy env attaches it IN ADDITION to
 *     the file handlers (a sink is added, never replaced).
 *   - R3-08 `PHLIX_LOG_LEVEL`: a valid name overrides the per-handler floor
 *     for every level-driven handler; unset keeps the shipped defaults and
 *     an unknown name fails SOFT (config level stands — a typo in a deploy
 *     file must not mute or flood the logs).
 *
 * Env vars are restored after every test via tearDown so the suite stays
 * hermetic (README "no test may rely on ambient env" law).
 */
class LogEnvironmentKnobsTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> env names this class mutates */
    private const TOUCHED = [LogChannels::LOG_STDOUT_ENV, LogChannels::LOG_LEVEL_ENV];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phlix_test_logenv_' . uniqid('', true);
        mkdir($this->tempDir, 0700, true);
        foreach (self::TOUCHED as $name) {
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TOUCHED as $name) {
            putenv($name);
        }
        array_map('unlink', glob($this->tempDir . '/*') ?: []);
        @rmdir($this->tempDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function shippedConfig(): array
    {
        $config = require dirname(__DIR__, 4) . '/config/logger.php';
        $this->assertIsArray($config);

        return $config;
    }

    public function test_shipped_config_ships_an_env_gated_stdout_stream_handler(): void
    {
        $handlers = $this->shippedConfig()['handlers'];
        $this->assertIsArray($handlers);
        $this->assertArrayHasKey('stdout', $handlers, 'R14-09: config/logger.php must ship the stdout sink');

        $stdout = $handlers['stdout'];
        $this->assertIsArray($stdout);
        $this->assertSame('stream', $stdout['type']);
        $this->assertSame('php://stdout', $stdout['path']);
        $this->assertSame(
            LogChannels::LOG_STDOUT_ENV,
            $stdout['env'] ?? null,
            'the stdout handler must be gated OFF by default via PHLIX_LOG_STDOUT',
        );
    }

    public function test_stdout_handler_is_absent_without_the_env_switch(): void
    {
        $logger = new StructuredLogger(LogChannels::APPLICATION, $this->shippedConfig());

        $this->assertFalse(
            $this->hasStdoutStreamHandler($logger),
            'unset PHLIX_LOG_STDOUT must attach no stdout handler (byte-identical routing)',
        );
    }

    public function test_truthy_stdout_env_attaches_the_stream_sink_alongside_the_files(): void
    {
        putenv(LogChannels::LOG_STDOUT_ENV . '=1');

        // Point the file handlers at this test's temp dir so the logger is
        // fully sandboxed; the stdout handler path needs no redirection.
        $config = $this->shippedConfig();
        foreach ($config['handlers'] as $name => $handler) {
            if (is_array($handler) && ($handler['type'] ?? null) === 'size_rotating') {
                $config['handlers'][$name]['path'] = $this->tempDir . '/' . $name . '.log';
            }
        }
        $config['handlers']['events']['env'] = 'PHLIX_R1409_DEFINITELY_UNSET';

        $logger = new StructuredLogger(LogChannels::APPLICATION, $config);

        $this->assertTrue($this->hasStdoutStreamHandler($logger), 'PHLIX_LOG_STDOUT=1 must attach the stdout sink');

        // ...and the file sink still received its record — ADDITIVE sink law.
        $logger->info('r1409 additive record');
        clearstatcache();
        $this->assertFileExists($this->tempDir . '/file.log');
        $written = (string) file_get_contents($this->tempDir . '/file.log');
        $this->assertStringContainsString('r1409 additive record', $written);
    }

    public function test_unset_env_level_keeps_the_shipped_info_floor_debug_is_dropped(): void
    {
        $config = $this->levelProbeConfig();
        $logger = new StructuredLogger(LogChannels::APPLICATION, $config);

        $logger->info('floor-info-visible');
        $logger->debug('floor-debug-dropped');

        $contents = (string) file_get_contents($this->tempDir . '/probe.log');
        $this->assertStringContainsString('floor-info-visible', $contents);
        $this->assertStringNotContainsString('floor-debug-dropped', $contents);
    }

    public function test_log_level_debug_loosens_the_info_floor(): void
    {
        putenv(LogChannels::LOG_LEVEL_ENV . '=debug');

        $logger = new StructuredLogger(LogChannels::APPLICATION, $this->levelProbeConfig());
        $logger->debug('knob-debug-visible');

        $this->assertStringContainsString('knob-debug-visible', $this->probeLog());
    }

    public function test_log_level_error_quiets_below_error(): void
    {
        putenv(LogChannels::LOG_LEVEL_ENV . '=ERROR'); // case-insensitive

        $logger = new StructuredLogger(LogChannels::APPLICATION, $this->levelProbeConfig());
        $logger->info('knob-info-muted');
        $logger->error('knob-error-visible');

        $contents = (string) file_get_contents($this->tempDir . '/probe.log');
        $this->assertStringNotContainsString('knob-info-muted', $contents);
        $this->assertStringContainsString('knob-error-visible', $contents);
    }

    public function test_unknown_log_level_name_fails_soft_config_floor_stands(): void
    {
        putenv(LogChannels::LOG_LEVEL_ENV . '=verbose');

        $logger = new StructuredLogger(LogChannels::APPLICATION, $this->levelProbeConfig());
        $logger->info('soft-fallback-visible');
        $logger->debug('soft-fallback-dropped');

        $contents = (string) file_get_contents($this->tempDir . '/probe.log');
        $this->assertStringContainsString('soft-fallback-visible', $contents);
        $this->assertStringNotContainsString('soft-fallback-dropped', $contents);
    }

    public function test_empty_log_level_value_is_treated_as_unset(): void
    {
        putenv(LogChannels::LOG_LEVEL_ENV . '=');

        $logger = new StructuredLogger(LogChannels::APPLICATION, $this->levelProbeConfig());
        $logger->info('empty-is-unset-visible');

        $this->assertStringContainsString('empty-is-unset-visible', $this->probeLog());
    }

    private function probeLog(): string
    {
        return (string) file_get_contents($this->tempDir . '/probe.log');
    }

    /**
     * @return array<string, mixed>
     */
    private function levelProbeConfig(): array
    {
        return [
            'handlers' => [
                'file' => [
                    'type' => 'size_rotating',
                    'path' => $this->tempDir . '/probe.log',
                    'max_files' => 1,
                    'max_file_size_mb' => 1,
                    'level' => 'info',
                ],
            ],
        ];
    }

    private function hasStdoutStreamHandler(StructuredLogger $logger): bool
    {
        $property = new ReflectionProperty(StructuredLogger::class, 'logger');
        /** @var \Monolog\Logger $monolog */
        $monolog = $property->getValue($logger);
        $handlers = new ReflectionProperty(GroupHandler::class, 'handlers');

        foreach ($monolog->getHandlers() as $group) {
            // Monolog 3 keeps GroupHandler::$handlers protected — the test
            // reaches it by reflection rather than loosening production API.
            $inner = $group instanceof GroupHandler ? $handlers->getValue($group) : [$group];
            foreach ($inner as $handler) {
                if ($handler instanceof StreamHandler && $handler->getUrl() === 'php://stdout') {
                    return true;
                }
            }
        }

        return false;
    }
}
