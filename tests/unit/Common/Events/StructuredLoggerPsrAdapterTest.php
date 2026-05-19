<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events;

use Monolog\Level;
use Phlex\Common\Events\StructuredLoggerPsrAdapter;
use Phlex\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use ReflectionClass;
use Stringable;

/**
 * @covers \Phlex\Common\Events\StructuredLoggerPsrAdapter
 */
final class StructuredLoggerPsrAdapterTest extends TestCase
{
    public function test_forwards_psr_string_levels_to_monolog_levels(): void
    {
        $spy = new class ('spy', [
            'handlers' => [
                'null' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug'],
            ],
        ]) extends StructuredLogger {
            /** @var array<int, array{level: Level, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param mixed              $level
             * @param string|Stringable  $message
             * @param array<string,mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (!$level instanceof Level) {
                    throw new \LogicException('Spy expected a Monolog Level from the adapter.');
                }
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $adapter = new StructuredLoggerPsrAdapter($spy);

        $adapter->info('info-message', ['k' => 'v']);
        $adapter->warning('warn-message');
        $adapter->error('err-message');
        $adapter->log(LogLevel::CRITICAL, 'crit-message');

        $this->assertCount(4, $spy->records);
        $this->assertSame(Level::Info, $spy->records[0]['level']);
        $this->assertSame('info-message', $spy->records[0]['message']);
        $this->assertSame(['k' => 'v'], $spy->records[0]['context']);
        $this->assertSame(Level::Warning, $spy->records[1]['level']);
        $this->assertSame(Level::Error, $spy->records[2]['level']);
        $this->assertSame(Level::Critical, $spy->records[3]['level']);
    }

    public function test_unknown_string_level_falls_back_to_debug(): void
    {
        $spy = new class ('spy', [
            'handlers' => [
                'null' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug'],
            ],
        ]) extends StructuredLogger {
            /** @var array<int, Level> */
            public array $levels = [];

            /**
             * @param mixed              $level
             * @param string|Stringable  $message
             * @param array<string,mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (!$level instanceof Level) {
                    throw new \LogicException('Spy expected a Monolog Level from the adapter.');
                }
                $this->levels[] = $level;
            }
        };

        $adapter = new StructuredLoggerPsrAdapter($spy);
        $adapter->log('not-a-level', 'msg');

        $this->assertSame([Level::Debug], $spy->levels);
    }

    public function test_passes_through_monolog_level_objects(): void
    {
        $spy = new class ('spy', [
            'handlers' => [
                'null' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug'],
            ],
        ]) extends StructuredLogger {
            /** @var array<int, Level> */
            public array $levels = [];

            /**
             * @param mixed              $level
             * @param string|Stringable  $message
             * @param array<string,mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (!$level instanceof Level) {
                    throw new \LogicException('Spy expected a Monolog Level from the adapter.');
                }
                $this->levels[] = $level;
            }
        };

        $adapter = new StructuredLoggerPsrAdapter($spy);
        $adapter->log(Level::Notice, 'msg');

        $this->assertSame([Level::Notice], $spy->levels);
    }

    public function test_integer_level_resolved_via_monolog_from_value(): void
    {
        $spy = new class ('spy', [
            'handlers' => [
                'null' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug'],
            ],
        ]) extends StructuredLogger {
            /** @var array<int, Level> */
            public array $levels = [];

            /**
             * @param mixed              $level
             * @param string|Stringable  $message
             * @param array<string,mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (!$level instanceof Level) {
                    throw new \LogicException('Spy expected a Monolog Level from the adapter.');
                }
                $this->levels[] = $level;
            }
        };

        $adapter = new StructuredLoggerPsrAdapter($spy);
        $adapter->log(Level::Warning->value, 'msg');

        $this->assertSame([Level::Warning], $spy->levels);
    }

    public function test_uses_reflection_to_inspect_internal_state(): void
    {
        // Smoke: ensure the adapter constructor stores the delegate.
        $logger = new StructuredLogger('spy', [
            'handlers' => [
                'null' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug'],
            ],
        ]);
        $adapter = new StructuredLoggerPsrAdapter($logger);

        $ref = new ReflectionClass($adapter);
        $prop = $ref->getProperty('delegate');
        $prop->setAccessible(true);

        $this->assertSame($logger, $prop->getValue($adapter));
    }
}
