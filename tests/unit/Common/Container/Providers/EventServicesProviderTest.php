<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Container\Providers;

use Crell\Tukio\Dispatcher;
use Crell\Tukio\OrderedListenerProvider;
use DI\ContainerBuilder;
use Phlex\Common\Container\Providers\CoreServicesProvider;
use Phlex\Common\Container\Providers\EventServicesProvider;
use Phlex\Common\Events\EventDispatcherFactory;
use Phlex\Common\Events\ListenerRegistry;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Phlex\Tests\Fixtures\Events\SampleEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * @covers \Phlex\Common\Container\Providers\EventServicesProvider
 */
final class EventServicesProviderTest extends TestCase
{
    private string $tempDir = '';
    private string $loggerConfigPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR);

        $this->tempDir = sys_get_temp_dir() . '/phlex_events_provider_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $config = "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n";
        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents($this->loggerConfigPath, $config);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        putenv(EventDispatcherFactory::DEBUG_ENV_VAR);
        @unlink($this->loggerConfigPath);
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
    }

    public function test_register_wires_dispatcher_and_registry(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new CoreServicesProvider())->register($builder, [
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ]);
        (new EventServicesProvider())->register($builder, [
            'logger_config_path' => $this->loggerConfigPath,
        ]);

        $container = $builder->build();

        $this->assertTrue($container->has(EventDispatcherInterface::class));
        $this->assertTrue($container->has(ListenerRegistry::class));
        $this->assertTrue($container->has(ListenerProviderInterface::class));
        $this->assertTrue($container->has(OrderedListenerProvider::class));
        $this->assertTrue($container->has(EventDispatcherFactory::class));
        $this->assertTrue($container->has('logger.events'));

        $dispatcher = $container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);

        $registry = $container->get(ListenerRegistry::class);
        $this->assertInstanceOf(ListenerRegistry::class, $registry);

        // Singleton expectation: resolving twice yields the same provider.
        $this->assertSame(
            $container->get(OrderedListenerProvider::class),
            $container->get(OrderedListenerProvider::class)
        );
        $this->assertSame(
            $container->get(ListenerRegistry::class),
            $container->get(ListenerRegistry::class)
        );
    }

    public function test_dispatcher_reaches_listener_subscribed_via_registry(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new CoreServicesProvider())->register($builder, [
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ]);
        (new EventServicesProvider())->register($builder, [
            'logger_config_path' => $this->loggerConfigPath,
        ]);

        $container = $builder->build();

        /** @var ListenerRegistry $registry */
        $registry = $container->get(ListenerRegistry::class);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);

        $captured = [];
        $registry->subscribe(SampleEvent::class, function (SampleEvent $e) use (&$captured): void {
            $captured[] = $e->message;
        });

        $dispatcher->dispatch(new SampleEvent('round-trip'));
        $this->assertSame(['round-trip'], $captured);
    }

    public function test_logger_events_alias_resolves_to_structured_logger(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new CoreServicesProvider())->register($builder, [
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ]);
        (new EventServicesProvider())->register($builder, [
            'logger_config_path' => $this->loggerConfigPath,
        ]);

        $container = $builder->build();

        $this->assertInstanceOf(StructuredLogger::class, $container->get('logger.events'));
    }
}
