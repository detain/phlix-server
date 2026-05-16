<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events;

use Crell\Tukio\DebugEventDispatcher;
use Crell\Tukio\Dispatcher;
use DI\ContainerBuilder;
use Phlex\Common\Events\EventDispatcherFactory;
use Phlex\Common\Events\ListenerRegistry;
use Phlex\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Phlex\Tests\Fixtures\Events\SampleEvent;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Unit tests for {@see EventDispatcherFactory}.
 *
 * @covers \Phlex\Common\Events\EventDispatcherFactory
 */
final class EventDispatcherFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR);
    }

    public function test_create_returns_psr_dispatcher(): void
    {
        $registry = new ListenerRegistry();
        $dispatcher = EventDispatcherFactory::create($registry);

        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
    }

    public function test_debug_decorator_logs_when_env_set(): void
    {
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR . '=1');

        $registry = new ListenerRegistry();
        $logger = new class () extends AbstractLogger {
            /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $dispatcher = EventDispatcherFactory::create($registry, $logger);
        $this->assertInstanceOf(DebugEventDispatcher::class, $dispatcher);

        $dispatcher->dispatch(new SampleEvent('hi'));

        $this->assertNotEmpty($logger->records, 'debug logger should receive a record');
        $this->assertStringContainsString('Processing event', $logger->records[0]['message']);
        $this->assertSame(SampleEvent::class, $logger->records[0]['context']['type'] ?? null);
    }

    public function test_no_debug_decorator_by_default(): void
    {
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR);

        $registry = new ListenerRegistry();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('debug');

        $dispatcher = EventDispatcherFactory::create($registry, $logger);
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
        $this->assertNotInstanceOf(DebugEventDispatcher::class, $dispatcher);

        $dispatcher->dispatch(new SampleEvent('hi'));
    }

    public function test_debug_enabled_recognises_truthy_values(): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on'] as $val) {
            putenv(EventDispatcherFactory::DEBUG_ENV_VAR . '=' . $val);
            $this->assertTrue(
                EventDispatcherFactory::debugEnabled(),
                "value '{$val}' should be truthy"
            );
        }

        foreach (['', '0', 'false', 'no', 'off', 'maybe'] as $val) {
            putenv(EventDispatcherFactory::DEBUG_ENV_VAR . '=' . $val);
            $this->assertFalse(
                EventDispatcherFactory::debugEnabled(),
                "value '{$val}' should be falsy"
            );
        }

        putenv(EventDispatcherFactory::DEBUG_ENV_VAR);
        $this->assertFalse(EventDispatcherFactory::debugEnabled());
    }

    public function test_new_provider_returns_fresh_instance(): void
    {
        $a = EventDispatcherFactory::newProvider();
        $b = EventDispatcherFactory::newProvider();
        $this->assertNotSame($a, $b);
    }

    public function test_invoke_resolves_dispatcher_from_container(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([
            ListenerRegistry::class => new ListenerRegistry(),
        ]);
        $container = $builder->build();

        $factory = new EventDispatcherFactory();
        $dispatcher = $factory($container);
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
    }

    public function test_invoke_wraps_in_debug_decorator_with_psr_logger_alias(): void
    {
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR . '=1');

        $psrLogger = new class () extends AbstractLogger {
            /** @var array<int, string> */
            public array $messages = [];
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string)$message;
            }
        };

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([
            ListenerRegistry::class => new ListenerRegistry(),
            'logger.events' => $psrLogger,
        ]);
        $container = $builder->build();

        $factory = new EventDispatcherFactory();
        $dispatcher = $factory($container);
        $this->assertInstanceOf(DebugEventDispatcher::class, $dispatcher);

        $dispatcher->dispatch(new SampleEvent('routed'));
        $this->assertNotEmpty($psrLogger->messages);
    }

    public function test_invoke_wraps_in_debug_decorator_with_structured_logger_alias(): void
    {
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR . '=on');

        $structured = new StructuredLogger('events.test', [
            'handlers' => [
                'mem' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug'],
            ],
        ]);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([
            ListenerRegistry::class => new ListenerRegistry(),
            'logger.events' => $structured,
        ]);
        $container = $builder->build();

        $factory = new EventDispatcherFactory();
        $dispatcher = $factory($container);
        $this->assertInstanceOf(DebugEventDispatcher::class, $dispatcher);
        // No exception means the StructuredLogger->adapter branch worked.
        $dispatcher->dispatch(new SampleEvent('hello'));
    }

    public function test_invoke_falls_back_to_logger_factory_when_alias_missing(): void
    {
        // No 'logger.events' container alias is registered, but the
        // LoggerFactory is pointed at a working config so the factory's
        // fallback path resolves the events channel from disk.
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR . '=true');

        $tempDir = sys_get_temp_dir() . '/phlex_ed_factory_' . uniqid('', true);
        mkdir($tempDir, 0775, true);
        $loggerConfigPath = $tempDir . '/logger.php';
        file_put_contents(
            $loggerConfigPath,
            "<?php\nreturn ['handlers' => ['mem' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];\n"
        );

        try {
            \Phlex\Common\Logger\LoggerFactory::reset();
            \Phlex\Common\Logger\LoggerFactory::init($loggerConfigPath);

            $builder = new ContainerBuilder();
            $builder->useAutowiring(true);
            $builder->addDefinitions([
                ListenerRegistry::class => new ListenerRegistry(),
            ]);
            $container = $builder->build();

            $factory = new EventDispatcherFactory();
            $dispatcher = $factory($container);
            $this->assertInstanceOf(DebugEventDispatcher::class, $dispatcher);
        } finally {
            \Phlex\Common\Logger\LoggerFactory::reset();
            @unlink($loggerConfigPath);
            @rmdir($tempDir);
        }
    }

    public function test_create_with_no_logger_returns_undecorated_dispatcher_even_when_debug_on(): void
    {
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR . '=1');

        $registry = new ListenerRegistry();
        $dispatcher = EventDispatcherFactory::create($registry, null);
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
        $this->assertNotInstanceOf(DebugEventDispatcher::class, $dispatcher);
    }
}
